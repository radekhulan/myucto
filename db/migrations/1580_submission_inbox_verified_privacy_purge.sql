-- MyÚčto.cz — ověřené dvoufázové odstranění místní kopie ISDS zprávy.

ALTER TABLE submission_inbox_messages
  MODIFY COLUMN local_content_state ENUM('available','purging','purged') NOT NULL DEFAULT 'available',
  ADD UNIQUE KEY IF NOT EXISTS uq_submission_inbox_supplier_id (supplier_id, id);

ALTER TABLE submission_inbox_messages
  DROP CONSTRAINT IF EXISTS chk_submission_inbox_local_content;
ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT chk_submission_inbox_local_content CHECK (
    (local_content_state IN ('available','purging') AND local_content_purged_at IS NULL)
    OR (local_content_state = 'purged' AND local_content_purged_at IS NOT NULL)
  );

CREATE TABLE IF NOT EXISTS submission_inbox_purge_manifest (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  inbox_message_id   BIGINT UNSIGNED NOT NULL,
  entry_no           INT UNSIGNED NOT NULL,
  sha256             CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  internal_filename  VARCHAR(255) NOT NULL,
  thumb_filename     VARCHAR(255) NULL,
  status             ENUM('pending','deleted','retained_shared','failed') NOT NULL DEFAULT 'pending',
  attempts           INT UNSIGNED NOT NULL DEFAULT 0,
  last_error         VARCHAR(500) NULL,
  resolved_at        DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_submission_inbox_purge_entry (supplier_id, inbox_message_id, entry_no),
  KEY idx_submission_inbox_purge_pending (supplier_id, inbox_message_id, status),
  CONSTRAINT fk_submission_inbox_purge_message
    FOREIGN KEY (supplier_id, inbox_message_id)
    REFERENCES submission_inbox_messages (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_submission_inbox_purge_attempts CHECK (attempts >= 0),
  CONSTRAINT chk_submission_inbox_purge_resolved CHECK (
    (status IN ('pending','failed') AND resolved_at IS NULL)
    OR (status IN ('deleted','retained_shared') AND resolved_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE submission_outbox
  DROP FOREIGN KEY IF EXISTS fk_submission_outbox_receipt_document;
ALTER TABLE submission_outbox
  DROP INDEX IF EXISTS fk_submission_outbox_receipt_document,
  DROP INDEX IF EXISTS idx_submission_outbox_supplier_receipt,
  ADD INDEX IF NOT EXISTS idx_submission_outbox_receipt_document (receipt_document_id);
ALTER TABLE submission_outbox
  ADD CONSTRAINT fk_submission_outbox_receipt_document
    FOREIGN KEY (receipt_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT;

DROP TRIGGER IF EXISTS trg_submission_outbox_inbox_privacy_insert;
DELIMITER $$
CREATE TRIGGER trg_submission_outbox_inbox_privacy_insert
BEFORE INSERT ON submission_outbox
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16);
  IF NEW.receipt_inbox_message_id IS NOT NULL THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.receipt_inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze navázat jako doručenku.';
    END IF;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_submission_outbox_inbox_privacy_update;
DELIMITER $$
CREATE TRIGGER trg_submission_outbox_inbox_privacy_update
BEFORE UPDATE ON submission_outbox
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16);
  IF NEW.receipt_inbox_message_id IS NOT NULL
     AND NOT (NEW.receipt_inbox_message_id <=> OLD.receipt_inbox_message_id) THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.receipt_inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze navázat jako doručenku.';
    END IF;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_submission_defect_notice_inbox_privacy;
DELIMITER $$
CREATE TRIGGER trg_submission_defect_notice_inbox_privacy
BEFORE INSERT ON submission_defect_notices
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16);
  IF NEW.inbox_message_id IS NOT NULL THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze zaevidovat jako výzvu.';
    END IF;
  END IF;
END$$
DELIMITER ;
