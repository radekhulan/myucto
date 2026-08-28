-- MyÚčto.cz — MZ-21: neměnné zdroje navazujících interakcí REGZEC A2–A8.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_registration_event_snapshots (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  interaction_code      VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_code           TINYINT UNSIGNED NOT NULL,
  effective_on          DATE NOT NULL,
  source_kind           VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_reference      VARCHAR(191) NOT NULL,
  source_manifest_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_ciphertext   LONGTEXT NOT NULL,
  snapshot_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  approved_by           BIGINT UNSIGNED NOT NULL,
  approved_at           DATETIME NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_registration_event_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_registration_event_source (
    supplier_id, environment, employment_id, source_manifest_hash
  ),
  KEY idx_payroll_registration_event_employment (
    supplier_id, environment, employment_id, action_code, effective_on, id
  ),
  CONSTRAINT fk_payroll_registration_event_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_event_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_registration_event_interaction CHECK (
    (interaction_code = 'termination' AND action_code = 2)
    OR (interaction_code = 'change' AND action_code = 3)
    OR (interaction_code = 'correction' AND action_code = 4)
    OR (interaction_code = 'variable_symbol_transfer' AND action_code = 5)
    OR (interaction_code = 'czech_legislation_start' AND action_code = 6)
    OR (interaction_code = 'czech_legislation_end' AND action_code = 7)
    OR (interaction_code = 'cancellation' AND action_code = 8)
  ),
  CONSTRAINT chk_payroll_registration_event_source_kind CHECK (
    source_kind IN ('employment_exit','verified_change','verified_correction',
                    'employer_transfer','jurisdiction_evidence','verified_cancellation')
  ),
  CONSTRAINT chk_payroll_registration_event_hashes CHECK (
    source_manifest_hash REGEXP '^[0-9a-f]{64}$'
    AND snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_registration_event_ciphertext CHECK (
    snapshot_ciphertext LIKE 'enc:v2:%'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_registration_event_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_registration_event_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_registration_event_immutable_update
BEFORE UPDATE ON payroll_registration_event_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll registration event snapshots are immutable';
END//

CREATE TRIGGER trg_payroll_registration_event_immutable_delete
BEFORE DELETE ON payroll_registration_event_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll registration event snapshots are append-only';
END//

DELIMITER ;
