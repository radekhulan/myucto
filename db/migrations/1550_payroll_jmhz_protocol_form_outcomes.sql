-- MyÚčto.cz — JMHZ: doložené výsledky jednotlivých formulářů z protokolu ČSSZ.
--
-- Stav formuláře je NULL záměrně, pokud protokol uvádí pouze chybu formuláře,
-- ale jeho individuální výsledek nedokládá. Přijatý/zamítnutý stav se ukládá
-- jen z Item/@result dílčího protokolu. Syrové XML zůstává v šifrovaném
-- receipt_original artefaktu; texty chyb jsou šifrované také, protože mohou
-- obsahovat osobní údaje. Řádky jsou append-only stejně jako jejich protokol.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_protocol_form_outcomes (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  environment                   ENUM('production','test') NOT NULL,
  submission_id                 BIGINT UNSIGNED NOT NULL,
  receipt_id                    BIGINT UNSIGNED NOT NULL,
  artifact_id                   BIGINT UNSIGNED NOT NULL,
  part_id                       BIGINT UNSIGNED NULL,
  form_guid                     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  protocol_status_code          TINYINT UNSIGNED NULL,
  protocol_status_name          VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  remote_status                 ENUM('accepted','rejected') NULL,
  external_person_reference     VARCHAR(128) NULL,
  external_employment_reference VARCHAR(128) NULL,
  error_count                   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  errors_ciphertext             LONGTEXT NOT NULL,
  errors_sha256                 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_form_outcome_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_jmhz_form_outcome_receipt_form
    (supplier_id, environment, receipt_id, form_guid),
  KEY idx_payroll_jmhz_form_outcome_submission
    (supplier_id, environment, submission_id, form_guid),
  KEY idx_payroll_jmhz_form_outcome_artifact
    (supplier_id, environment, submission_id, artifact_id),
  KEY idx_payroll_jmhz_form_outcome_part
    (supplier_id, environment, submission_id, part_id),

  CONSTRAINT fk_payroll_jmhz_form_outcome_receipt
    FOREIGN KEY (supplier_id, environment, receipt_id)
    REFERENCES payroll_submission_receipts (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_form_outcome_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_form_outcome_artifact
    FOREIGN KEY (supplier_id, environment, submission_id, artifact_id)
    REFERENCES payroll_submission_artifacts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_form_outcome_part
    FOREIGN KEY (supplier_id, environment, submission_id, part_id)
    REFERENCES payroll_submission_parts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_jmhz_form_outcome_guid CHECK (
    form_guid REGEXP
      '^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$'
  ),
  CONSTRAINT chk_payroll_jmhz_form_outcome_status CHECK (
    (
      protocol_status_code IS NULL
      AND protocol_status_name IS NULL
      AND remote_status IS NULL
    )
    OR
    (
      protocol_status_code BETWEEN 1 AND 6
      AND protocol_status_name IS NOT NULL
      AND remote_status IS NOT NULL
    )
  ),
  CONSTRAINT chk_payroll_jmhz_form_outcome_errors CHECK (
    errors_ciphertext LIKE 'enc:v2:%'
    AND errors_sha256 REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_form_outcome_update
BEFORE UPDATE ON payroll_jmhz_protocol_form_outcomes
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'jmhz protocol form outcomes are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_form_outcome_delete
BEFORE DELETE ON payroll_jmhz_protocol_form_outcomes
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'jmhz protocol form outcomes are immutable';
END//

DELIMITER ;
