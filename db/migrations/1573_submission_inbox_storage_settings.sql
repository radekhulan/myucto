-- MyÚčto.cz — per-tenant a per-environment cílová složka příchozí ISDS.

CREATE TABLE IF NOT EXISTS submission_inbox_storage_settings (
  supplier_id    INT UNSIGNED NOT NULL,
  channel        ENUM('isds') NOT NULL DEFAULT 'isds',
  environment    ENUM('production','test') NOT NULL,
  base_folder_id BIGINT UNSIGNED NOT NULL,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, channel, environment),
  KEY idx_submission_inbox_storage_folder (base_folder_id),
  CONSTRAINT fk_submission_inbox_storage_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_submission_inbox_storage_folder
    FOREIGN KEY (base_folder_id) REFERENCES document_folders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_inbox_storage_user
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_submission_inbox_storage_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_submission_inbox_storage_insert_guard//
CREATE TRIGGER trg_submission_inbox_storage_insert_guard
BEFORE INSERT ON submission_inbox_storage_settings
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM document_folders f
     WHERE f.id = NEW.base_folder_id
       AND f.supplier_id = NEW.supplier_id
       AND f.deleted_at IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission inbox storage folder tenant mismatch or deleted';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_submission_inbox_storage_update_guard//
CREATE TRIGGER trg_submission_inbox_storage_update_guard
BEFORE UPDATE ON submission_inbox_storage_settings
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.channel <=> OLD.channel)
     OR NOT (NEW.environment <=> OLD.environment) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission inbox storage identity is immutable';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM document_folders f
     WHERE f.id = NEW.base_folder_id
       AND f.supplier_id = NEW.supplier_id
       AND f.deleted_at IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission inbox storage folder tenant mismatch or deleted';
  END IF;
END//

DELIMITER ;
