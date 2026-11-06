SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;

ALTER TABLE journal_entries MODIFY source_type ENUM(
 'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
 'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
 'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
 'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
 'card_settlement','card_writeoff','other_item','payroll_accident_insurance',
 'payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment','unit_payment_revision','property_service_settlement'
) NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS property_service_settlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 segment_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 document_json LONGTEXT NOT NULL CHECK (JSON_VALID(document_json)),
 document_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 origin_receipt_json LONGTEXT NOT NULL CHECK (JSON_VALID(origin_receipt_json)),
 origin_receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_service_settlement_tenant (supplier_id,id),
 UNIQUE KEY uq_service_settlement_revision (supplier_id,unit_id,service_id,segment_key,revision_no),
 UNIQUE KEY uq_service_settlement_token (supplier_id,idempotency_key),
 FOREIGN KEY (supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,service_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,previous_id) REFERENCES property_service_settlements(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK (revision_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_service_settlement_origins (
 supplier_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 ordinal INT UNSIGNED NOT NULL,
 charge_line_id BIGINT UNSIGNED NULL,
 opening_component_id BIGINT UNSIGNED NULL,
 opening_advance_component_id BIGINT UNSIGNED NULL,
 debt_id BIGINT UNSIGNED NULL,
 PRIMARY KEY (supplier_id,document_id,ordinal),
 FOREIGN KEY (supplier_id,document_id) REFERENCES property_service_settlements(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,charge_line_id) REFERENCES unit_charge_lines(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,opening_component_id) REFERENCES property_opening_components(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,opening_advance_component_id) REFERENCES property_opening_components(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,debt_id) REFERENCES unit_payment_debts(supplier_id,id) ON DELETE RESTRICT,
 CHECK ((charge_line_id IS NOT NULL AND opening_component_id IS NULL AND opening_advance_component_id IS NULL AND debt_id IS NOT NULL) OR (charge_line_id IS NULL AND opening_component_id IS NOT NULL AND opening_advance_component_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_service_settlement_applications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 reversal_of BIGINT UNSIGNED NULL,
 application_slot BIGINT UNSIGNED AS (IFNULL(reversal_of,0)) PERSISTENT,
 accounting_date DATE NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 receipt_json LONGTEXT NOT NULL CHECK (JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_service_application_tenant (supplier_id,id),
 UNIQUE KEY uq_service_application_document (supplier_id,document_id,application_slot),
 UNIQUE KEY uq_service_application_reversal (supplier_id,reversal_of),
 UNIQUE KEY uq_service_application_token (supplier_id,idempotency_key),
 UNIQUE KEY uq_service_application_entry (supplier_id,journal_entry_id),
 FOREIGN KEY (supplier_id,document_id) REFERENCES property_service_settlements(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,reversal_of) REFERENCES property_service_settlement_applications(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_service_settlement_effects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 application_id BIGINT UNSIGNED NOT NULL,
 ordinal INT UNSIGNED NOT NULL,
 kind VARCHAR(32) NOT NULL,
 debt_id BIGINT UNSIGNED NULL,
 charge_line_id BIGINT UNSIGNED NULL,
 opening_component_id BIGINT UNSIGNED NULL,
 allocation_id BIGINT UNSIGNED NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 effect_json LONGTEXT NOT NULL CHECK (JSON_VALID(effect_json)),
 effect_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_service_effect_tenant (supplier_id,id),
 UNIQUE KEY uq_service_effect_ordinal (supplier_id,application_id,ordinal),
 FOREIGN KEY (supplier_id,application_id) REFERENCES property_service_settlement_applications(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,debt_id) REFERENCES unit_payment_debts(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,charge_line_id) REFERENCES unit_charge_lines(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,opening_component_id) REFERENCES property_opening_components(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,allocation_id) REFERENCES unit_payment_allocations(supplier_id,id) ON DELETE RESTRICT,
 CHECK (amount_cents>0 AND kind IN ('advance_applied','claim_reduction','allocation_relief','opening_paid_credit','new_claim')),
 CHECK (kind<>'allocation_relief' OR allocation_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE OR REPLACE TRIGGER property_service_settlements_update BEFORE UPDATE ON property_service_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlements_delete BEFORE DELETE ON property_service_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlement_origins_update BEFORE UPDATE ON property_service_settlement_origins FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlement_origins_delete BEFORE DELETE ON property_service_settlement_origins FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlement_applications_update BEFORE UPDATE ON property_service_settlement_applications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlement_applications_delete BEFORE DELETE ON property_service_settlement_applications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlement_effects_update BEFORE UPDATE ON property_service_settlement_effects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
CREATE OR REPLACE TRIGGER property_service_settlement_effects_delete BEFORE DELETE ON property_service_settlement_effects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement history';
ALTER TABLE property_service_settlements ADD COLUMN IF NOT EXISTS cost_line_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE property_service_settlements ADD CONSTRAINT fk_service_settlement_cost FOREIGN KEY IF NOT EXISTS (cost_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT;
DELIMITER //
CREATE OR REPLACE TRIGGER property_service_settlement_cost_tenant_insert BEFORE INSERT ON property_service_settlements FOR EACH ROW
BEGIN
 IF NOT EXISTS (SELECT id FROM journal_entry_lines WHERE id=NEW.cost_line_id AND supplier_id=NEW.supplier_id FOR UPDATE) THEN
  SIGNAL SQLSTATE '23000' SET MESSAGE_TEXT='Service settlement cost tenant mismatch';
 END IF;
END//
DELIMITER ;




CREATE TABLE IF NOT EXISTS property_service_settlement_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 reversal_of BIGINT UNSIGNED NULL,
 expected_version INT UNSIGNED NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 preview_json LONGTEXT NOT NULL CHECK(JSON_VALID(preview_json)),
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_service_request_tenant(supplier_id,id),
 UNIQUE KEY uq_service_request_token(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id,document_id) REFERENCES property_service_settlements(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,reversal_of) REFERENCES property_service_settlement_applications(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_service_settlement_heads (
 supplier_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 0,
 application_id BIGINT UNSIGNED NULL,
 PRIMARY KEY(supplier_id,document_id),
 FOREIGN KEY(supplier_id,document_id) REFERENCES property_service_settlements(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,application_id) REFERENCES property_service_settlement_applications(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE property_service_settlement_applications ADD COLUMN IF NOT EXISTS request_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE property_service_settlement_applications ADD UNIQUE INDEX IF NOT EXISTS uq_service_application_request(supplier_id,request_id);
ALTER TABLE property_service_settlement_applications ADD CONSTRAINT fk_service_application_request FOREIGN KEY IF NOT EXISTS(supplier_id,request_id) REFERENCES property_service_settlement_requests(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_credit_lots ADD COLUMN IF NOT EXISTS settlement_effect_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_credit_lots ADD UNIQUE INDEX IF NOT EXISTS uq_credit_settlement_effect(supplier_id,settlement_effect_id);
ALTER TABLE unit_credit_lots ADD CONSTRAINT fk_credit_settlement_effect FOREIGN KEY IF NOT EXISTS(supplier_id,settlement_effect_id) REFERENCES property_service_settlement_effects(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_credit_lots DROP CONSTRAINT IF EXISTS chk_unit_credit_origin;
ALTER TABLE unit_credit_lots ADD CONSTRAINT IF NOT EXISTS chk_unit_credit_origin CHECK ((operation_id IS NOT NULL)+(opening_item_id IS NOT NULL)+(settlement_effect_id IS NOT NULL)=1);
CREATE OR REPLACE TRIGGER property_service_settlement_requests_update BEFORE UPDATE ON property_service_settlement_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement request';
CREATE OR REPLACE TRIGGER property_service_settlement_requests_delete BEFORE DELETE ON property_service_settlement_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service settlement request';

ALTER TABLE unit_payment_debts ADD COLUMN IF NOT EXISTS settlement_effect_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_payment_debts ADD UNIQUE INDEX IF NOT EXISTS uq_debt_settlement_effect(supplier_id,settlement_effect_id);
ALTER TABLE unit_payment_debts ADD CONSTRAINT fk_debt_settlement_effect FOREIGN KEY IF NOT EXISTS(supplier_id,settlement_effect_id) REFERENCES property_service_settlement_effects(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_payment_debts DROP CONSTRAINT IF EXISTS chk_unit_debt_origin;
ALTER TABLE unit_payment_debts ADD CONSTRAINT IF NOT EXISTS chk_unit_debt_origin CHECK ((charge_id IS NOT NULL)+(opening_item_id IS NOT NULL)+(settlement_effect_id IS NOT NULL)=1);
ALTER TABLE unit_payment_allocations ADD COLUMN IF NOT EXISTS settlement_effect_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_payment_allocations ADD CONSTRAINT fk_allocation_settlement_effect FOREIGN KEY IF NOT EXISTS(supplier_id,settlement_effect_id) REFERENCES property_service_settlement_effects(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_payment_allocations DROP CONSTRAINT IF EXISTS chk_unit_allocation;
ALTER TABLE unit_payment_allocations ADD CONSTRAINT chk_unit_allocation CHECK (amount_cents>0 AND ((charge_line_id IS NOT NULL)+(accessory_id IS NOT NULL)+(opening_component_id IS NOT NULL)+(settlement_effect_id IS NOT NULL))=1 AND (opening_component_id IS NULL OR charge_id IS NULL) AND (settlement_effect_id IS NULL OR charge_id IS NULL));
