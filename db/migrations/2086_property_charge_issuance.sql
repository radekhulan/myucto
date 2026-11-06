SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;

ALTER TABLE journal_entries MODIFY source_type ENUM(
 'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
 'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
 'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
 'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
 'card_settlement','card_writeoff','other_item','payroll_accident_insurance',
 'payroll_travel','deferred_revenue_accrual','unit_charge'
) NOT NULL DEFAULT 'manual';

ALTER TABLE journal_entries ADD UNIQUE KEY IF NOT EXISTS uq_unit_charge_journal_tenant (supplier_id,id);

CREATE TABLE IF NOT EXISTS unit_charge_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 version_id BIGINT UNSIGNED NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_issue_batch_tenant (supplier_id,id),
 UNIQUE KEY uq_issue_batch_token (supplier_id,idempotency_key),
 CONSTRAINT fk_issue_batch_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_batch_version FOREIGN KEY (supplier_id,version_id) REFERENCES unit_charge_versions(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_batch_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 source_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 previous_charge_id BIGINT UNSIGNED NULL,
 title VARCHAR(32) NOT NULL,
 issue_mode VARCHAR(20) NOT NULL,
 period_from DATE NOT NULL,
 period_to DATE NOT NULL,
 claim_origin_at DATETIME(6) NOT NULL,
 issued_at DATETIME(6) NOT NULL,
 accounting_date DATE NOT NULL,
 due_date DATE NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'draft',
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 journal_entry_id BIGINT UNSIGNED NULL,
 reversal_entry_id BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_issue_charge_tenant (supplier_id,id),
 UNIQUE KEY uq_issue_charge_revision (supplier_id,unit_id,source_key,revision_no),
 UNIQUE KEY uq_issue_charge_batch_unit (supplier_id,batch_id,unit_id),
 UNIQUE KEY uq_issue_charge_journal (supplier_id,journal_entry_id),
 UNIQUE KEY uq_issue_charge_reversal (supplier_id,reversal_entry_id),
 CONSTRAINT chk_issue_charge_dates CHECK (period_to >= period_from),
 CONSTRAINT chk_issue_charge_mode CHECK (issue_mode IN ('initial','replacement','difference')),
 CONSTRAINT chk_issue_charge_title CHECK (title IN ('prescription','extra_contribution')),
 CONSTRAINT chk_issue_charge_state CHECK ((status='draft' AND journal_entry_id IS NULL AND reversal_entry_id IS NULL) OR (status='posted' AND journal_entry_id IS NOT NULL AND reversal_entry_id IS NULL) OR (status='reversed' AND journal_entry_id IS NOT NULL AND reversal_entry_id IS NOT NULL) OR (status='cancelled' AND journal_entry_id IS NULL AND reversal_entry_id IS NULL)),
 CONSTRAINT fk_issue_charge_batch FOREIGN KEY (supplier_id,batch_id) REFERENCES unit_charge_batches(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_charge_unit FOREIGN KEY (supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_charge_previous FOREIGN KEY (supplier_id,previous_charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_charge_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_charge_reversal FOREIGN KEY (supplier_id,reversal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_lines (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 component_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 line_json LONGTEXT NOT NULL CHECK (JSON_VALID(line_json)),
 UNIQUE KEY uq_issue_line_tenant (supplier_id,id),
 UNIQUE KEY uq_issue_line_component (supplier_id,charge_id,component_id),
 CONSTRAINT fk_issue_line_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_line_component FOREIGN KEY (supplier_id,component_id) REFERENCES unit_charge_components(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_line_service FOREIGN KEY (supplier_id,service_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_parties (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 group_key VARCHAR(80) NOT NULL,
 role VARCHAR(16) NOT NULL,
 party_json LONGTEXT NOT NULL CHECK (JSON_VALID(party_json)),
 UNIQUE KEY uq_issue_party_member (supplier_id,charge_id,role,client_id),
 CONSTRAINT chk_issue_party_role CHECK (role IN ('debtor','guarantor')),
 CONSTRAINT fk_issue_party_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_party_client FOREIGN KEY (supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_notices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 component_id BIGINT UNSIGNED NOT NULL,
 notice_json LONGTEXT NOT NULL CHECK (JSON_VALID(notice_json)),
 UNIQUE KEY uq_issue_notice_component (supplier_id,charge_id,component_id),
 CONSTRAINT fk_issue_notice_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_notice_component FOREIGN KEY (supplier_id,component_id) REFERENCES unit_charge_components(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_adjustments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 adjustment_charge_id BIGINT UNSIGNED NULL,
 kind VARCHAR(20) NOT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 effective_at DATETIME(6) NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_issue_adjustment_tenant (supplier_id,id),
 UNIQUE KEY uq_issue_adjustment_token (supplier_id,idempotency_key),
 CONSTRAINT chk_issue_adjustment_kind CHECK (kind IN ('reversal','cancel_draft','difference','replacement')),
 CONSTRAINT fk_issue_adjustment_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_adjustment_new FOREIGN KEY (supplier_id,adjustment_charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_adjustment_journal FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_adjustment_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_evidence_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(24) NOT NULL,
 effective_at DATETIME(6) NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_issue_evidence_token (supplier_id,idempotency_key),
 CONSTRAINT chk_issue_evidence_kind CHECK (kind IN ('reminder','liability_assessment','allocation_regime','security_basis')),
 CONSTRAINT fk_issue_evidence_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_evidence_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_charge_issue_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 charge_id BIGINT UNSIGNED NULL,
 action VARCHAR(24) NOT NULL,
 payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 CONSTRAINT fk_issue_audit_batch FOREIGN KEY (supplier_id,batch_id) REFERENCES unit_charge_batches(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_audit_charge FOREIGN KEY (supplier_id,charge_id) REFERENCES unit_charges(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_issue_audit_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS unit_charges_guard_update BEFORE UPDATE ON unit_charges FOR EACH ROW
BEGIN
 IF NOT (NEW.id=OLD.id AND NEW.supplier_id=OLD.supplier_id AND NEW.batch_id=OLD.batch_id AND NEW.unit_id=OLD.unit_id
 AND BINARY NEW.source_key=BINARY OLD.source_key AND NEW.revision_no=OLD.revision_no AND NEW.previous_charge_id <=> OLD.previous_charge_id
 AND NEW.title=OLD.title AND NEW.issue_mode=OLD.issue_mode AND NEW.period_from=OLD.period_from AND NEW.period_to=OLD.period_to
 AND NEW.claim_origin_at=OLD.claim_origin_at AND NEW.issued_at=OLD.issued_at AND NEW.accounting_date=OLD.accounting_date AND NEW.due_date=OLD.due_date
 AND NEW.amount_cents=OLD.amount_cents AND BINARY NEW.snapshot_json=BINARY OLD.snapshot_json AND NEW.snapshot_hash=OLD.snapshot_hash
 AND NEW.row_version=OLD.row_version+1
 AND ((OLD.status='draft' AND NEW.status IN ('posted','cancelled'))
 OR (OLD.status='posted' AND NEW.status='reversed' AND NEW.journal_entry_id=OLD.journal_entry_id))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
 END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS unit_charges_immutable_delete BEFORE DELETE ON unit_charges FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_batches_immutable_update BEFORE UPDATE ON unit_charge_batches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_batches_immutable_delete BEFORE DELETE ON unit_charge_batches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_lines_immutable_update BEFORE UPDATE ON unit_charge_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_lines_immutable_delete BEFORE DELETE ON unit_charge_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_parties_immutable_update BEFORE UPDATE ON unit_charge_parties FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_parties_immutable_delete BEFORE DELETE ON unit_charge_parties FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_notices_immutable_update BEFORE UPDATE ON unit_charge_notices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_notices_immutable_delete BEFORE DELETE ON unit_charge_notices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_adjustments_immutable_update BEFORE UPDATE ON unit_charge_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_adjustments_immutable_delete BEFORE DELETE ON unit_charge_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_evidence_events_immutable_update BEFORE UPDATE ON unit_charge_evidence_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_evidence_events_immutable_delete BEFORE DELETE ON unit_charge_evidence_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_issue_audit_immutable_update BEFORE UPDATE ON unit_charge_issue_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';
CREATE TRIGGER IF NOT EXISTS unit_charge_issue_audit_immutable_delete BEFORE DELETE ON unit_charge_issue_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable issued property charge';

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
JOIN (
    SELECT 'property.charges.issue' AS permission_key
    UNION ALL SELECT 'property.charges.post'
    UNION ALL SELECT 'property.charges.reverse'
) p
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant')
    AND (r.system_key <> 'accountant' OR p.permission_key <> 'property.charges.reverse');