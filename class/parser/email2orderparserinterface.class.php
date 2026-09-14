<?php
/* Copyright (C) 2026 dolibarr-email2order contributors */

/**
 * Normalized parser contract for supplier confirmation emails.
 */
interface Email2OrderParserInterface
{
	/**
	 * @param array<string,mixed> $message Normalized email input
	 * @return bool
	 */
	public function supports(array $message): bool;

	/**
	 * Parse an email into normalized supplier-order data.
	 *
	 * Expected output keys:
	 * - supplier_reference: string
	 * - original_sender_email: string
	 * - delivery_date: int|null
	 * - currency: string
	 * - lines: array<int,array<string,mixed>>
	 *
	 * @param array<string,mixed> $message Normalized email input
	 * @return array<string,mixed>
	 */
	public function parse(array $message): array;

	/**
	 * @return string Parser identifier
	 */
	public function getName(): string;
}
