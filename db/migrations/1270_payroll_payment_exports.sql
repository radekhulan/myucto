-- MyÚčto.cz — MZ-17: neměnný content-addressed archiv platebních exportů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_payment_exports (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  export_format         ENUM('abo','sepa','csv','pdf','manual') NOT NULL,
  export_revision_no    INT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_export_id  BIGINT UNSIGNED NULL,
  source_snapshot_hash  CHAR(64) NOT NULL,
  exporter_version      VARCHAR(64) NOT NULL,
  file_sha256           CHAR(64) NOT NULL,
  size_bytes            BIGINT UNSIGNED NOT NULL,
  mime_type             VARCHAR(96) NOT NULL,
  storage_key           CHAR(64) NOT NULL,
  suggested_filename    VARCHAR(160) NOT NULL,
  manifest_json         LONGTEXT NULL
    CHECK (manifest_json IS NULL OR JSON_VALID(manifest_json)),
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_export_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_payment_export_revision (
    supplier_id, batch_id, export_format, export_revision_no
  ),
  UNIQUE KEY uq_payroll_payment_export_supersedes (
    supplier_id, supersedes_export_id
  ),
  UNIQUE KEY uq_payroll_payment_export_idempotency (
    supplier_id, idempotency_key_hash
  ),
  KEY idx_payroll_payment_export_batch (
    supplier_id, batch_id, created_at
  ),
  KEY idx_payroll_payment_export_content (
    supplier_id, storage_key
  ),
  KEY idx_payroll_payment_export_creator (created_by),
  CONSTRAINT fk_payroll_payment_export_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_payment_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_export_supersedes
    FOREIGN KEY (supplier_id, supersedes_export_id)
    REFERENCES payroll_payment_exports (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_export_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_payment_export_revision CHECK (
    export_revision_no > 0
  ),
  CONSTRAINT chk_payroll_payment_export_hashes CHECK (
    source_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND file_sha256 REGEXP '^[0-9a-f]{64}$'
    AND storage_key = file_sha256
  ),
  CONSTRAINT chk_payroll_payment_export_size CHECK (size_bytes > 0),
  CONSTRAINT chk_payroll_payment_export_filename CHECK (
    suggested_filename REGEXP '^[a-z0-9][a-z0-9._-]{0,159}$'
  ),
  CONSTRAINT chk_payroll_payment_export_metadata CHECK (
    exporter_version REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}$'
    AND CHAR_LENGTH(mime_type) BETWEEN 3 AND 96
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payment_export_download_grants (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  export_id             BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  token_hash            BINARY(32) NOT NULL,
  expires_at            DATETIME NOT NULL,
  used_at               DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_export_grant_token (token_hash),
  KEY idx_payroll_payment_export_grant_export (
    supplier_id, export_id, expires_at
  ),
  KEY idx_payroll_payment_export_grant_user (
    user_id, expires_at
  ),
  KEY idx_payroll_payment_export_grant_expiry (
    expires_at, used_at
  ),
  CONSTRAINT fk_payroll_payment_export_grant_export
    FOREIGN KEY (supplier_id, export_id)
    REFERENCES payroll_payment_exports (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_export_grant_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_payment_export_grant_ttl CHECK (
    TIMESTAMPDIFF(SECOND, created_at, expires_at) BETWEEN 30 AND 900
  ),
  CONSTRAINT chk_payroll_payment_export_grant_used CHECK (
    used_at IS NULL OR used_at BETWEEN created_at AND expires_at
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_payment_export_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_payment_export_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_export_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_payment_export_grant_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_payment_export_grant_consume_update;

DELIMITER //

CREATE TRIGGER trg_payroll_payment_export_validate_insert
BEFORE INSERT ON payroll_payment_exports
FOR EACH ROW
BEGIN
  DECLARE batch_snapshot_hash CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE batch_export_format VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE previous_batch_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE previous_export_format VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE previous_revision_no INT UNSIGNED DEFAULT NULL;

  SELECT batch.snapshot_hash, batch.export_format
    INTO batch_snapshot_hash, batch_export_format
    FROM payroll_payment_batches batch
   WHERE batch.supplier_id = NEW.supplier_id
     AND batch.id = NEW.batch_id;

  IF batch_snapshot_hash IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export batch does not belong to supplier';
  END IF;
  IF NEW.source_snapshot_hash <> batch_snapshot_hash THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export source snapshot differs from batch';
  END IF;
  IF NEW.export_format <> batch_export_format THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export format differs from batch';
  END IF;
  IF NEW.storage_key <> NEW.file_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export content address is invalid';
  END IF;

  IF NEW.export_revision_no = 1 THEN
    IF NEW.supersedes_export_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export first revision cannot supersede another export';
    END IF;
  ELSE
    IF NEW.supersedes_export_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export revision requires its predecessor';
    END IF;

    SELECT previous.batch_id,
           previous.export_format,
           previous.export_revision_no
      INTO previous_batch_id,
           previous_export_format,
           previous_revision_no
      FROM payroll_payment_exports previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.supersedes_export_id;

    IF previous_batch_id IS NULL
      OR previous_batch_id <> NEW.batch_id
      OR previous_export_format <> NEW.export_format
      OR previous_revision_no + 1 <> NEW.export_revision_no
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export revision chain is inconsistent';
    END IF;
  END IF;
END//

CREATE TRIGGER trg_payroll_payment_export_immutable_update
BEFORE UPDATE ON payroll_payment_exports
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment exports are immutable';
END//

CREATE TRIGGER trg_payroll_payment_export_immutable_delete
BEFORE DELETE ON payroll_payment_exports
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment exports are immutable';
END//

CREATE TRIGGER trg_payroll_payment_export_grant_validate_insert
BEFORE INSERT ON payroll_payment_export_download_grants
FOR EACH ROW
BEGIN
  DECLARE owned_export_id BIGINT UNSIGNED DEFAULT NULL;

  SELECT payment_export.id
    INTO owned_export_id
    FROM payroll_payment_exports payment_export
   WHERE payment_export.supplier_id = NEW.supplier_id
     AND payment_export.id = NEW.export_id;

  IF owned_export_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export grant target does not belong to supplier';
  END IF;
  IF TIMESTAMPDIFF(SECOND, NEW.created_at, NEW.expires_at) NOT BETWEEN 30 AND 900 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export grant TTL must be between 30 and 900 seconds';
  END IF;
  IF NEW.used_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export grant must be unused when issued';
  END IF;
END//

CREATE TRIGGER trg_payroll_payment_export_grant_consume_update
BEFORE UPDATE ON payroll_payment_export_download_grants
FOR EACH ROW
BEGIN
  IF NEW.id <> OLD.id
    OR NEW.supplier_id <> OLD.supplier_id
    OR NEW.export_id <> OLD.export_id
    OR NEW.user_id <> OLD.user_id
    OR NOT (NEW.token_hash <=> OLD.token_hash)
    OR NEW.expires_at <> OLD.expires_at
    OR NEW.created_at <> OLD.created_at
    OR OLD.used_at IS NOT NULL
    OR NEW.used_at IS NULL
    OR NEW.used_at < OLD.created_at
    OR NEW.used_at > OLD.expires_at
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export grant only allows one-time consumption';
  END IF;
END//

DELIMITER ;
