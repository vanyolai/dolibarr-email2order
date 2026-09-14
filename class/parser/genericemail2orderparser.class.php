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
