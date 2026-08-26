-- MyUcto.cz - oddelene identifikatory financni spravy pro REGZEL.
--
-- EPO pouziva trimestny c_ufo v supplier.financial_office_code. REGZEL ma
-- vlastni ctyrmistne kodFU a volitelny kodPracovisteFU; jejich zamena
-- zneplatnovala jinak spravne REGZEL XML.

SET NAMES utf8mb4;

ALTER TABLE payroll_regzel_employer_profiles
  ADD COLUMN IF NOT EXISTS tax_office_code CHAR(4) CHARACTER SET ascii
    COLLATE ascii_bin NULL
    COMMENT 'REGZEL kodFU z ciselniku GFR, oddeleny od EPO c_ufo'
    AFTER protected_labor_market,
  ADD COLUMN IF NOT EXISTS tax_office_workplace_code CHAR(4)
    CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'REGZEL kodPracovisteFU z ciselniku GFR, povinny mimo SFU 4000'
    AFTER tax_office_code;

ALTER TABLE payroll_regzel_employer_profiles
  DROP CONSTRAINT IF EXISTS chk_payroll_regzel_tax_office_codes;
ALTER TABLE payroll_regzel_employer_profiles
  ADD CONSTRAINT chk_payroll_regzel_tax_office_codes CHECK (
    (tax_office_code IS NULL OR tax_office_code REGEXP '^([2-6][0-9]{3}|7000)$')
    AND (tax_office_workplace_code IS NULL
      OR tax_office_workplace_code REGEXP '^([2-6][0-9]{3}|7000)$')
  );
