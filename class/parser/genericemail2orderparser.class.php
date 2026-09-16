<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

/**
 * Parser facade with a conservative generic fallback.
 *
 * The Email Collector hook currently instantiates this class directly. It
 * delegates structured messages to the XLSX/profile parsers and only uses the
 * generic extraction rules when no structured parser claims the message.
 */
class GenericEmail2OrderParser implements Email2OrderParserInterface
{
	/** @var string Effective parser identifier */
	private $activeParserName = 'generic';

	/** @inheritdoc */
	public function supports(array $message): bool
	{
		return true;
	}

	/** @inheritdoc */
	public function getName(): string
	{
		return $this->activeParserName;
	}

	/** @inheritdoc */
	public function parse(array $message): array
	{
		dol_include_once('/email2order/class/parser/emileemail2orderparser.class.php');
		if (class_exists('EmileEmail2OrderParser')) {
			$parser = new EmileEmail2OrderParser();
			if ($parser->supports($message)) {
				$this->activeParserName = $parser->getName();
				return $parser->parse($message);
			}
		}

		dol_include_once('/email2order/class/parser/profiledhtmlemail2orderparser.class.php');
		if (class_exists('ProfiledHtmlEmail2OrderParser')) {
			$parser = new ProfiledHtmlEmail2OrderParser();
			if ($parser->supports($message)) {
				$result = $parser->parse($message);
				$this->activeParserName = $parser->getName();
				if ($this->activeParserName === 'html-profile:powerbizt') {
					$result = $this->correctPowerUnitPrices($result, $message);
				}
				return $result;
			}
		}

		$this->activeParserName = 'generic';
		$subject = (string) ($message['subject'] ?? '');
		$body = (string) ($message['body'] ?? '');
		$header = (string) ($message['header'] ?? '');

		return array(
			'supplier_reference' => $this->extractSupplierReference($subject."\n".$body),
			'original_sender_email' => $this->extractOriginalSender($body."\n".$header),
			'order_date' => null,
			'delivery_date' => null,
			'currency' => '',
			'lines' => array(),
		);
	}

	/**
	 * POWER displays rounded whole-HUF unit prices, while its line total is the
	 * authoritative net amount. Reconstruct the effective net unit price from
	 * line total / quantity so the imported supplier-order total stays exact.
	 *
	 * Dolibarr may pass a flattened plain body to the hook while keeping the
	 * original decoded HTML MIME part in $GLOBALS['htmlmsg']. The profile parser
	 * can therefore succeed from HTML even when the plain body no longer has a
	 * parseable row layout. Check both representations here as well.
	 *
	 * @param array<string,mixed> $result Parsed normalized order
	 * @param array<string,mixed> $message Normalized email
	 * @return array<string,mixed>
	 */
	private function correctPowerUnitPrices(array $result, array $message): array
	{
		$sources = array((string) ($message['body'] ?? ''));

		if (isset($GLOBALS['htmlmsg']) && is_string($GLOBALS['htmlmsg']) && trim($GLOBALS['htmlmsg']) !== '') {
			$htmlText = $GLOBALS['htmlmsg'];
			$htmlText = preg_replace('/<br\s*\/?>/iu', ' ', $htmlText);
			$htmlText = preg_replace('/<\/(?:td|th|tr|div|p|table|li)>/iu', ' ', (string) $htmlText);
			$htmlText = html_entity_decode(strip_tags((string) $htmlText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$sources[] = $htmlText;
		}

		$pattern = '/(?:K[eé]szlet|Rakt[aá]ron)\s+(.+?)\s+Term[eé]kk[oó]d\s*:\s*([A-Z0-9._\/-]+)\s+Egys[eé]g[aá]r\s*:\s*([0-9][0-9\s.,]*)\s*Ft\s+([0-9][0-9\s.,]*)\s*Ft\s+([0-9]+(?:[.,][0-9]+)?)\s+(?=Rakt[aá]ron\b)/iu';
		$effectivePrices = array();

		foreach ($sources as $source) {
			$source = str_replace("\xC2\xA0", ' ', (string) $source);
			$flat = preg_replace('/\s+/u', ' ', $source);
			if (!is_string($flat) || $flat === '') {
				continue;
			}

			$matches = array();
			if (!preg_match_all($pattern, $flat, $matches, PREG_SET_ORDER)) {
				continue;
			}

			foreach ($matches as $match) {
				$ref = trim((string) ($match[2] ?? ''));
				$lineTotal = $this->parsePowerMoney((string) ($match[4] ?? ''));
				$qty = $this->parsePowerQuantity((string) ($match[5] ?? ''));
				if ($ref !== '' && $lineTotal >= 0 && $qty > 0) {
					$effectivePrices[strtolower($ref)] = round($lineTotal / $qty, 6);
				}
			}
		}

		if (empty($effectivePrices)) {
			return $result;
		}

		$lines = (array) ($result['lines'] ?? array());
		foreach ($lines as $index => $line) {
			$ref = strtolower(trim((string) ($line['supplier_product_ref'] ?? '')));
			if ($ref !== '' && isset($effectivePrices[$ref])) {
				$lines[$index]['unit_price'] = $effectivePrices[$ref];
			}
		}
		$result['lines'] = $lines;

		return $result;
	}

	/** @param string $value @return float */
	private function parsePowerMoney(string $value): float
	{
		$value = preg_replace('/[^0-9,\.\-]/u', '', str_replace(array("\xC2\xA0", ' '), '', $value));
		if (!is_string($value) || $value === '') {
			return -1.0;
		}
		if (strpos($value, ',') !== false && strpos($value, '.') === false) {
			$decimals = strlen($value) - strrpos($value, ',') - 1;
			return $decimals === 3 ? (float) str_replace(',', '', $value) : (float) str_replace(',', '.', $value);
		}
		return (float) str_replace(',', '', $value);
	}

	/** @param string $value @return float */
	private function parsePowerQuantity(string $value): float
	{
		if (preg_match('/-?[0-9]+(?:[.,][0-9]+)?/u', $value, $matches)) {
			return (float) str_replace(',', '.', (string) $matches[0]);
		}
		return 0.0;
	}

	/**
	 * @param string $text Message text
	 * @return string
	 */
	private function extractSupplierReference(string $text): string
	{
		$patterns = array(
			'/\b(?:order\s*(?:confirmation)?|confirmation|visszaigazol(?:ás|as)|rendel(?:és|es))\b(?:\s*(?:no\.?|nr\.?|number|sz(?:á|a)m|#))?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9._\/\-]{2,})/iu',
		);

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $matches)) {
				$candidate = trim((string) $matches[1]);
				if (preg_match('/\d/', $candidate)) {
					return $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Recover the original sender from common manually-forwarded headers.
	 *
	 * @param string $text Message body/header
	 * @return string
	 */
	private function extractOriginalSender(string $text): string
	{
		$pattern = '/(?:^|\R)(?:From|Feladó|Felado|Von)\s*:\s*(?:[^\r\n<]*<)?([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})(?:>)?/imu';
		if (preg_match($pattern, $text, $matches)) {
			return strtolower(trim((string) $matches[1]));
		}

		return '';
	}
}
