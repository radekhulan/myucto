-- MyÚčto.cz — soukromí příchozích ISDS: skrytí hlavičky a odstranění místní kopie.

ALTER TABLE submission_inbox_messages
  ADD COLUMN IF NOT EXISTS hidden_at DATETIME NULL AFTER processed_at,
  ADD COLUMN IF NOT EXISTS hidden_by BIGINT UNSIGNED NULL AFTER hidden_at,
  ADD COLUMN IF NOT EXISTS local_content_state ENUM('available','purged') NOT NULL DEFAULT 'available' AFTER hidden_by,
  ADD COLUMN IF NOT EXISTS local_content_purged_at DATETIME NULL AFTER local_content_state,
  ADD COLUMN IF NOT EXISTS local_content_purged_by BIGINT UNSIGNED NULL AFTER local_content_purged_at,
  ADD COLUMN IF NOT EXISTS lifecycle_row_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER local_content_purged_by;

ALTER TABLE submission_inbox_messages
  ADD INDEX IF NOT EXISTS idx_submission_inbox_visibility
    (supplier_id, environment, hidden_at, fetched_at);

ALTER TABLE submission_inbox_messages
  DROP CONSTRAINT IF EXISTS fk_submission_inbox_hidden_by;
ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT fk_submission_inbox_hidden_by
    FOREIGN KEY (hidden_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE submission_inbox_messages
  DROP CONSTRAINT IF EXISTS fk_submission_inbox_purged_by;
ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT fk_submission_inbox_purged_by
    FOREIGN KEY (local_content_purged_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE submission_inbox_messages
  DROP CONSTRAINT IF EXISTS chk_submission_inbox_local_content;
ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT chk_submission_inbox_local_content CHECK (
    (local_content_state = 'available' AND local_content_purged_at IS NULL)
    OR (local_content_state = 'purged' AND local_content_purged_at IS NOT NULL)
  );

ALTER TABLE submission_inbox_messages
  DROP CONSTRAINT IF EXISTS chk_submission_inbox_lifecycle_version;
ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT chk_submission_inbox_lifecycle_version CHECK (lifecycle_row_version > 0);
