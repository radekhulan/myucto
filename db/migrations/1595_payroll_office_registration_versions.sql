-- MyÚčto.cz — MZ-03: účinná, append-only registrace mzdové účtárny u ČSSZ.
-- Starý payroll_offices.social_security_variable_symbol se záměrně nebackfilluje:
-- jeho minulá účinnost není doložená a nesmí se vymyslet.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_office_registration_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  office_id BIGINT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  social_security_variable_symbol VARCHAR(10) NOT NULL,
  source_reference VARCHAR(500) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_office_registration_effective
    (supplier_id, office_id, effective_from),
  UNIQUE KEY uq_payroll_office_registration_supplier_id (supplier_id, id),
  KEY ix_payroll_office_registration_resolve
    (supplier_id, office_id, effective_from),
  CONSTRAINT fk_payroll_office_registration_office
    FOREIGN KEY (supplier_id, office_id)
    REFERENCES payroll_offices (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_office_registration_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_office_registration_symbol
    CHECK (social_security_variable_symbol REGEXP '^[0-9]{10}$'),
  CONSTRAINT chk_payroll_office_registration_source
    CHECK (CHAR_LENGTH(TRIM(source_reference)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

-- Effective intervals are represented by consecutive start dates.  A new
-- version must therefore extend the known timeline forward; inserting an
-- older date would silently rewrite the interval resolved for an already
-- prepared payroll period.
CREATE TRIGGER IF NOT EXISTS trg_payroll_office_registration_no_backdate
BEFORE INSERT ON payroll_office_registration_versions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_office_registration_versions
     WHERE supplier_id = NEW.supplier_id
       AND office_id = NEW.office_id
       AND effective_from >= NEW.effective_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll office registration effective interval overlaps existing version';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_office_registration_immutable_update
BEFORE UPDATE ON payroll_office_registration_versions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll office registration versions are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_office_registration_immutable_delete
BEFORE DELETE ON payroll_office_registration_versions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll office registration versions are append-only';
END//

DELIMITER ;
