-- Sjednaná hodinová sazba je samostatný údaj účinné verze podmínek vztahu.
-- Dosavadní částkové vstupy ani pravděpodobný výdělek neurčují způsob odměňování.
ALTER TABLE payroll_employment_terms
    ADD COLUMN IF NOT EXISTS remuneration_type ENUM('monthly', 'hourly') NOT NULL DEFAULT 'monthly',
    ADD COLUMN IF NOT EXISTS hourly_rate_minor BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS remuneration_basis VARCHAR(255) NULL,
    ADD CONSTRAINT IF NOT EXISTS chk_payroll_terms_remuneration CHECK (
        (remuneration_type = 'monthly' AND hourly_rate_minor IS NULL)
        OR (remuneration_type = 'hourly' AND hourly_rate_minor IS NOT NULL
            AND hourly_rate_minor BETWEEN 1 AND 1000000000 AND monthly_gross_minor IS NULL
            AND remuneration_basis IS NOT NULL AND CHAR_LENGTH(TRIM(remuneration_basis)) > 0)
    );
