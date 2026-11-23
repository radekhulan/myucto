-- Náhradní volno a pozdější vyplacení příplatku jsou odlišná vypořádání.
ALTER TABLE payroll_hourly_time_entitlement_revisions
  ADD COLUMN IF NOT EXISTS settled_duration_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS settled_delta_units BIGINT NOT NULL DEFAULT 0,
  DROP CONSTRAINT IF EXISTS chk_hourly_time_revision_balance;
ALTER TABLE payroll_hourly_time_entitlement_revisions
  ADD CONSTRAINT chk_hourly_time_revision_balance
  CHECK (allocated_duration_units + settled_duration_units <= accrued_duration_units);
