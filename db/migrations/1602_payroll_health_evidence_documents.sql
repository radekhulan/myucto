-- MyÚčto.cz — DMS důkaz účinné historie zdravotního pojištění.
-- Textové reference zůstávají dohledávacími metadaty; nový důkaz je vždy
-- tenantově ověřený aktivní dokument se serverem zjištěným SHA-256 otiskem.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_health_coverage_history
  ADD COLUMN IF NOT EXISTS health_evidence_document_id BIGINT UNSIGNED NULL
    AFTER insurer_evidence_reference,
  ADD COLUMN IF NOT EXISTS health_evidence_document_sha256 CHAR(64) NULL
    AFTER health_evidence_document_id,
  ADD KEY IF NOT EXISTS idx_pp_health_coverage_evidence_document
    (supplier_id, health_evidence_document_id);

ALTER TABLE payroll_person_health_coverage_history
  DROP FOREIGN KEY IF EXISTS fk_pp_health_coverage_evidence_document;
ALTER TABLE payroll_person_health_coverage_history
  ADD CONSTRAINT fk_pp_health_coverage_evidence_document
    FOREIGN KEY (health_evidence_document_id) REFERENCES documents (id) ON DELETE RESTRICT;

DROP TRIGGER IF EXISTS trg_pp_health_coverage_evidence_insert;
DROP TRIGGER IF EXISTS trg_pp_health_coverage_evidence_update;

DELIMITER //

CREATE TRIGGER trg_pp_health_coverage_evidence_insert
BEFORE INSERT ON payroll_person_health_coverage_history
FOR EACH ROW
BEGIN
  IF (NEW.health_evidence_document_id IS NULL) <> (NEW.health_evidence_document_sha256 IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence document and SHA-256 must be recorded together';
  END IF;
  IF NEW.health_evidence_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM documents document
     WHERE document.id = NEW.health_evidence_document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.health_evidence_document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence requires an active tenant DMS document with matching SHA-256';
  END IF;
END//

CREATE TRIGGER trg_pp_health_coverage_evidence_update
BEFORE UPDATE ON payroll_person_health_coverage_history
FOR EACH ROW
BEGIN
  IF (NEW.health_evidence_document_id IS NULL) <> (NEW.health_evidence_document_sha256 IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence document and SHA-256 must be recorded together';
  END IF;
  IF OLD.health_evidence_document_id IS NOT NULL
     AND NOT (NEW.health_evidence_document_id <=> OLD.health_evidence_document_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence document is immutable once attached';
  END IF;
  IF OLD.health_evidence_document_sha256 IS NOT NULL
     AND NOT (NEW.health_evidence_document_sha256 <=> OLD.health_evidence_document_sha256) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence SHA-256 is immutable once attached';
  END IF;
  IF NEW.health_evidence_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM documents document
     WHERE document.id = NEW.health_evidence_document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.health_evidence_document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence requires an active tenant DMS document with matching SHA-256';
  END IF;
END//

DELIMITER ;
