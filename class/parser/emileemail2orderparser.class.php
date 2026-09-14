<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

/**
 * Parser for E-mile order-confirmation emails.
 *
 * E-mile sends a structured XLSX attachment named rendeles-*.xlsx. We prefer
 * that attachment over scraping the HTML body because it contains exact
 * product references, quantities, discounted net unit prices and VAT.
 */
class EmileEmail2OrderParser implements Email2OrderParserInterface
{
	/** @inheritdoc */
	public function supports(array $message): bool
	{
		$text = strtolower(
			(string) ($message['from'] ?? '')."\n".
			(string) ($message['subject'] ?? '')."\n".
			(string) ($message['body'] ?? '')."\n".
			(string) ($message['header'] ?? '')
		);

		$hasSupplierIdentity = strpos($text, 'milekft@mile-kft.hu') !== false
			|| strpos($text, 'www.e-mile.hu') !== false
			|| strpos($text, 'az e-mile csapata') !== false;

		if (!$hasSupplierIdentity) {
			return false;
		}

		return $this->findOrderXlsx((array) ($message['attachments'] ?? array())) !== null
			|| preg_match('/(?:^|\s)(?:fwd:\s*)?megrendel[eé]s\s*-/iu', (string) ($message['subject'] ?? '')) === 1;
	}

	/** @inheritdoc */
	public function getName(): string
	{
		return 'emile';
	}

	/** @inheritdoc */
	public function parse(array $message): array
	{
		$subject = (string) ($message['subject'] ?? '');
		$body = (string) ($message['body'] ?? '');
		$attachments = (array) ($message['attachments'] ?? array());

		$lines = array();
		$currency = '';
		$xlsx = $this->findOrderXlsx($attachments);
		if ($xlsx !== null) {
			$parsedSheet = $this->parseOrderXlsx($xlsx);
			$lines = $parsedSheet['lines'];
			$currency = $parsedSheet['currency'];
		}

		return array(
			'supplier_reference' => $this->extractSupplierReference($subject, $body),
			'original_sender_email' => 'milekft@mile-kft.hu',
			'delivery_date' => null,
			'currency' => $currency,
			'lines' => $lines,
		);
	}

	/**
	 * @param string $subject Message subject
	 * @param string $body Message body
	 * @return string
	 */
	private function extractSupplierReference(string $subject, string $body): string
	{
		$text = html_entity_decode(strip_tags(str_ireplace(array('<br>', '<br/>', '<br />'), "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (preg_match('/Rendel[eé]ssz[aá]m\s*:\s*([A-Z0-9][A-Z0-9\/._-]+)/iu', $text, $matches)) {
			return trim((string) $matches[1]);
		}

		if (preg_match('/(?:^|\s)(?:Fwd:\s*)?Megrendel[eé]s\s*-\s*([A-Z0-9]+\/[A-Z0-9]+)/iu', $subject, $matches)) {
			return trim((string) $matches[1]);
		}

		return '';
	}

	/**
	 * Find the structured E-mile XLSX attachment.
	 * Supports both native PHP IMAP (filename => raw content) and Webklex
	 * attachment objects.
	 *
	 * @param array<mixed> $attachments Attachments
	 * @return string|null Raw XLSX bytes
	 */
	private function findOrderXlsx(array $attachments): ?string
	{
		foreach ($attachments as $key => $attachment) {
			$filename = is_string($key) ? $key : '';
			$content = null;

			if (is_string($attachment)) {
				$content = $attachment;
			} elseif (is_object($attachment)) {
				if (method_exists($attachment, 'getName')) {
					$filename = (string) $attachment->getName();
				} elseif (method_exists($attachment, 'getFilename')) {
					$filename = (string) $attachment->getFilename();
				}

				if (method_exists($attachment, 'getContent')) {
					$content = $attachment->getContent();
				}
			}

			if (!is_string($content) || $content === '') {
				continue;
			}
			if (preg_match('/^rendeles-.*\.xlsx$/iu', basename($filename))) {
				return $content;
			}
		}

		return null;
	}

	/**
	 * Parse the first worksheet of the E-mile XLSX attachment.
	 *
	 * Expected columns are identified from the header row, not fixed indexes.
	 *
	 * @param string $content Raw XLSX bytes
	 * @return array{lines:array<int,array<string,mixed>>,currency:string}
	 */
	private function parseOrderXlsx(string $content): array
	{
		$result = array('lines' => array(), 'currency' => '');
		if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
			return $result;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'email2order_xlsx_');
		if ($tmp === false) {
			return $result;
		}

		try {
			if (file_put_contents($tmp, $content) === false) {
				return $result;
			}

			$zip = new ZipArchive();
			if ($zip->open($tmp) !== true) {
				return $result;
			}

			$sharedStrings = $this->readSharedStrings($zip);
			$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
			$zip->close();
			if (!is_string($sheetXml) || $sheetXml === '') {
				return $result;
			}

			$rows = $this->readWorksheetRows($sheetXml, $sharedStrings);
			if (count($rows) < 2) {
				return $result;
			}

			$headers = array();
			foreach ($rows[0] as $column => $value) {
				$headers[$this->normalizeHeader((string) $value)] = $column;
			}

			$required = array('cikkszam', 'megnevezes', 'mennyiseg', 'kedvezmenyes_netto', 'afakulcs');
			foreach ($required as $header) {
				if (!isset($headers[$header])) {
					return $result;
				}
			}

			foreach (array_slice($rows, 1) as $row) {
				$supplierRef = trim((string) ($row[$headers['cikkszam']] ?? ''));
				$qty = (float) ($row[$headers['mennyiseg']] ?? 0);
				$unitPrice = (float) ($row[$headers['kedvezmenyes_netto']] ?? 0);
				if ($supplierRef === '' || $qty <= 0 || $unitPrice < 0) {
					continue;
				}

				$vatRaw = (float) ($row[$headers['afakulcs']] ?? 0);
				$vatRate = $vatRaw > 1 ? ($vatRaw - 1) * 100 : $vatRaw * 100;
				$currencyValue = isset($headers['penznem']) ? trim((string) ($row[$headers['penznem']] ?? '')) : '';
				if ($result['currency'] === '' && $currencyValue !== '') {
					$result['currency'] = $this->normalizeCurrency($currencyValue);
				}

				$result['lines'][] = array(
					'supplier_product_ref' => $supplierRef,
					'label' => trim((string) ($row[$headers['megnevezes']] ?? '')),
					'qty' => $qty,
					'unit_price' => $unitPrice,
					'vat_rate' => round($vatRate, 3),
					'unit' => isset($headers['mennyisegi_egyseg']) ? trim((string) ($row[$headers['mennyisegi_egyseg']] ?? '')) : '',
					'barcode' => isset($headers['vonalkod']) ? trim((string) ($row[$headers['vonalkod']] ?? '')) : '',
				);
			}
		} finally {
			@unlink($tmp);
		}

		return $result;
	}

	/**
	 * @param ZipArchive $zip XLSX archive
	 * @return array<int,string>
	 */
	private function readSharedStrings(ZipArchive $zip): array
	{
		$xml = $zip->getFromName('xl/sharedStrings.xml');
		if (!is_string($xml) || $xml === '') {
			return array();
		}

		$root = @simplexml_load_string($xml);
		if ($root === false) {
			return array();
		}

		$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$strings = array();
		foreach ($root->children($ns)->si as $si) {
			$children = $si->children($ns);
			$text = isset($children->t) ? (string) $children->t : '';
			if ($text === '') {
				foreach ($children->r as $run) {
					$text .= (string) $run->children($ns)->t;
				}
			}
			$strings[] = $text;
		}
		return $strings;
	}

	/**
	 * @param string $xml Worksheet XML
	 * @param array<int,string> $sharedStrings Shared-string table
	 * @return array<int,array<string,mixed>> Rows indexed by Excel column letter
	 */
	private function readWorksheetRows(string $xml, array $sharedStrings): array
	{
		$root = @simplexml_load_string($xml);
		if ($root === false) {
			return array();
		}

		$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rows = array();
		$sheetChildren = $root->children($ns);
		foreach ($sheetChildren->sheetData->row as $row) {
			$values = array();
			foreach ($row->children($ns)->c as $cell) {
				$ref = (string) $cell['r'];
				$column = preg_replace('/\d+/', '', $ref);
				if (!is_string($column) || $column === '') {
					continue;
				}
				$type = (string) $cell['t'];
				$cellChildren = $cell->children($ns);
				$value = isset($cellChildren->v) ? (string) $cellChildren->v : '';
				if ($type === 's' && $value !== '') {
					$value = $sharedStrings[(int) $value] ?? '';
				} elseif ($type === 'inlineStr' && isset($cellChildren->is)) {
					$value = (string) $cellChildren->is->children($ns)->t;
				} elseif ($value !== '' && is_numeric($value)) {
					$value = (float) $value;
				}
				$values[$column] = $value;
			}
			$rows[] = $values;
		}
		return $rows;
	}

	/**
	 * @param string $header Header label
	 * @return string
	 */
	private function normalizeHeader(string $header): string
	{
		$map = array(
			'Cikkszám' => 'cikkszam',
			'Megnevezés' => 'megnevezes',
			'Mennyiség' => 'mennyiseg',
			'Mennyiségi egység' => 'mennyisegi_egyseg',
			'Kedvezményes nettó' => 'kedvezmenyes_netto',
			'Pénznem' => 'penznem',
			'Áfakulcs' => 'afakulcs',
			'Vonalkód' => 'vonalkod',
		);
		return $map[trim($header)] ?? strtolower(trim($header));
	}

	/**
	 * @param string $currency Supplier currency label
	 * @return string ISO currency code
	 */
	private function normalizeCurrency(string $currency): string
	{
		$currency = strtoupper(trim($currency));
		return in_array($currency, array('FT', 'HUF'), true) ? 'HUF' : $currency;
	}
}
