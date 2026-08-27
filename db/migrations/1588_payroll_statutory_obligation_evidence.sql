-- MyÚčto.cz — MZ-24 P0: neměnný důkaz ručně splněné zákonné povinnosti.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_statutory_obligation_evidence (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  environment                ENUM('production','test') NOT NULL,
  agenda_code                ENUM('NEMPRI','HZUPN','STATUTORY_ACCIDENT_INSURANCE') NOT NULL,
  employee_id                BIGINT UNSIGNED NULL,
  period_start               DATE NOT NULL,
  period_end                 DATE NOT NULL,
  case_reference             VARCHAR(128) NOT NULL,
  receipt_reference          VARCHAR(128) NOT NULL,
  completed_on               DATE NOT NULL,
  payment_amount_minor       BIGINT UNSIGNED NULL,
  payment_currency           CHAR(3) NULL,
  document_id                BIGINT UNSIGNED NOT NULL,
  document_sha256            CHAR(64) NOT NULL,
  capability_matrix_version  VARCHAR(64) NOT NULL,
  capability_matrix_sha256   CHAR(64) NOT NULL,
  attestation_version        VARCHAR(64) NOT NULL,
  request_fingerprint        CHAR(64) NOT NULL,
  idempotency_key_hash       BINARY(32) NOT NULL,
  created_by                 BIGINT UNSIGNED NOT NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_stat_obligation_evidence_supplier_id (
    supplier_id, id
  ),
  UNIQUE KEY uq_payroll_stat_obligation_evidence_idempotency (
    supplier_id, environment, idempotency_key_hash
  ),
  KEY idx_payroll_stat_obligation_evidence_period (
    supplier_id, environment, period_start, agenda_code, created_at
  ),
  KEY idx_payroll_stat_obligation_evidence_employee (
    supplier_id, employee_id, period_start
  ),
  KEY idx_payroll_stat_obligation_evidence_document (
    supplier_id, document_id
  ),
  CONSTRAINT fk_payroll_stat_obligation_evidence_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_stat_obligation_evidence_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_stat_obligation_evidence_document
    FOREIGN KEY (document_id)
    REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_stat_obligation_evidence_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_stat_obligation_evidence_period CHECK (
    period_end >= period_start
  ),
  CONSTRAINT chk_payroll_stat_obligation_evidence_hashes CHECK (
    document_sha256 REGEXP '^[0-9a-f]{64}$'
    AND capability_matrix_sha256 REGEXP '^[0-9a-f]{64}$'
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_stat_obligation_evidence_references CHECK (
    CHAR_LENGTH(TRIM(case_reference)) > 0
    AND CHAR_LENGTH(TRIM(receipt_reference)) > 0
  ),
  CONSTRAINT chk_payroll_stat_obligation_evidence_subject CHECK (
    (
      agenda_code IN ('NEMPRI','HZUPN')
      AND employee_id IS NOT NULL
      AND payment_amount_minor IS NULL
      AND payment_currency IS NULL
    ) OR (
      agenda_code = 'STATUTORY_ACCIDENT_INSURANCE'
      AND employee_id IS NULL
      AND payment_amount_minor > 0
      AND payment_currency = 'CZK'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_statutory_obligation_evidence
  MODIFY COLUMN agenda_code
    ENUM('NEMPRI','HZUPN','STATUTORY_ACCIDENT_INSURANCE') NOT NULL,
  MODIFY COLUMN employee_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS payment_amount_minor BIGINT UNSIGNED NULL
    AFTER completed_on,
  ADD COLUMN IF NOT EXISTS payment_currency CHAR(3) NULL
    AFTER payment_amount_minor;

ALTER TABLE payroll_statutory_obligation_evidence
  DROP CONSTRAINT IF EXISTS chk_payroll_stat_obligation_evidence_subject;

ALTER TABLE payroll_statutory_obligation_evidence
  ADD CONSTRAINT chk_payroll_stat_obligation_evidence_subject CHECK (
    (
      agenda_code IN ('NEMPRI','HZUPN')
      AND employee_id IS NOT NULL
      AND payment_amount_minor IS NULL
      AND payment_currency IS NULL
    ) OR (
      agenda_code = 'STATUTORY_ACCIDENT_INSURANCE'
      AND employee_id IS NULL
      AND payment_amount_minor > 0
      AND payment_currency = 'CZK'
    )
  );

DROP TRIGGER IF EXISTS trg_payroll_stat_obligation_evidence_tenant_insert;
DROP TRIGGER IF EXISTS trg_payroll_stat_obligation_evidence_no_update;
DROP TRIGGER IF EXISTS trg_payroll_stat_obligation_evidence_no_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_stat_obligation_evidence_tenant_insert
BEFORE INSERT ON payroll_statutory_obligation_evidence
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.id = NEW.document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.scope = 'company'
       AND document.sha256 = NEW.document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll statutory obligation evidence document mismatch';
  END IF;
END//

CREATE TRIGGER trg_payroll_stat_obligation_evidence_no_update
BEFORE UPDATE ON payroll_statutory_obligation_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory obligation evidence is immutable';
END//

CREATE TRIGGER trg_payroll_stat_obligation_evidence_no_delete
BEFORE DELETE ON payroll_statutory_obligation_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory obligation evidence is append-only';
END//

DELIMITER ;
