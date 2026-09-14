<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

/**
 * Conservative fallback parser. Supplier-specific parsers are tried before
 * the generic extraction rules.
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
		// Keep the action hook simple for now: supplier-specific parsers are
		// delegated from this fallback parser until a dedicated registry exists.
		dol_include_once('/email2order/class/parser/emileemail2orderparser.class.php');
		if (class_exists('EmileEmail2OrderParser')) {
			$emileParser = new EmileEmail2OrderParser();
			if ($emileParser->supports($message)) {
				$this->activeParserName = $emileParser->getName();
				return $emileParser->parse($message);
			}
		}

		$this->activeParserName = 'generic';
		$subject = (string) ($message['subject'] ?? '');
		$body = (string) ($message['body'] ?? '');
		$header = (string) ($message['header'] ?? '');

		return array(
			'supplier_reference' => $this->extractSupplierReference($subject."\n".$body),
			'original_sender_email' => $this->extractOriginalSender($body."\n".$header),
			'delivery_date' => null,
			'currency' => '',
			'lines' => array(),
		);
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
				// Avoid treating ordinary words as references.
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
