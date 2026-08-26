-- MyÚčto.cz — MZ-27-W10: byte-level vazba produkční kvalifikace na firemní DMS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_production_qualification_documents (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  qualification_id  BIGINT UNSIGNED NOT NULL,
  evidence_key      VARCHAR(64) NOT NULL,
  sequence_no       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  document_id       BIGINT UNSIGNED NOT NULL,
  document_sha256   CHAR(64) NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_qualification_document_slot
    (qualification_id, evidence_key, sequence_no),
  KEY idx_payroll_qualification_document_supplier
    (supplier_id, qualification_id),
  KEY idx_payroll_qualification_document_dms
    (supplier_id, document_id),
  CONSTRAINT fk_payroll_qualification_document_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_qualification_document_qualification
    FOREIGN KEY (qualification_id) REFERENCES payroll_production_qualifications (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_qualification_document_dms
    FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_qualification_document_hash CHECK (
    document_sha256 REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_qualification_document_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_qualification_document_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_qualification_document_immutable_update
BEFORE UPDATE ON payroll_production_qualification_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll production qualification document is immutable';
END//

CREATE TRIGGER trg_payroll_qualification_document_immutable_delete
BEFORE DELETE ON payroll_production_qualification_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll production qualification document is immutable';
END//

DELIMITER ;
