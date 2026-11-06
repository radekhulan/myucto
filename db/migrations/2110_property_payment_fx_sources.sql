SET NAMES utf8mb4;

ALTER TABLE unit_payment_sources
  ADD COLUMN IF NOT EXISTS currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CZK' AFTER amount_cents,
  ADD COLUMN IF NOT EXISTS native_amount_minor DECIMAL(30,0) NULL AFTER currency_code,
  ADD COLUMN IF NOT EXISTS consumed_native_minor DECIMAL(30,0) NULL AFTER native_amount_minor,
  ADD COLUMN IF NOT EXISTS fx_rate DECIMAL(18,6) NULL AFTER consumed_native_minor;

ALTER TABLE unit_payment_operations
  ADD COLUMN IF NOT EXISTS source_amount_cents DECIMAL(30,0) NULL AFTER amount_cents,
  ADD COLUMN IF NOT EXISTS source_native_minor DECIMAL(30,0) NULL AFTER source_amount_cents;

UPDATE unit_payment_operations
   SET source_amount_cents = amount_cents
 WHERE source_id IS NOT NULL
   AND kind IN ('allocation', 'refund')
   AND source_amount_cents IS NULL;

ALTER TABLE unit_payment_sources DROP CONSTRAINT IF EXISTS chk_unit_payment_source_amount;
ALTER TABLE unit_payment_sources ADD CONSTRAINT chk_unit_payment_source_amount CHECK (
  amount_cents > 0
  AND consumed_cents >= 0
  AND consumed_cents <= amount_cents
  AND direction IN ('in','out')
  AND (
    (currency_code = 'CZK' AND native_amount_minor IS NULL AND consumed_native_minor IS NULL AND fx_rate IS NULL)
    OR
    (currency_code <> 'CZK' AND native_amount_minor > 0 AND consumed_native_minor >= 0
      AND consumed_native_minor <= native_amount_minor AND fx_rate > 0)
  )
);

ALTER TABLE unit_payment_operations DROP CONSTRAINT IF EXISTS chk_unit_payment_source_values;
ALTER TABLE unit_payment_operations ADD CONSTRAINT chk_unit_payment_source_values CHECK (
  (kind IN ('allocation','refund') AND source_id IS NOT NULL AND source_amount_cents > 0)
  OR
  (kind NOT IN ('allocation','refund') AND source_amount_cents IS NULL AND source_native_minor IS NULL)
);

ALTER TABLE unit_payment_operations
  ADD INDEX IF NOT EXISTS idx_unit_payment_operations_source_order (supplier_id, source_id, status, id, kind);

DELIMITER $$
CREATE OR REPLACE TRIGGER unit_payment_sources_immutable_update BEFORE UPDATE ON unit_payment_sources FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.bank_transaction_id <=> BINARY OLD.bank_transaction_id)
 OR NOT (BINARY NEW.cash_document_id <=> BINARY OLD.cash_document_id)
 OR NOT (BINARY NEW.journal_entry_id <=> BINARY OLD.journal_entry_id)
 OR NOT (BINARY NEW.direction <=> BINARY OLD.direction)
 OR NOT (BINARY NEW.amount_cents <=> BINARY OLD.amount_cents)
 OR NOT (BINARY NEW.currency_code <=> BINARY OLD.currency_code)
 OR NOT (BINARY NEW.native_amount_minor <=> BINARY OLD.native_amount_minor)
 OR NOT (BINARY NEW.fx_rate <=> BINARY OLD.fx_rate)
 OR NOT (BINARY NEW.receipt_json <=> BINARY OLD.receipt_json)
 OR NOT (BINARY NEW.receipt_hash <=> BINARY OLD.receipt_hash) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'; END IF;
END$$

CREATE OR REPLACE TRIGGER unit_payment_operations_immutable_update BEFORE UPDATE ON unit_payment_operations FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.source_id <=> BINARY OLD.source_id)
 OR NOT (BINARY NEW.original_operation_id <=> BINARY OLD.original_operation_id)
 OR NOT (BINARY NEW.kind <=> BINARY OLD.kind)
 OR NOT (BINARY NEW.amount_cents <=> BINARY OLD.amount_cents)
 OR NOT (BINARY NEW.source_amount_cents <=> BINARY OLD.source_amount_cents)
 OR NOT (BINARY NEW.source_native_minor <=> BINARY OLD.source_native_minor)
 OR NOT (BINARY NEW.accounting_date <=> BINARY OLD.accounting_date)
 OR NOT (BINARY NEW.request_json <=> BINARY OLD.request_json)
 OR NOT (BINARY NEW.snapshot_json <=> BINARY OLD.snapshot_json)
 OR NOT (BINARY NEW.request_hash <=> BINARY OLD.request_hash)
 OR NOT (BINARY NEW.snapshot_hash <=> BINARY OLD.snapshot_hash)
 OR NOT (BINARY NEW.idempotency_key <=> BINARY OLD.idempotency_key)
 OR NOT (BINARY NEW.created_by <=> BINARY OLD.created_by)
 OR NOT (BINARY NEW.recorded_at <=> BINARY OLD.recorded_at)
 OR (OLD.journal_entry_id IS NOT NULL AND NOT (NEW.journal_entry_id <=> OLD.journal_entry_id))
 OR (OLD.reversal_entry_id IS NOT NULL AND NOT (NEW.reversal_entry_id <=> OLD.reversal_entry_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'; END IF;
END$$
DELIMITER ;
