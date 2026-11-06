SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;
ALTER TABLE journal_entries MODIFY source_type ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening','depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax','profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual','settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay','card_settlement','card_writeoff','other_item','payroll_accident_insurance','payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment','unit_payment_revision','property_service_settlement','property_service_lifecycle') NOT NULL DEFAULT 'manual';
CREATE TABLE IF NOT EXISTS property_service_lifecycle_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 period_from DATE NOT NULL,
 period_to DATE NOT NULL,
 policy VARCHAR(40) NOT NULL,
 facts_json LONGTEXT NOT NULL CHECK(JSON_VALID(facts_json)),
 facts_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_lifecycle_tenant(supplier_id,id),
 UNIQUE KEY uq_lifecycle_case(supplier_id,unit_id,service_id,period_from,period_to),
 FOREIGN KEY(supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,service_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(period_from<=period_to),
 CHECK(policy IN ('service_balance_sheet','service_profit_and_loss'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_service_lifecycle_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_json LONGTEXT NULL CHECK(receipt_json IS NULL OR JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_lifecycle_event(supplier_id,id),
 UNIQUE KEY uq_lifecycle_event_key(supplier_id,case_id,event_key),
 FOREIGN KEY(supplier_id,case_id) REFERENCES property_service_lifecycle_cases(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_service_lifecycle_claims (
 supplier_id INT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NOT NULL,
 line_id BIGINT UNSIGNED NOT NULL,
 claim_role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,line_id,claim_role),
 FOREIGN KEY(supplier_id,event_id) REFERENCES property_service_lifecycle_events(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
