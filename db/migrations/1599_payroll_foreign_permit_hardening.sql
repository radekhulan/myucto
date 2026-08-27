-- MZ-04: konvergence staršího draftu 1598 a jednoznačný řetězec obnovení oprávnění.
SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_foreign_permit_authoritative_insert;
DROP TRIGGER IF EXISTS trg_payroll_foreign_permit_immutable_update;

ALTER TABLE payroll_person_foreign_permits
  ADD COLUMN IF NOT EXISTS document_supplier_id BIGINT UNSIGNED NULL AFTER valid_until;

UPDATE payroll_person_foreign_permits
   SET document_supplier_id = supplier_id
 WHERE document_supplier_id IS NULL;

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

DELIMITER ;

ALTER TABLE payroll_person_foreign_permits
  MODIFY COLUMN document_supplier_id BIGINT UNSIGNED NOT NULL,
  ADD KEY IF NOT EXISTS idx_payroll_foreign_permit_document_scope (document_supplier_id, document_id),
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_foreign_permit_predecessor (supplier_id, supersedes_permit_id),
  DROP FOREIGN KEY IF EXISTS fk_payroll_foreign_permit_document;

ALTER TABLE payroll_person_foreign_permits
  ADD CONSTRAINT fk_payroll_foreign_permit_document
    FOREIGN KEY IF NOT EXISTS (document_supplier_id, document_id)
    REFERENCES documents (supplier_id, id) ON DELETE RESTRICT;
