-- Pracovní evidence čerpání dotací a účelových darů (#403).
-- Průběžná vrstva vedle potvrzených zdrojů (nonprofit_funding_revisions) a J6: přiřazení výnosových
-- a nákladových řádků deníku ke zdroji v průběhu roku. Vazba na řádek neblokuje účetnictví:
-- přeúčtování řádek smaže a vazba ztratí line_id (ON DELETE SET NULL), služba ji podle snímku
-- ukáže ke kontrole. linked_line_id drží původní id řádku (bez FK) pro unikátnost aktivních vazeb;
-- entry_source_type/id drží doklad zápisu pro „Navázat znovu“ po odúčtování a novém zaúčtování dokladu.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_funding_work_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('operating_grant','investment_grant','gift') NOT NULL,
    title VARCHAR(255) NOT NULL,
    contract_ref VARCHAR(100) NULL,
    budget_cents BIGINT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NOT NULL,
    activity_kind_ids LONGTEXT NOT NULL CHECK (JSON_VALID(activity_kind_ids)),
    account_prefixes LONGTEXT NOT NULL CHECK (JSON_VALID(account_prefixes)),
    legacy_source_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_nfws_tenant (supplier_id, id),
    KEY idx_nfws_period (supplier_id, period_id, status),
    KEY idx_nfws_provider (supplier_id, provider_id),
    CONSTRAINT chk_nfws_budget CHECK (budget_cents > 0),
    CONSTRAINT chk_nfws_range CHECK (valid_from <= valid_to),
    CONSTRAINT fk_nfws_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nfws_period FOREIGN KEY (supplier_id, period_id) REFERENCES accounting_periods(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfws_provider FOREIGN KEY (supplier_id, provider_id) REFERENCES clients(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfws_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfws_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_funding_work_incomes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NULL,
    linked_line_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    entry_source_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entry_source_id BIGINT UNSIGNED NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    amount_cents BIGINT NOT NULL,
    entry_date DATE NOT NULL,
    status ENUM('active','removed') NOT NULL DEFAULT 'active',
    active_line_id BIGINT UNSIGNED AS (IF(status = 'active', linked_line_id, NULL)) STORED,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_nfwi_tenant (supplier_id, id),
    UNIQUE KEY uq_nfwi_active_line (supplier_id, active_line_id),
    KEY idx_nfwi_source (supplier_id, source_id, status),
    KEY idx_nfwi_line (line_id),
    CONSTRAINT fk_nfwi_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nfwi_source FOREIGN KEY (supplier_id, source_id) REFERENCES nonprofit_funding_work_sources(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwi_line FOREIGN KEY (line_id) REFERENCES journal_entry_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_nfwi_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwi_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_funding_work_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NULL,
    linked_line_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    entry_source_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entry_source_id BIGINT UNSIGNED NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    line_amount_cents BIGINT NOT NULL,
    entry_date DATE NOT NULL,
    credit_notes_seen INT UNSIGNED NOT NULL DEFAULT 0,
    amount_cents BIGINT UNSIGNED NOT NULL,
    note VARCHAR(500) NULL,
    status ENUM('active','removed') NOT NULL DEFAULT 'active',
    active_line_id BIGINT UNSIGNED AS (IF(status = 'active', linked_line_id, NULL)) STORED,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_nfwa_tenant (supplier_id, id),
    UNIQUE KEY uq_nfwa_active_line (supplier_id, source_id, active_line_id),
    KEY idx_nfwa_source (supplier_id, source_id, status),
    KEY idx_nfwa_cost_line (supplier_id, linked_line_id, status),
    KEY idx_nfwa_line (line_id),
    CONSTRAINT chk_nfwa_amount CHECK (amount_cents > 0),
    CONSTRAINT fk_nfwa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nfwa_source FOREIGN KEY (supplier_id, source_id) REFERENCES nonprofit_funding_work_sources(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwa_line FOREIGN KEY (line_id) REFERENCES journal_entry_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_nfwa_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwa_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_funding_work_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    income_id BIGINT UNSIGNED NULL,
    allocation_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    before_json LONGTEXT NULL CHECK (before_json IS NULL OR JSON_VALID(before_json)),
    after_json LONGTEXT NULL CHECK (after_json IS NULL OR JSON_VALID(after_json)),
    reason TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nfwh_tenant (supplier_id, id),
    KEY idx_nfwh_source (supplier_id, source_id, id),
    CONSTRAINT fk_nfwh_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nfwh_source FOREIGN KEY (supplier_id, source_id) REFERENCES nonprofit_funding_work_sources(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwh_income FOREIGN KEY (supplier_id, income_id) REFERENCES nonprofit_funding_work_incomes(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwh_allocation FOREIGN KEY (supplier_id, allocation_id) REFERENCES nonprofit_funding_work_allocations(supplier_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfwh_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_nfwh_immutable_update BEFORE UPDATE ON nonprofit_funding_work_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_funding_work_history_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_nfwh_immutable_delete BEFORE DELETE ON nonprofit_funding_work_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_funding_work_history_immutable';
END$$
DELIMITER ;
