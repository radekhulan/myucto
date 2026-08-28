-- MyÚčto.cz — MZ-24: autoritativní, verzovaný profil REGZEC A1.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_registration_a1_profiles (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  employee_id         BIGINT UNSIGNED NOT NULL,
  employment_id       BIGINT UNSIGNED NOT NULL,
  effective_on        DATE NOT NULL,
  profile_ciphertext  LONGTEXT NOT NULL,
  profile_hash        BINARY(32) NOT NULL,
  reference_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_version         INT UNSIGNED NOT NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_registration_a1_profile_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_registration_a1_profile_version
    (supplier_id, employment_id, row_version),
  UNIQUE KEY uq_payroll_registration_a1_profile_reference
    (supplier_id, employment_id, reference_hash),
  KEY idx_payroll_registration_a1_profile_current
    (supplier_id, employment_id, row_version, id),
  CONSTRAINT fk_payroll_registration_a1_profile_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_a1_profile_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_registration_a1_profile_version
    CHECK (row_version > 0),
  CONSTRAINT chk_payroll_registration_a1_profile_ciphertext
    CHECK (profile_ciphertext LIKE 'enc:v2:%'),
  CONSTRAINT chk_payroll_registration_a1_profile_reference
    CHECK (reference_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_registration_a1_profile_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_registration_a1_profile_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_registration_a1_profile_immutable_update
BEFORE UPDATE ON payroll_registration_a1_profiles
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll REGZEC A1 profile versions are immutable';
END//

CREATE TRIGGER trg_payroll_registration_a1_profile_immutable_delete
BEFORE DELETE ON payroll_registration_a1_profiles
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll REGZEC A1 profile versions are append-only';
END//

DELIMITER ;

