SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_tax_assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    previous_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL,
    reason TEXT NOT NULL,
    ruleset VARCHAR(80) NOT NULL,
    input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
    assessment_json LONGTEXT NOT NULL CHECK (JSON_VALID(assessment_json)),
    result_json LONGTEXT NOT NULL CHECK (JSON_VALID(result_json)),
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nonprofit_tax_revision (supplier_id, period_id, revision_no),
    UNIQUE KEY uq_nonprofit_tax_idempotency (supplier_id, idempotency_key),
    UNIQUE KEY uq_nonprofit_tax_tenant (supplier_id, id),
    UNIQUE KEY uq_nonprofit_tax_period_revision (supplier_id, period_id, id),
    CONSTRAINT chk_nonprofit_tax_status CHECK (status IN ('confirmed','withdrawn')),
    CONSTRAINT chk_nonprofit_tax_revision CHECK (revision_no > 0),
    CONSTRAINT fk_nonprofit_tax_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nonprofit_tax_period FOREIGN KEY (period_id) REFERENCES accounting_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_nonprofit_tax_previous FOREIGN KEY (supplier_id, period_id, previous_id) REFERENCES nonprofit_tax_assessments(supplier_id, period_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_nonprofit_tax_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nonprofit_tax_assessments DROP FOREIGN KEY IF EXISTS fk_nonprofit_tax_previous;
ALTER TABLE nonprofit_tax_assessments DROP INDEX IF EXISTS fk_nonprofit_tax_previous;
ALTER TABLE nonprofit_tax_assessments ADD UNIQUE KEY IF NOT EXISTS uq_nonprofit_tax_period_revision (supplier_id, period_id, id);
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nonprofit_tax_previous
    FOREIGN KEY (supplier_id, period_id, previous_id) REFERENCES nonprofit_tax_assessments(supplier_id, period_id, id) ON DELETE CASCADE;

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_nonprofit_tax_insert
BEFORE INSERT ON nonprofit_tax_assessments FOR EACH ROW
BEGIN
    DECLARE actual_supplier INT UNSIGNED;
    DECLARE actual_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SELECT supplier_id, status INTO actual_supplier, actual_status FROM accounting_periods WHERE id = NEW.period_id FOR UPDATE;
    IF actual_supplier IS NULL OR actual_supplier <> NEW.supplier_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_tax_period_not_found';
    END IF;
    IF actual_status NOT IN ('open','closing') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_tax_period_locked';
    END IF;
END$$
CREATE OR REPLACE TRIGGER trg_nonprofit_tax_immutable_update
BEFORE UPDATE ON nonprofit_tax_assessments FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_tax_assessment_immutable';
END$$
CREATE OR REPLACE TRIGGER trg_nonprofit_tax_immutable_delete
BEFORE DELETE ON nonprofit_tax_assessments FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_tax_assessment_immutable';
END$$
DELIMITER ;
