SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS accounting_framework_conversions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 source_period_id BIGINT UNSIGNED NOT NULL,
 target_period_id BIGINT UNSIGNED NOT NULL,
 source_entry_id BIGINT UNSIGNED NULL,
 opening_entry_id BIGINT UNSIGNED NULL,
 effective_from DATE NOT NULL,
 status ENUM('pending','applied','cancelled') NOT NULL,
 active_slot TINYINT GENERATED ALWAYS AS (CASE WHEN status='cancelled' THEN NULL ELSE 1 END) PERSISTENT,
 source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 cancelled_by BIGINT UNSIGNED NULL,
 cancelled_at DATETIME(6) NULL,
 UNIQUE KEY uq_framework_conversion_tenant(supplier_id,id),
 UNIQUE KEY uq_framework_conversion_source(supplier_id,source_period_id,active_slot),
 UNIQUE KEY uq_framework_conversion_target(supplier_id,target_period_id,active_slot),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(source_period_id) REFERENCES accounting_periods(id) ON DELETE RESTRICT,
 FOREIGN KEY(target_period_id) REFERENCES accounting_periods(id) ON DELETE RESTRICT,
 FOREIGN KEY(source_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(opening_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(cancelled_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(source_period_id<>target_period_id),
 CHECK((status='cancelled')=(cancelled_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_framework_conversion_cells (
 supplier_id INT UNSIGNED NOT NULL,
 conversion_id BIGINT UNSIGNED NOT NULL,
 cell_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 opening_line_id BIGINT UNSIGNED NULL,
 source_json LONGTEXT NOT NULL CHECK(JSON_VALID(source_json)),
 target_json LONGTEXT NOT NULL CHECK(JSON_VALID(target_json)),
 PRIMARY KEY(supplier_id,conversion_id,cell_key),
 UNIQUE KEY uq_framework_conversion_line(supplier_id,opening_line_id),
 FOREIGN KEY(supplier_id,conversion_id) REFERENCES accounting_framework_conversions(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(opening_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_framework_conversion_commands (
 supplier_id INT UNSIGNED NOT NULL,
 command_token VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_json LONGTEXT NOT NULL CHECK(JSON_VALID(response_json)),
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 PRIMARY KEY(supplier_id,command_token),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_framework_conversion_decisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 conversion_id BIGINT UNSIGNED NOT NULL,
 accounting_date DATE NOT NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_conversion_result_decision(supplier_id,conversion_id),
 FOREIGN KEY(supplier_id,conversion_id) REFERENCES accounting_framework_conversions(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounting_framework_conversions MODIFY COLUMN source_entry_id BIGINT UNSIGNED NULL;
UPDATE accounting_framework_conversions SET source_entry_id=NULL WHERE status='cancelled';
