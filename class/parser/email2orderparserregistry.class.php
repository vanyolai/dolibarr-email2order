<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

dol_include_once('/email2order/class/parser/genericemail2orderparser.class.php');
dol_include_once('/email2order/class/parser/emileemail2orderparser.class.php');
dol_include_once('/email2order/class/parser/profiledhtmlemail2orderparser.class.php');

/**
 * Central parser registry.
 *
 * Parsers are ordered from most structured/specific to most generic.
 */
class Email2OrderParserRegistry
{
	/**
	 * @param array<string,mixed> $message Normalized email
	 * @return Email2OrderParserInterface
	 */
	public function select(array $message): Email2OrderParserInterface
	{
		$parsers = array(
			new EmileEmail2OrderParser(),
			new ProfiledHtmlEmail2OrderParser(),
			new GenericEmail2OrderParser(),
		);

		foreach ($parsers as $parser) {
			if ($parser->supports($message)) {
				return $parser;
			}
		}

		// Generic parser currently supports every message, so this is only a
		// defensive fallback.
		return new GenericEmail2OrderParser();
	}
}
