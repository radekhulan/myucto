SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS accounting_securities_debt_lots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 lot_reference VARCHAR(190) NOT NULL,
 acquired_on DATE NOT NULL,
 maturity_on DATE NOT NULL,
 facts_json LONGTEXT NOT NULL CHECK(JSON_VALID(facts_json)),
 facts_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_securities_debt_tenant(supplier_id,id),
 UNIQUE KEY uq_securities_debt_lot(supplier_id,lot_reference),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(acquired_on<maturity_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_securities_debt_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NOT NULL,
 operation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 accounting_date DATE NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 reversal_of BIGINT UNSIGNED NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_securities_debt_event_tenant(supplier_id,id),
 UNIQUE KEY uq_securities_debt_reversal(supplier_id,reversal_of),
 KEY idx_securities_debt_lot(supplier_id,lot_id,accounting_date,id),
 FOREIGN KEY(supplier_id,lot_id) REFERENCES accounting_securities_debt_lots(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,reversal_of) REFERENCES accounting_securities_debt_events(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 CHECK(operation IN ('accrual','amortisation','valuation','coupon_receipt','principal_redemption','reverse'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_securities_debt_claims (
 supplier_id INT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NULL,
 line_id BIGINT UNSIGNED NOT NULL,
 component VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_cents DECIMAL(30,0) NOT NULL,
 PRIMARY KEY(supplier_id,line_id),
 FOREIGN KEY(supplier_id,lot_id) REFERENCES accounting_securities_debt_lots(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,event_id) REFERENCES accounting_securities_debt_events(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT,
 CHECK(component IN ('acquisition','coupon_receipt','principal_redemption','fx_carry')),
 CHECK(amount_cents>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_securities_debt_commands (
 supplier_id INT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_json LONGTEXT NOT NULL CHECK(JSON_VALID(response_json)),
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 PRIMARY KEY(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_securities_debt_fx_carries (
 supplier_id INT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 slot VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 accounting_date DATE NOT NULL,
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(supplier_id,lot_id,period_id,slot),
 KEY idx_securities_debt_fx_entry(supplier_id,journal_entry_id),
 FOREIGN KEY(supplier_id,lot_id) REFERENCES accounting_securities_debt_lots(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(period_id) REFERENCES accounting_periods(id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 CHECK(slot IN ('saldo','reversal'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
