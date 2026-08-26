-- MyUcto.cz - vlastni cislo platce jako samostatny nepovinny udaj REGZEL.
--
-- VCP ma podle REGZELDOPL25 presne devet cislic. Neni totozne s registracnim
-- cislem zamestnavatele ani s desetimistnym variabilnim symbolem CSSZ.

SET NAMES utf8mb4;

ALTER TABLE payroll_regzel_employer_profiles
  ADD COLUMN IF NOT EXISTS payer_reference_number CHAR(9)
    CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'Volitelne REGZEL vcp 600000000-699999999'
    AFTER tax_office_workplace_code;

ALTER TABLE payroll_regzel_employer_profiles
  DROP CONSTRAINT IF EXISTS chk_payroll_regzel_payer_reference_number;
ALTER TABLE payroll_regzel_employer_profiles
  ADD CONSTRAINT chk_payroll_regzel_payer_reference_number CHECK (
    payer_reference_number IS NULL
    OR payer_reference_number REGEXP '^6[0-9]{8}$'
  );
