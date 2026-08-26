-- MZ-08-W04: zákonné kvalifikační znaky, informační povinnost,
-- strukturovaný platební cíl a verzovaná neměnná evidence.

SET NAMES utf8mb4;

ALTER TABLE payroll_risky_savings_evidence
  ADD COLUMN IF NOT EXISTS revision_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER period_start,
  ADD COLUMN IF NOT EXISTS risk_factor ENUM(
    'vibration','cold','heat','dynamic_physical_load'
  ) NOT NULL DEFAULT 'vibration' AFTER revision_no,
  ADD COLUMN IF NOT EXISTS work_category TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER risk_factor,
  ADD COLUMN IF NOT EXISTS employee_informed_on DATE NULL AFTER right_claimed_on,
  ADD COLUMN IF NOT EXISTS institution_account_id BIGINT UNSIGNED NULL AFTER pension_company,
  ADD COLUMN IF NOT EXISTS variable_symbol VARCHAR(10) NULL AFTER product_reference,
  ADD COLUMN IF NOT EXISTS specific_symbol VARCHAR(10) NULL AFTER variable_symbol,
  ADD COLUMN IF NOT EXISTS payment_message VARCHAR(190) NULL AFTER specific_symbol,
  ADD COLUMN IF NOT EXISTS superseded_at TIMESTAMP NULL AFTER approved_by,
  ADD COLUMN IF NOT EXISTS superseded_by BIGINT UNSIGNED NULL AFTER superseded_at,
  MODIFY COLUMN approved_by BIGINT UNSIGNED NULL,
  MODIFY COLUMN superseded_by BIGINT UNSIGNED NULL,
  MODIFY COLUMN payment_reference VARCHAR(190) NULL,
  MODIFY COLUMN status ENUM('draft','approved','superseded') NOT NULL DEFAULT 'draft';

ALTER TABLE payroll_risky_savings_evidence
  DROP INDEX IF EXISTS uq_payroll_risky_savings_evidence_period,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_risky_savings_evidence_revision
    (supplier_id, employment_id, period_start, revision_no),
  ADD KEY IF NOT EXISTS ix_payroll_risky_savings_evidence_account
    (supplier_id, institution_account_id),
  ADD KEY IF NOT EXISTS ix_payroll_risky_savings_evidence_superseded_by
    (superseded_by),
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_risky_savings_evidence_account
    (supplier_id, institution_account_id)
    REFERENCES payroll_institution_accounts (supplier_id, id) ON DELETE RESTRICT,
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_risky_savings_evidence_superseded_by
    (superseded_by) REFERENCES users (id) ON DELETE SET NULL,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_risky_savings_category
    CHECK (work_category = 3);

ALTER TABLE payroll_risky_savings_contributions
  ADD COLUMN IF NOT EXISTS institution_account_id BIGINT UNSIGNED NULL AFTER pension_company,
  ADD COLUMN IF NOT EXISTS variable_symbol VARCHAR(10) NULL AFTER product_reference,
  ADD COLUMN IF NOT EXISTS specific_symbol VARCHAR(10) NULL AFTER variable_symbol,
  ADD COLUMN IF NOT EXISTS payment_message VARCHAR(190) NULL AFTER specific_symbol,
  ADD KEY IF NOT EXISTS ix_payroll_risky_savings_contribution_account
    (supplier_id, institution_account_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_risky_savings_contribution_account
    (supplier_id, institution_account_id)
    REFERENCES payroll_institution_accounts (supplier_id, id) ON DELETE RESTRICT;
