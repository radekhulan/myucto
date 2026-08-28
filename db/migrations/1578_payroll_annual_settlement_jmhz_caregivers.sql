-- JMHZ 10441-10445: roční evidence jiné osoby ve společné domácnosti,
-- která uplatňovala daňové zvýhodnění na stejné vyživované dítě.
--
-- Stav je součástí téže optimisticky zamykané žádosti o roční zúčtování.
-- Samostatné osoby se ukládají atomicky s žádostí a při zúčtování se zmrazí
-- do neměnného ročního snapshotu; JMHZ je proto později nečte z živé tabulky.

SET NAMES utf8mb4;

ALTER TABLE payroll_annual_settlement_requests
  ADD COLUMN IF NOT EXISTS other_household_caregiver_status
    ENUM('unknown','none','present') NOT NULL DEFAULT 'unknown'
    AFTER annual_claims_note;

CREATE TABLE IF NOT EXISTS payroll_annual_settlement_other_caregivers (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED NOT NULL,
  request_id       BIGINT UNSIGNED NOT NULL,
  position         SMALLINT UNSIGNED NOT NULL,
  given_name       VARCHAR(100) NOT NULL,
  family_name      VARCHAR(100) NOT NULL,
  birth_date       DATE NOT NULL,
  months_mask      CHAR(12) NOT NULL
                   COMMENT 'JMHZ 10445: leden až prosinec, A = ano, N = ne.',
  created_by       BIGINT UNSIGNED NULL,
  updated_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_other_caregiver_position
    (supplier_id, request_id, position),
  KEY fk_payroll_annual_settlement_other_caregiver_creator (created_by),
  KEY fk_payroll_annual_settlement_other_caregiver_editor (updated_by),

  CONSTRAINT fk_payroll_annual_settlement_other_caregiver_request
    FOREIGN KEY (supplier_id, request_id)
    REFERENCES payroll_annual_settlement_requests (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_annual_settlement_other_caregiver_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_annual_settlement_other_caregiver_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_annual_settlement_other_caregiver_position
    CHECK (position BETWEEN 1 AND 100),
  CONSTRAINT chk_payroll_annual_settlement_other_caregiver_months
    CHECK (months_mask REGEXP '^[AN]{12}$' AND months_mask LIKE '%A%'),
  CONSTRAINT chk_payroll_annual_settlement_other_caregiver_names
    CHECK (CHAR_LENGTH(TRIM(given_name)) > 0 AND CHAR_LENGTH(TRIM(family_name)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
