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
	 * The reader deliberately does not require ext-zip or SimpleXML. It uses
	 * ZipArchive when available, otherwise the standard PharData ZIP reader,
	 * and parses the small XLSX XML parts without optional XML extensions.
	 *
	 * @param string $content Raw XLSX bytes
	 * @return array{lines:array<int,array<string,mixed>>,currency:string}
	 */
	private function parseOrderXlsx(string $content): array
	{
		$result = array('lines' => array(), 'currency' => '');
		$tmpBase = tempnam(sys_get_temp_dir(), 'email2order_xlsx_');
		if ($tmpBase === false) {
			return $result;
		}
		$tmp = $tmpBase.'.xlsx';
		@unlink($tmp);
		if (!@rename($tmpBase, $tmp)) {
			@unlink($tmpBase);
			return $result;
		}

		try {
			if (file_put_contents($tmp, $content) === false) {
				return $result;
			}

			$entries = $this->readXlsxEntries($tmp);
			$sheetXml = $entries['sheet'];
			if ($sheetXml === '') {
				return $result;
			}

			$sharedStrings = $this->readSharedStringsXml($entries['shared_strings']);
			$rows = $this->readWorksheetRowsXml($sheetXml, $sharedStrings);
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
	 * @param string $filename Temporary XLSX file
	 * @return array{shared_strings:string,sheet:string}
	 */
	private function readXlsxEntries(string $filename): array
	{
		$result = array('shared_strings' => '', 'sheet' => '');

		if (class_exists('ZipArchive')) {
			$zip = new ZipArchive();
			if ($zip->open($filename) === true) {
				$shared = $zip->getFromName('xl/sharedStrings.xml');
				$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
				$zip->close();
				$result['shared_strings'] = is_string($shared) ? $shared : '';
				$result['sheet'] = is_string($sheet) ? $sheet : '';
				return $result;
			}
		}

		if (class_exists('PharData')) {
			try {
				$archive = new PharData($filename);
				if (isset($archive['xl/sharedStrings.xml'])) {
					$result['shared_strings'] = (string) $archive['xl/sharedStrings.xml']->getContent();
				}
				if (isset($archive['xl/worksheets/sheet1.xml'])) {
					$result['sheet'] = (string) $archive['xl/worksheets/sheet1.xml']->getContent();
				}
			} catch (Throwable $e) {
				// Invalid/unsupported archive. Caller will return an empty result.
			}
		}

		return $result;
	}

	/**
	 * @param string $xml sharedStrings.xml
	 * @return array<int,string>
	 */
	private function readSharedStringsXml(string $xml): array
	{
		if ($xml === '') {
			return array();
		}

		$strings = array();
		if (!preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si', $xml, $items)) {
			return $strings;
		}

		foreach ($items[1] as $item) {
			$text = '';
			if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si', (string) $item, $texts)) {
				foreach ($texts[1] as $value) {
					$text .= $this->decodeXmlText((string) $value);
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
	private function readWorksheetRowsXml(string $xml, array $sharedStrings): array
	{
		$rows = array();
		if (!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si', $xml, $rowMatches)) {
			return $rows;
		}

		foreach ($rowMatches[1] as $rowXml) {
			$values = array();
			if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/si', (string) $rowXml, $cellMatches, PREG_SET_ORDER)) {
				foreach ($cellMatches as $cellMatch) {
					$attributes = (string) $cellMatch[1];
					$cellXml = (string) $cellMatch[2];

					if (!preg_match('/\br="([A-Z]+)[0-9]+"/i', $attributes, $refMatch)) {
						continue;
					}
					$column = strtoupper((string) $refMatch[1]);

					$type = '';
					if (preg_match('/\bt="([^"]+)"/i', $attributes, $typeMatch)) {
						$type = (string) $typeMatch[1];
					}

					$value = '';
					if ($type === 'inlineStr') {
						if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si', $cellXml, $inlineTexts)) {
							foreach ($inlineTexts[1] as $inlineText) {
								$value .= $this->decodeXmlText((string) $inlineText);
							}
						}
					} elseif (preg_match('/<v\b[^>]*>(.*?)<\/v>/si', $cellXml, $valueMatch)) {
						$value = $this->decodeXmlText((string) $valueMatch[1]);
					}

					if ($type === 's' && $value !== '') {
						$value = $sharedStrings[(int) $value] ?? '';
					} elseif ($value !== '' && is_numeric($value)) {
						$value = (float) $value;
					}

					$values[$column] = $value;
				}
			}
			$rows[] = $values;
		}

		return $rows;
	}

	/**
	 * @param string $value XML character data
	 * @return string
	 */
	private function decodeXmlText(string $value): string
	{
		return html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
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
