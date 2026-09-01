ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS monthly_gross_minor BIGINT UNSIGNED NULL
    COMMENT 'sjednaná měsíční hrubá mzda platná pro tuto verzi podmínek'
    AFTER fixed_term_end_on;

UPDATE payroll_employment_terms terms
JOIN payroll_employments employment
  ON employment.supplier_id = terms.supplier_id
 AND employment.id = terms.employment_id
SET terms.monthly_gross_minor = employment.monthly_gross_minor
WHERE terms.monthly_gross_minor IS NULL
  AND employment.monthly_gross_minor IS NOT NULL;
