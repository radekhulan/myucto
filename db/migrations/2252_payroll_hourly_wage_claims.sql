-- Neměnné podklady a výhradní zdroj nároku na mzdu z hodinové evidence.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_hourly_wage_claims (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  work_period_start DATE NOT NULL,
  purpose VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  claim_source ENUM('hourly_calculation','external_amount') NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hourly_wage_claim_id (supplier_id, id),
  UNIQUE KEY uq_hourly_wage_claim_employment (supplier_id, id, employment_id),
  UNIQUE KEY uq_hourly_wage_claim_scope (supplier_id, employment_id, work_period_start, purpose),
  CONSTRAINT fk_hourly_wage_claim_employment FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_wage_claim_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_hourly_wage_claim_period CHECK (DAY(work_period_start) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_hourly_wage_materializations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  claim_id BIGINT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  accounting_period_start DATE NOT NULL,
  run_id BIGINT UNSIGNED NOT NULL,
  input_id BIGINT UNSIGNED NULL,
  full_amount_minor BIGINT NOT NULL,
  delta_amount_minor BIGINT NOT NULL,
  evidence_hash BINARY(32) NOT NULL,
  source_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash BINARY(32) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hourly_wage_materialization_id (supplier_id, id),
  UNIQUE KEY uq_hourly_wage_materialization_sequence (supplier_id, claim_id, sequence_no),
  UNIQUE KEY uq_hourly_wage_materialization_input (supplier_id, input_id),
  KEY idx_hourly_wage_materialization_run (supplier_id, run_id),
  KEY idx_hourly_wage_materialization_employment (supplier_id, employment_id, accounting_period_start),
  CONSTRAINT fk_hourly_wage_materialization_claim FOREIGN KEY (supplier_id, claim_id, employment_id)
    REFERENCES payroll_hourly_wage_claims (supplier_id, id, employment_id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_wage_materialization_run FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_wage_materialization_input FOREIGN KEY (supplier_id, input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_wage_materialization_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_hourly_wage_materialization_period CHECK (DAY(accounting_period_start) = 1),
  CONSTRAINT chk_hourly_wage_materialization_sequence CHECK (sequence_no > 0),
  CONSTRAINT chk_hourly_wage_materialization_amount CHECK (full_amount_minor >= 0),
  CONSTRAINT chk_hourly_wage_materialization_input CHECK ((delta_amount_minor = 0 AND input_id IS NULL) OR (delta_amount_minor <> 0 AND input_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identita nároku je stálá; změny stavu a čerpání jsou samostatné revize.
CREATE TABLE IF NOT EXISTS payroll_hourly_time_entitlements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  entitlement_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind ENUM('holiday','overtime') NOT NULL,
  work_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hourly_time_entitlement_id (supplier_id, id),
  UNIQUE KEY uq_hourly_time_entitlement_owner (supplier_id, id, employment_id),
  UNIQUE KEY uq_hourly_time_entitlement_identity (supplier_id, employment_id, entitlement_key),
  KEY idx_hourly_time_entitlement_date (supplier_id, employment_id, work_date),
  CONSTRAINT fk_hourly_time_entitlement_employment FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_hourly_time_entitlement_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  entitlement_id BIGINT UNSIGNED NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  accounting_period_start DATE NOT NULL,
  command_key_hash BINARY(32) NOT NULL,
  accrued_duration_units BIGINT UNSIGNED NOT NULL,
  allocated_duration_units BIGINT UNSIGNED NOT NULL,
  accrued_delta_units BIGINT NOT NULL,
  allocated_delta_units BIGINT NOT NULL,
  due_on DATE NULL,
  source_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash BINARY(32) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hourly_time_entitlement_revision_id (supplier_id, id),
  UNIQUE KEY uq_hourly_time_entitlement_revision (supplier_id, entitlement_id, sequence_no),
  KEY idx_hourly_time_entitlement_revision_command (supplier_id, command_key_hash),
  KEY idx_hourly_time_entitlement_revision_employment (supplier_id, employment_id, accounting_period_start),
  CONSTRAINT fk_hourly_time_revision_entitlement FOREIGN KEY (supplier_id, entitlement_id, employment_id)
    REFERENCES payroll_hourly_time_entitlements (supplier_id, id, employment_id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_time_revision_employment FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_time_revision_actor FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_hourly_time_revision_period CHECK (DAY(accounting_period_start) = 1),
  CONSTRAINT chk_hourly_time_revision_sequence CHECK (sequence_no > 0),
  CONSTRAINT chk_hourly_time_revision_balance CHECK (allocated_duration_units <= accrued_duration_units)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payroll_hourly_included_consumption (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  scope_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  kind VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  accounting_period_start DATE NOT NULL,
  command_key_hash BINARY(32) NOT NULL,
  duration_units BIGINT UNSIGNED NOT NULL,
  source_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash BINARY(32) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hourly_included_consumption_id (supplier_id, id),
  UNIQUE KEY uq_hourly_included_consumption_revision (supplier_id, employment_id, scope_key, accounting_period_start, command_key_hash),
  KEY idx_hourly_included_consumption_period (supplier_id, employment_id, accounting_period_start),
  KEY idx_hourly_included_consumption_command (supplier_id, command_key_hash),
  CONSTRAINT fk_hourly_included_consumption_employment FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_included_consumption_actor FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_hourly_included_consumption_period CHECK (DAY(accounting_period_start) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE payroll_hourly_included_consumption MODIFY COLUMN scope_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Doba se může zúčtovat v jiném období než vznik práce; oprava nese rozdíl
-- přesných jednotek a nesmí změnit již zmrazený původní řádek.
ALTER TABLE payroll_hourly_wage_materializations
  ADD COLUMN IF NOT EXISTS full_duration_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS duration_delta_units BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS command_key_hash BINARY(32) NULL,
  ADD KEY IF NOT EXISTS idx_hourly_wage_materialization_command (supplier_id, command_key_hash);
ALTER TABLE payroll_hourly_wage_materializations DROP CONSTRAINT IF EXISTS chk_hourly_wage_materialization_command;
ALTER TABLE payroll_hourly_wage_materializations ADD CONSTRAINT chk_hourly_wage_materialization_command CHECK (command_key_hash IS NOT NULL);

-- Stejný zdrojový otisk nese i vstup, ze kterého se skládá zmrazený běh.
ALTER TABLE payroll_inputs DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot;
ALTER TABLE payroll_inputs ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
  (recurring_component_id IS NOT NULL AND source_kind = 'recurring'
    AND source_snapshot_json IS NOT NULL AND JSON_VALID(source_snapshot_json)
    AND source_snapshot_hash IS NOT NULL AND OCTET_LENGTH(source_snapshot_hash) = 32)
  OR (recurring_component_id IS NULL AND source_kind = 'travel' AND external_id LIKE 'travel:%'
    AND source_snapshot_json IS NOT NULL AND JSON_VALID(source_snapshot_json)
    AND source_snapshot_hash IS NOT NULL AND OCTET_LENGTH(source_snapshot_hash) = 32)
  OR (recurring_component_id IS NULL AND source_kind NOT IN ('recurring', 'travel') AND (
      (source_snapshot_json IS NULL AND source_snapshot_hash IS NULL
        AND NOT (source_kind = 'time' AND COALESCE(external_id, '') LIKE 'hourly-wage:%'))
      OR ((source_kind = 'manual' AND external_id LIKE 'quick-monthly:%')
        OR (source_kind IN ('absence', 'correction') AND external_id LIKE 'leave:%')
        OR (source_kind = 'time' AND external_id LIKE 'hourly-wage:%'))
        AND source_snapshot_json IS NOT NULL AND JSON_VALID(source_snapshot_json)
        AND source_snapshot_hash IS NOT NULL AND OCTET_LENGTH(source_snapshot_hash) = 32
  ))
);

DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_hourly_wage_materialization_no_update BEFORE UPDATE ON payroll_hourly_wage_materializations
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly wage materializations are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_hourly_wage_materialization_no_delete BEFORE DELETE ON payroll_hourly_wage_materializations
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly wage materializations are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_hourly_time_revision_no_update BEFORE UPDATE ON payroll_hourly_time_entitlement_revisions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly time entitlement revisions are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_hourly_time_revision_no_delete BEFORE DELETE ON payroll_hourly_time_entitlement_revisions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly time entitlement revisions are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_hourly_included_consumption_no_update BEFORE UPDATE ON payroll_hourly_included_consumption
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly included time consumption is immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_hourly_included_consumption_no_delete BEFORE DELETE ON payroll_hourly_included_consumption
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly included time consumption is immutable'; END//
DELIMITER ;
