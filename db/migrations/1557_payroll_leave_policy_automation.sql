-- MZ-03-W04 + MZ-07-W01: účinná výměra dovolené a dohledatelný automatický nárok.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_policies
  ADD COLUMN IF NOT EXISTS leave_entitlement_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 4
    AFTER travel_expense_policy,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_employer_policy_leave_weeks
    CHECK (leave_entitlement_weeks BETWEEN 4 AND 12);

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS leave_entitlement_weeks_override SMALLINT UNSIGNED NULL
    AFTER weekly_hours,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_employment_term_leave_weeks
    CHECK (
      leave_entitlement_weeks_override IS NULL
      OR leave_entitlement_weeks_override BETWEEN 4 AND 12
    );

ALTER TABLE payroll_leave_entitlement_snapshots
  MODIFY COLUMN support_status ENUM('manual_review','supported') NOT NULL DEFAULT 'manual_review',
  ADD COLUMN IF NOT EXISTS calculation_mode ENUM('manual','automatic') NOT NULL DEFAULT 'manual'
    AFTER support_status,
  ADD COLUMN IF NOT EXISTS source_snapshot_hash BINARY(32) NULL
    AFTER calculation_mode,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_leave_snapshot_automatic_source
    CHECK (calculation_mode = 'manual' OR source_snapshot_hash IS NOT NULL);
