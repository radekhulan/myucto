SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS nonprofit_funding_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    previous_id BIGINT UNSIGNED NULL,
    parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    policy_json LONGTEXT NOT NULL CHECK (JSON_VALID(policy_json)),
    basis_json LONGTEXT NOT NULL CHECK (JSON_VALID(basis_json)),
    request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
    reason TEXT NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nfr_revision (supplier_id,source_key,revision_no),
    UNIQUE KEY uq_nfr_token (supplier_id,idempotency_key),
    UNIQUE KEY uq_nfr_tenant (supplier_id,id),
    UNIQUE KEY uq_nfr_chain (supplier_id,source_key,id),
    CONSTRAINT chk_nfr_revision CHECK (revision_no > 0),
    CONSTRAINT fk_nfr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nfr_period FOREIGN KEY (supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfr_provider FOREIGN KEY (supplier_id,provider_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfr_assessment FOREIGN KEY (supplier_id,assessment_id) REFERENCES nonprofit_tax_assessments(supplier_id,id) ON DELETE RESTRICT,
    CONSTRAINT fk_nfr_previous FOREIGN KEY (supplier_id,source_key,previous_id) REFERENCES nonprofit_funding_revisions(supplier_id,source_key,id) ON DELETE CASCADE,
    CONSTRAINT fk_nfr_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE nonprofit_funding_revisions ADD COLUMN IF NOT EXISTS request_json LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(request_json));
DELIMITER $$
CREATE OR REPLACE TRIGGER trg_nfr_insert BEFORE INSERT ON nonprofit_funding_revisions FOR EACH ROW
BEGIN
    DECLARE period_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SELECT status INTO period_status FROM accounting_periods WHERE supplier_id=NEW.supplier_id AND id=NEW.period_id FOR UPDATE;
    IF period_status IS NULL OR period_status NOT IN ('open','closing') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_funding_period_locked';
    END IF;
END$$
CREATE OR REPLACE TRIGGER trg_nfr_immutable_update BEFORE UPDATE ON nonprofit_funding_revisions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_funding_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_nfr_immutable_delete BEFORE DELETE ON nonprofit_funding_revisions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_funding_immutable';
END$$
DELIMITER ;
