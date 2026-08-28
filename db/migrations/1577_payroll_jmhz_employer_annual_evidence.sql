-- Neměnné roční údaje zaměstnavatele pro prosincové JMHZ.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_employer_annual_evidence (
  id                                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                             INT UNSIGNED NOT NULL,
  report_year                             SMALLINT UNSIGNED NOT NULL,
  revision_no                             INT UNSIGNED NOT NULL,
  previous_revision_id                    BIGINT UNSIGNED NULL,
  schema_reference                        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  collective_agreement_types_json         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ownership_form                          CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  average_headcount_hundredths             INT UNSIGNED NOT NULL,
  average_disabled_headcount_hundredths    INT UNSIGNED NOT NULL,
  disabled_share_hundredths                INT UNSIGNED NOT NULL,
  ozp_reporting_office_id                  BIGINT UNSIGNED NULL,
  evidence_reference                       VARCHAR(500) NULL,
  payload_sha256                           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by                               BIGINT UNSIGNED NULL,
  created_at                               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  UNIQUE KEY uq_payroll_jmhz_employer_annual_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_jmhz_employer_annual_revision
    (supplier_id, report_year, revision_no),
  UNIQUE KEY uq_payroll_jmhz_employer_annual_payload
    (supplier_id, report_year, payload_sha256),
  KEY idx_payroll_jmhz_employer_annual_latest
    (supplier_id, report_year, revision_no),

  CONSTRAINT fk_payroll_jmhz_employer_annual_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_employer_annual_previous
    FOREIGN KEY (supplier_id, previous_revision_id)
    REFERENCES payroll_jmhz_employer_annual_evidence (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_employer_annual_office
    FOREIGN KEY (supplier_id, ozp_reporting_office_id)
    REFERENCES payroll_offices (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_employer_annual_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,

  CONSTRAINT chk_payroll_jmhz_employer_annual_schema
    CHECK (schema_reference = 'payroll-jmhz-employer-annual-evidence.v1'),
  CONSTRAINT chk_payroll_jmhz_employer_annual_year
    CHECK (report_year BETWEEN 2026 AND 2100),
  CONSTRAINT chk_payroll_jmhz_employer_annual_revision
    CHECK (revision_no > 0),
  CONSTRAINT chk_payroll_jmhz_employer_annual_collective
    CHECK (JSON_VALID(collective_agreement_types_json)),
  CONSTRAINT chk_payroll_jmhz_employer_annual_ownership
    CHECK (ownership_form IN ('1','2','3','4')),
  CONSTRAINT chk_payroll_jmhz_employer_annual_ozp
    CHECK (
      average_disabled_headcount_hundredths <= average_headcount_hundredths
      AND disabled_share_hundredths <= 10000
    ),
  CONSTRAINT chk_payroll_jmhz_employer_annual_hash
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_jmhz_preparation_snapshots
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD CONSTRAINT chk_payroll_jmhz_preparation_builder CHECK (
    builder_version IN (
      'jmhz-preparation-source.v1',
      'jmhz-preparation-source.v2',
      'jmhz-preparation-source.v3',
      'jmhz-preparation-source.v4',
      'jmhz-preparation-source.v5',
      'jmhz-preparation-source.v6',
      'jmhz-preparation-source.v7',
      'jmhz-preparation-source.v8',
      'jmhz-preparation-source.v9',
      'jmhz-preparation-source.v10'
    )
  );

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_employer_annual_validate_insert//
CREATE TRIGGER trg_payroll_jmhz_employer_annual_validate_insert
BEFORE INSERT ON payroll_jmhz_employer_annual_evidence
FOR EACH ROW
BEGIN
  IF NEW.previous_revision_id IS NULL THEN
    IF NEW.revision_no <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'First JMHZ employer annual revision must be number 1';
    END IF;
  ELSEIF NOT EXISTS (
    SELECT 1
      FROM payroll_jmhz_employer_annual_evidence previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.previous_revision_id
       AND previous.report_year = NEW.report_year
       AND previous.revision_no + 1 = NEW.revision_no
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ employer annual revision chain is inconsistent';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_employer_annual_no_update//
CREATE TRIGGER trg_payroll_jmhz_employer_annual_no_update
BEFORE UPDATE ON payroll_jmhz_employer_annual_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ employer annual evidence is immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_employer_annual_no_delete//
CREATE TRIGGER trg_payroll_jmhz_employer_annual_no_delete
BEFORE DELETE ON payroll_jmhz_employer_annual_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ employer annual evidence is append-only';
END//

DELIMITER ;
