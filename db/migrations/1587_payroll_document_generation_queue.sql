-- MyÚčto.cz — MZ-16: durable asynchronní fronta mzdových dokumentů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_document_batches (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  status                ENUM('queued','running','retry_wait','failed','completed') NOT NULL DEFAULT 'queued',
  source_snapshot_hash  CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  item_count            INT UNSIGNED NOT NULL,
  succeeded_count       INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count          INT UNSIGNED NOT NULL DEFAULT 0,
  bundle_document_id    BIGINT UNSIGNED NULL,
  requested_by          BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at            DATETIME NULL,
  completed_at          DATETIME NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_batch_id (supplier_id, id),
  UNIQUE KEY uq_payroll_document_batch_revision (supplier_id, revision_id),
  UNIQUE KEY uq_payroll_document_batch_idempotency (supplier_id, idempotency_key_hash),
  KEY idx_payroll_document_batch_work (status, updated_at, id),
  CONSTRAINT fk_payroll_document_batch_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_batch_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_batch_bundle
    FOREIGN KEY (supplier_id, bundle_document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_batch_requester
    FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_document_batch_hash
    CHECK (source_snapshot_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_document_batch_counts
    CHECK (item_count > 0 AND succeeded_count <= item_count AND failed_count <= item_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_document_batch_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  source_snapshot_hash  CHAR(64) NOT NULL,
  status                ENUM('queued','processing','retry_wait','failed','succeeded') NOT NULL DEFAULT 'queued',
  attempt_count         INT UNSIGNED NOT NULL DEFAULT 0,
  available_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_token           BINARY(16) NULL,
  locked_at             DATETIME NULL,
  document_id           BIGINT UNSIGNED NULL,
  last_error_code       VARCHAR(64) NULL,
  last_error_message    VARCHAR(500) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at          DATETIME NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_batch_item_id (supplier_id, id),
  UNIQUE KEY uq_payroll_document_batch_item_employee (supplier_id, batch_id, employee_id),
  KEY idx_payroll_document_batch_item_work (status, available_at, id),
  KEY idx_payroll_document_batch_item_batch (supplier_id, batch_id, status, id),
  CONSTRAINT fk_payroll_document_batch_item_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_document_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_batch_item_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_batch_item_document
    FOREIGN KEY (supplier_id, document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_document_batch_item_hash
    CHECK (source_snapshot_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_document_batch_item_lease
    CHECK ((status = 'processing' AND lease_token IS NOT NULL AND locked_at IS NOT NULL)
      OR (status <> 'processing' AND lease_token IS NULL AND locked_at IS NULL)),
  CONSTRAINT chk_payroll_document_batch_item_result
    CHECK ((status = 'succeeded' AND document_id IS NOT NULL AND completed_at IS NOT NULL)
      OR (status <> 'succeeded' AND document_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_document_batch_attempts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  item_id               BIGINT UNSIGNED NOT NULL,
  attempt_no            INT UNSIGNED NOT NULL,
  lease_token           BINARY(16) NOT NULL,
  status                ENUM('running','succeeded','failed','stale') NOT NULL DEFAULT 'running',
  error_code            VARCHAR(64) NULL,
  error_message         VARCHAR(500) NULL,
  started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at           DATETIME NULL,

  UNIQUE KEY uq_payroll_document_batch_attempt (supplier_id, item_id, attempt_no),
  KEY idx_payroll_document_batch_attempt_batch (supplier_id, batch_id, started_at),
  CONSTRAINT fk_payroll_document_batch_attempt_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_document_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_batch_attempt_item
    FOREIGN KEY (supplier_id, item_id)
    REFERENCES payroll_document_batch_items (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_document_batch_attempt_no CHECK (attempt_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_document_batch_source_insert
BEFORE INSERT ON payroll_document_batches
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.revision_id
       AND revision.run_id = NEW.run_id
       AND revision.status IN ('approved', 'superseded')
       AND revision.result_snapshot_hash = NEW.source_snapshot_hash
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll document batch requires an approved matching revision';
  END IF;
END//

CREATE OR REPLACE TRIGGER trg_payroll_document_batch_item_source_insert
BEFORE INSERT ON payroll_document_batch_items
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_document_batches batch
      JOIN payroll_run_persons person
        ON person.supplier_id = batch.supplier_id
       AND person.revision_id = batch.revision_id
       AND person.employee_id = NEW.employee_id
     WHERE batch.supplier_id = NEW.supplier_id
       AND batch.id = NEW.batch_id
       AND person.status = 'calculated'
       AND person.result_hash = NEW.source_snapshot_hash
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll document batch item requires a matching calculated person';
  END IF;
END//

DELIMITER ;
