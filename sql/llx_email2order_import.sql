-- Copyright (C) 2026 dolibarr-email2order contributors
-- GPL-3.0-or-later

CREATE TABLE llx_email2order_import(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	message_id VARCHAR(255),
	message_hash VARCHAR(64) NOT NULL,
	sender VARCHAR(255),
	subject VARCHAR(512),
	fk_soc INTEGER NOT NULL,
	fk_supplier_order INTEGER NOT NULL,
	supplier_ref VARCHAR(255),
	parser VARCHAR(128),
	date_email DATETIME,
	date_creation DATETIME NOT NULL,
	fk_user_create INTEGER,
	tms TIMESTAMP
) ENGINE=innodb;
