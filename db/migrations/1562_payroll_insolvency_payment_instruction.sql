-- MZ-14: neměnný platební pokyn pro standardní oddlužení.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_insolvency_payment_instructions (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                     INT UNSIGNED NOT NULL,
  employee_id                     BIGINT UNSIGNED NOT NULL,
  employment_id                   BIGINT UNSIGNED NOT NULL,
  period_start                    DATE NOT NULL,
  institution_account_id          BIGINT UNSIGNED NOT NULL,
  institution_account_row_version INT UNSIGNED NOT NULL,
  institution_account_hash        CHAR(64) NOT NULL,
  institution_type                VARCHAR(32) NOT NULL,
  institution_code                VARCHAR(32) NOT NULL,
  decision_document_id            BIGINT UNSIGNED NOT NULL,
  decision_document_hash          CHAR(64) NOT NULL,
  instruction_hash                CHAR(64) NOT NULL,
  created_by                      BIGINT UNSIGNED NOT NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_insolvency_instruction_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_insolvency_instruction_hash
    (supplier_id, instruction_hash),
  KEY ix_payroll_insolvency_instruction_scope
    (supplier_id, employee_id, employment_id, period_start, id),
  KEY ix_payroll_insolvency_instruction_account
    (supplier_id, institution_account_id),
  KEY ix_payroll_insolvency_instruction_document (decision_document_id),
  CONSTRAINT fk_payroll_insolvency_instruction_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_insolvency_instruction_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_insolvency_instruction_account
    FOREIGN KEY (supplier_id, institution_account_id)
    REFERENCES payroll_institution_accounts (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_insolvency_instruction_document
    FOREIGN KEY (decision_document_id) REFERENCES documents (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_insolvency_instruction_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_insolvency_instruction_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_insolvency_instruction_account_hash
    CHECK (institution_account_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_insolvency_instruction_document_hash
    CHECK (decision_document_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_insolvency_instruction_hash
    CHECK (instruction_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_insolvency_instruction_type
    CHECK (institution_type = 'other_recipient')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_enforcement_person_month_evidence
  ADD COLUMN IF NOT EXISTS insolvency_payment_instruction_id BIGINT UNSIGNED NULL
    AFTER insolvency_recipient_verified,
  ADD KEY IF NOT EXISTS ix_payroll_enforcement_month_insolvency_instruction
    (supplier_id, insolvency_payment_instruction_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_enforcement_month_insolvency_instruction
    (supplier_id, insolvency_payment_instruction_id)
    REFERENCES payroll_insolvency_payment_instructions (supplier_id, id)
    ON DELETE RESTRICT;

ALTER TABLE payroll_enforcement_month_results
  ADD COLUMN IF NOT EXISTS insolvency_payment_instruction_id BIGINT UNSIGNED NULL
    AFTER employee_id,
  ADD KEY IF NOT EXISTS ix_payroll_enforcement_result_insolvency_instruction
    (supplier_id, insolvency_payment_instruction_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_enforcement_result_insolvency_instruction
    (supplier_id, insolvency_payment_instruction_id)
    REFERENCES payroll_insolvency_payment_instructions (supplier_id, id)
    ON DELETE RESTRICT;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_insolvency_instruction_insert
BEFORE INSERT ON payroll_insolvency_payment_instructions
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_employments employment
     WHERE employment.supplier_id = NEW.supplier_id
       AND employment.id = NEW.employment_id
       AND employment.employee_id = NEW.employee_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll insolvency instruction employment mismatch';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM payroll_institution_accounts account
      JOIN payroll_institutions institution
        ON institution.supplier_id = account.supplier_id
       AND institution.id = account.institution_id
     WHERE account.supplier_id = NEW.supplier_id
       AND account.id = NEW.institution_account_id
       AND account.row_version = NEW.institution_account_row_version
       AND LOWER(HEX(account.bank_account_hash)) = NEW.institution_account_hash
       AND account.currency_code = 'CZK'
       AND account.verified_by IS NOT NULL
       AND account.verified_on IS NOT NULL
       AND institution.institution_type = NEW.institution_type
       AND institution.institution_code = NEW.institution_code
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll insolvency instruction payment target mismatch';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.supplier_id = NEW.supplier_id
       AND document.id = NEW.decision_document_id
       AND document.sha256 = NEW.decision_document_hash
       AND document.deleted_at IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll insolvency instruction decision document mismatch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_insolvency_instruction_immutable_update
BEFORE UPDATE ON payroll_insolvency_payment_instructions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll insolvency payment instructions are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_insolvency_instruction_immutable_delete
BEFORE DELETE ON payroll_insolvency_payment_instructions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll insolvency payment instructions are append-only';
END//

DROP TRIGGER IF EXISTS trg_documents_payroll_enforcement_evidence_update//

CREATE TRIGGER trg_documents_payroll_enforcement_evidence_update
BEFORE UPDATE ON documents
FOR EACH ROW
BEGIN
  IF (
    EXISTS (
      SELECT 1
        FROM payroll_enforcement_case_documents case_document
       WHERE case_document.dms_document_id = OLD.id
    )
    OR EXISTS (
      SELECT 1
        FROM payroll_insolvency_payment_instructions instruction
       WHERE instruction.decision_document_id = OLD.id
    )
  ) AND (
    NOT (NEW.supplier_id <=> OLD.supplier_id)
    OR NOT (NEW.sha256 <=> OLD.sha256)
    OR NOT (NEW.deleted_at <=> OLD.deleted_at)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document is retained as payroll enforcement evidence';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_enforcement_result_revision_insert//

CREATE TRIGGER trg_payroll_enforcement_result_revision_insert
BEFORE INSERT ON payroll_enforcement_month_results
FOR EACH ROW
BEGIN
  IF NEW.revision_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
      JOIN payroll_runs run
        ON run.supplier_id = revision.supplier_id
       AND run.id = revision.run_id
      JOIN payroll_run_persons person
        ON person.supplier_id = revision.supplier_id
       AND person.revision_id = revision.id
       AND person.employee_id = NEW.employee_id
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.revision_id
       AND run.period_start = NEW.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement result does not match run person and period';
  END IF;

  IF NEW.insolvency_payment_instruction_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_insolvency_payment_instructions instruction
     WHERE instruction.supplier_id = NEW.supplier_id
       AND instruction.id = NEW.insolvency_payment_instruction_id
       AND instruction.employee_id = NEW.employee_id
       AND instruction.period_start = NEW.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement result insolvency instruction mismatch';
  END IF;
END//

DELIMITER ;
