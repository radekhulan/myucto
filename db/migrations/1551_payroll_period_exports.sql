-- MZ-31-W04/W05: neměnné měsíční a roční exporty mezd a jednorázové stahování.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_period_exports (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  export_scope          ENUM('monthly','annual') NOT NULL,
  period_start          DATE NOT NULL,
  period_end            DATE NOT NULL,
  source_manifest_hash  CHAR(64) NOT NULL,
  manifest_json         LONGTEXT NOT NULL CHECK (JSON_VALID(manifest_json)),
  file_sha256           CHAR(64) NOT NULL,
  size_bytes            BIGINT UNSIGNED NOT NULL,
  mime_type             VARCHAR(96) NOT NULL DEFAULT 'application/zip',
  storage_key           CHAR(64) NOT NULL,
  suggested_filename    VARCHAR(160) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_period_export_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_period_export_source (
    supplier_id, export_scope, period_start, period_end, source_manifest_hash
  ),
  KEY idx_payroll_period_export_period (
    supplier_id, export_scope, period_start, period_end, id
  ),
  CONSTRAINT fk_payroll_period_export_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_period_export_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_period_export_interval CHECK (
    period_end >= period_start
    AND (
      (
        export_scope = 'monthly'
        AND DAY(period_start) = 1
        AND period_end = LAST_DAY(period_start)
      )
      OR
      (
        export_scope = 'annual'
        AND MONTH(period_start) = 1
        AND DAY(period_start) = 1
        AND MONTH(period_end) = 12
        AND DAY(period_end) = 31
        AND YEAR(period_start) = YEAR(period_end)
      )
    )
  ),
  CONSTRAINT chk_payroll_period_export_hashes CHECK (
    source_manifest_hash REGEXP '^[0-9a-f]{64}$'
    AND file_sha256 REGEXP '^[0-9a-f]{64}$'
    AND storage_key = file_sha256
  ),
  CONSTRAINT chk_payroll_period_export_file CHECK (
    size_bytes > 0
    AND mime_type = 'application/zip'
    AND suggested_filename REGEXP '^[a-z0-9][a-z0-9._-]{0,159}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_period_export_download_grants (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  export_id             BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  token_hash            BINARY(32) NOT NULL,
  created_at            DATETIME(6) NOT NULL,
  expires_at            DATETIME(6) NOT NULL,
  used_at               DATETIME(6) NULL,

  UNIQUE KEY uq_payroll_period_export_grant_token (token_hash),
  KEY idx_payroll_period_export_grant (
    supplier_id, export_id, user_id, expires_at
  ),
  CONSTRAINT fk_payroll_period_export_grant_export
    FOREIGN KEY (supplier_id, export_id)
    REFERENCES payroll_period_exports (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_period_export_grant_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_period_export_grant_interval CHECK (
    expires_at > created_at AND (used_at IS NULL OR used_at >= created_at)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_period_export_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_period_export_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_period_export_immutable_update
BEFORE UPDATE ON payroll_period_exports
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll period exports are immutable';
END//

CREATE TRIGGER trg_payroll_period_export_immutable_delete
BEFORE DELETE ON payroll_period_exports
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll period exports are append-only';
END//

DELIMITER ;
