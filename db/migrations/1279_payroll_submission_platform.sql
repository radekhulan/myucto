-- MyÚčto.cz — MZ-19: tenantový registr povinností a důkazní platforma podání.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_agenda_matrix (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  agenda_code           VARCHAR(48) NOT NULL,
  valid_from            DATE NOT NULL,
  valid_to              DATE NULL,
  replacement_mode      ENUM(
    'fully_replaced','partially_replaced','standalone','unknown'
  ) NOT NULL DEFAULT 'unknown',
  ruleset_id            VARCHAR(96) NOT NULL,
  ruleset_hash          CHAR(64) NOT NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_agenda_matrix_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_agenda_matrix_effective (
    supplier_id, agenda_code, valid_from
  ),
  KEY idx_payroll_agenda_matrix_period (
    supplier_id, agenda_code, valid_from, valid_to
  ),
  CONSTRAINT fk_payroll_agenda_matrix_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_agenda_matrix_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_agenda_matrix_interval CHECK (
    valid_to IS NULL OR valid_to >= valid_from
  ),
  CONSTRAINT chk_payroll_agenda_matrix_hash CHECK (
    ruleset_hash REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_agenda_matrix_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_obligations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  agenda_code           VARCHAR(48) NOT NULL,
  subject_type          ENUM(
    'employer','office','person','employment','payroll_run','other'
  ) NOT NULL,
  subject_reference     VARCHAR(96) NOT NULL,
  period_start          DATE NOT NULL,
  period_end            DATE NOT NULL,
  obligation_kind       ENUM('regular','correction','cancellation') NOT NULL,
  preferred_channel     ENUM(
    'manual_upload','isds','vrep_apep','pikr','health_portal','other'
  ) NOT NULL,
  status                ENUM(
    'open','prepared','submitted','fulfilled','overdue',
    'cancelled','manual_review'
  ) NOT NULL DEFAULT 'open',
  responsible_user_id   BIGINT UNSIGNED NULL,
  source_event_type     VARCHAR(64) NOT NULL,
  source_event_reference VARCHAR(96) NOT NULL,
  source_event_hash     CHAR(64) NOT NULL,
  request_fingerprint   CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_obligations_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_obligations_environment_id (
    supplier_id, environment, id
  ),
  UNIQUE KEY uq_payroll_obligations_idempotency (
    supplier_id, environment, idempotency_key_hash
  ),
  KEY idx_payroll_obligations_queue (
    supplier_id, status, period_end, agenda_code
  ),
  KEY idx_payroll_obligations_subject (
    supplier_id, subject_type, subject_reference, period_end
  ),
  CONSTRAINT fk_payroll_obligations_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_obligations_responsible
    FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_obligations_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_obligations_period CHECK (
    period_end >= period_start
  ),
  CONSTRAINT chk_payroll_obligations_source_hash CHECK (
    source_event_hash REGEXP '^[0-9a-f]{64}$'
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_obligations_reference CHECK (
    subject_reference REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$'
    AND source_event_reference
      REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$'
  ),
  CONSTRAINT chk_payroll_obligations_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_submission_deadlines (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  obligation_id         BIGINT UNSIGNED NOT NULL,
  deadline_kind         ENUM(
    'regular','substitute','appeal','correction'
  ) NOT NULL,
  earliest_submission_on DATE NOT NULL,
  due_on                DATE NOT NULL,
  calendar_basis        ENUM('calendar_days','business_days') NOT NULL,
  fiction_delivery_days SMALLINT UNSIGNED NULL,
  ruleset_id            VARCHAR(96) NOT NULL,
  ruleset_hash          CHAR(64) NOT NULL,
  trigger_event_hash    CHAR(64) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_submission_deadlines_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submission_deadline_kind (
    supplier_id, obligation_id, deadline_kind
  ),
  KEY idx_payroll_submission_deadline_due (
    supplier_id, due_on, deadline_kind
  ),
  CONSTRAINT fk_payroll_submission_deadline_obligation
    FOREIGN KEY (supplier_id, environment, obligation_id)
    REFERENCES payroll_obligations (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_deadline_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_deadline_interval CHECK (
    due_on >= earliest_submission_on
  ),
  CONSTRAINT chk_payroll_submission_deadline_hashes CHECK (
    ruleset_hash REGEXP '^[0-9a-f]{64}$'
    AND trigger_event_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_submissions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  obligation_id         BIGINT UNSIGNED NOT NULL,
  corrects_submission_id BIGINT UNSIGNED NULL,
  submission_kind       ENUM('regular','correction','cancellation') NOT NULL,
  channel               ENUM(
    'manual_upload','isds','vrep_apep','pikr','health_portal','other'
  ) NOT NULL,
  status                ENUM(
    'draft','validated','prepared','ready','submitted','processing',
    'accepted','partially_accepted','rejected','waiting_for_identity',
    'correction_required','superseded','cancelled_in_time'
  ) NOT NULL DEFAULT 'draft',
  correlation_reference VARCHAR(128) NULL,
  source_revision_id    BIGINT UNSIGNED NULL,
  source_snapshot_hash  CHAR(64) NOT NULL,
  request_fingerprint   CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  submitted_at          DATETIME NULL,
  decided_at            DATETIME NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  regular_scope_id      BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN submission_kind = 'regular' THEN obligation_id ELSE NULL END
    ) STORED,

  UNIQUE KEY uq_payroll_submissions_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submissions_environment_id (
    supplier_id, environment, id
  ),
  UNIQUE KEY uq_payroll_submissions_idempotency (
    supplier_id, environment, idempotency_key_hash
  ),
  UNIQUE KEY uq_payroll_submissions_regular (
    supplier_id, environment, regular_scope_id
  ),
  UNIQUE KEY uq_payroll_submissions_correlation (
    supplier_id, environment, channel, correlation_reference
  ),
  KEY idx_payroll_submissions_obligation (
    supplier_id, obligation_id, created_at
  ),
  KEY idx_payroll_submissions_status (
    supplier_id, status, updated_at
  ),
  KEY idx_payroll_submissions_source_revision (
    supplier_id, source_revision_id
  ),
  CONSTRAINT fk_payroll_submissions_obligation
    FOREIGN KEY (supplier_id, environment, obligation_id)
    REFERENCES payroll_obligations (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submissions_correction
    FOREIGN KEY (supplier_id, environment, corrects_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submissions_revision
    FOREIGN KEY (supplier_id, source_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submissions_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submissions_hashes CHECK (
    source_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_submissions_correction CHECK (
    (
      submission_kind = 'regular'
      AND corrects_submission_id IS NULL
    )
    OR
    (
      submission_kind <> 'regular'
      AND corrects_submission_id IS NOT NULL
    )
  ),
  CONSTRAINT chk_payroll_submissions_dates CHECK (
    (
      (
        status IN ('draft','validated','prepared','ready')
        AND submitted_at IS NULL
      )
      OR
      status = 'cancelled_in_time'
      OR
      (
        status NOT IN (
          'draft','validated','prepared','ready','cancelled_in_time'
        )
        AND submitted_at IS NOT NULL
      )
    )
    AND
    (
      (
        status IN (
          'accepted','partially_accepted','rejected',
          'correction_required','superseded','cancelled_in_time'
        )
        AND decided_at IS NOT NULL
      )
      OR
      (
        status NOT IN (
          'accepted','partially_accepted','rejected',
          'correction_required','superseded','cancelled_in_time'
        )
        AND decided_at IS NULL
      )
    )
  ),
  CONSTRAINT chk_payroll_submissions_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_submission_parts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  part_reference        VARCHAR(96) NOT NULL,
  agenda_code           VARCHAR(48) NOT NULL,
  subject_reference     VARCHAR(96) NOT NULL,
  status                ENUM(
    'draft','validated','prepared','ready','submitted','processing',
    'accepted','rejected','correction_required'
  ) NOT NULL DEFAULT 'draft',
  source_entity_type    VARCHAR(64) NOT NULL,
  source_entity_reference VARCHAR(96) NOT NULL,
  source_snapshot_hash  CHAR(64) NOT NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_submission_parts_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submission_parts_aggregate_id (
    supplier_id, environment, submission_id, id
  ),
  UNIQUE KEY uq_payroll_submission_part_reference (
    supplier_id, submission_id, part_reference
  ),
  KEY idx_payroll_submission_parts_status (
    supplier_id, submission_id, status
  ),
  CONSTRAINT fk_payroll_submission_parts_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_parts_hash CHECK (
    source_snapshot_hash REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_submission_parts_references CHECK (
    part_reference REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$'
    AND subject_reference REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$'
    AND source_entity_reference
      REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$'
  ),
  CONSTRAINT chk_payroll_submission_parts_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_submission_artifacts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  part_id               BIGINT UNSIGNED NULL,
  artifact_kind         ENUM(
    'outbound_xml','outbound_zip','validation_protocol',
    'receipt_original','receipt_parsed','manual_attachment'
  ) NOT NULL,
  direction             ENUM('outbound','inbound','internal') NOT NULL,
  mime_type             VARCHAR(96) NOT NULL,
  content_ciphertext    LONGTEXT NOT NULL,
  byte_size             BIGINT UNSIGNED NOT NULL,
  artifact_sha256       CHAR(64) NOT NULL,
  xsd_version           VARCHAR(96) NULL,
  catalog_version       VARCHAR(96) NULL,
  channel               ENUM(
    'manual_upload','isds','vrep_apep','pikr','health_portal','other'
  ) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_submission_artifacts_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submission_artifacts_aggregate_id (
    supplier_id, environment, submission_id, id
  ),
  UNIQUE KEY uq_payroll_submission_artifact_idempotency (
    supplier_id, environment, idempotency_key_hash
  ),
  KEY idx_payroll_submission_artifacts_submission (
    supplier_id, submission_id, part_id, created_at
  ),
  CONSTRAINT fk_payroll_submission_artifacts_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_artifacts_part
    FOREIGN KEY (supplier_id, environment, submission_id, part_id)
    REFERENCES payroll_submission_parts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_artifacts_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_artifacts_content CHECK (
    content_ciphertext LIKE 'enc:v2:%'
  ),
  CONSTRAINT chk_payroll_submission_artifacts_shape CHECK (
    byte_size > 0
    AND artifact_sha256 REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_submission_receipts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  part_id               BIGINT UNSIGNED NULL,
  artifact_id           BIGINT UNSIGNED NOT NULL,
  receipt_reference     VARCHAR(128) NOT NULL,
  correlation_reference VARCHAR(128) NULL,
  protocol_code         VARCHAR(64) NOT NULL,
  remote_status         ENUM(
    'submitted','processing','accepted','partially_accepted','rejected',
    'waiting_for_identity','correction_required'
  ) NULL,
  verification_status   ENUM('unverified','trusted') NOT NULL,
  summary_hash          CHAR(64) NOT NULL,
  request_fingerprint   CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  received_at           DATETIME NOT NULL,
  imported_by           BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_submission_receipts_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submission_receipt_idempotency (
    supplier_id, environment, idempotency_key_hash
  ),
  UNIQUE KEY uq_payroll_submission_receipt_reference (
    supplier_id, environment, protocol_code, receipt_reference
  ),
  KEY idx_payroll_submission_receipts_submission (
    supplier_id, submission_id, received_at
  ),
  CONSTRAINT fk_payroll_submission_receipts_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_receipts_part
    FOREIGN KEY (supplier_id, environment, submission_id, part_id)
    REFERENCES payroll_submission_parts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_receipts_artifact
    FOREIGN KEY (supplier_id, environment, submission_id, artifact_id)
    REFERENCES payroll_submission_artifacts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_receipts_importer
    FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_receipts_hash CHECK (
    summary_hash REGEXP '^[0-9a-f]{64}$'
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_submission_receipts_verification CHECK (
    (verification_status = 'unverified' AND remote_status IS NULL)
    OR (verification_status = 'trusted' AND remote_status IS NOT NULL)
  ),
  CONSTRAINT chk_payroll_submission_receipts_reference CHECK (
    receipt_reference REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
    AND (
      correlation_reference IS NULL
      OR correlation_reference
        REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_submission_issues (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  part_id               BIGINT UNSIGNED NULL,
  severity              ENUM('blocker','error','warning','info') NOT NULL,
  validation_stage      ENUM(
    'source','xsd','catalog','transport','remote'
  ) NOT NULL,
  issue_code            VARCHAR(96) NOT NULL,
  entity_type           VARCHAR(64) NULL,
  entity_reference      VARCHAR(96) NULL,
  details_ciphertext    LONGTEXT NULL,
  details_hash          CHAR(64) NULL,
  is_resolved           TINYINT(1) NOT NULL DEFAULT 0,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  resolved_by           BIGINT UNSIGNED NULL,
  resolved_at           DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_submission_issues_supplier_id (supplier_id, id),
  KEY idx_payroll_submission_issues_open (
    supplier_id, submission_id, is_resolved, severity
  ),
  CONSTRAINT fk_payroll_submission_issues_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_issues_part
    FOREIGN KEY (supplier_id, environment, submission_id, part_id)
    REFERENCES payroll_submission_parts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_issues_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_issues_resolver
    FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_issues_details CHECK (
    (
      details_ciphertext IS NULL
      AND details_hash IS NULL
    )
    OR
    (
      details_ciphertext LIKE 'enc:v2:%'
      AND details_hash REGEXP '^[0-9a-f]{64}$'
    )
  ),
  CONSTRAINT chk_payroll_submission_issues_resolution CHECK (
    (
      is_resolved = 0
      AND resolved_at IS NULL
    )
    OR
    (
      is_resolved = 1
      AND resolved_at IS NOT NULL
    )
  ),
  CONSTRAINT chk_payroll_submission_issues_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_agenda_matrix_update
BEFORE UPDATE ON payroll_agenda_matrix
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll agenda matrix rows are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_agenda_matrix_delete
BEFORE DELETE ON payroll_agenda_matrix
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll agenda matrix rows are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_deadline_update
BEFORE UPDATE ON payroll_submission_deadlines
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission deadlines are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_deadline_delete
BEFORE DELETE ON payroll_submission_deadlines
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission deadlines are immutable';
END//

-- Correlation se nesmí PŘEPSAT jinou hodnotou — váže podání na to, co úřad
-- potvrdil. Vynulovat ji ale smí návrat do předodeslaného stavu: tam se stopa po
-- vědomě zahozeném pokusu maže, aby šlo podat znovu. Podrobně v migraci
-- 1737_payroll_submission_correlation_reset.sql, která tenhle trigger u starších
-- instalací dorovnává.
CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_correlation_update
BEFORE UPDATE ON payroll_submissions
FOR EACH ROW
BEGIN
  IF OLD.correlation_reference IS NOT NULL
     AND NOT (NEW.correlation_reference <=> OLD.correlation_reference)
     AND NOT (
       NEW.correlation_reference IS NULL
       AND NEW.status IN ('draft', 'validated', 'prepared', 'ready')
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll submission correlation is immutable';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_artifact_update
BEFORE UPDATE ON payroll_submission_artifacts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission artifacts are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_artifact_delete
BEFORE DELETE ON payroll_submission_artifacts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission artifacts are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_receipt_update
BEFORE UPDATE ON payroll_submission_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission receipts are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_submission_receipt_delete
BEFORE DELETE ON payroll_submission_receipts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission receipts are immutable';
END//

DELIMITER ;
