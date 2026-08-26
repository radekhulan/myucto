-- MyÚčto.cz — MZ-27-W10: explicitní produkční kvalifikace firmy.

SET NAMES utf8mb4;

ALTER TABLE payroll_module_state
  MODIFY COLUMN status
    ENUM('disabled','setup','qualification_required','active','suspended')
    NOT NULL DEFAULT 'disabled';

ALTER TABLE payroll_module_state
  DROP CONSTRAINT IF EXISTS chk_payroll_module_state_start;

ALTER TABLE payroll_module_state
  ADD CONSTRAINT chk_payroll_module_state_start CHECK (
    (status = 'disabled' AND start_period IS NULL)
    OR (
      status IN ('setup','qualification_required','active','suspended')
      AND start_period IS NOT NULL
    )
  );

CREATE TABLE IF NOT EXISTS payroll_production_qualifications (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  module_state_row_version INT UNSIGNED NOT NULL,
  support_matrix_version  VARCHAR(64) NOT NULL,
  support_matrix_sha256   CHAR(64) NOT NULL,
  evidence_json           LONGTEXT NOT NULL CHECK (JSON_VALID(evidence_json)),
  evidence_sha256         CHAR(64) NOT NULL,
  qualified_by            BIGINT UNSIGNED NOT NULL,
  qualified_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_production_qualification_supplier (supplier_id),
  CONSTRAINT fk_payroll_production_qualification_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_production_qualification_user
    FOREIGN KEY (qualified_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_production_qualification_hashes CHECK (
    support_matrix_sha256 REGEXP '^[0-9a-f]{64}$'
    AND evidence_sha256 REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_production_qualifications
  DROP FOREIGN KEY IF EXISTS fk_payroll_production_qualification_user;

ALTER TABLE payroll_production_qualifications
  MODIFY COLUMN qualified_by BIGINT UNSIGNED NOT NULL;

ALTER TABLE payroll_production_qualifications
  ADD CONSTRAINT fk_payroll_production_qualification_user
    FOREIGN KEY (qualified_by) REFERENCES users (id) ON DELETE RESTRICT;

-- Starý stav `active` vznikal automaticky po setup-checku nebo prvním approve,
-- tedy bez důkazu MZ-27-W10. Žádná ruční kvalifikační cesta dříve neexistovala.
-- Historii nevracíme do `setup`: explicitní mezistav zachová informaci, že firma
-- už mzdové běhy má, ale nepředstírá splněnou produkční kvalifikaci.
UPDATE payroll_module_state
   SET status = 'qualification_required',
       activated_by = NULL,
       activated_at = NULL,
       row_version = row_version + 1
 WHERE status = 'active'
   AND NOT EXISTS (
     SELECT 1
       FROM payroll_production_qualifications qualification
      WHERE qualification.supplier_id = payroll_module_state.supplier_id
   );

UPDATE payroll_module_state
   SET activated_by = NULL,
       activated_at = NULL
 WHERE status IN ('disabled', 'setup', 'qualification_required');

DROP TRIGGER IF EXISTS trg_payroll_production_qualification_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_production_qualification_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_production_qualification_immutable_update
BEFORE UPDATE ON payroll_production_qualifications
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll production qualification is immutable';
END//

CREATE TRIGGER trg_payroll_production_qualification_immutable_delete
BEFORE DELETE ON payroll_production_qualifications
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll production qualification is immutable';
END//

DELIMITER ;
