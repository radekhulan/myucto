-- MyÚčto.cz — MZ-21: business idempotence neměnných REGZEC událostí.

SET NAMES utf8mb4;

ALTER TABLE payroll_registration_event_snapshots
  MODIFY source_reference VARCHAR(191)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

DROP PROCEDURE IF EXISTS assert_payroll_registration_event_business_keys;

DELIMITER //

CREATE PROCEDURE assert_payroll_registration_event_business_keys()
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_registration_event_snapshots
     GROUP BY supplier_id, environment, employment_id, interaction_code,
              effective_on, source_reference
    HAVING COUNT(*) > 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll registration event business-key duplicates require manual resolution';
  END IF;
END//

DELIMITER ;

CALL assert_payroll_registration_event_business_keys();
DROP PROCEDURE IF EXISTS assert_payroll_registration_event_business_keys;

ALTER TABLE payroll_registration_event_snapshots
  ADD UNIQUE INDEX IF NOT EXISTS uq_payroll_registration_event_business (
    supplier_id, environment, employment_id, interaction_code, effective_on,
    source_reference
  );
