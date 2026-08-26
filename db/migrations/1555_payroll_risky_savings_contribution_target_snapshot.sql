-- MZ-08-W04: platební závazek musí vycházet ze stejného ověřeného účtu,
-- který byl zmrazen při schválení mzdové revize.

SET NAMES utf8mb4;

ALTER TABLE payroll_risky_savings_contributions
  ADD COLUMN IF NOT EXISTS institution_account_row_version INT UNSIGNED NULL
    AFTER institution_account_id,
  ADD COLUMN IF NOT EXISTS institution_account_hash CHAR(64) NULL
    AFTER institution_account_row_version,
  ADD COLUMN IF NOT EXISTS institution_account_masked VARCHAR(191) NULL
    AFTER institution_account_hash,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_risky_savings_contribution_account_hash
    CHECK (
      institution_account_hash IS NULL
      OR institution_account_hash REGEXP '^[0-9a-f]{64}$'
    );

