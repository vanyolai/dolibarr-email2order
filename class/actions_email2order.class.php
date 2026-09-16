<?php
/* Copyright (C) 2026 dolibarr-email2order contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

dol_include_once('/email2order/class/parser/email2orderparserinterface.class.php');
dol_include_once('/email2order/class/parser/genericemail2orderparser.class.php');

/**
 * Hooks for Email2Order.
 */
class ActionsEmail2Order extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add Email2Order as an operation in the Email Collector card.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     EmailCollector object
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsEmailCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$langs->load('email2order@email2order');
		$this->results = array(
			'hooksupplierorderimport' => 'Email2OrderCollectorAction',
		);

		return 0;
	}

	/**
	 * Process one email selected by Dolibarr's Email Collector.
	 *
	 * @param array<string,mixed> $parameters Email Collector payload
	 * @param CommonObject        $object     EmailCollector object
	 * @param string              $action     Operation type
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function doCollectImapOneCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		if ($action !== 'hooksupplierorderimport') {
			return 0;
		}

		$langs->load('email2order@email2order');

		$message = array(
			'from' => (string) ($parameters['from'] ?? ''),
			'subject' => (string) ($parameters['subject'] ?? ''),
			'body' => (string) ($parameters['messagetext'] ?? ''),
			'header' => (string) ($parameters['header'] ?? ''),
			'overview' => $parameters['overview'] ?? null,
			'attachments' => $parameters['attachments'] ?? array(),
		);

		$parser = $this->selectParser($message);
		$parsed = $parser->parse($message);

		$supplierId = $this->getIntegerActionParameter((string) ($parameters['actionparam'] ?? ''), 'supplier_id');
		if ($supplierId <= 0) {
			$supplierId = (int) ($parameters['thirdpartyid'] ?? 0);
		}
		if (!$this->isSupplier($supplierId)) {
			$supplierId = 0;
		}

		$senderCandidates = array();
		if (!empty($parsed['original_sender_email'])) {
			$senderCandidates[] = (string) $parsed['original_sender_email'];
		}
		$currentSender = $this->extractEmailAddress((string) $message['from']);
		if ($currentSender !== '') {
			$senderCandidates[] = $currentSender;
		}
		$effectiveSender = !empty($parsed['original_sender_email']) ? (string) $parsed['original_sender_email'] : $currentSender;

		// First prefer an exact supplier/company or supplier/contact email match.
		if ($supplierId <= 0) {
			foreach (array_unique($senderCandidates) as $candidateEmail) {
				$supplierId = $this->findSupplierByEmail($candidateEmail);
				if ($supplierId > 0) {
					break;
				}
			}
		}

		// Technical webshop senders (noreply@, orders@, etc.) often differ from
		// the business email stored on the Dolibarr supplier. For a structured,
		// positively identified parser only, fall back to the sender domain. The
		// domain must resolve to exactly one supplier and public mail domains are
		// never accepted for this fallback.
		if ($supplierId <= 0 && $this->allowsSupplierDomainFallback($parser)) {
			$supplierId = $this->findSupplierByEmailDomain($effectiveSender);
		}

		if ($supplierId <= 0) {
			$this->appendDiagnostic($object, $message, $parser, $parsed, 0, $currentSender, $effectiveSender, null);
			return $this->fail($langs->trans('Email2OrderSupplierNotFound'));
		}

		$messageId = $this->extractMessageId((string) $message['header']);
		$messageHash = $this->buildMessageHash($effectiveSender, (string) $message['subject'], (string) $message['body']);

		$existingOrderId = $this->findExistingImport($messageHash);
		$this->appendDiagnostic($object, $message, $parser, $parsed, $supplierId, $currentSender, $effectiveSender, $existingOrderId);
		if ($existingOrderId > 0) {
			$this->resprints = $langs->trans('Email2OrderAlreadyImported', $existingOrderId);
			dol_syslog('Email2Order: email already imported as supplier order id='.$existingOrderId, LOG_INFO);
			return 0;
		}
		if ($existingOrderId < 0) {
			return $this->fail($langs->trans('Email2OrderImportTableError').' '.$this->db->lasterror());
		}

		$emailDate = $this->extractEmailTimestamp($message['overview']);
		$order = new CommandeFournisseur($this->db);
		$order->socid = $supplierId;
		$order->fourn_id = $supplierId;
		$order->ref = '(PROV)';
		$order->ref_supplier = (string) ($parsed['supplier_reference'] ?? '');
		$order->date = $emailDate;
		$order->date_commande = $emailDate;
		$order->source = 0;
		$order->note_private = $this->buildPrivateNote($messageId, $effectiveSender, (string) $message['subject'], (string) $message['body'], $parser->getName());

		if (!empty($parsed['delivery_date'])) {
			$order->delivery_date = (int) $parsed['delivery_date'];
		}
		if (!empty($parsed['currency']) && isModEnabled('multicurrency')) {
			$order->multicurrency_code = (string) $parsed['currency'];
		}

		$orderId = $order->create($user);
		if ($orderId <= 0) {
			return $this->fail($langs->trans('Email2OrderCreateFailed').' '.$order->error.' '.implode(' ', (array) $order->errors));
		}

		// Ensure ref is populated before using the supplier-order document directory.
		if (empty($order->ref) || $order->ref === '(PROV)') {
			$order->fetch($orderId);
		}

		$result = $this->addParsedLines($order, $supplierId, (array) ($parsed['lines'] ?? array()));
		if ($result < 0) {
			return $result;
		}

		$result = $this->saveAttachments($order, $message['attachments']);
		if ($result < 0) {
			return $result;
		}

		$result = $this->recordImport(
			$messageHash,
			$messageId,
			$effectiveSender,
			(string) $message['subject'],
			$supplierId,
			$orderId,
			(string) ($parsed['supplier_reference'] ?? ''),
			$parser->getName(),
			$emailDate,
			(int) $user->id
		);
		if ($result < 0) {
			return $this->fail($langs->trans('Email2OrderImportTableError').' '.$this->db->lasterror());
		}

		$this->resprints = $langs->trans('Email2OrderCreated', $order->ref);
		dol_syslog('Email2Order: created draft supplier order id='.$orderId.' ref='.$order->ref.' from email hash='.$messageHash, LOG_INFO);

		return 0;
	}

	/**
	 * Append a concise Email2Order parse summary to Email Collector debug output.
	 * This intentionally contains metadata only, not the full message body.
	 *
	 * @param CommonObject $collector EmailCollector object
	 * @param array<string,mixed> $message Normalized email
	 * @param Email2OrderParserInterface $parser Selected parser
	 * @param array<string,mixed> $parsed Parsed result
	 * @param int $supplierId Matched supplier id, 0 if unresolved
	 * @param string $currentSender Envelope/current From email
	 * @param string $effectiveSender Effective/original sender used by Email2Order
	 * @param int|null $existingOrderId Existing imported order id, 0 when new, -1 on lookup error, null before lookup
	 * @return void
	 */
	private function appendDiagnostic(&$collector, array $message, Email2OrderParserInterface $parser, array $parsed, int $supplierId, string $currentSender, string $effectiveSender, ?int $existingOrderId): void
	{
		if (!is_object($collector) || !property_exists($collector, 'debuginfo')) {
			return;
		}

		$supplierLabel = 'NOT RESOLVED';
		if ($supplierId > 0) {
			$supplier = new Societe($this->db);
			if ($supplier->fetch($supplierId) > 0) {
				$name = trim((string) (!empty($supplier->name) ? $supplier->name : $supplier->nom));
				$supplierLabel = ($name !== '' ? $name.' ' : '').'(#'.$supplierId.')';
			} else {
				$supplierLabel = '#'.$supplierId;
			}
		}

		$attachmentNames = $this->getAttachmentNames($message['attachments'] ?? array());
		$lines = (array) ($parsed['lines'] ?? array());
		$currency = trim((string) ($parsed['currency'] ?? ''));
		$supplierReference = trim((string) ($parsed['supplier_reference'] ?? ''));

		$debug = '<br><strong>Email2Order diagnostic</strong>';
		$debug .= '<br>Parser: '.dol_escape_htmltag($parser->getName());
		$debug .= '<br>Current sender: '.dol_escape_htmltag($currentSender !== '' ? $currentSender : '(none)');
		$debug .= '<br>Effective/original sender: '.dol_escape_htmltag($effectiveSender !== '' ? $effectiveSender : '(none)');
		$debug .= '<br>Supplier: '.dol_escape_htmltag($supplierLabel);
		$debug .= '<br>Supplier reference: '.dol_escape_htmltag($supplierReference !== '' ? $supplierReference : '(none)');
		$debug .= '<br>Currency: '.dol_escape_htmltag($currency !== '' ? $currency : '(not parsed)');
		$debug .= '<br>Attachments: '.dol_escape_htmltag(!empty($attachmentNames) ? implode(', ', $attachmentNames) : '(none)');
		$debug .= '<br>Parsed lines: '.count($lines);

		if ($existingOrderId !== null) {
			if ($existingOrderId > 0) {
				$debug .= '<br>Deduplication: already imported as supplier order #'.((int) $existingOrderId);
			} elseif ($existingOrderId === 0) {
				$debug .= '<br>Deduplication: new message';
			} else {
				$debug .= '<br>Deduplication: lookup error';
			}
		}

		foreach ($lines as $index => $line) {
			$qty = isset($line['qty']) ? (float) $line['qty'] : 0.0;
			$supplierRef = trim((string) ($line['supplier_product_ref'] ?? ''));
			$unit = trim((string) ($line['unit'] ?? ''));
			$unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : 0.0;
			$vatRate = isset($line['vat_rate']) ? (float) $line['vat_rate'] : 0.0;
			$label = trim((string) ($line['label'] ?? ''));
			$matchText = 'not checked';

			if ($supplierId > 0 && $supplierRef !== '' && $qty > 0) {
				$productMatch = $this->findSupplierProduct($supplierId, $supplierRef, $qty);
				if (!empty($productMatch)) {
					$matchText = 'product #'.((int) ($productMatch['fk_product'] ?? 0)).', supplier price #'.((int) ($productMatch['rowid'] ?? 0));
				} else {
					$matchText = 'no Dolibarr supplier-product match; free line';
				}
			} elseif ($supplierRef === '') {
				$matchText = 'no supplier product reference; free line';
			}

			$lineText = '#'.($index + 1)
				.' ref='.(($supplierRef !== '') ? $supplierRef : '(none)')
				.' | qty='.$qty.(($unit !== '') ? ' '.$unit : '')
				.' | net unit='.$unitPrice.(($currency !== '') ? ' '.$currency : '')
				.' | VAT='.$vatRate.'%'
				.' | '.$matchText;
			if ($label !== '') {
				$lineText .= ' | '.dol_trunc($label, 120);
			}
			$debug .= '<br>&nbsp;&nbsp;'.dol_escape_htmltag($lineText);
		}

		$collector->debuginfo .= $debug;
		dol_syslog('Email2Order diagnostic: parser='.$parser->getName().' sender='.$effectiveSender.' supplier='.$supplierId.' supplier_ref='.$supplierReference.' lines='.count($lines).' attachments='.implode(',', $attachmentNames), LOG_INFO);
	}

	/**
	 * Return attachment names for diagnostics without touching attachment content.
	 *
	 * @param mixed $attachments Email Collector attachment collection
	 * @return string[]
	 */
	private function getAttachmentNames($attachments): array
	{
		$names = array();
		if (!is_array($attachments) && !($attachments instanceof Traversable)) {
			return $names;
		}

		foreach ($attachments as $key => $attachment) {
			$filename = is_string($key) ? $key : '';
			if (is_object($attachment)) {
				if (method_exists($attachment, 'getName')) {
					$filename = (string) $attachment->getName();
				} elseif (method_exists($attachment, 'getFilename')) {
					$filename = (string) $attachment->getFilename();
				}
			}
			$filename = trim($filename);
			if ($filename !== '') {
				$names[] = $filename;
			}
		}

		return $names;
	}

	/**
	 * @param array<string,mixed> $message Normalized email
	 * @return Email2OrderParserInterface
	 */
	private function selectParser(array $message): Email2OrderParserInterface
	{
		// Supplier-specific parsers will be inserted before this fallback.
		return new GenericEmail2OrderParser();
	}

	/**
	 * @param int $supplierId Third party id
	 * @return bool
	 */
	private function isSupplier(int $supplierId): bool
	{
		if ($supplierId <= 0) {
			return false;
		}

		$supplier = new Societe($this->db);
		$result = $supplier->fetch($supplierId);
		return $result > 0 && !empty($supplier->fournisseur);
	}

	/**
	 * Resolve an exact email address against supplier company/contact emails.
	 * Ambiguous addresses intentionally return no result.
	 *
	 * @param string $email Email address
	 * @return int Supplier id, 0 when not found/ambiguous
	 */
	private function findSupplierByEmail(string $email): int
	{
		global $conf;

		$email = strtolower(trim($email));
		if ($email === '') {
			return 0;
		}

		$sql = 'SELECT DISTINCT s.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'societe AS s';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON sp.fk_soc = s.rowid';
		$sql .= ' WHERE s.entity = '.((int) $conf->entity);
		$sql .= ' AND s.fournisseur > 0';
		$sql .= " AND (LOWER(s.email) = '".$this->db->escape($email)."' OR LOWER(sp.email) = '".$this->db->escape($email)."')";

		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Only structured parsers may use domain-based supplier resolution. The
	 * generic parser is deliberately excluded because it has not positively
	 * identified a known supplier message format.
	 *
	 * @param Email2OrderParserInterface $parser Selected parser
	 * @return bool
	 */
	private function allowsSupplierDomainFallback(Email2OrderParserInterface $parser): bool
	{
		$name = $parser->getName();
		return $name === 'emile' || strpos($name, 'html-profile:') === 0;
	}

	/**
	 * Resolve a structured supplier message by sender domain when the exact
	 * technical sender address is not stored in Dolibarr. The domain may match
	 * either a supplier/company or supplier/contact email domain, or the supplier
	 * website host. It is accepted only when the combined match is unique.
	 *
	 * Public mailbox providers are explicitly excluded because a unique match in
	 * today's data would not make such a domain a reliable supplier identity.
	 *
	 * @param string $email Effective/original sender email
	 * @return int Supplier id, 0 when unsafe, not found or ambiguous
	 */
	private function findSupplierByEmailDomain(string $email): int
	{
		global $conf;

		$email = strtolower(trim($email));
		$at = strrpos($email, '@');
		if ($at === false) {
			return 0;
		}

		$domain = substr($email, $at + 1);
		if ($domain === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1) {
			return 0;
		}

		$publicDomains = array(
			'gmail.com',
			'googlemail.com',
			'outlook.com',
			'hotmail.com',
			'live.com',
			'yahoo.com',
			'icloud.com',
			'me.com',
			'mac.com',
			'proton.me',
			'protonmail.com',
			'aol.com',
			'gmx.com',
			'gmx.de',
			'freemail.hu',
			'citromail.hu',
			'indamail.hu',
		);
		if (in_array($domain, $publicDomains, true)) {
			dol_syslog('Email2Order: supplier domain fallback blocked for public domain '.$domain, LOG_INFO);
			return 0;
		}

		$suffix = '%@'.$domain;
		$escapedSuffix = $this->db->escape($suffix);
		$urlCandidates = array(
			$domain,
			'www.'.$domain,
			'http://'.$domain,
			'https://'.$domain,
			'http://www.'.$domain,
			'https://www.'.$domain,
		);
		$urlClauses = array();
		foreach ($urlCandidates as $candidate) {
			$escapedCandidate = $this->db->escape($candidate);
			$urlClauses[] = "LOWER(TRIM(s.url)) = '".$escapedCandidate."'";
			$urlClauses[] = "LOWER(TRIM(s.url)) LIKE '".$escapedCandidate."/%'";
		}

		$sql = 'SELECT DISTINCT s.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'societe AS s';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON sp.fk_soc = s.rowid';
		$sql .= ' WHERE s.entity = '.((int) $conf->entity);
		$sql .= ' AND s.fournisseur > 0';
		$sql .= " AND (LOWER(s.email) LIKE '".$escapedSuffix."' OR LOWER(sp.email) LIKE '".$escapedSuffix."'";
		if (!empty($urlClauses)) {
			$sql .= ' OR '.implode(' OR ', $urlClauses);
		}
		$sql .= ')';

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('Email2Order: supplier domain lookup failed for '.$domain.': '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}

		$count = $this->db->num_rows($resql);
		if ($count !== 1) {
			if ($count > 1) {
				dol_syslog('Email2Order: supplier domain '.$domain.' is ambiguous across '.$count.' suppliers', LOG_WARNING);
			}
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$supplierId = $obj ? (int) $obj->rowid : 0;
		if ($supplierId > 0) {
			dol_syslog('Email2Order: resolved supplier id='.$supplierId.' by unique sender/website domain '.$domain, LOG_INFO);
		}
		return $supplierId;
	}

	/**
	 * @param string $value Free-form From value
	 * @return string
	 */
	private function extractEmailAddress(string $value): string
	{
		if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $value, $matches)) {
			return strtolower(trim((string) $matches[1]));
		}
		return '';
	}

	/**
	 * @param string $header Raw email header
	 * @return string
	 */
	private function extractMessageId(string $header): string
	{
		if (preg_match('/^Message-ID:\s*<?([^>\r\n]+)>?/mi', $header, $matches)) {
			return trim((string) $matches[1]);
		}
		return '';
	}

	/**
	 * A content fingerprint is preferred to the wrapper Message-ID so forwarding
	 * the same supplier confirmation twice remains idempotent.
	 *
	 * @param string $sender Sender email
	 * @param string $subject Subject
	 * @param string $body Body
	 * @return string
	 */
	private function buildMessageHash(string $sender, string $subject, string $body): string
	{
		$normalizedBody = preg_replace('/\s+/u', ' ', trim($body));
		if ($normalizedBody === null) {
			$normalizedBody = trim($body);
		}
		return hash('sha256', strtolower(trim($sender))."\n".trim($subject)."\n".$normalizedBody);
	}

	/**
	 * @param string $messageHash SHA-256 fingerprint
	 * @return int 0 not found, >0 supplier order id, -1 SQL error
	 */
	private function findExistingImport(string $messageHash): int
	{
		global $conf;

		$sql = 'SELECT fk_supplier_order FROM '.MAIN_DB_PREFIX.'email2order_import';
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= " AND message_hash = '".$this->db->escape($messageHash)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->fk_supplier_order : 0;
	}

	/**
	 * @param mixed $overview Email overview from Email Collector
	 * @return int Unix timestamp
	 */
	private function extractEmailTimestamp($overview): int
	{
		$dateValue = '';
		if (is_array($overview) && isset($overview['date'])) {
			$dateValue = (string) $overview['date'];
		} elseif (is_object($overview) && isset($overview->date)) {
			$dateValue = (string) $overview->date;
		}

		$timestamp = $dateValue !== '' ? strtotime($dateValue) : false;
		return $timestamp !== false ? $timestamp : dol_now();
	}

	/**
	 * @param CommandeFournisseur $order Supplier order
	 * @param int $supplierId Supplier id
	 * @param array<int,array<string,mixed>> $lines Parsed lines
	 * @return int
	 */
	private function addParsedLines(CommandeFournisseur $order, int $supplierId, array $lines): int
	{
		foreach ($lines as $line) {
			$qty = isset($line['qty']) ? (float) $line['qty'] : 0.0;
			if ($qty <= 0) {
				continue;
			}

			$supplierRef = trim((string) ($line['supplier_product_ref'] ?? ''));
			$productMatch = $supplierRef !== '' ? $this->findSupplierProduct($supplierId, $supplierRef, $qty) : array();
			$fkProduct = (int) ($productMatch['fk_product'] ?? 0);
			$fkSupplierPrice = (int) ($productMatch['rowid'] ?? 0);
			$unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : (float) ($productMatch['unitprice'] ?? 0.0);
			$vatRate = isset($line['vat_rate']) ? (float) $line['vat_rate'] : (float) ($productMatch['tva_tx'] ?? 0.0);
			$description = trim((string) ($line['label'] ?? ''));
			if ($description === '') {
				$description = $supplierRef !== '' ? 'Supplier item '.$supplierRef : 'Imported supplier item';
			}

			$result = $order->addline(
				$description,
				$unitPrice,
				$qty,
				$vatRate,
				0.0,
				0.0,
				$fkProduct,
				$fkSupplierPrice,
				$supplierRef
			);
			if ($result <= 0) {
				return $this->fail('Email2Order: failed to add supplier-order line: '.$order->error.' '.implode(' ', (array) $order->errors));
			}
		}

		return 0;
	}

	/**
	 * @param int $supplierId Supplier id
	 * @param string $supplierRef Supplier product reference
	 * @param float $qty Requested quantity
	 * @return array<string,mixed>
	 */
	private function findSupplierProduct(int $supplierId, string $supplierRef, float $qty): array
	{
		$sql = 'SELECT rowid, fk_product, unitprice, tva_tx, ref_fourn, quantity';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
		$sql .= ' WHERE fk_soc = '.((int) $supplierId);
		$sql .= " AND ref_fourn = '".$this->db->escape($supplierRef)."'";
		$sql .= ' AND quantity <= '.((float) $qty);
		$sql .= ' ORDER BY quantity DESC, rowid ASC';
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : false;

		if (!$obj) {
			$sql = 'SELECT rowid, fk_product, unitprice, tva_tx, ref_fourn, quantity';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
			$sql .= ' WHERE fk_soc = '.((int) $supplierId);
			$sql .= " AND ref_fourn = '".$this->db->escape($supplierRef)."'";
			$sql .= ' ORDER BY quantity ASC, rowid ASC LIMIT 1';
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : false;
		}

		return $obj ? array(
			'rowid' => (int) $obj->rowid,
			'fk_product' => (int) $obj->fk_product,
			'unitprice' => (float) $obj->unitprice,
			'tva_tx' => (float) $obj->tva_tx,
			'ref_fourn' => (string) $obj->ref_fourn,
		) : array();
	}

	/**
	 * @param CommandeFournisseur $order Supplier order
	 * @param mixed $attachments Email Collector attachment collection
	 * @return int
	 */
	private function saveAttachments(CommandeFournisseur $order, $attachments): int
	{
		global $conf;

		if (empty($attachments)) {
			return 0;
		}

		$destdir = $conf->fournisseur->commande->dir_output.'/'.dol_sanitizeFileName($order->ref);
		if (!dol_is_dir($destdir)) {
			$mkdirResult = dol_mkdir($destdir);
			if ($mkdirResult < 0) {
				return $this->fail('Email2Order: cannot create attachment directory '.$destdir);
			}
		}

		foreach ($attachments as $key => $attachment) {
			$filename = '';
			$content = null;

			if (is_object($attachment)) {
				if (method_exists($attachment, 'getName')) {
					$filename = (string) $attachment->getName();
				} elseif (method_exists($attachment, 'getFilename')) {
					$filename = (string) $attachment->getFilename();
				}
				if (method_exists($attachment, 'getContent')) {
					$content = $attachment->getContent();
				}
			} elseif (is_string($attachment) && is_string($key)) {
				$filename = $key;
				$content = $attachment;
			}

			if ($filename === '' || $content === null) {
				continue;
			}

			$filename = dol_sanitizeFileName($filename);
			if ($filename === '') {
				continue;
			}

			$target = $destdir.'/'.$filename;
			$pathInfo = pathinfo($filename);
			$base = (string) ($pathInfo['filename'] ?? 'attachment');
			$extension = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? '.'.$pathInfo['extension'] : '';
			$counter = 1;
			while (file_exists($target)) {
				$target = $destdir.'/'.$base.'_'.$counter.$extension;
				$counter++;
			}

			if (file_put_contents($target, $content) === false) {
				return $this->fail('Email2Order: failed to save attachment '.$filename);
			}
			dolChmod($target);
			addFileIntoDatabaseIndex($destdir, basename($target));
		}

		return 0;
	}

	/**
	 * @param string $messageHash Fingerprint
	 * @param string $messageId Message-ID
	 * @param string $sender Sender email
	 * @param string $subject Subject
	 * @param int $supplierId Supplier id
	 * @param int $orderId Supplier order id
	 * @param string $supplierRef Supplier confirmation reference
	 * @param string $parser Parser id
	 * @param int $emailDate Email date
	 * @param int $userId Importing Dolibarr user
	 * @return int
	 */
	private function recordImport(string $messageHash, string $messageId, string $sender, string $subject, int $supplierId, int $orderId, string $supplierRef, string $parser, int $emailDate, int $userId): int
	{
		global $conf;

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'email2order_import (';
		$sql .= 'entity, message_id, message_hash, sender, subject, fk_soc, fk_supplier_order, supplier_ref, parser, date_email, date_creation, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $conf->entity).',';
		$sql .= "'".$this->db->escape($messageId)."',";
		$sql .= "'".$this->db->escape($messageHash)."',";
		$sql .= "'".$this->db->escape($sender)."',";
		$sql .= "'".$this->db->escape(substr($subject, 0, 512))."',";
		$sql .= ((int) $supplierId).',';
		$sql .= ((int) $orderId).',';
		$sql .= "'".$this->db->escape(substr($supplierRef, 0, 255))."',";
		$sql .= "'".$this->db->escape(substr($parser, 0, 128))."',";
		$sql .= "'".$this->db->idate($emailDate)."',";
		$sql .= "'".$this->db->idate(dol_now())."',";
		$sql .= ((int) $userId);
		$sql .= ')';

		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * @param string $messageId Message-ID
	 * @param string $sender Sender email
	 * @param string $subject Subject
	 * @param string $body Body
	 * @param string $parser Parser id
	 * @return string Safe HTML note
	 */
	private function buildPrivateNote(string $messageId, string $sender, string $subject, string $body, string $parser): string
	{
		$note = '<strong>Email2Order import</strong><br>';
		$note .= 'Parser: '.dol_escape_htmltag($parser).'<br>';
		if ($messageId !== '') {
			$note .= 'Message-ID: '.dol_escape_htmltag($messageId).'<br>';
		}
		$note .= 'From: '.dol_escape_htmltag($sender).'<br>';
		$note .= 'Subject: '.dol_escape_htmltag($subject).'<br><br>';
		$note .= '<pre>'.dol_escape_htmltag($body).'</pre>';
		return $note;
	}

	/**
	 * @param string $actionParam Operation parameters
	 * @param string $name Parameter name
	 * @return int
	 */
	private function getIntegerActionParameter(string $actionParam, string $name): int
	{
		if (preg_match('/(?:^|[;\r\n])\s*'.preg_quote($name, '/').'\s*=\s*(\d+)\s*(?:$|[;\r\n])/', $actionParam, $matches)) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * @param string $message Error message
	 * @return int Always -1
	 */
	private function fail(string $message): int
	{
		$this->error = $message;
		$this->errors[] = $message;
		$this->resprints = $message;
		dol_syslog($message, LOG_ERR);
		return -1;
	}
}
