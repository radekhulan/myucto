SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;
ALTER TABLE journal_entries MODIFY source_type ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening','depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax','profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual','settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay','card_settlement','card_writeoff','other_item','payroll_accident_insurance','payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment','unit_payment_revision','property_service_settlement','property_service_lifecycle','property_funding') NOT NULL DEFAULT 'manual';
CREATE TABLE IF NOT EXISTS property_funding_heads (
 supplier_id INT UNSIGNED NOT NULL,
 house_id BIGINT UNSIGNED NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(supplier_id,house_id),
 FOREIGN KEY(supplier_id,house_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_funding_operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 house_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 accounting_date DATE NOT NULL,
 effective_at DATETIME(6) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(snapshot_json)),
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_json LONGTEXT NULL CHECK(receipt_json IS NULL OR JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 reversal_of BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_funding_tenant(supplier_id,id),
 UNIQUE KEY uq_funding_key(supplier_id,idempotency_key),
 UNIQUE KEY uq_funding_revision(supplier_id,house_id,revision),
 UNIQUE KEY uq_funding_reversal(supplier_id,reversal_of),
 FOREIGN KEY(supplier_id,house_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,reversal_of) REFERENCES property_funding_operations(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(kind IN ('draw','income','transfer','payout','reverse')),
 CHECK((receipt_json IS NULL AND receipt_hash IS NULL AND journal_entry_id IS NULL) OR (receipt_json IS NOT NULL AND receipt_hash IS NOT NULL AND journal_entry_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_funding_effects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 account_code VARCHAR(50) NOT NULL,
 amount_cents BIGINT NOT NULL,
 source_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 source_id BIGINT UNSIGNED NULL,
 parties_json LONGTEXT NULL CHECK(parties_json IS NULL OR JSON_VALID(parties_json)),
 UNIQUE KEY uq_funding_effect_tenant(supplier_id,id),
 KEY ix_funding_effect_source(supplier_id,source_kind,source_id),
 FOREIGN KEY(supplier_id,operation_id) REFERENCES property_funding_operations(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,unit_id) REFERENCES property_objects(supplier_id,id) ON DELETE RESTRICT,
 CHECK(amount_cents<>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS property_funding_claims (
 supplier_id INT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 source_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 claim_role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(supplier_id,operation_id,source_kind,source_id,claim_role),
 KEY ix_funding_claim_source(supplier_id,source_kind,source_id,claim_role),
 FOREIGN KEY(supplier_id,operation_id) REFERENCES property_funding_operations(supplier_id,id) ON DELETE RESTRICT,
 CHECK(amount_cents>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
