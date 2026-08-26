CREATE TABLE IF NOT EXISTS payroll_risky_savings_evidence (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employment_id               BIGINT UNSIGNED NOT NULL,
  period_start                DATE NOT NULL,
  qualifying_shift_eighths    INT UNSIGNED NOT NULL,
  right_claimed_on            DATE NOT NULL,
  pension_company             VARCHAR(190) NOT NULL,
  product_reference           VARCHAR(190) NOT NULL,
  payment_reference           VARCHAR(190) NOT NULL,
  evidence_reference          VARCHAR(500) NULL,
  status                      ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  approved_at                 TIMESTAMP NULL,
  approved_by                 INT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_risky_savings_evidence_period
    (supplier_id, employment_id, period_start),
  UNIQUE KEY uq_payroll_risky_savings_evidence_supplier_id (supplier_id, id),
  KEY ix_payroll_risky_savings_evidence_period_status
    (supplier_id, period_start, status, employment_id),
  CONSTRAINT fk_payroll_risky_savings_evidence_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_risky_savings_evidence_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_risky_savings_evidence_eighths
    CHECK (qualifying_shift_eighths <= 2480)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_risky_savings_contributions
  ADD COLUMN IF NOT EXISTS revision_id BIGINT UNSIGNED NULL AFTER supplier_id,
  ADD COLUMN IF NOT EXISTS source_evidence_id BIGINT UNSIGNED NULL AFTER employment_id,
  ADD COLUMN IF NOT EXISTS qualifying_shift_eighths INT UNSIGNED NULL AFTER qualifying_shifts,
  ADD COLUMN IF NOT EXISTS right_claimed_on DATE NULL AFTER contribution_minor,
  ADD COLUMN IF NOT EXISTS pension_company VARCHAR(190) NULL AFTER right_claimed_on,
  ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(190) NULL AFTER product_reference,
  ADD COLUMN IF NOT EXISTS payment_due_on DATE NULL AFTER payment_reference,
  ADD COLUMN IF NOT EXISTS paid_on DATE NULL AFTER payment_due_on;

ALTER TABLE payroll_risky_savings_contributions
  DROP INDEX IF EXISTS uq_payroll_risky_savings_period,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_risky_savings_revision_employment
    (supplier_id, revision_id, employment_id),
  ADD KEY IF NOT EXISTS ix_payroll_risky_savings_employment
    (supplier_id, employment_id),
  ADD KEY IF NOT EXISTS ix_payroll_risky_savings_due
    (supplier_id, status, payment_due_on),
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_risky_savings_revision
    (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_risky_savings_source_evidence
    (supplier_id, source_evidence_id)
    REFERENCES payroll_risky_savings_evidence (supplier_id, id) ON DELETE RESTRICT;

UPDATE payroll_component_definitions
   SET tax_treatment = 'exempt',
       value_kind = 'non_monetary',
       social_participation_treatment = 'excluded',
       social_treatment = 'excluded',
       health_participation_treatment = 'excluded',
       health_treatment = 'excluded',
       average_earning_treatment = 'excluded',
       enforcement_treatment = 'excluded',
       jmhz_treatment = 'included',
       statistics_treatment = 'included',
       exemption_basket = 'old_age_savings',
       exemption_basis = 'benefit_basket',
       row_version = row_version + 1
 WHERE code = 'PRISPEVEK_RIZIKOVE_SPORENI'
   AND valid_from = '2026-01-01'
   AND (
     tax_treatment <> 'exempt'
     OR value_kind <> 'non_monetary'
     OR social_participation_treatment <> 'excluded'
     OR social_treatment <> 'excluded'
     OR health_participation_treatment <> 'excluded'
     OR health_treatment <> 'excluded'
     OR average_earning_treatment <> 'excluded'
     OR enforcement_treatment <> 'excluded'
     OR jmhz_treatment <> 'included'
     OR statistics_treatment <> 'included'
     OR exemption_basket IS NULL
     OR exemption_basket <> 'old_age_savings'
     OR exemption_basis IS NULL
     OR exemption_basis <> 'benefit_basket'
   );
