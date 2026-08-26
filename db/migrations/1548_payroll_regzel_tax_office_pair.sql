-- MyUcto.cz - presna vazba financniho uradu a jeho pracoviste v REGZEL.
--
-- kodFU je kod financniho uradu z ciselniku GFŘ, nikoli libovolny kod jeho
-- pracoviste. kodPracovisteFU je povinny s vyjimkou SFU (kodFU 4000).

SET NAMES utf8mb4;

ALTER TABLE payroll_regzel_employer_profiles
  DROP CONSTRAINT IF EXISTS chk_payroll_regzel_tax_office_codes;
ALTER TABLE payroll_regzel_employer_profiles
  ADD CONSTRAINT chk_payroll_regzel_tax_office_codes CHECK (
    (
      tax_office_code IS NULL
      AND tax_office_workplace_code IS NULL
    )
    OR (
      tax_office_code IN (
        '2000', '2100', '2200', '2300', '2400', '2500', '2600', '2700',
        '2800', '2900', '3000', '3100', '3200', '3300', '4000'
      )
      AND (
        (tax_office_code = '4000' AND tax_office_workplace_code IS NULL)
        OR (tax_office_code <> '4000' AND tax_office_workplace_code IS NOT NULL)
      )
      AND (
        tax_office_workplace_code IS NULL
        OR tax_office_workplace_code REGEXP '^[2-6][0-9]{3}$'
      )
    )
  );
