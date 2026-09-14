<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

dol_include_once('/email2order/class/extractor/htmlorderextractor.class.php');

/**
 * Profile-driven parser for structured HTML order confirmations.
 *
 * Supplier differences live in declarative profiles (sender/subject hints,
 * table headers and field extraction rules). The DOM/table extraction and
 * normalization code is shared by all profiles.
 */
class ProfiledHtmlEmail2OrderParser implements Email2OrderParserInterface
{
	/** @var string */
	private $activeProfile = '';

	/** @var HtmlOrderExtractor */
	private $extractor;

	public function __construct()
	{
		$this->extractor = new HtmlOrderExtractor();
	}

	/** @inheritdoc */
	public function supports(array $message): bool
	{
		$profile = $this->detectProfile($message);
		if ($profile === null) {
			return false;
		}
		$this->activeProfile = (string) $profile['id'];
		return true;
	}

	/** @inheritdoc */
	public function getName(): string
	{
		return $this->activeProfile !== '' ? 'html-profile:'.$this->activeProfile : 'html-profile';
	}

	/** @inheritdoc */
	public function parse(array $message): array
	{
		$profile = $this->getActiveProfile($message);
		if ($profile === null) {
			return $this->emptyResult();
		}

		$this->activeProfile = (string) $profile['id'];
		$body = (string) ($message['body'] ?? '');
		$subject = (string) ($message['subject'] ?? '');
		$header = (string) ($message['header'] ?? '');
		$text = $this->extractor->toText($body);
		$combinedText = $subject."\n".$text;

		$tables = $this->extractor->extractTables($body);
		$tableMatch = $this->extractor->findTableByHeaderPatterns($tables, (array) ($profile['table_header_patterns'] ?? array()));
		$lines = $tableMatch !== null ? $this->parseLines($tableMatch, $profile) : array();

		return array(
			'supplier_reference' => $this->extractFirst($combinedText, (array) ($profile['supplier_reference_regexes'] ?? array())),
			'original_sender_email' => $this->resolveSupplierSender($message, $profile),
			'order_date' => $this->extractDate($combinedText, (array) ($profile['order_date_regexes'] ?? array())),
			'delivery_date' => null,
			'currency' => (string) ($profile['currency'] ?? 'HUF'),
			'lines' => $lines,
		);
	}

	/**
	 * @param array<string,mixed> $message Message
	 * @return array<string,mixed>|null
	 */
	private function detectProfile(array $message): ?array
	{
		$haystack = strtolower(
			(string) ($message['from'] ?? '')."\n".
			(string) ($message['subject'] ?? '')."\n".
			(string) ($message['body'] ?? '')."\n".
			(string) ($message['header'] ?? '')
		);

		foreach ($this->getProfiles() as $profile) {
			$domainMatched = false;
			foreach ((array) ($profile['domains'] ?? array()) as $domain) {
				if ($domain !== '' && strpos($haystack, strtolower((string) $domain)) !== false) {
					$domainMatched = true;
					break;
				}
			}
			if (!$domainMatched) {
				continue;
			}

			$subjectHints = (array) ($profile['subject_hints'] ?? array());
			if (!empty($subjectHints)) {
				$subject = strtolower((string) ($message['subject'] ?? ''));
				$hintMatched = false;
				foreach ($subjectHints as $hint) {
					if ($hint !== '' && strpos($subject, strtolower((string) $hint)) !== false) {
						$hintMatched = true;
						break;
					}
				}
				// Forwarded subjects may be localized/rewritten, so domain identity
				// remains sufficient if the supplier appears in the forwarded body.
				if (!$hintMatched && strpos($haystack, 'fwd:') === false && strpos($haystack, 'fw:') === false) {
					continue;
				}
			}

			return $profile;
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $message Message
	 * @return array<string,mixed>|null
	 */
	private function getActiveProfile(array $message): ?array
	{
		if ($this->activeProfile !== '') {
			foreach ($this->getProfiles() as $profile) {
				if (($profile['id'] ?? '') === $this->activeProfile) {
					return $profile;
				}
			}
		}
		return $this->detectProfile($message);
	}

	/**
	 * @param array{table:array<string,mixed>,header_index:int} $tableMatch Matched table
	 * @param array<string,mixed> $profile Profile
	 * @return array<int,array<string,mixed>>
	 */
	private function parseLines(array $tableMatch, array $profile): array
	{
		$table = (array) ($tableMatch['table'] ?? array());
		$rows = (array) ($table['rows'] ?? array());
		$headerIndex = (int) ($tableMatch['header_index'] ?? 0);
		$headerRow = (array) ($rows[$headerIndex] ?? array());

		$columns = array();
		if (!empty($profile['fixed_columns']) && is_array($profile['fixed_columns'])) {
			$columns = $profile['fixed_columns'];
		} else {
			foreach ((array) ($profile['columns'] ?? array()) as $field => $pattern) {
				$columns[$field] = $this->extractor->findColumn($headerRow, (string) $pattern);
			}
		}

		$lines = array();
		for ($rowIndex = $headerIndex + 1, $count = count($rows); $rowIndex < $count; $rowIndex++) {
			$cells = (array) (($rows[$rowIndex]['cells'] ?? array()));
			if (empty($cells)) {
				continue;
			}

			$productText = $this->cellText($cells, (int) ($columns['product'] ?? -1));
			$qtyText = $this->cellText($cells, (int) ($columns['qty'] ?? -1));
			$unitText = $this->cellText($cells, (int) ($columns['unit'] ?? -1));
			$priceText = $this->cellText($cells, (int) ($columns['unit_price'] ?? -1));
			$vatText = $this->cellText($cells, (int) ($columns['vat'] ?? -1));

			$qty = $this->parseQuantity($qtyText);
			if ($qty <= 0 || $productText === '') {
				continue;
			}

			$supplierProductRef = '';
			if (!empty($profile['product_ref_is_product_cell'])) {
				$supplierProductRef = trim($productText);
			} elseif (!empty($profile['product_ref_regex'])) {
				$supplierProductRef = $this->extractFirst($productText, array((string) $profile['product_ref_regex']));
			}

			$manufacturerRef = '';
			if (!empty($profile['manufacturer_ref_regex'])) {
				$manufacturerRef = $this->extractFirst($productText, array((string) $profile['manufacturer_ref_regex']));
				if (strtoupper($manufacturerRef) === 'N/A') {
					$manufacturerRef = '';
				}
			}

			$priceSource = $priceText;
			if (($profile['unit_price_source'] ?? '') === 'product') {
				$priceSource = $productText;
			}
			$unitPrice = $this->extractUnitPrice($priceSource, $profile);
			if ($unitPrice < 0) {
				continue;
			}

			$vatRate = isset($profile['default_vat']) ? (float) $profile['default_vat'] : 0.0;
			if ($vatText !== '') {
				$parsedVat = $this->extractPercent($vatText);
				if ($parsedVat !== null) {
					$vatRate = $parsedVat;
				}
			} elseif (($profile['vat_source'] ?? '') === 'unit_price') {
				$parsedVat = $this->extractPercent($priceSource);
				if ($parsedVat !== null) {
					$vatRate = $parsedVat;
				}
			}

			$label = $this->cleanupLabel($productText, (array) ($profile['label_strip_patterns'] ?? array()));
			$unit = $unitText !== '' ? $this->cleanupUnit($unitText) : $this->extractUnitFromQuantity($qtyText);

			$lines[] = array(
				'supplier_product_ref' => $supplierProductRef,
				'manufacturer_ref' => $manufacturerRef,
				'label' => $label,
				'qty' => $qty,
				'unit' => $unit,
				'unit_price' => $unitPrice,
				'vat_rate' => $vatRate,
			);
		}

		return $lines;
	}

	/**
	 * @param array<int,array{text:string,html:string}> $cells Cells
	 * @param int $index Index
	 * @return string
	 */
	private function cellText(array $cells, int $index): string
	{
		return $index >= 0 && isset($cells[$index]) ? trim((string) ($cells[$index]['text'] ?? '')) : '';
	}

	/**
	 * @param string $text Source
	 * @param array<string,mixed> $profile Profile
	 * @return float
	 */
	private function extractUnitPrice(string $text, array $profile): float
	{
		if ($text === '') {
			return -1.0;
		}

		if (!empty($profile['unit_price_regex'])) {
			$value = $this->extractFirst($text, array((string) $profile['unit_price_regex']));
			return $value !== '' ? $this->parseMoney($value, (string) ($profile['number_format'] ?? 'auto')) : -1.0;
		}

		$amounts = array();
		if (preg_match_all('/(-?[0-9][0-9\s.,]*)\s*(?:Ft|HUF)\b/iu', $text, $matches)) {
			foreach ((array) ($matches[1] ?? array()) as $amount) {
				$amounts[] = $this->parseMoney((string) $amount, (string) ($profile['number_format'] ?? 'auto'));
			}
		}
		$pick = isset($profile['unit_price_amount_index']) ? (int) $profile['unit_price_amount_index'] : 0;
		return isset($amounts[$pick]) ? (float) $amounts[$pick] : -1.0;
	}

	/**
	 * @param string $text Text
	 * @param string[] $patterns Regexes without delimiters
	 * @return string
	 */
	private function extractFirst(string $text, array $patterns): string
	{
		foreach ($patterns as $pattern) {
			$matches = array();
			if (@preg_match('/'.$pattern.'/iu', $text, $matches) === 1 && isset($matches[1])) {
				return trim((string) $matches[1]);
			}
		}
		return '';
	}

	/**
	 * @param string $text Text
	 * @param string[] $patterns Regexes
	 * @return int|null Unix timestamp
	 */
	private function extractDate(string $text, array $patterns): ?int
	{
		$value = $this->extractFirst($text, $patterns);
		if ($value === '') {
			return null;
		}
		$normalized = preg_replace('/(?<=\d)\.(?=\d)/', '-', trim($value));
		$normalized = is_string($normalized) ? str_replace('. ', ' ', $normalized) : trim($value);
		$timestamp = strtotime($normalized);
		return $timestamp !== false ? $timestamp : null;
	}

	/**
	 * @param array<string,mixed> $message Message
	 * @param array<string,mixed> $profile Profile
	 * @return string
	 */
	private function resolveSupplierSender(array $message, array $profile): string
	{
		$text = (string) ($message['from'] ?? '')."\n".(string) ($message['body'] ?? '')."\n".(string) ($message['header'] ?? '');
		foreach ((array) ($profile['domains'] ?? array()) as $domain) {
			$matches = array();
			if (preg_match('/([A-Z0-9._%+\-]+@'.preg_quote((string) $domain, '/').')/iu', $text, $matches)) {
				return strtolower(trim((string) $matches[1]));
			}
		}
		return strtolower((string) ($profile['canonical_sender'] ?? ''));
	}

	/**
	 * @param string $value Quantity text
	 * @return float
	 */
	private function parseQuantity(string $value): float
	{
		if (preg_match('/-?[0-9]+(?:[.,][0-9]+)?/u', str_replace("\xC2\xA0", ' ', $value), $matches)) {
			return (float) str_replace(',', '.', (string) $matches[0]);
		}
		return 0.0;
	}

	/**
	 * @param string $value Money text
	 * @param string $format Number convention
	 * @return float
	 */
	private function parseMoney(string $value, string $format): float
	{
		$value = trim(str_replace(array("\xC2\xA0", ' '), '', $value));
		$value = preg_replace('/[^0-9,\.\-]/u', '', $value);
		if (!is_string($value) || $value === '') {
			return -1.0;
		}

		if ($format === 'comma_thousands') {
			return (float) str_replace(',', '', $value);
		}
		if ($format === 'us') {
			return (float) str_replace(',', '', $value);
		}
		if ($format === 'decimal_comma') {
			return (float) str_replace(',', '.', str_replace('.', '', $value));
		}

		$lastComma = strrpos($value, ',');
		$lastDot = strrpos($value, '.');
		if ($lastComma !== false && $lastDot !== false) {
			if ($lastDot > $lastComma) {
				return (float) str_replace(',', '', $value);
			}
			return (float) str_replace(',', '.', str_replace('.', '', $value));
		}
		if ($lastComma !== false) {
			$decimals = strlen($value) - $lastComma - 1;
			return $decimals === 3 ? (float) str_replace(',', '', $value) : (float) str_replace(',', '.', $value);
		}
		return (float) $value;
	}

	/**
	 * @param string $text Text
	 * @return float|null
	 */
	private function extractPercent(string $text): ?float
	{
		if (preg_match('/(-?[0-9]+(?:[.,][0-9]+)?)\s*%/u', $text, $matches)) {
			return (float) str_replace(',', '.', (string) $matches[1]);
		}
		return null;
	}

	/**
	 * @param string $label Product cell text
	 * @param string[] $patterns Strip regexes
	 * @return string
	 */
	private function cleanupLabel(string $label, array $patterns): string
	{
		foreach ($patterns as $pattern) {
			$replaced = @preg_replace('/'.$pattern.'/iu', ' ', $label);
			if (is_string($replaced)) {
				$label = $replaced;
			}
		}
		return $this->extractor->normalizeText($label);
	}

	/**
	 * @param string $value Unit cell
	 * @return string
	 */
	private function cleanupUnit(string $value): string
	{
		$value = preg_replace('/^[0-9\s.,]+/u', '', trim($value));
		$value = is_string($value) ? trim($value, " .\t\n\r\0\x0B") : '';
		return $value;
	}

	/**
	 * @param string $value Quantity cell
	 * @return string
	 */
	private function extractUnitFromQuantity(string $value): string
	{
		if (preg_match('/[0-9]+(?:[.,][0-9]+)?\s*([^0-9\s]+(?:\s+[^0-9\s]+)*)/u', $value, $matches)) {
			return trim((string) $matches[1], " .\t\n\r\0\x0B");
		}
		return '';
	}

	/**
	 * Supplier profiles for the currently known recurring vendors.
	 *
	 * Regex strings intentionally omit delimiters; the parser adds /.../iu.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getProfiles(): array
	{
		return array(
			array(
				'id' => 'dsc',
				'domains' => array('dsc.hu'),
				'canonical_sender' => 'noreply.dsc@dsc.hu',
				'subject_hints' => array('sikeres rendel'),
				'supplier_reference_regexes' => array('Rendel[eé]ssz[aá]m\s*:\s*([A-Z0-9._\/-]+)'),
				'order_date_regexes' => array('Rendel[eé]s d[aá]tuma\s*:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})'),
				'table_header_patterns' => array('Term[eé]k', 'Nett[oó] egys[eé]g[aá]r', '[ÁA]fa', 'Mennyis[eé]g'),
				'columns' => array('product' => '^Term[eé]k$', 'unit_price' => 'Nett[oó] egys[eé]g[aá]r', 'vat' => '^[ÁA]fa$', 'qty' => 'Mennyis[eé]g'),
				'product_ref_is_product_cell' => true,
				'number_format' => 'comma_thousands',
				'currency' => 'HUF',
			),
			array(
				'id' => 'delton',
				'domains' => array('delton.hu'),
				'canonical_sender' => 'rendel@delton.hu',
				'subject_hints' => array('rendelés visszaigazolása', 'rendeles visszaigazolasa'),
				'supplier_reference_regexes' => array('Megrendel[eé]s sz[aá]ma\s*:\s*([0-9]+\/[0-9]+)'),
				'order_date_regexes' => array('Id[oő]pontja\s*:\s*([0-9]{4}\.[0-9]{2}\.[0-9]{2}\.\s*[0-9]{2}:[0-9]{2}:[0-9]{2})'),
				'table_header_patterns' => array('mennyis[eé]g', 'term[eé]kn[eé]v', 'egys[eé]g[aá]r', '[oö]ssz[aá]r'),
				'fixed_columns' => array('qty' => 0, 'unit' => 1, 'product' => 2, 'unit_price' => 3),
				'default_vat' => 27.0,
				'number_format' => 'auto',
				'currency' => 'HUF',
			),
			array(
				'id' => 'daniella',
				'domains' => array('daniella.hu'),
				'canonical_sender' => 'no-reply@daniella.hu',
				'subject_hints' => array('daniella.hu'),
				'supplier_reference_regexes' => array('Rendel[eé]s\s+#?([0-9]+\/ORD\/[0-9]{4}\/[0-9]{2}\/[0-9]{2})'),
				'order_date_regexes' => array('Megrendel[eé]s D[aá]tuma\s*([0-9]{4}-[0-9]{2}-[0-9]{2})'),
				'table_header_patterns' => array('Term[eé]k', 'Rendelt Mennyis[eé]g', '[ÁA]r.*brutt[oó].*nett[oó]', '[ÉE]rt[eé]k'),
				'columns' => array('product' => '^Term[eé]k$', 'qty' => 'Rendelt Mennyis[eé]g', 'unit_price' => '^[ÁA]r'),
				'manufacturer_ref_regex' => 'Gy[aá]rt[oó]i azonos[ií]t[oó]\s*:\s*([^|\s]+)',
				'unit_price_amount_index' => 1,
				'vat_source' => 'unit_price',
				'number_format' => 'us',
				'label_strip_patterns' => array('Gy[aá]rt[oó]\s*:[^|]+\|\s*Gy[aá]rt[oó]i azonos[ií]t[oó]\s*:\s*\S+'),
				'currency' => 'HUF',
			),
			array(
				'id' => 'powerbizt',
				'domains' => array('powerbizt.hu'),
				'canonical_sender' => 'uzlet@powerbizt.hu',
				'subject_hints' => array('rendelés visszaigazolás', 'rendeles visszaigazolas'),
				'supplier_reference_regexes' => array('Foglal[aá]si sz[aá]mok\s*:\s*([A-Z]{1,5}-[0-9]+)'),
				'order_date_regexes' => array(),
				'table_header_patterns' => array('Term[eé]k', '[ÁA]r.*[oö]ssz', 'Db\.?', 'K[eé]szlet'),
				'columns' => array('product' => '^Term[eé]k$', 'qty' => 'Db\.?'),
				'product_ref_regex' => 'Term[eé]kk[oó]d\s*:\s*([A-Z0-9._\/-]+)',
				'unit_price_source' => 'product',
				'unit_price_regex' => 'Egys[eé]g[aá]r\s*:\s*([0-9][0-9\s.,]*)\s*Ft',
				'default_vat' => 27.0,
				'number_format' => 'auto',
				'label_strip_patterns' => array('Term[eé]kk[oó]d\s*:\s*[A-Z0-9._\/-]+', 'Egys[eé]g[aá]r\s*:\s*[0-9][0-9\s.,]*\s*Ft'),
				'currency' => 'HUF',
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function emptyResult(): array
	{
		return array(
			'supplier_reference' => '',
			'original_sender_email' => '',
			'order_date' => null,
			'delivery_date' => null,
			'currency' => '',
			'lines' => array(),
		);
	}
}
