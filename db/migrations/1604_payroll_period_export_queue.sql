-- MyÚčto.cz — MZ-31-W06: durable asynchronní export mzdového období.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_period_export_jobs (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  export_scope          ENUM('monthly','annual') NOT NULL,
  period_start          DATE NOT NULL,
  period_end            DATE NOT NULL,
  status                ENUM('queued','processing','retry_wait','failed','completed') NOT NULL DEFAULT 'queued',
  attempt_count         INT UNSIGNED NOT NULL DEFAULT 0,
  available_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_token           BINARY(16) NULL,
  locked_at             DATETIME NULL,
  export_id             BIGINT UNSIGNED NULL,
  requested_by          BIGINT UNSIGNED NULL,
  last_error_code       VARCHAR(64) NULL,
  last_error_message    VARCHAR(500) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at            DATETIME NULL,
  completed_at          DATETIME NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_period_export_job_id (supplier_id, id),
  KEY idx_payroll_period_export_job_period (
    supplier_id, export_scope, period_start, period_end
  ),
  KEY idx_payroll_period_export_job_work (status, available_at, id),
  KEY idx_payroll_period_export_job_export (supplier_id, export_id),
  CONSTRAINT fk_payroll_period_export_job_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_period_export_job_export
    FOREIGN KEY (supplier_id, export_id)
    REFERENCES payroll_period_exports (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_period_export_job_requester
    FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_period_export_job_interval CHECK (
    period_end >= period_start
    AND (
      (export_scope = 'monthly' AND DAY(period_start) = 1 AND period_end = LAST_DAY(period_start))
      OR
      (export_scope = 'annual' AND MONTH(period_start) = 1 AND DAY(period_start) = 1
       AND MONTH(period_end) = 12 AND DAY(period_end) = 31 AND YEAR(period_start) = YEAR(period_end))
    )
  ),
  CONSTRAINT chk_payroll_period_export_job_lease CHECK (
    (status = 'processing' AND lease_token IS NOT NULL AND locked_at IS NOT NULL)
    OR (status <> 'processing' AND lease_token IS NULL AND locked_at IS NULL)
  ),
  CONSTRAINT chk_payroll_period_export_job_result CHECK (
    (status = 'completed' AND export_id IS NOT NULL AND completed_at IS NOT NULL)
    OR (status <> 'completed' AND export_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_period_export_job_attempts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  job_id                BIGINT UNSIGNED NOT NULL,
  attempt_no            INT UNSIGNED NOT NULL,
  lease_token           BINARY(16) NOT NULL,
  status                ENUM('running','succeeded','failed','stale') NOT NULL DEFAULT 'running',
  error_code            VARCHAR(64) NULL,
  error_message         VARCHAR(500) NULL,
  started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at           DATETIME NULL,

  UNIQUE KEY uq_payroll_period_export_job_attempt (supplier_id, job_id, attempt_no),
  KEY idx_payroll_period_export_job_attempt (supplier_id, job_id, started_at),
  CONSTRAINT fk_payroll_period_export_job_attempt_job
    FOREIGN KEY (supplier_id, job_id)
    REFERENCES payroll_period_export_jobs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_period_export_job_attempt_no CHECK (attempt_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
