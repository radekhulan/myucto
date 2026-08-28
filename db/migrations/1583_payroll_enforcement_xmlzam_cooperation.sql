SET NAMES utf8mb4;

ALTER TABLE document_files
  ADD UNIQUE KEY IF NOT EXISTS uq_document_files_supplier_id (supplier_id, id);

ALTER TABLE submission_outbox
  MODIFY COLUMN artifact_kind
    ENUM('payroll_submission','tax_submission','document','payroll_xmlzam') NOT NULL;

CREATE TABLE IF NOT EXISTS payroll_enforcement_xmlzam_requests (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  inbox_message_id      BIGINT UNSIGNED NOT NULL,
  source_document_id    BIGINT UNSIGNED NOT NULL,
  source_document_file_id BIGINT UNSIGNED NOT NULL,
  request_identifier    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issued_on             DATE NOT NULL,
  executor_box_id       CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_xml_sha256     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_ciphertext   LONGTEXT NOT NULL,
  snapshot_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  imported_by           BIGINT UNSIGNED NOT NULL,
  imported_at           DATETIME NOT NULL,

  UNIQUE KEY uq_xmlzam_request_tenant_id (supplier_id, id),
  UNIQUE KEY uq_xmlzam_request_source (supplier_id, environment, inbox_message_id, source_document_file_id),
  UNIQUE KEY uq_xmlzam_request_identifier (supplier_id, environment, request_identifier),
  CONSTRAINT fk_xmlzam_request_supplier FOREIGN KEY (supplier_id)
    REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_request_employee FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_request_inbox FOREIGN KEY (supplier_id, inbox_message_id)
    REFERENCES submission_inbox_messages (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_request_document FOREIGN KEY (source_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_request_file FOREIGN KEY (supplier_id, source_document_file_id)
    REFERENCES document_files (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_request_user FOREIGN KEY (imported_by)
    REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_xmlzam_request_sha CHECK (
    source_xml_sha256 REGEXP '^[0-9a-f]{64}$'
    AND snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_xmlzam_request_ciphertext CHECK (snapshot_ciphertext LIKE 'enc:v2:%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_xmlzam_responses (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  request_id            BIGINT UNSIGNED NOT NULL,
  case_id               BIGINT UNSIGNED NOT NULL,
  response_identifier   VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  includes_wages        TINYINT(1) NOT NULL DEFAULT 1,
  source_manifest_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_ciphertext   LONGTEXT NOT NULL,
  snapshot_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  xml_ciphertext        LONGTEXT NOT NULL,
  xml_sha256            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  approved_by           BIGINT UNSIGNED NOT NULL,
  approved_at           DATETIME NOT NULL,

  UNIQUE KEY uq_xmlzam_response_tenant_id (supplier_id, id),
  UNIQUE KEY uq_xmlzam_response_idempotency (supplier_id, environment, idempotency_key_hash),
  UNIQUE KEY uq_xmlzam_response_identifier (supplier_id, environment, response_identifier),
  CONSTRAINT fk_xmlzam_response_supplier FOREIGN KEY (supplier_id)
    REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_response_request FOREIGN KEY (supplier_id, request_id)
    REFERENCES payroll_enforcement_xmlzam_requests (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_response_case FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_response_user FOREIGN KEY (approved_by)
    REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_xmlzam_response_hashes CHECK (
    source_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    AND snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'
    AND xml_sha256 REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_xmlzam_response_ciphertexts CHECK (
    snapshot_ciphertext LIKE 'enc:v2:%' AND xml_ciphertext LIKE 'enc:v2:%'
  ),
  CONSTRAINT chk_xmlzam_response_includes_wages CHECK (includes_wages IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_xmlzam_dispatches (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  environment ENUM('production','test') NOT NULL,
  response_id BIGINT UNSIGNED NOT NULL,
  outbox_id   BIGINT UNSIGNED NOT NULL,
  enqueued_by BIGINT UNSIGNED NOT NULL,
  enqueued_at DATETIME NOT NULL,
  UNIQUE KEY uq_xmlzam_dispatch_response (supplier_id, environment, response_id),
  UNIQUE KEY uq_xmlzam_dispatch_outbox (supplier_id, outbox_id),
  CONSTRAINT fk_xmlzam_dispatch_response FOREIGN KEY (supplier_id, response_id)
    REFERENCES payroll_enforcement_xmlzam_responses (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_dispatch_outbox FOREIGN KEY (supplier_id, outbox_id)
    REFERENCES submission_outbox (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_xmlzam_dispatch_user FOREIGN KEY (enqueued_by)
    REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_xmlzam_request_no_update
BEFORE UPDATE ON payroll_enforcement_xmlzam_requests
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'XMLZAM request snapshots are immutable'//

CREATE OR REPLACE TRIGGER trg_xmlzam_request_no_delete
BEFORE DELETE ON payroll_enforcement_xmlzam_requests
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'XMLZAM request snapshots are append-only'//

CREATE OR REPLACE TRIGGER trg_xmlzam_response_no_update
BEFORE UPDATE ON payroll_enforcement_xmlzam_responses
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'XMLZAM response snapshots are immutable'//

CREATE OR REPLACE TRIGGER trg_xmlzam_response_no_delete
BEFORE DELETE ON payroll_enforcement_xmlzam_responses
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'XMLZAM response snapshots are append-only'//

CREATE OR REPLACE TRIGGER trg_xmlzam_dispatch_no_update
BEFORE UPDATE ON payroll_enforcement_xmlzam_dispatches
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'XMLZAM dispatch links are immutable'//

CREATE OR REPLACE TRIGGER trg_xmlzam_dispatch_no_delete
BEFORE DELETE ON payroll_enforcement_xmlzam_dispatches
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'XMLZAM dispatch links are append-only'//

DELIMITER ;
