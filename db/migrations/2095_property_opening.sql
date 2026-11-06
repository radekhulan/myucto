CREATE TABLE IF NOT EXISTS property_opening_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 revision_no INT UNSIGNED NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 mode VARCHAR(24) NOT NULL,
 accounting_date DATE NOT NULL,
 effective_at DATETIME(6) NOT NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 reversal_entry_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_property_opening_tenant (supplier_id,id),
 UNIQUE KEY uq_property_opening_revision (supplier_id,period_id,revision_no),
 UNIQUE KEY uq_property_opening_token (supplier_id,idempotency_key),
 CONSTRAINT fk_property_opening_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_period FOREIGN KEY (supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_previous FOREIGN KEY (supplier_id,previous_id) REFERENCES property_opening_batches(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_entry FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_reversal FOREIGN KEY (supplier_id,reversal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT chk_property_opening_status CHECK (status IN ('pending','posted','reversed') AND mode IN ('post_new','bind_existing','revision','reversal'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_opening_heads (
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (supplier_id,period_id),
 CONSTRAINT fk_property_opening_head_period FOREIGN KEY (supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_head_batch FOREIGN KEY (supplier_id,batch_id) REFERENCES property_opening_batches(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_opening_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 case_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind VARCHAR(24) NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NULL,
 origin_at DATETIME(6) NOT NULL,
 due_date DATE NULL,
 period_from DATE NOT NULL,
 period_to DATE NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_property_opening_item_tenant (supplier_id,id),
 UNIQUE KEY uq_property_opening_case (supplier_id,batch_id,case_key),
 KEY ix_property_opening_unit (supplier_id,unit_id,batch_id),
 CONSTRAINT fk_property_opening_item_batch FOREIGN KEY (supplier_id,batch_id) REFERENCES property_opening_batches(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_item_unit FOREIGN KEY (supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_item_service FOREIGN KEY (supplier_id,service_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT chk_property_opening_item CHECK (amount_cents>0 AND period_to>=period_from AND kind IN ('debt','credit','advance','service_carry'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_opening_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 component_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(32) NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 journal_line_id BIGINT UNSIGNED NOT NULL,
 fact_json LONGTEXT NOT NULL CHECK (JSON_VALID(fact_json)),
 fact_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_property_opening_component_tenant (supplier_id,id),
 UNIQUE KEY uq_property_opening_component (supplier_id,item_id,component_key),
 KEY ix_property_opening_line (supplier_id,journal_line_id),
 CONSTRAINT fk_property_opening_component_item FOREIGN KEY (supplier_id,item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_component_entry FOREIGN KEY (supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_component_line FOREIGN KEY (journal_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT,
 CONSTRAINT chk_property_opening_component CHECK (amount_cents>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_opening_parties (
 supplier_id INT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 group_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(16) NOT NULL,
 fact_json LONGTEXT NOT NULL CHECK (JSON_VALID(fact_json)),
 PRIMARY KEY (supplier_id,item_id,group_key,client_id),
 CONSTRAINT fk_property_opening_party_item FOREIGN KEY (supplier_id,item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_party_client FOREIGN KEY (supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT chk_property_opening_party CHECK (role IN ('debtor','guarantor','creditor'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE unit_payment_debts MODIFY charge_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_payment_debts ADD COLUMN IF NOT EXISTS opening_item_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_payment_debts ADD UNIQUE INDEX IF NOT EXISTS uq_unit_debt_opening (supplier_id,opening_item_id);
ALTER TABLE unit_payment_debts ADD CONSTRAINT fk_unit_debt_opening FOREIGN KEY IF NOT EXISTS (supplier_id,opening_item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_payment_debts ADD CONSTRAINT IF NOT EXISTS chk_unit_debt_origin CHECK ((charge_id IS NULL)<>(opening_item_id IS NULL));

ALTER TABLE unit_credit_lots MODIFY operation_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_credit_lots ADD COLUMN IF NOT EXISTS opening_item_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_credit_lots ADD UNIQUE INDEX IF NOT EXISTS uq_unit_credit_opening (supplier_id,opening_item_id);
ALTER TABLE unit_credit_lots ADD CONSTRAINT fk_unit_credit_opening FOREIGN KEY IF NOT EXISTS (supplier_id,opening_item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_credit_lots ADD CONSTRAINT IF NOT EXISTS chk_unit_credit_origin CHECK ((operation_id IS NULL)<>(opening_item_id IS NULL));

ALTER TABLE unit_payment_allocations MODIFY charge_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_payment_allocations ADD COLUMN IF NOT EXISTS opening_component_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_payment_allocations DROP CONSTRAINT IF EXISTS chk_unit_allocation;
ALTER TABLE unit_payment_allocations ADD CONSTRAINT chk_unit_allocation CHECK (amount_cents>0 AND ((charge_line_id IS NOT NULL)+(accessory_id IS NOT NULL)+(opening_component_id IS NOT NULL))=1 AND (opening_component_id IS NULL OR charge_id IS NULL));
ALTER TABLE unit_payment_allocations ADD CONSTRAINT fk_unit_allocation_opening FOREIGN KEY IF NOT EXISTS (supplier_id,opening_component_id) REFERENCES property_opening_components(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_claim_accessory_receipts MODIFY charge_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_claim_accessory_receipts MODIFY journal_line_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_claim_accessory_receipts ADD COLUMN IF NOT EXISTS opening_component_id BIGINT UNSIGNED NULL;
ALTER TABLE unit_claim_accessory_receipts ADD UNIQUE INDEX IF NOT EXISTS uq_unit_accessory_opening (supplier_id,opening_component_id);
ALTER TABLE unit_claim_accessory_receipts ADD CONSTRAINT fk_unit_accessory_opening FOREIGN KEY IF NOT EXISTS (supplier_id,opening_component_id) REFERENCES property_opening_components(supplier_id,id) ON DELETE RESTRICT;
ALTER TABLE unit_claim_accessory_receipts ADD CONSTRAINT IF NOT EXISTS chk_unit_accessory_origin CHECK ((journal_line_id IS NULL)<>(opening_component_id IS NULL));

CREATE TABLE IF NOT EXISTS property_opening_states (
 supplier_id INT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 profile VARCHAR(32) NULL,
 purpose_ref TEXT NOT NULL,
 counterpart_ref TEXT NULL,
 settlement_due_on DATE NULL,
 prescribed_cents DECIMAL(30,0) NOT NULL,
 paid_cents DECIMAL(30,0) NOT NULL,
 used_cents DECIMAL(30,0) NOT NULL,
 settled_cents DECIMAL(30,0) NOT NULL,
 cancelled_unpaid_cents DECIMAL(30,0) NOT NULL,
 PRIMARY KEY (supplier_id,item_id),
 CONSTRAINT fk_property_opening_state_item FOREIGN KEY (supplier_id,item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT chk_property_opening_state CHECK (prescribed_cents>=0 AND paid_cents>=0 AND used_cents>=0 AND settled_cents>=0 AND cancelled_unpaid_cents>=0 AND paid_cents<=prescribed_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_opening_case_links (
 supplier_id INT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 debt_item_id BIGINT UNSIGNED NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 PRIMARY KEY (supplier_id,item_id,debt_item_id),
 CONSTRAINT fk_property_opening_link_item FOREIGN KEY (supplier_id,item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_link_debt FOREIGN KEY (supplier_id,debt_item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT chk_property_opening_link CHECK (amount_cents>0 AND item_id<>debt_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_opening_recipients (
 supplier_id INT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (supplier_id,item_id,client_id),
 CONSTRAINT fk_property_opening_recipient_item FOREIGN KEY (supplier_id,item_id) REFERENCES property_opening_items(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_property_opening_recipient_client FOREIGN KEY (supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE OR REPLACE TRIGGER property_opening_states_immutable_update BEFORE UPDATE ON property_opening_states FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_states_immutable_delete BEFORE DELETE ON property_opening_states FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_links_immutable_update BEFORE UPDATE ON property_opening_case_links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_links_immutable_delete BEFORE DELETE ON property_opening_case_links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_recipients_immutable_update BEFORE UPDATE ON property_opening_recipients FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_recipients_immutable_delete BEFORE DELETE ON property_opening_recipients FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_items_immutable_update BEFORE UPDATE ON property_opening_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_items_immutable_delete BEFORE DELETE ON property_opening_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_components_immutable_update BEFORE UPDATE ON property_opening_components FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_components_immutable_delete BEFORE DELETE ON property_opening_components FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_parties_immutable_update BEFORE UPDATE ON property_opening_parties FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_parties_immutable_delete BEFORE DELETE ON property_opening_parties FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';
CREATE OR REPLACE TRIGGER property_opening_batches_immutable_delete BEFORE DELETE ON property_opening_batches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable';

DELIMITER $$
CREATE OR REPLACE TRIGGER property_opening_batches_immutable_update BEFORE UPDATE ON property_opening_batches FOR EACH ROW
BEGIN
 IF NOT (BINARY NEW.supplier_id<=>BINARY OLD.supplier_id)
 OR NOT (BINARY NEW.period_id<=>BINARY OLD.period_id)
 OR NOT (BINARY NEW.previous_id<=>BINARY OLD.previous_id)
 OR NOT (BINARY NEW.revision_no<=>BINARY OLD.revision_no)
 OR NOT (BINARY NEW.mode<=>BINARY OLD.mode)
 OR NOT (BINARY NEW.accounting_date<=>BINARY OLD.accounting_date)
 OR NOT (BINARY NEW.effective_at<=>BINARY OLD.effective_at)
 OR NOT (BINARY NEW.request_json<=>BINARY OLD.request_json)
 OR NOT (BINARY NEW.request_hash<=>BINARY OLD.request_hash)
 OR NOT (BINARY NEW.snapshot_json<=>BINARY OLD.snapshot_json)
 OR NOT (BINARY NEW.snapshot_hash<=>BINARY OLD.snapshot_hash)
 OR NOT (BINARY NEW.idempotency_key<=>BINARY OLD.idempotency_key)
 OR NOT (BINARY NEW.created_by<=>BINARY OLD.created_by)
 OR NOT (BINARY NEW.recorded_at<=>BINARY OLD.recorded_at)
 OR (OLD.journal_entry_id IS NOT NULL AND NOT (NEW.journal_entry_id<=>OLD.journal_entry_id))
 OR (OLD.reversal_entry_id IS NOT NULL AND NOT (NEW.reversal_entry_id<=>OLD.reversal_entry_id))
 OR NOT (NEW.status=OLD.status OR (OLD.status='pending' AND NEW.status='posted') OR (OLD.status='posted' AND NEW.status='reversed'))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property opening history is immutable'; END IF;
END$$
CREATE OR REPLACE TRIGGER unit_credit_opening_origin_immutable BEFORE UPDATE ON unit_credit_lots FOR EACH ROW
BEGIN
 IF NOT (NEW.opening_item_id<=>OLD.opening_item_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Property credit origin is immutable'; END IF;
END$$
DELIMITER ;

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
JOIN (
    SELECT 'property.opening.manage' AS permission_key
    UNION ALL SELECT 'property.opening.post'
    UNION ALL SELECT 'property.opening.revise'
) p
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant')
    AND (r.system_key <> 'accountant' OR p.permission_key <> 'property.opening.revise');
