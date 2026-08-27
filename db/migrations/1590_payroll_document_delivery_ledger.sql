-- MyÚčto.cz — MZ-16-W05: auditovatelná evidence předání mzdových dokumentů.
--
-- Ledger nese pouze typ události, vazbu na tenant/osobu/dokument a čas aktéra.
-- Neobsahuje PDF, e-mailovou adresu ani krátkodobý download token.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_document_delivery_events (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  payroll_document_id   BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  event_type            ENUM('handover','downloaded','external_notification') NOT NULL,
  recorded_by           BIGINT UNSIGNED NULL,
  occurred_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_delivery_event_id (supplier_id, id),
  KEY idx_payroll_document_delivery_document (supplier_id, payroll_document_id, occurred_at, id),
  KEY idx_payroll_document_delivery_employee (supplier_id, employee_id, occurred_at, id),
  CONSTRAINT fk_payroll_document_delivery_document
    FOREIGN KEY (supplier_id, payroll_document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_delivery_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_delivery_user
    FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_delivery_tenant_person_insert
BEFORE INSERT ON payroll_document_delivery_events
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_generated_documents document
     WHERE document.supplier_id = NEW.supplier_id
       AND document.id = NEW.payroll_document_id
       AND document.employee_id = NEW.employee_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll document delivery tenant or person mismatch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_delivery_immutable_update
BEFORE UPDATE ON payroll_document_delivery_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll document delivery events are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_delivery_immutable_delete
BEFORE DELETE ON payroll_document_delivery_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll document delivery events are append-only';
END//

DELIMITER ;
