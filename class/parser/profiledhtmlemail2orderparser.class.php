<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

dol_include_once('/email2order/class/extractor/htmlorderextractor.class.php');

/**
 * Profile-driven parser for structured supplier order confirmations.
 *
 * HTML table handling is shared. Supplier differences are declarative where
 * possible. Conservative profile-specific plain-text fallbacks are available
 * for forwarded messages where the mail client flattens the original HTML.
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
		$text = $this->extractor->toText($body);
		$combinedText = $subject."\n".$text;

		// Prefer the original decoded HTML MIME part if Dolibarr Email Collector
		// happens to expose it globally while passing only plain messagetext to the hook.
		$htmlBody = $body;
		if (isset($GLOBALS['htmlmsg']) && is_string($GLOBALS['htmlmsg']) && trim($GLOBALS['htmlmsg']) !== '') {
			$htmlBody = $GLOBALS['htmlmsg'];
		}

		$tables = $this->extractor->extractTables($htmlBody);
		$tableMatch = $this->extractor->findTableByHeaderPatterns($tables, (array) ($profile['table_header_patterns'] ?? array()));
		$lines = $tableMatch !== null ? $this->parseHtmlLines($tableMatch, $profile) : array();

		// Forwarding clients may flatten the supplier's HTML table. Fall back only
		// to deterministic profile-specific text rules; never guess arbitrary rows.
		if (empty($lines)) {
			$lines = $this->parsePlainTextLines($combinedText, $profile);
		}

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
	private function parseHtmlLines(array $tableMatch, array $profile): array
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
			$cells = (array) ($rows[$rowIndex]['cells'] ?? array());
			if (empty($cells)) {
				continue;
			}

			$productText = $this->cellText($cells, (int) ($columns['product'] ?? -1));
			$qtyText = $this->cellText($cells, (int) ($columns['qty'] ?? -1));
			$unitText = $this->cellText($cells, (int) ($columns['unit'] ?? -1));
			$priceText = $this->cellText($cells, (int) ($columns['unit_price'] ?? -1));
			$vatText = $this->cellText($cells, (int) ($columns['vat'] ?? -1));

			$line = $this->buildLine($productText, $qtyText, $unitText, $priceText, $vatText, $profile);
			if ($line !== null) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Conservative text fallbacks used only when no HTML table yielded lines.
	 *
	 * @param string $text Normalized forwarded message text
	 * @param array<string,mixed> $profile Profile
	 * @return array<int,array<string,mixed>>
	 */
	private function parsePlainTextLines(string $text, array $profile): array
	{
		$id = (string) ($profile['id'] ?? '');
		$flat = preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text));
		$flat = is_string($flat) ? trim($flat) : trim($text);

		if ($id === 'dsc') {
			// DSC order rows are: product-code, net unit, VAT, gross unit,
			// quantity+unit, net total, gross total. The totals anchor the pattern
			// so summary rows cannot be mistaken for products.
			$pattern = '/\b([A-Z0-9][A-Z0-9._\/-]{4,})\s+([0-9][0-9\s.,]*)\s*Ft\s+([0-9]+(?:[.,][0-9]+)?)\s*%\s+([0-9][0-9\s.,]*)\s*Ft\s+([0-9]+(?:[.,][0-9]+)?)\s*([^0-9\s]+)\s+([0-9][0-9\s.,]*)\s*Ft\s+([0-9][0-9\s.,]*)\s*Ft\b/u';
			$matches = array();
			if (preg_match_all($pattern, $flat, $matches, PREG_SET_ORDER)) {
				$lines = array();
				foreach ($matches as $match) {
					$ref = trim((string) ($match[1] ?? ''));
					$price = $this->parseMoney((string) ($match[2] ?? ''), 'comma_thousands');
					$vat = (float) str_replace(',', '.', (string) ($match[3] ?? '0'));
					$qty = $this->parseQuantity((string) ($match[5] ?? ''));
					$unit = trim((string) ($match[6] ?? ''));
					if ($ref !== '' && $price >= 0 && $qty > 0) {
						$lines[] = array(
							'supplier_product_ref' => $ref,
							'manufacturer_ref' => '',
							'label' => $ref,
							'qty' => $qty,
							'unit' => $unit,
							'unit_price' => $price,
							'vat_rate' => $vat,
						);
					}
				}
				return $lines;
			}
		}

		if ($id === 'delton') {
			// Delton forwarded confirmations flatten the original table as:
			//   Mennyiség | M.e. | Terméknév | Egységár | Összár
			// Current Delton pages render line prices as "9 437.-" without an Ft
			// suffix, while older/alternate output may still use Ft/HUF. Restrict
			// parsing to the table area before the summary and require two explicit
			// price terminators so numeric model references stay part of the label.
			$headerMatch = array();
			if (preg_match('/mennyis[eé]g\s+.*?term[eé]kn[eé]v\s+egys[eé]g[aá]r\s+[oö]ssz[aá]r/iu', $flat, $headerMatch, PREG_OFFSET_CAPTURE)) {
				$headerText = (string) ($headerMatch[0][0] ?? '');
				$headerOffset = (int) ($headerMatch[0][1] ?? -1);
				if ($headerText !== '' && $headerOffset >= 0) {
					$tableText = substr($flat, $headerOffset + strlen($headerText));
					$summaryOffset = preg_match('/\b[oö]sszesen\s*:/iu', $tableText, $summaryMatch, PREG_OFFSET_CAPTURE)
						? (int) ($summaryMatch[0][1] ?? -1)
						: -1;
					if ($summaryOffset >= 0) {
						$tableText = substr($tableText, 0, $summaryOffset);
					}

					$amountPattern = '((?:[0-9]{1,3}(?:\s[0-9]{3})+|[0-9]+)(?:[.,][0-9]{1,2})?)';
					$rowPattern = '/(?:^|\s)([0-9]+(?:[.,][0-9]+)?)\s+(\p{L}+(?:\.)?)\s+(.+?)\s+'
						.$amountPattern.'\s*(?:\.-|Ft|HUF)\s+'
						.$amountPattern.'\s*(?:\.-|Ft|HUF)(?=\s|$)/iu';
					$matches = array();
					if (preg_match_all($rowPattern, $tableText, $matches, PREG_SET_ORDER)) {
						$lines = array();
						foreach ($matches as $match) {
							$qty = $this->parseQuantity((string) ($match[1] ?? ''));
							$unit = $this->cleanupUnit((string) ($match[2] ?? ''));
							$label = $this->extractor->normalizeText((string) ($match[3] ?? ''));
							$unitPrice = $this->parseMoney((string) ($match[4] ?? ''), (string) ($profile['number_format'] ?? 'auto'));
							$lineTotal = $this->parseMoney((string) ($match[5] ?? ''), (string) ($profile['number_format'] ?? 'auto'));

							if ($qty <= 0 || $unit === '' || $label === '' || $unitPrice < 0 || $lineTotal < 0) {
								continue;
							}

							$lines[] = array(
								'supplier_product_ref' => '',
								'manufacturer_ref' => '',
								'label' => $label,
								'qty' => $qty,
								'unit' => $unit,
								'unit_price' => $unitPrice,
								'vat_rate' => isset($profile['default_vat']) ? (float) $profile['default_vat'] : 0.0,
							);
						}
						return $lines;
					}
				}
			}
		}

		if ($id === 'daniella') {
			// Daniella flattened rows keep a strong product-cell marker:
			//   <label> Gyártó: ... | Gyártói azonosító: <manufacturer ref>
			// followed by quantity+unit, gross unit price, net unit price, VAT and
			// the line value. Keep the manufacturer reference distinct from the
			// supplier-product reference, matching the structured HTML parser.
			$markerPattern = '/Gy[aá]rt[oó]i azonos[ií]t[oó]\s*:\s*([A-Z0-9._\/-]+)/iu';
			$markerRows = array();
			if (preg_match_all($markerPattern, $flat, $markerRows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
				$lines = array();
				$markerCount = count($markerRows);
				for ($index = 0; $index < $markerCount; $index++) {
					$marker = $markerRows[$index];
					$fullMarker = (string) ($marker[0][0] ?? '');
					$markerOffset = (int) ($marker[0][1] ?? -1);
					$manufacturerRef = trim((string) ($marker[1][0] ?? ''));
					if ($fullMarker === '' || $markerOffset < 0 || $manufacturerRef === '') {
						continue;
					}

					$markerEnd = $markerOffset + strlen($fullMarker);
					$nextMarkerOffset = $index + 1 < $markerCount
						? (int) ($markerRows[$index + 1][0][1] ?? strlen($flat))
						: strlen($flat);
					$rowTail = substr($flat, $markerEnd, max(0, $nextMarkerOffset - $markerEnd));

					$qtyMatch = array();
					if (!preg_match('/^\s+([0-9]+(?:[.,][0-9]+)?)\s+(\p{L}+(?:\.)?)/u', $rowTail, $qtyMatch)) {
						continue;
					}
					$qty = $this->parseQuantity((string) ($qtyMatch[1] ?? ''));
					$unit = $this->cleanupUnit((string) ($qtyMatch[2] ?? ''));
					$afterQuantity = substr($rowTail, strlen((string) ($qtyMatch[0] ?? '')));

					$moneyMatches = array();
					if (!preg_match_all('/([0-9][0-9\s.,]*)\s*(?:Ft|HUF)\b/iu', $afterQuantity, $moneyMatches) || count((array) ($moneyMatches[1] ?? array())) < 2) {
						continue;
					}
					$netPrice = $this->parseMoney((string) $moneyMatches[1][1], 'us');

					$vatMatch = array();
					if (!preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*%/u', $afterQuantity, $vatMatch)) {
						continue;
					}
					$vat = (float) str_replace(',', '.', (string) ($vatMatch[1] ?? '0'));

					// Locate the product label immediately before the current Gyártó:
					// marker. The previous line value or the Érték column header gives a
					// deterministic left boundary in the flattened table.
					$beforeMarker = substr($flat, 0, $markerOffset);
					$manufacturerMatches = array();
					if (!preg_match_all('/Gy[aá]rt[oó]\s*:\s*/iu', $beforeMarker, $manufacturerMatches, PREG_OFFSET_CAPTURE)) {
						continue;
					}
					$manufacturerTokens = (array) ($manufacturerMatches[0] ?? array());
					$lastManufacturerToken = end($manufacturerTokens);
					if (!is_array($lastManufacturerToken)) {
						continue;
					}
					$productEnd = (int) ($lastManufacturerToken[1] ?? 0);
					$beforeProduct = substr($flat, 0, $productEnd);
					$labelStart = 0;
					$boundaryEnds = array();

					$valueMatches = array();
					if (preg_match_all('/[0-9][0-9\s.,]*\s*(?:Ft|HUF)\b/iu', $beforeProduct, $valueMatches, PREG_OFFSET_CAPTURE)) {
						$valueTokens = (array) ($valueMatches[0] ?? array());
						$lastValueToken = end($valueTokens);
						if (is_array($lastValueToken)) {
							$boundaryEnds[] = (int) ($lastValueToken[1] ?? 0) + strlen((string) ($lastValueToken[0] ?? ''));
						}
					}

					$headerMatches = array();
					if (preg_match_all('/[ÉE]rt[eé]k\b/iu', $beforeProduct, $headerMatches, PREG_OFFSET_CAPTURE)) {
						$headerTokens = (array) ($headerMatches[0] ?? array());
						$lastHeaderToken = end($headerTokens);
						if (is_array($lastHeaderToken)) {
							$boundaryEnds[] = (int) ($lastHeaderToken[1] ?? 0) + strlen((string) ($lastHeaderToken[0] ?? ''));
						}
					}
					if (!empty($boundaryEnds)) {
						$labelStart = max($boundaryEnds);
					}

					$label = $this->extractor->normalizeText(substr($flat, $labelStart, max(0, $productEnd - $labelStart)));
					$cleanLabel = preg_replace('/^\s*(?:[ÁA]r\s*)?\(\s*brutt[oó]\s*\/\s*nett[oó]\s*\)\s*/iu', '', $label);
					if (is_string($cleanLabel)) {
						$label = $this->extractor->normalizeText($cleanLabel);
					}
					if ($qty > 0 && $netPrice >= 0 && $label !== '') {
						$lines[] = array(
							'supplier_product_ref' => '',
							'manufacturer_ref' => $manufacturerRef,
							'label' => $label,
							'qty' => $qty,
							'unit' => $unit,
							'unit_price' => $netPrice,
							'vat_rate' => $vat,
						);
					}
				}
				return $lines;
			}
		}

		if ($id === 'powerbizt') {
			// POWER flattened rows retain stable semantic anchors:
			//   <label> Termékkód: <ref> Egységár: <net> Ft <line total> Ft <qty> Raktáron
			// The first row is preceded by the Készlet column heading, later rows by
			// the previous row's Raktáron status. This prevents matching the summary.
			$pattern = '/(?:K[eé]szlet|Rakt[aá]ron)\s+(.+?)\s+Term[eé]kk[oó]d\s*:\s*([A-Z0-9._\/-]+)\s+Egys[eé]g[aá]r\s*:\s*([0-9][0-9\s.,]*)\s*Ft\s+([0-9][0-9\s.,]*)\s*Ft\s+([0-9]+(?:[.,][0-9]+)?)\s+(?=Rakt[aá]ron\b)/iu';
			$matches = array();
			if (preg_match_all($pattern, $flat, $matches, PREG_SET_ORDER)) {
				$lines = array();
				foreach ($matches as $match) {
					$label = trim((string) ($match[1] ?? ''));
					$ref = trim((string) ($match[2] ?? ''));
					$price = $this->parseMoney((string) ($match[3] ?? ''), 'auto');
					$qty = $this->parseQuantity((string) ($match[5] ?? ''));
					if ($ref !== '' && $price >= 0 && $qty > 0) {
						$lines[] = array(
							'supplier_product_ref' => $ref,
							'manufacturer_ref' => '',
							'label' => $this->extractor->normalizeText($label),
							'qty' => $qty,
							'unit' => 'db',
							'unit_price' => $price,
							'vat_rate' => 27.0,
						);
					}
				}
				return $lines;
			}
		}

		return array();
	}

	/**
	 * Build one normalized line from profile-mapped cells.
	 *
	 * @param string $productText Product cell
	 * @param string $qtyText Quantity cell
	 * @param string $unitText Unit cell
	 * @param string $priceText Unit-price cell
	 * @param string $vatText VAT cell
	 * @param array<string,mixed> $profile Profile
	 * @return array<string,mixed>|null
	 */
	private function buildLine(string $productText, string $qtyText, string $unitText, string $priceText, string $vatText, array $profile): ?array
	{
		$qty = $this->parseQuantity($qtyText);
		if ($qty <= 0 || $productText === '') {
			return null;
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

		$priceSource = (($profile['unit_price_source'] ?? '') === 'product') ? $productText : $priceText;
		$unitPrice = $this->extractUnitPrice($priceSource, $profile);
		if ($unitPrice < 0) {
			return null;
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

		return array(
			'supplier_product_ref' => $supplierProductRef,
			'manufacturer_ref' => $manufacturerRef,
			'label' => $this->cleanupLabel($productText, (array) ($profile['label_strip_patterns'] ?? array())),
			'qty' => $qty,
			'unit' => $unitText !== '' ? $this->cleanupUnit($unitText) : $this->extractUnitFromQuantity($qtyText),
			'unit_price' => $unitPrice,
			'vat_rate' => $vatRate,
		);
	}

	/** @param array<int,array{text:string,html:string}> $cells @param int $index @return string */
	private function cellText(array $cells, int $index): string
	{
		return $index >= 0 && isset($cells[$index]) ? trim((string) ($cells[$index]['text'] ?? '')) : '';
	}

	/** @param string $text @param array<string,mixed> $profile @return float */
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

	/** @param string $text @param string[] $patterns @return string */
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

	/** @param string $text @param string[] $patterns @return int|null */
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

	/** @param array<string,mixed> $message @param array<string,mixed> $profile @return string */
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

	/** @param string $value @return float */
	private function parseQuantity(string $value): float
	{
		if (preg_match('/-?[0-9]+(?:[.,][0-9]+)?/u', str_replace("\xC2\xA0", ' ', $value), $matches)) {
			return (float) str_replace(',', '.', (string) $matches[0]);
		}
		return 0.0;
	}

	/** @param string $value @param string $format @return float */
	private function parseMoney(string $value, string $format): float
	{
		$value = trim(str_replace(array("\xC2\xA0", ' '), '', $value));
		$value = preg_replace('/[^0-9,\.\-]/u', '', $value);
		if (!is_string($value) || $value === '') {
			return -1.0;
		}
		if ($format === 'comma_thousands' || $format === 'us') {
			return (float) str_replace(',', '', $value);
		}
		if ($format === 'decimal_comma') {
			return (float) str_replace(',', '.', str_replace('.', '', $value));
		}
		$lastComma = strrpos($value, ',');
		$lastDot = strrpos($value, '.');
		if ($lastComma !== false && $lastDot !== false) {
			return $lastDot > $lastComma ? (float) str_replace(',', '', $value) : (float) str_replace(',', '.', str_replace('.', '', $value));
		}
		if ($lastComma !== false) {
			$decimals = strlen($value) - $lastComma - 1;
			return $decimals === 3 ? (float) str_replace(',', '', $value) : (float) str_replace(',', '.', $value);
		}
		return (float) $value;
	}

	/** @param string $text @return float|null */
	private function extractPercent(string $text): ?float
	{
		if (preg_match('/(-?[0-9]+(?:[.,][0-9]+)?)\s*%/u', $text, $matches)) {
			return (float) str_replace(',', '.', (string) $matches[1]);
		}
		return null;
	}

	/** @param string $label @param string[] $patterns @return string */
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

	/** @param string $value @return string */
	private function cleanupUnit(string $value): string
	{
		$value = preg_replace('/^[0-9\s.,]+/u', '', trim($value));
		return is_string($value) ? trim($value, " .\t\n\r\0\x0B") : '';
	}

	/** @param string $value @return string */
	private function extractUnitFromQuantity(string $value): string
	{
		if (preg_match('/[0-9]+(?:[.,][0-9]+)?\s*([^0-9\s]+(?:\s+[^0-9\s]+)*)/u', $value, $matches)) {
			return trim((string) $matches[1], " .\t\n\r\0\x0B");
		}
		return '';
	}

	/** @return array<int,array<string,mixed>> */
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
				'supplier_reference_regexes' => array(
					'Foglal[aá]si sz[aá]mok\s*:\s*([A-Z]{1,5}-[0-9]+)',
					'\b(BI-[0-9]+)\b',
				),
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

	/** @return array<string,mixed> */
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
