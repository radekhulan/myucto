-- MyÚčto.cz — MZ-31-W06: kompatibilní doplnění počítadla po sobě jdoucích selhání.

SET NAMES utf8mb4;

ALTER TABLE payroll_period_export_jobs
  ADD COLUMN IF NOT EXISTS failure_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempt_count;
