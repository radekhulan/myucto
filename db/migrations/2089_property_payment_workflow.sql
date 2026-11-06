SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;

ALTER TABLE journal_entries MODIFY source_type ENUM(
 'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
 'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
 'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
 'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
 'card_settlement','card_writeoff','other_item','payroll_accident_insurance',
 'payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment','unit_payment_revision'
) NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS unit_payment_workflows (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 source_kind VARCHAR(4) NOT NULL,
 bank_transaction_id BIGINT UNSIGNED NULL,
 cash_document_id BIGINT UNSIGNED NULL,
 source_id BIGINT UNSIGNED NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'pending',
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 plan_json LONGTEXT NOT NULL CHECK (JSON_VALID(plan_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_unit_payment_workflow_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_workflow_token (supplier_id,idempotency_key),
 CONSTRAINT chk_unit_payment_workflow_source CHECK (source_kind IN ('bank','cash') AND NOT (bank_transaction_id IS NOT NULL AND cash_document_id IS NOT NULL)),
 CONSTRAINT chk_unit_payment_workflow_status CHECK (status IN ('pending','confirmed') AND (status='pending' OR (source_id IS NOT NULL AND journal_entry_id IS NOT NULL))),
 CONSTRAINT fk_unit_payment_workflow_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_workflow_bank FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_workflow_cash FOREIGN KEY (cash_document_id) REFERENCES cash_documents(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_workflow_source FOREIGN KEY (supplier_id,source_id) REFERENCES unit_payment_sources(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_workflow_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_workflow_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_workflow_operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 workflow_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 part_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 part_no INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_unit_payment_workflow_operation (supplier_id,operation_id),
 UNIQUE KEY uq_unit_payment_workflow_part (supplier_id,workflow_id,part_key),
 UNIQUE KEY uq_unit_payment_workflow_order (supplier_id,workflow_id,part_no),
 CONSTRAINT fk_unit_payment_workflow_part_owner FOREIGN KEY (supplier_id,workflow_id) REFERENCES unit_payment_workflows(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_workflow_part_operation FOREIGN KEY (supplier_id,operation_id) REFERENCES unit_payment_operations(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_source_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 previous_revision_id BIGINT UNSIGNED NULL,
 expected_source_version INT UNSIGNED NOT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 status VARCHAR(8) NOT NULL DEFAULT 'pending',
 accounting_date DATE NOT NULL,
 effective_at DATETIME(6) NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
 UNIQUE KEY uq_unit_payment_revision_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_revision_source_id (supplier_id,source_id,id),
 UNIQUE KEY uq_unit_payment_revision_number (supplier_id,source_id,revision_no),
 UNIQUE KEY uq_unit_payment_revision_token (supplier_id,idempotency_key),
 UNIQUE KEY uq_unit_payment_revision_entry (supplier_id,journal_entry_id),
 CONSTRAINT chk_unit_payment_revision_status CHECK (revision_no>0 AND expected_source_version>0 AND status IN ('pending','posted') AND (status='pending' OR journal_entry_id IS NOT NULL)),
 CONSTRAINT fk_unit_payment_revision_source FOREIGN KEY (supplier_id,source_id) REFERENCES unit_payment_sources(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_revision_previous FOREIGN KEY (supplier_id,source_id,previous_revision_id) REFERENCES unit_payment_source_revisions(supplier_id,source_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_revision_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_revision_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_source_heads (
 supplier_id INT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 revision_id BIGINT UNSIGNED NOT NULL,
 row_version INT UNSIGNED NOT NULL,
 PRIMARY KEY (supplier_id,source_id),
 CONSTRAINT chk_unit_payment_source_head_version CHECK (row_version>0),
 CONSTRAINT fk_unit_payment_source_head_source FOREIGN KEY (supplier_id,source_id) REFERENCES unit_payment_sources(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_source_head_revision FOREIGN KEY (supplier_id,source_id,revision_id) REFERENCES unit_payment_source_revisions(supplier_id,source_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE TRIGGER unit_payment_workflows_guard_update BEFORE UPDATE ON unit_payment_workflows FOR EACH ROW
BEGIN
 IF OLD.status<>'pending' OR NEW.id<>OLD.id
 OR NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.source_kind <=> BINARY OLD.source_kind)
 OR NOT (BINARY NEW.bank_transaction_id <=> BINARY OLD.bank_transaction_id)
 OR (OLD.cash_document_id IS NOT NULL AND NOT (BINARY NEW.cash_document_id <=> BINARY OLD.cash_document_id))
 OR (OLD.source_id IS NOT NULL AND NOT (BINARY NEW.source_id <=> BINARY OLD.source_id))
 OR (OLD.journal_entry_id IS NOT NULL AND NOT (BINARY NEW.journal_entry_id <=> BINARY OLD.journal_entry_id))
 OR NOT (BINARY NEW.request_json <=> BINARY OLD.request_json)
 OR NOT (BINARY NEW.plan_json <=> BINARY OLD.plan_json)
 OR NOT (BINARY NEW.request_hash <=> BINARY OLD.request_hash)
 OR NOT (BINARY NEW.preview_hash <=> BINARY OLD.preview_hash)
 OR NOT (BINARY NEW.idempotency_key <=> BINARY OLD.idempotency_key)
 OR NOT (BINARY NEW.created_by <=> BINARY OLD.created_by)
 OR NOT (BINARY NEW.recorded_at <=> BINARY OLD.recorded_at)
 OR NEW.row_version<>OLD.row_version+1
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment workflow is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_payment_workflows_guard_delete BEFORE DELETE ON unit_payment_workflows FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment workflow is immutable'$$
CREATE OR REPLACE TRIGGER unit_payment_workflow_operations_guard_update BEFORE UPDATE ON unit_payment_workflow_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment workflow is immutable'$$
CREATE OR REPLACE TRIGGER unit_payment_workflow_operations_guard_delete BEFORE DELETE ON unit_payment_workflow_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment workflow is immutable'$$
CREATE OR REPLACE TRIGGER unit_payment_source_revisions_guard_update BEFORE UPDATE ON unit_payment_source_revisions FOR EACH ROW
BEGIN
 IF OLD.status<>'pending' OR NEW.status<>'posted' OR NEW.id<>OLD.id OR OLD.journal_entry_id IS NOT NULL OR NEW.journal_entry_id IS NULL
 OR NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.source_id <=> BINARY OLD.source_id)
 OR NOT (BINARY NEW.revision_no <=> BINARY OLD.revision_no)
 OR NOT (BINARY NEW.previous_revision_id <=> BINARY OLD.previous_revision_id)
 OR NOT (BINARY NEW.expected_source_version <=> BINARY OLD.expected_source_version)
 OR NOT (BINARY NEW.accounting_date <=> BINARY OLD.accounting_date)
 OR NOT (BINARY NEW.effective_at <=> BINARY OLD.effective_at)
 OR NOT (BINARY NEW.request_json <=> BINARY OLD.request_json)
 OR NOT (BINARY NEW.snapshot_json <=> BINARY OLD.snapshot_json)
 OR NOT (BINARY NEW.request_hash <=> BINARY OLD.request_hash)
 OR NOT (BINARY NEW.snapshot_hash <=> BINARY OLD.snapshot_hash)
 OR NOT (BINARY NEW.idempotency_key <=> BINARY OLD.idempotency_key)
 OR NOT (BINARY NEW.created_by <=> BINARY OLD.created_by)
 OR NOT (BINARY NEW.recorded_at <=> BINARY OLD.recorded_at)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment source revision is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_payment_source_revisions_guard_delete BEFORE DELETE ON unit_payment_source_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment source revision is immutable'$$
CREATE OR REPLACE TRIGGER unit_payment_source_heads_guard_update BEFORE UPDATE ON unit_payment_source_heads FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id <=> BINARY OLD.supplier_id) OR NOT (BINARY NEW.source_id <=> BINARY OLD.source_id)
 OR NEW.row_version<>OLD.row_version+1 OR NEW.revision_id=OLD.revision_id
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment source head requires a new revision'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_payment_source_heads_guard_delete BEFORE DELETE ON unit_payment_source_heads FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment source head is immutable'$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS unit_payment_exports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 content_base64 LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_payment_export_tenant (supplier_id,id),
 UNIQUE KEY uq_unit_payment_export_token (supplier_id,idempotency_key),
 CONSTRAINT fk_unit_payment_export_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_export_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_payment_export_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 export_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 UNIQUE KEY uq_unit_payment_export_reservation (supplier_id,reservation_id),
 UNIQUE KEY uq_unit_payment_export_item_tenant (supplier_id,id),
 CONSTRAINT chk_unit_payment_export_amount CHECK (amount_cents>0),
 CONSTRAINT fk_unit_payment_export_item_owner FOREIGN KEY (supplier_id,export_id) REFERENCES unit_payment_exports(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_unit_payment_export_item_reservation FOREIGN KEY (supplier_id,reservation_id) REFERENCES unit_credit_reservations(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TRIGGER unit_payment_exports_immutable_update BEFORE UPDATE ON unit_payment_exports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment export is immutable';
CREATE OR REPLACE TRIGGER unit_payment_exports_immutable_delete BEFORE DELETE ON unit_payment_exports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment export is immutable';
CREATE OR REPLACE TRIGGER unit_payment_export_items_immutable_update BEFORE UPDATE ON unit_payment_export_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment export is immutable';
CREATE OR REPLACE TRIGGER unit_payment_export_items_immutable_delete BEFORE DELETE ON unit_payment_export_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property payment export is immutable';
