-- MyÚčto.cz — MZ-21: aktuální REGZEC relDetail pro přímé druhy činnosti.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_jmhz_relationship_detail;

UPDATE payroll_employment_terms
   SET jmhz_relationship_detail_code = '1'
 WHERE activity_code IS NOT NULL
   AND activity_code <> '10'
   AND activity_code NOT REGEXP '^[1-9]$'
   AND jmhz_relationship_detail_code IS NULL;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_jmhz_relationship_detail CHECK (
    jmhz_relationship_detail_code IS NULL
    OR (
      activity_code IN ('1','2','3','4','5','6','7','8','9')
      AND jmhz_relationship_detail_code IN ('1','2','3')
    )
    OR (
      activity_code IS NOT NULL
      AND activity_code <> '10'
      AND activity_code NOT REGEXP '^[1-9]$'
      AND jmhz_relationship_detail_code = '1'
    )
  );
