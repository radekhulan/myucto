-- MyÚčto.cz — doložené ruční dokončení ELDP v oficiálním rozhraní.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_eldp_manual_completions (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  environment                ENUM('production','test') NOT NULL,
  statement_id               BIGINT UNSIGNED NOT NULL,
  obligation_id              BIGINT UNSIGNED NOT NULL,
  authority_status           ENUM('submitted','accepted') NOT NULL,
  confirmation_document_supplier_id BIGINT UNSIGNED NOT NULL,
  confirmation_document_id   BIGINT UNSIGNED NOT NULL,
  confirmation_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  confirmation_byte_size     BIGINT UNSIGNED NOT NULL,
  confirmation_mime_type     VARCHAR(96) NOT NULL,
  authority_reference        VARCHAR(190) NOT NULL,
  confirmed_on               DATE NOT NULL,
  evidence_manifest_json     LONGTEXT NOT NULL CHECK (JSON_VALID(evidence_manifest_json)),
  evidence_sha256            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key_hash       BINARY(32) NOT NULL,
  obligation_row_version_before INT UNSIGNED NOT NULL,
  recorded_by                BIGINT UNSIGNED NOT NULL,
  recorded_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_eldp_manual_completion_supplier_id (supplier_id, id),
  UNIQUE KEY uq_eldp_manual_completion_slot
    (supplier_id, environment, statement_id, authority_status),
  UNIQUE KEY uq_eldp_manual_completion_idempotency
    (supplier_id, environment, idempotency_key_hash),
  KEY idx_eldp_manual_completion_obligation
    (supplier_id, environment, obligation_id),
  KEY idx_eldp_manual_completion_document
    (confirmation_document_supplier_id, confirmation_document_id),

  CONSTRAINT fk_eldp_manual_completion_statement
    FOREIGN KEY (supplier_id, environment, statement_id)
    REFERENCES payroll_eldp_statements (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_eldp_manual_completion_obligation
    FOREIGN KEY (supplier_id, environment, obligation_id)
    REFERENCES payroll_obligations (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_eldp_manual_completion_document
    FOREIGN KEY (confirmation_document_supplier_id, confirmation_document_id)
    REFERENCES documents (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_eldp_manual_completion_user
    FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_eldp_manual_completion_hashes CHECK (
    confirmation_sha256 REGEXP '^[0-9a-f]{64}$'
    AND evidence_sha256 REGEXP '^[0-9a-f]{64}$'
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_eldp_manual_completion_shape CHECK (
    confirmation_byte_size > 0
    AND obligation_row_version_before > 0
    AND confirmation_document_supplier_id = supplier_id
    AND CHAR_LENGTH(TRIM(authority_reference)) BETWEEN 1 AND 190
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_eldp_manual_completion_immutable_update;
DROP TRIGGER IF EXISTS trg_eldp_manual_completion_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_eldp_manual_completion_immutable_update
BEFORE UPDATE ON payroll_eldp_manual_completions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ELDP manual completion is immutable';
END//

CREATE TRIGGER trg_eldp_manual_completion_immutable_delete
BEFORE DELETE ON payroll_eldp_manual_completions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ELDP manual completion is immutable';
END//

DELIMITER ;
