-- Samostatné posouzení mzdy pro § 111 bez změny daňové klasifikace nebo starých snímků.
ALTER TABLE payroll_component_definitions
    ADD COLUMN IF NOT EXISTS minimum_wage_treatment ENUM('included', 'excluded', 'manual_review') NULL,
    ADD COLUMN IF NOT EXISTS minimum_wage_reason VARCHAR(500) NULL;
