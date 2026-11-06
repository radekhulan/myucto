-- Doložený rozsah sjednání příplatků hodinové mzdy v existujících verzovaných zásadách.
ALTER TABLE payroll_employment_surcharge_policies
    ADD COLUMN IF NOT EXISTS hourly_terms_json LONGTEXT NULL,
    DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_hourly_terms;
ALTER TABLE payroll_employment_surcharge_policies
    ADD CONSTRAINT chk_payroll_surcharge_hourly_terms CHECK (hourly_terms_json IS NULL OR JSON_VALID(hourly_terms_json));
