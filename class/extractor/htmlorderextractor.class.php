<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

/**
 * Generic HTML helper used by supplier profiles.
 *
 * It deliberately knows nothing about a concrete supplier. Its job is only
 * to turn an HTML email into normalized text and table/cell structures that
 * profile-driven parsers can inspect deterministically.
 */
class HtmlOrderExtractor
{
	/**
	 * Convert HTML to readable normalized text.
	 *
	 * @param string $html HTML body or Email Collector plain body
	 * @return string
	 */
	public function toText(string $html): string
	{
		if ($html === '') {
			return '';
		}

		$prepared = preg_replace('/<(?:br|\/p|\/div|\/tr|\/li)\b[^>]*>/iu', "$0\n", $html);
		if (!is_string($prepared)) {
			$prepared = $html;
		}
		$text = html_entity_decode(strip_tags($prepared), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return $this->normalizeText($text, true);
	}

	/**
	 * Extract every HTML table into rows/cells.
	 *
	 * Dolibarr Email Collector intentionally passes a plain-text `messagetext`
	 * to action hooks even when an HTML MIME part exists. During collection the
	 * decoded HTML part is still available in the global $htmlmsg used by the
	 * core collector. Prefer it transparently when the hook body itself does not
	 * contain HTML.
	 *
	 * DOMDocument is used when available. A small regex based fallback keeps the
	 * extractor functional on PHP installations without ext-dom.
	 *
	 * @param string $html HTML body or Email Collector plain body
	 * @return array<int,array{rows:array<int,array{cells:array<int,array{text:string,html:string}>,has_th:bool}>}>
	 */
	public function extractTables(string $html): array
	{
		global $htmlmsg;

		if (!$this->looksLikeHtmlWithTables($html) && isset($htmlmsg) && is_string($htmlmsg) && $this->looksLikeHtmlWithTables($htmlmsg)) {
			$html = $htmlmsg;
		}
		if (!$this->looksLikeHtmlWithTables($html)) {
			return array();
		}

		$document = $this->loadDocument($html);
		if ($document !== null) {
			return $this->extractTablesWithDom($document);
		}

		return $this->extractTablesWithoutDom($html);
	}

	/**
	 * @param DOMDocument $document Parsed document
	 * @return array<int,array{rows:array<int,array{cells:array<int,array{text:string,html:string}>,has_th:bool}>}>
	 */
	private function extractTablesWithDom(DOMDocument $document): array
	{
		$xpath = new DOMXPath($document);
		$tableNodes = $xpath->query('//table');
		if ($tableNodes === false) {
			return array();
		}

		$tables = array();
		foreach ($tableNodes as $tableNode) {
			if (!($tableNode instanceof DOMElement)) {
				continue;
			}

			$rowNodes = $xpath->query('./tr|./thead/tr|./tbody/tr|./tfoot/tr', $tableNode);
			if ($rowNodes === false) {
				continue;
			}

			$rows = array();
			foreach ($rowNodes as $rowNode) {
				if (!($rowNode instanceof DOMElement)) {
					continue;
				}

				$cellNodes = $xpath->query('./th|./td', $rowNode);
				if ($cellNodes === false || $cellNodes->length === 0) {
					continue;
				}

				$cells = array();
				$hasTh = false;
				foreach ($cellNodes as $cellNode) {
					if (!($cellNode instanceof DOMElement)) {
						continue;
					}
					if (strtolower($cellNode->tagName) === 'th') {
						$hasTh = true;
					}
					$cells[] = array(
						'text' => $this->normalizeText((string) $cellNode->textContent),
						'html' => $this->innerHtml($cellNode),
					);
				}

				if (!empty($cells)) {
					$rows[] = array('cells' => $cells, 'has_th' => $hasTh);
				}
			}

			if (!empty($rows)) {
				$tables[] = array('rows' => $rows);
			}
		}

		return $tables;
	}

	/**
	 * Extension-free HTML table fallback.
	 *
	 * This is intentionally conservative: it only recognizes explicit table,
	 * tr, td and th markup. Supplier-specific interpretation remains in profiles.
	 *
	 * @param string $html HTML body
	 * @return array<int,array{rows:array<int,array{cells:array<int,array{text:string,html:string}>,has_th:bool}>}>
	 */
	private function extractTablesWithoutDom(string $html): array
	{
		$tables = array();
		$tableMatches = array();
		if (preg_match_all('/<table\b[^>]*>(.*?)<\/table\s*>/isu', $html, $tableMatches) !== false) {
			foreach ((array) ($tableMatches[1] ?? array()) as $tableHtml) {
				$rowMatches = array();
				if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr\s*>/isu', (string) $tableHtml, $rowMatches) === false) {
					continue;
				}

				$rows = array();
				foreach ((array) ($rowMatches[1] ?? array()) as $rowHtml) {
					$cellMatches = array();
					if (preg_match_all('/<(td|th)\b[^>]*>(.*?)<\/\1\s*>/isu', (string) $rowHtml, $cellMatches, PREG_SET_ORDER) === false) {
						continue;
					}

					$cells = array();
					$hasTh = false;
					foreach ($cellMatches as $cellMatch) {
						$tag = strtolower((string) ($cellMatch[1] ?? ''));
						$cellHtml = (string) ($cellMatch[2] ?? '');
						if ($tag === 'th') {
							$hasTh = true;
						}
						$cells[] = array(
							'text' => $this->htmlFragmentToText($cellHtml),
							'html' => $cellHtml,
						);
					}
					if (!empty($cells)) {
						$rows[] = array('cells' => $cells, 'has_th' => $hasTh);
					}
				}

				if (!empty($rows)) {
					$tables[] = array('rows' => $rows);
				}
			}
		}

		return $tables;
	}

	/**
	 * Find a table/header row containing all supplied regular expressions.
	 *
	 * @param array<int,array{rows:array<int,array{cells:array<int,array{text:string,html:string}>,has_th:bool}>}> $tables Tables
	 * @param string[] $patterns Regexes without delimiters
	 * @return array{table:array<string,mixed>,header_index:int}|null
	 */
	public function findTableByHeaderPatterns(array $tables, array $patterns): ?array
	{
		foreach ($tables as $table) {
			foreach ((array) ($table['rows'] ?? array()) as $rowIndex => $row) {
				$headerText = implode(' | ', array_map(static function ($cell) {
					return (string) ($cell['text'] ?? '');
				}, (array) ($row['cells'] ?? array())));

				$allMatch = true;
				foreach ($patterns as $pattern) {
					if (@preg_match('/'.$pattern.'/iu', $headerText) !== 1) {
						$allMatch = false;
						break;
					}
				}
				if ($allMatch) {
					return array('table' => $table, 'header_index' => (int) $rowIndex);
				}
			}
		}

		return null;
	}

	/**
	 * Resolve a column index from a header row.
	 *
	 * @param array<string,mixed> $headerRow Header row
	 * @param string $pattern Regex without delimiters
	 * @return int -1 when not found
	 */
	public function findColumn(array $headerRow, string $pattern): int
	{
		foreach ((array) ($headerRow['cells'] ?? array()) as $index => $cell) {
			if (@preg_match('/'.$pattern.'/iu', (string) ($cell['text'] ?? '')) === 1) {
				return (int) $index;
			}
		}
		return -1;
	}

	/**
	 * Normalize whitespace while optionally preserving line breaks.
	 *
	 * @param string $text Text
	 * @param bool $preserveNewlines Preserve logical lines
	 * @return string
	 */
	public function normalizeText(string $text, bool $preserveNewlines = false): string
	{
		$text = str_replace(array("\xC2\xA0", "\u{00A0}"), ' ', $text);
		$text = str_replace(array("\r\n", "\r"), "\n", $text);

		if ($preserveNewlines) {
			$lines = array();
			foreach (explode("\n", $text) as $line) {
				$line = preg_replace('/[\t ]+/u', ' ', trim($line));
				if (is_string($line) && $line !== '') {
					$lines[] = $line;
				}
			}
			return implode("\n", $lines);
		}

		$normalized = preg_replace('/\s+/u', ' ', trim($text));
		return is_string($normalized) ? $normalized : trim($text);
	}

	/**
	 * @param string $html HTML
	 * @return bool
	 */
	private function looksLikeHtmlWithTables(string $html): bool
	{
		return $html !== '' && preg_match('/<table\b/i', $html) === 1;
	}

	/**
	 * @param string $html HTML fragment
	 * @return string
	 */
	private function htmlFragmentToText(string $html): string
	{
		$prepared = preg_replace('/<(?:br|\/p|\/div|\/li)\b[^>]*>/iu', "$0\n", $html);
		if (!is_string($prepared)) {
			$prepared = $html;
		}
		return $this->normalizeText(html_entity_decode(strip_tags($prepared), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * @param string $html HTML body
	 * @return DOMDocument|null
	 */
	private function loadDocument(string $html): ?DOMDocument
	{
		if ($html === '' || !class_exists('DOMDocument')) {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument('1.0', 'UTF-8');
		$loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $loaded ? $document : null;
	}

	/**
	 * @param DOMElement $node Element
	 * @return string
	 */
	private function innerHtml(DOMElement $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child) {
			$part = $node->ownerDocument ? $node->ownerDocument->saveHTML($child) : false;
			if (is_string($part)) {
				$html .= $part;
			}
		}
		return $html;
	}
}
