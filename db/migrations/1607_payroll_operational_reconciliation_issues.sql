-- MyÚčto.cz — MZ-27: stable tenant-scoped operational reconciliation issues.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_operational_reconciliation_issues (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  current_revision_id   BIGINT UNSIGNED NOT NULL,
  period_start          DATE NOT NULL,
  issue_key             VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope                 ENUM('posting','payment','health','jmhz') NOT NULL,
  category              VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status                ENUM('open','resolved') NOT NULL DEFAULT 'open',
  finding_state         ENUM('diff','blocked','not_materialized') NOT NULL,
  expected_minor        BIGINT NULL,
  actual_minor          BIGINT NULL,
  difference_minor      BIGINT NULL,
  source_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_hash           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  first_seen_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  resolved_at           DATETIME(6) NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                          ON UPDATE CURRENT_TIMESTAMP(6),

  UNIQUE KEY uq_payroll_operational_reconciliation_issue_id (supplier_id, id),
  UNIQUE KEY uq_payroll_operational_reconciliation_issue_key (
    supplier_id, run_id, issue_key
  ),
  KEY idx_payroll_operational_reconciliation_open (
    supplier_id, status, finding_state, period_start, id
  ),
  KEY idx_payroll_operational_reconciliation_revision (
    supplier_id, current_revision_id
  ),
  CONSTRAINT fk_payroll_operational_reconciliation_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_operational_reconciliation_revision
    FOREIGN KEY (supplier_id, current_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_operational_reconciliation_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_operational_reconciliation_key
    CHECK (issue_key REGEXP '^[a-z0-9][a-z0-9._:-]{0,190}$'),
  CONSTRAINT chk_payroll_operational_reconciliation_category
    CHECK (category REGEXP '^[a-z0-9][a-z0-9._:-]{0,95}$'),
  CONSTRAINT chk_payroll_operational_reconciliation_hash
    CHECK (source_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_operational_reconciliation_amounts CHECK (
    (expected_minor IS NULL AND actual_minor IS NULL AND difference_minor IS NULL)
    OR
    (expected_minor IS NOT NULL AND actual_minor IS NOT NULL
      AND difference_minor = expected_minor - actual_minor)
  ),
  CONSTRAINT chk_payroll_operational_reconciliation_resolution CHECK (
    (status = 'open' AND resolved_at IS NULL)
    OR (status = 'resolved' AND resolved_at IS NOT NULL)
  ),
  CONSTRAINT chk_payroll_operational_reconciliation_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_operational_reconciliation_issue_events (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  issue_id              BIGINT UNSIGNED NOT NULL,
  transition_kind       ENUM('detected','observed','resolved','reopened') NOT NULL,
  from_status           ENUM('open','resolved') NULL,
  to_status             ENUM('open','resolved') NOT NULL,
  finding_state         ENUM('diff','blocked','not_materialized') NOT NULL,
  expected_minor        BIGINT NULL,
  actual_minor          BIGINT NULL,
  difference_minor      BIGINT NULL,
  source_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_hash           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  occurred_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  UNIQUE KEY uq_payroll_operational_reconciliation_event_id (supplier_id, id),
  KEY idx_payroll_operational_reconciliation_event_issue (
    supplier_id, issue_id, occurred_at, id
  ),
  CONSTRAINT fk_payroll_operational_reconciliation_event_issue
    FOREIGN KEY (supplier_id, issue_id)
    REFERENCES payroll_operational_reconciliation_issues (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_operational_reconciliation_event_hash
    CHECK (source_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_operational_reconciliation_event_amounts CHECK (
    (expected_minor IS NULL AND actual_minor IS NULL AND difference_minor IS NULL)
    OR
    (expected_minor IS NOT NULL AND actual_minor IS NOT NULL
      AND difference_minor = expected_minor - actual_minor)
  ),
  CONSTRAINT chk_payroll_operational_reconciliation_event_transition CHECK (
    (transition_kind = 'detected' AND from_status IS NULL AND to_status = 'open')
    OR (transition_kind = 'observed' AND from_status = 'open' AND to_status = 'open')
    OR (transition_kind = 'resolved' AND from_status = 'open' AND to_status = 'resolved')
    OR (transition_kind = 'reopened' AND from_status = 'resolved' AND to_status = 'open')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
