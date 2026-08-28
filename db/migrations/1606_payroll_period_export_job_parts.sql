-- MyÚčto.cz — MZ-31-W06: independently leased, resumable archive parts.

SET NAMES utf8mb4;

ALTER TABLE payroll_period_export_jobs
  ADD COLUMN IF NOT EXISTS failure_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempt_count;

CREATE TABLE IF NOT EXISTS payroll_period_export_job_parts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  job_id                BIGINT UNSIGNED NOT NULL,
  part_key              CHAR(64) NOT NULL,
  part_kind             ENUM('document','submission_artifact','submission_protocol','archive') NOT NULL,
  source_id             BIGINT UNSIGNED NOT NULL,
  source_sha256         CHAR(64) NULL,
  source_size_bytes     BIGINT UNSIGNED NULL,
  status                ENUM('queued','processing','retry_wait','failed','completed') NOT NULL DEFAULT 'queued',
  attempt_count         INT UNSIGNED NOT NULL DEFAULT 0,
  available_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_token           BINARY(16) NULL,
  locked_at             DATETIME NULL,
  storage_key           CHAR(64) NULL,
  last_error_code       VARCHAR(64) NULL,
  last_error_message    VARCHAR(500) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at          DATETIME NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_period_export_part_id (supplier_id, id),
  UNIQUE KEY uq_payroll_period_export_part_key (supplier_id, job_id, part_key),
  UNIQUE KEY uq_payroll_period_export_part_source (supplier_id, job_id, part_kind, source_id),
  KEY idx_payroll_period_export_part_work (status, available_at, id),
  KEY idx_payroll_period_export_part_job (supplier_id, job_id, status, id),
  CONSTRAINT fk_payroll_period_export_part_job
    FOREIGN KEY (supplier_id, job_id)
    REFERENCES payroll_period_export_jobs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_period_export_part_source CHECK (
    (part_kind = 'archive' AND source_id = 0 AND source_sha256 IS NULL
      AND source_size_bytes IS NULL)
    OR
    (part_kind <> 'archive' AND source_id > 0
      AND source_sha256 REGEXP '^[0-9a-f]{64}$' AND source_size_bytes > 0)
  ),
  CONSTRAINT chk_payroll_period_export_part_lease CHECK (
    (status = 'processing' AND lease_token IS NOT NULL AND locked_at IS NOT NULL)
    OR (status <> 'processing' AND lease_token IS NULL AND locked_at IS NULL)
  ),
  CONSTRAINT chk_payroll_period_export_part_result CHECK (
    (status = 'completed' AND completed_at IS NOT NULL
      AND ((part_kind = 'archive' AND storage_key IS NULL)
        OR (part_kind <> 'archive' AND storage_key = source_sha256)))
    OR (status <> 'completed' AND completed_at IS NULL AND storage_key IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_period_export_job_parts
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_period_export_part_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS payroll_period_export_job_part_attempts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  job_part_id           BIGINT UNSIGNED NOT NULL,
  attempt_no            INT UNSIGNED NOT NULL,
  lease_token           BINARY(16) NOT NULL,
  status                ENUM('running','succeeded','failed','stale') NOT NULL DEFAULT 'running',
  error_code            VARCHAR(64) NULL,
  error_message         VARCHAR(500) NULL,
  started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at           DATETIME NULL,

  UNIQUE KEY uq_payroll_period_export_part_attempt (supplier_id, job_part_id, attempt_no),
  KEY idx_payroll_period_export_part_attempt (supplier_id, job_part_id, started_at),
  CONSTRAINT fk_payroll_period_export_part_attempt_part
    FOREIGN KEY (supplier_id, job_part_id)
    REFERENCES payroll_period_export_job_parts (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_period_export_part_attempt_no CHECK (attempt_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
