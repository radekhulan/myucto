SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;
ALTER TABLE journal_entries MODIFY source_type ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening','depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax','profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual','settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay','card_settlement','card_writeoff','other_item','payroll_accident_insurance','payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment','unit_payment_revision','property_service_settlement','property_service_lifecycle','property_funding','nonprofit_capital') NOT NULL DEFAULT 'manual';
ALTER TABLE journal_entry_lines ADD UNIQUE INDEX IF NOT EXISTS uq_capital_line_tenant(supplier_id,id);
ALTER TABLE assets ADD UNIQUE INDEX IF NOT EXISTS uq_assets_tenant_id(supplier_id,id);
ALTER TABLE depreciation_entries ADD UNIQUE INDEX IF NOT EXISTS uq_depreciation_tenant_id(supplier_id,id);
CREATE TABLE IF NOT EXISTS nonprofit_capital_commands (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_ncc_tenant(supplier_id,id),
 UNIQUE KEY uq_ncc_token(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nonprofit_capital_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 command_id BIGINT UNSIGNED NOT NULL,
 source_line_id BIGINT UNSIGNED NOT NULL,
 asset_id BIGINT UNSIGNED NULL,
 provider_id BIGINT UNSIGNED NULL,
 kind ENUM('investment','fund') NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 policy_json LONGTEXT NOT NULL CHECK(JSON_VALID(policy_json)),
 basis_json LONGTEXT NOT NULL CHECK(JSON_VALID(basis_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_ncs_tenant(supplier_id,id),
 UNIQUE KEY uq_ncs_command(supplier_id,command_id),
 UNIQUE KEY uq_ncs_line(supplier_id,source_line_id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,command_id) REFERENCES nonprofit_capital_commands(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,asset_id) REFERENCES assets(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,provider_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT,
 CHECK(amount_cents>0),
 CHECK((kind='investment' AND asset_id IS NOT NULL AND provider_id IS NOT NULL) OR (kind='fund' AND asset_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nonprofit_capital_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 command_id BIGINT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 original_id BIGINT UNSIGNED NULL,
 parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation ENUM('release','reverse') NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 entry_id BIGINT UNSIGNED NOT NULL,
 depreciation_id BIGINT UNSIGNED NULL,
 cost_line_id BIGINT UNSIGNED NULL,
 snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(snapshot_json)),
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_nce_tenant(supplier_id,id),
 UNIQUE KEY uq_nce_chain(supplier_id,source_id,id),
 UNIQUE KEY uq_nce_command(supplier_id,command_id),
 UNIQUE KEY uq_nce_reverse(supplier_id,original_id),
 UNIQUE KEY uq_nce_entry(supplier_id,entry_id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_id) REFERENCES nonprofit_capital_sources(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,command_id) REFERENCES nonprofit_capital_commands(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_id,previous_id) REFERENCES nonprofit_capital_events(supplier_id,source_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_id,original_id) REFERENCES nonprofit_capital_events(supplier_id,source_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,depreciation_id) REFERENCES depreciation_entries(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,cost_line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE RESTRICT,
 CHECK(amount_cents>0),
 CHECK((operation='release' AND original_id IS NULL) OR (operation='reverse' AND original_id IS NOT NULL)),
 CHECK(depreciation_id IS NULL OR cost_line_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
CREATE OR REPLACE TRIGGER trg_ncc_immutable_update BEFORE UPDATE ON nonprofit_capital_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_capital_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ncc_immutable_delete BEFORE DELETE ON nonprofit_capital_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_capital_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ncs_immutable_update BEFORE UPDATE ON nonprofit_capital_sources FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_capital_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ncs_immutable_delete BEFORE DELETE ON nonprofit_capital_sources FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_capital_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nce_immutable_update BEFORE UPDATE ON nonprofit_capital_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_capital_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nce_immutable_delete BEFORE DELETE ON nonprofit_capital_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_capital_immutable'; END$$
DELIMITER ;
