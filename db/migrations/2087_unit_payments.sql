SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;

ALTER TABLE journal_entries MODIFY source_type ENUM(
 'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
 'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
 'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
 'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
 'card_settlement','card_writeoff','other_item','payroll_accident_insurance',
 'payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment'
) NOT NULL DEFAULT 'manual';


CREATE TABLE IF NOT EXISTS unit_payment_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 bank_transaction_id BIGINT UNSIGNED NULL,
 cash_document_id BIGINT UNSIGNED NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 direction VARCHAR(3) NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 consumed_cents DECIMAL(30,0) NOT NULL DEFAULT 0,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 receipt_json LONGTEXT NOT NULL CHECK (JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_unit_payment_source_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_bank_source (bank_transaction_id),
 UNIQUE KEY uq_unit_payment_cash_source (cash_document_id),
 UNIQUE KEY uq_unit_payment_source_journal (supplier_id,journal_entry_id),
 CONSTRAINT chk_unit_payment_one_source CHECK ((bank_transaction_id IS NULL) <> (cash_document_id IS NULL)),
 CONSTRAINT chk_unit_payment_source_amount CHECK (amount_cents > 0 AND consumed_cents >= 0 AND consumed_cents <= amount_cents AND direction IN ('in','out')),
 CONSTRAINT fk_unit_payment_source_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_source_bank FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_source_cash FOREIGN KEY (cash_document_id) REFERENCES cash_documents(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_source_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NULL,
 original_operation_id BIGINT UNSIGNED NULL,
 kind VARCHAR(24) NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 accounting_date DATE NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 reversal_entry_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_payment_operation_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_operation_token (supplier_id,idempotency_key),
 UNIQUE KEY uq_unit_payment_operation_journal (supplier_id,journal_entry_id),
 CONSTRAINT chk_unit_payment_operation CHECK (amount_cents > 0 AND kind IN ('allocation','refund','setoff','reversal') AND status IN ('pending','posted','reversed')),
 CONSTRAINT fk_unit_payment_operation_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_operation_source FOREIGN KEY (supplier_id,source_id) REFERENCES unit_payment_sources(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_operation_original FOREIGN KEY (supplier_id,original_operation_id) REFERENCES unit_payment_operations(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_operation_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_operation_reverse FOREIGN KEY (supplier_id,reversal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_operation_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_claim_accessory_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 journal_line_id BIGINT UNSIGNED NOT NULL,
 component VARCHAR(32) NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 receipt_json LONGTEXT NOT NULL CHECK (JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_accessory_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_accessory_line (journal_line_id),
 CONSTRAINT chk_unit_accessory CHECK (amount_cents > 0 AND component IN ('determined_cost','default_interest','interest')),
 CONSTRAINT fk_unit_accessory_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_accessory_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_accessory_line FOREIGN KEY (journal_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_accessory_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 charge_line_id BIGINT UNSIGNED NULL,
 accessory_id BIGINT UNSIGNED NULL,
 debtor_id BIGINT UNSIGNED NOT NULL,
 liability_group VARCHAR(80) NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 allocation_json LONGTEXT NOT NULL CHECK (JSON_VALID(allocation_json)),
 UNIQUE KEY uq_unit_allocation_tenant (supplier_id,id),
 CONSTRAINT chk_unit_allocation CHECK (amount_cents > 0 AND ((charge_line_id IS NULL) <> (accessory_id IS NULL))),
 CONSTRAINT fk_unit_allocation_operation FOREIGN KEY (supplier_id,operation_id) REFERENCES unit_payment_operations(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_allocation_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_allocation_line FOREIGN KEY (supplier_id,charge_line_id) REFERENCES unit_charge_lines(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_allocation_accessory FOREIGN KEY (supplier_id,accessory_id) REFERENCES unit_claim_accessory_receipts(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_allocation_debtor FOREIGN KEY (supplier_id,debtor_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_credit_lots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 due_date DATE NOT NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_unit_credit_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_credit_origin (supplier_id,operation_id),
 CONSTRAINT chk_unit_credit_amount CHECK (amount_cents > 0),
 CONSTRAINT fk_unit_credit_operation FOREIGN KEY (supplier_id,operation_id) REFERENCES unit_payment_operations(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_credit_unit FOREIGN KEY (supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_credit_parties (
 supplier_id INT UNSIGNED NOT NULL,
 credit_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 party_json LONGTEXT NOT NULL CHECK (JSON_VALID(party_json)),
 PRIMARY KEY (credit_id,client_id),
 CONSTRAINT fk_unit_credit_party_credit FOREIGN KEY (supplier_id,credit_id) REFERENCES unit_credit_lots(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_credit_party_client FOREIGN KEY (supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_credit_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 credit_id BIGINT UNSIGNED NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 paid_cents DECIMAL(30,0) NOT NULL DEFAULT 0,
 status VARCHAR(16) NOT NULL DEFAULT 'reserved',
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_credit_reservation_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_credit_reservation_token (supplier_id,idempotency_key),
 CONSTRAINT chk_unit_credit_reservation CHECK (amount_cents > 0 AND paid_cents >= 0 AND paid_cents <= amount_cents AND status IN ('reserved','submitted','cancelled','failed','paid')),
 CONSTRAINT fk_unit_credit_reservation_credit FOREIGN KEY (supplier_id,credit_id) REFERENCES unit_credit_lots(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_credit_reservation_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_credit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 credit_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 kind VARCHAR(16) NOT NULL,
 event_json LONGTEXT NOT NULL CHECK (JSON_VALID(event_json)),
 UNIQUE KEY uq_unit_credit_event_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_credit_event_operation (supplier_id,operation_id),
 CONSTRAINT chk_unit_credit_event CHECK (amount_cents <> 0 AND kind IN ('refund','setoff','reversal','correction')),
 CONSTRAINT fk_unit_credit_event_credit FOREIGN KEY (supplier_id,credit_id) REFERENCES unit_credit_lots(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_credit_event_operation FOREIGN KEY (supplier_id,operation_id) REFERENCES unit_payment_operations(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_credit_event_reservation FOREIGN KEY (supplier_id,reservation_id) REFERENCES unit_credit_reservations(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 object_kind VARCHAR(32) NOT NULL,
 object_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(32) NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 CONSTRAINT fk_unit_payment_audit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_audit_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TRIGGER unit_claim_accessory_receipts_immutable_update BEFORE UPDATE ON unit_claim_accessory_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_claim_accessory_receipts_immutable_delete BEFORE DELETE ON unit_claim_accessory_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_payment_allocations_immutable_update BEFORE UPDATE ON unit_payment_allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_payment_allocations_immutable_delete BEFORE DELETE ON unit_payment_allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_credit_parties_immutable_update BEFORE UPDATE ON unit_credit_parties FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_credit_parties_immutable_delete BEFORE DELETE ON unit_credit_parties FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_credit_events_immutable_update BEFORE UPDATE ON unit_credit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_credit_events_immutable_delete BEFORE DELETE ON unit_credit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_payment_audit_immutable_update BEFORE UPDATE ON unit_payment_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE OR REPLACE TRIGGER unit_payment_audit_immutable_delete BEFORE DELETE ON unit_payment_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

CREATE TABLE IF NOT EXISTS unit_payment_debts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_payment_debt_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_debt_token (supplier_id,idempotency_key),
 CONSTRAINT fk_unit_payment_debt_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_debt_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_debt_lines (
 supplier_id INT UNSIGNED NOT NULL,
 debt_id BIGINT UNSIGNED NOT NULL,
 charge_line_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (supplier_id,charge_line_id),
 CONSTRAINT fk_unit_payment_debt_line_debt FOREIGN KEY (supplier_id,debt_id) REFERENCES unit_payment_debts(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_debt_line_charge FOREIGN KEY (supplier_id,charge_line_id) REFERENCES unit_charge_lines(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE unit_payment_allocations ADD COLUMN IF NOT EXISTS debt_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE unit_payment_allocations ADD CONSTRAINT fk_unit_allocation_debt FOREIGN KEY IF NOT EXISTS (supplier_id,debt_id) REFERENCES unit_payment_debts(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_claim_accessory_receipts ADD COLUMN IF NOT EXISTS debt_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE unit_claim_accessory_receipts ADD CONSTRAINT fk_unit_accessory_debt FOREIGN KEY IF NOT EXISTS (supplier_id,debt_id) REFERENCES unit_payment_debts(supplier_id,id) ON DELETE RESTRICT;

CREATE OR REPLACE TRIGGER unit_payment_debts_immutable_update BEFORE UPDATE ON unit_payment_debts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
CREATE OR REPLACE TRIGGER unit_payment_debts_immutable_delete BEFORE DELETE ON unit_payment_debts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
CREATE OR REPLACE TRIGGER unit_payment_debt_lines_immutable_update BEFORE UPDATE ON unit_payment_debt_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
CREATE OR REPLACE TRIGGER unit_payment_debt_lines_immutable_delete BEFORE DELETE ON unit_payment_debt_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';

DELIMITER $$
CREATE OR REPLACE TRIGGER unit_payment_sources_immutable_update BEFORE UPDATE ON unit_payment_sources FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.bank_transaction_id <=> BINARY OLD.bank_transaction_id)
 OR NOT (BINARY NEW.cash_document_id <=> BINARY OLD.cash_document_id)
 OR NOT (BINARY NEW.journal_entry_id <=> BINARY OLD.journal_entry_id)
 OR NOT (BINARY NEW.direction <=> BINARY OLD.direction)
 OR NOT (BINARY NEW.amount_cents <=> BINARY OLD.amount_cents)
 OR NOT (BINARY NEW.receipt_json <=> BINARY OLD.receipt_json)
 OR NOT (BINARY NEW.receipt_hash <=> BINARY OLD.receipt_hash) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_payment_sources_immutable_delete BEFORE DELETE ON unit_payment_sources FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'$$
CREATE OR REPLACE TRIGGER unit_payment_operations_immutable_update BEFORE UPDATE ON unit_payment_operations FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.source_id <=> BINARY OLD.source_id)
 OR NOT (BINARY NEW.original_operation_id <=> BINARY OLD.original_operation_id)
 OR NOT (BINARY NEW.kind <=> BINARY OLD.kind)
 OR NOT (BINARY NEW.amount_cents <=> BINARY OLD.amount_cents)
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
CREATE OR REPLACE TRIGGER unit_payment_operations_immutable_delete BEFORE DELETE ON unit_payment_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'$$
CREATE OR REPLACE TRIGGER unit_credit_lots_immutable_update BEFORE UPDATE ON unit_credit_lots FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.operation_id <=> BINARY OLD.operation_id)
 OR NOT (BINARY NEW.unit_id <=> BINARY OLD.unit_id)
 OR NOT (BINARY NEW.amount_cents <=> BINARY OLD.amount_cents)
 OR NOT (BINARY NEW.due_date <=> BINARY OLD.due_date)
 OR NOT (BINARY NEW.snapshot_json <=> BINARY OLD.snapshot_json)
 OR NOT (BINARY NEW.snapshot_hash <=> BINARY OLD.snapshot_hash) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_credit_lots_immutable_delete BEFORE DELETE ON unit_credit_lots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'$$
CREATE OR REPLACE TRIGGER unit_credit_reservations_immutable_update BEFORE UPDATE ON unit_credit_reservations FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.credit_id <=> BINARY OLD.credit_id)
 OR NOT (BINARY NEW.amount_cents <=> BINARY OLD.amount_cents)
 OR NOT (BINARY NEW.request_json <=> BINARY OLD.request_json)
 OR NOT (BINARY NEW.request_hash <=> BINARY OLD.request_hash)
 OR NOT (BINARY NEW.idempotency_key <=> BINARY OLD.idempotency_key)
 OR NOT (BINARY NEW.created_by <=> BINARY OLD.created_by)
 OR NOT (BINARY NEW.recorded_at <=> BINARY OLD.recorded_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_credit_reservations_immutable_delete BEFORE DELETE ON unit_credit_reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'$$
DELIMITER ;

ALTER TABLE unit_payment_operations DROP CONSTRAINT IF EXISTS chk_unit_payment_operation;
ALTER TABLE unit_payment_operations ADD CONSTRAINT chk_unit_payment_operation CHECK (amount_cents > 0 AND kind IN ('allocation','refund','setoff','reversal','correction') AND status IN ('pending','posted','reversed'));

CREATE TABLE IF NOT EXISTS unit_payment_corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 adjustment_charge_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_payment_correction_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_correction_charge (supplier_id,adjustment_charge_id),
 UNIQUE KEY uq_unit_payment_correction_token (supplier_id,idempotency_key),
 UNIQUE KEY uq_unit_payment_correction_operation (supplier_id,operation_id),
 CONSTRAINT chk_unit_payment_correction_status CHECK (status IN ('pending','posted','reversed')),
 CONSTRAINT fk_unit_payment_correction_charge FOREIGN KEY (supplier_id,adjustment_charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_correction_operation FOREIGN KEY (supplier_id,operation_id) REFERENCES unit_payment_operations(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_correction_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_correction_anchors (
 supplier_id INT UNSIGNED NOT NULL,
 correction_id BIGINT UNSIGNED NOT NULL,
 adjustment_line_id BIGINT UNSIGNED NOT NULL,
 original_line_id BIGINT UNSIGNED NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL CHECK (amount_cents > 0),
 PRIMARY KEY (supplier_id,correction_id,adjustment_line_id,original_line_id),
 CONSTRAINT fk_unit_payment_anchor_correction FOREIGN KEY (supplier_id,correction_id) REFERENCES unit_payment_corrections(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_anchor_adjustment FOREIGN KEY (supplier_id,adjustment_line_id) REFERENCES unit_charge_lines(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_anchor_original FOREIGN KEY (supplier_id,original_line_id) REFERENCES unit_charge_lines(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_reliefs (
 supplier_id INT UNSIGNED NOT NULL,
 correction_id BIGINT UNSIGNED NOT NULL,
 allocation_id BIGINT UNSIGNED NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL CHECK (amount_cents > 0),
 PRIMARY KEY (supplier_id,correction_id,allocation_id),
 CONSTRAINT fk_unit_payment_relief_correction FOREIGN KEY (supplier_id,correction_id) REFERENCES unit_payment_corrections(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_relief_allocation FOREIGN KEY (supplier_id,allocation_id) REFERENCES unit_payment_allocations(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TRIGGER unit_payment_anchors_immutable_update BEFORE UPDATE ON unit_payment_correction_anchors FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
CREATE OR REPLACE TRIGGER unit_payment_anchors_immutable_delete BEFORE DELETE ON unit_payment_correction_anchors FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
CREATE OR REPLACE TRIGGER unit_payment_reliefs_immutable_update BEFORE UPDATE ON unit_payment_reliefs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
CREATE OR REPLACE TRIGGER unit_payment_reliefs_immutable_delete BEFORE DELETE ON unit_payment_reliefs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable';
DELIMITER $$
CREATE OR REPLACE TRIGGER unit_payment_corrections_immutable_update BEFORE UPDATE ON unit_payment_corrections FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.adjustment_charge_id <=> BINARY OLD.adjustment_charge_id)
 OR NOT (BINARY NEW.request_json <=> BINARY OLD.request_json)
 OR NOT (BINARY NEW.snapshot_json <=> BINARY OLD.snapshot_json)
 OR NOT (BINARY NEW.request_hash <=> BINARY OLD.request_hash)
 OR NOT (BINARY NEW.snapshot_hash <=> BINARY OLD.snapshot_hash)
 OR NOT (BINARY NEW.idempotency_key <=> BINARY OLD.idempotency_key)
 OR NOT (NEW.created_by <=> OLD.created_by)
 OR NOT (NEW.recorded_at <=> OLD.recorded_at)
 OR (OLD.operation_id IS NOT NULL AND NOT (NEW.operation_id <=> OLD.operation_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_payment_corrections_immutable_delete BEFORE DELETE ON unit_payment_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment history is immutable'$$
DELIMITER ;
