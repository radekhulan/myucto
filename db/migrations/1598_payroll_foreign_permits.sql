-- MZ-04: append-only, účinná historie pobytových a pracovních oprávnění.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_person_foreign_permits (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  employee_id              BIGINT UNSIGNED NOT NULL,
  permit_kind              ENUM('residence','work') NOT NULL,
  permit_label             VARCHAR(128) NOT NULL,
  issuing_country_code     CHAR(2) NOT NULL,
  effective_from           DATE NOT NULL,
  valid_until              DATE NOT NULL,
  document_supplier_id     BIGINT UNSIGNED NOT NULL,
  document_id              BIGINT UNSIGNED NOT NULL,
  document_sha256          CHAR(64) NOT NULL,
  supersedes_permit_id     BIGINT UNSIGNED NULL,
  recorded_by              BIGINT UNSIGNED NOT NULL,
  recorded_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_foreign_permit_supplier_id (supplier_id, id),
  KEY idx_payroll_foreign_permit_effective (supplier_id, employee_id, permit_kind, effective_from, valid_until),
  KEY idx_payroll_foreign_permit_document_scope (document_supplier_id, document_id),
  KEY idx_payroll_foreign_permit_predecessor (supplier_id, supersedes_permit_id),
  CONSTRAINT fk_payroll_foreign_permit_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_foreign_permit_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_foreign_permit_document
    FOREIGN KEY (document_supplier_id, document_id)
    REFERENCES documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_foreign_permit_predecessor
    FOREIGN KEY (supplier_id, supersedes_permit_id)
    REFERENCES payroll_person_foreign_permits (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_foreign_permit_recorded_by
    FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_foreign_permit_dates CHECK (valid_until >= effective_from),
  CONSTRAINT chk_payroll_foreign_permit_document_tenant CHECK (document_supplier_id = supplier_id),
  CONSTRAINT chk_payroll_foreign_permit_country CHECK (issuing_country_code REGEXP '^[A-Z]{2}$'),
  CONSTRAINT chk_payroll_foreign_permit_sha256 CHECK (document_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_foreign_permit_label CHECK (CHAR_LENGTH(TRIM(permit_label)) BETWEEN 3 AND 128)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_foreign_permit_authoritative_insert
BEFORE INSERT ON payroll_person_foreign_permits
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
     FROM documents document
     WHERE document.id = NEW.document_id
       AND document.supplier_id = NEW.document_supplier_id
       AND document.deleted_at IS NULL
       AND document.scope = 'company'
       AND document.sha256 = NEW.document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit document mismatch';
  END IF;

  IF NEW.supersedes_permit_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_person_foreign_permits predecessor
     WHERE predecessor.id = NEW.supersedes_permit_id
       AND predecessor.supplier_id = NEW.supplier_id
       AND predecessor.employee_id = NEW.employee_id
       AND predecessor.permit_kind = NEW.permit_kind
       AND NEW.effective_from > predecessor.effective_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit predecessor mismatch';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_person_foreign_permits existing
     WHERE existing.supplier_id = NEW.supplier_id
       AND existing.employee_id = NEW.employee_id
       AND existing.permit_kind = NEW.permit_kind
       AND existing.effective_from <= NEW.valid_until
       AND existing.valid_until >= NEW.effective_from
       AND (NEW.supersedes_permit_id IS NULL OR existing.id <> NEW.supersedes_permit_id)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit overlap requires predecessor';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_foreign_permit_immutable_update
BEFORE UPDATE ON payroll_person_foreign_permits
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll foreign permit is immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_foreign_permit_append_only_delete
BEFORE DELETE ON payroll_person_foreign_permits
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll foreign permit is append-only';
END//

DELIMITER ;
