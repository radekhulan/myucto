-- MyÚčto.cz — REGZEC A2: neměnný důkaz všech dotčených opravných JMHZ.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_registration_a2_evidence_ledger (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  event_snapshot_id     BIGINT UNSIGNED NOT NULL,
  schema_reference      VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  policy_reference      VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decision              ENUM('accepted','blocked') NOT NULL,
  plan_json             LONGTEXT NOT NULL CHECK (JSON_VALID(plan_json)),
  plan_sha256           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_registration_a2_evidence_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_registration_a2_evidence_event
    (supplier_id, environment, event_snapshot_id),
  KEY idx_payroll_registration_a2_evidence_employment
    (supplier_id, employment_id, event_snapshot_id),

  CONSTRAINT fk_payroll_registration_a2_evidence_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_a2_evidence_event
    FOREIGN KEY (supplier_id, event_snapshot_id)
    REFERENCES payroll_registration_event_snapshots (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_a2_evidence_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_registration_a2_evidence_schema CHECK (
    schema_reference = _ascii'payroll-registration-a2-jmhz-evidence.v1' COLLATE ascii_bin
    AND policy_reference = _ascii'regzec-a2-retroactive-jmhz-acceptance.v1' COLLATE ascii_bin
  ),
  CONSTRAINT chk_payroll_registration_a2_evidence_plan CHECK (
    plan_sha256 REGEXP _ascii'^[0-9a-f]{64}$' COLLATE ascii_bin
    AND plan_sha256 = CONVERT(SHA2(plan_json, 256) USING ascii) COLLATE ascii_bin
    AND JSON_UNQUOTE(JSON_EXTRACT(plan_json, '$.decision')) = decision
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_registration_a2_evidence_insert_guard//
CREATE TRIGGER trg_payroll_registration_a2_evidence_insert_guard
BEFORE INSERT ON payroll_registration_a2_evidence_ledger
FOR EACH ROW
BEGIN
  IF NEW.decision <> 'accepted' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'REGZEC A2 may freeze only an accepted JMHZ evidence plan';
  END IF;
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_registration_event_snapshots event
     WHERE event.supplier_id = NEW.supplier_id
       AND event.environment = NEW.environment
       AND event.id = NEW.event_snapshot_id
       AND event.employment_id = NEW.employment_id
       AND event.interaction_code = 'termination'
       AND event.action_code = 2
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'REGZEC A2 evidence scope does not match its event';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_registration_a2_evidence_no_update//
CREATE TRIGGER trg_payroll_registration_a2_evidence_no_update
BEFORE UPDATE ON payroll_registration_a2_evidence_ledger
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_registration_a2_evidence_ledger is immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_registration_a2_evidence_no_delete//
CREATE TRIGGER trg_payroll_registration_a2_evidence_no_delete
BEFORE DELETE ON payroll_registration_a2_evidence_ledger
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_registration_a2_evidence_ledger is append-only';
END//

DELIMITER ;
