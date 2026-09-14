-- Copyright (C) 2026 dolibarr-email2order contributors
-- GPL-3.0-or-later

ALTER TABLE llx_email2order_import ADD UNIQUE INDEX uk_email2order_import_hash (entity, message_hash);
ALTER TABLE llx_email2order_import ADD INDEX idx_email2order_import_supplier (fk_soc);
ALTER TABLE llx_email2order_import ADD INDEX idx_email2order_import_order (fk_supplier_order);
