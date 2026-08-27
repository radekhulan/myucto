-- MyÚčto.cz — MZ-14-W01/W02: doložené strany případu a neměnný rozpad pohledávky.
--
-- Nejde o platební instrukce: účet a VS zůstávají výhradně v ověřeném
-- katalogu institucí. Každá právní strana i klasifikace pohledávky má svůj
-- aktivní DMS důkaz a historii nelze přepsat.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_enforcement_case_parties (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  case_id               BIGINT UNSIGNED NOT NULL,
  party_role            ENUM('court','executor','beneficiary') NOT NULL,
  revision_no           INT UNSIGNED NOT NULL,
  effective_from        DATE NOT NULL,
  party_name            VARCHAR(255) NOT NULL,
  party_reference       VARCHAR(128) NULL,
  source_document_id    BIGINT UNSIGNED NOT NULL,
  source_document_sha256 CHAR(64) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_case_party_revision
    (supplier_id, case_id, party_role, revision_no),
  UNIQUE KEY uq_payroll_enforcement_case_party_id (supplier_id, id),
  KEY idx_payroll_enforcement_case_party_effective
    (supplier_id, case_id, party_role, effective_from, revision_no),
  CONSTRAINT fk_payroll_enforcement_case_party_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_case_party_document
    FOREIGN KEY (source_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_case_party_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_case_party_hash
    CHECK (source_document_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_enforcement_case_party_name
    CHECK (CHAR_LENGTH(TRIM(party_name)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_claim_breakdowns (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  case_id               BIGINT UNSIGNED NOT NULL,
  claim_id              BIGINT UNSIGNED NOT NULL,
  revision_no           INT UNSIGNED NOT NULL,
  principal_minor_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  interest_minor_units  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  costs_minor_units     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  maintenance_minor_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_minor_units     BIGINT UNSIGNED AS (
    principal_minor_units + interest_minor_units + costs_minor_units
      + maintenance_minor_units
  ) PERSISTENT,
  source_document_id    BIGINT UNSIGNED NOT NULL,
  source_document_sha256 CHAR(64) NOT NULL,
  change_reason         VARCHAR(500) NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_claim_breakdown_revision
    (supplier_id, claim_id, revision_no),
  UNIQUE KEY uq_payroll_enforcement_claim_breakdown_id (supplier_id, id),
  KEY idx_payroll_enforcement_claim_breakdown_case
    (supplier_id, case_id, claim_id, revision_no),
  CONSTRAINT fk_payroll_enforcement_claim_breakdown_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_claim_breakdown_claim
    FOREIGN KEY (supplier_id, claim_id)
    REFERENCES payroll_enforcement_claims (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_claim_breakdown_document
    FOREIGN KEY (source_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_claim_breakdown_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_claim_breakdown_hash
    CHECK (source_document_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_party_insert
BEFORE INSERT ON payroll_enforcement_case_parties
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.id = NEW.source_document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.source_document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement party requires an active tenant DMS document';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_party_immutable_update
BEFORE UPDATE ON payroll_enforcement_case_parties
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement case parties are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_party_immutable_delete
BEFORE DELETE ON payroll_enforcement_case_parties
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement case parties are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_claim_breakdown_insert
BEFORE INSERT ON payroll_enforcement_claim_breakdowns
FOR EACH ROW
BEGIN
  DECLARE claim_total BIGINT UNSIGNED DEFAULT NULL;

  SELECT claim.outstanding_minor_units
    INTO claim_total
    FROM payroll_enforcement_claims claim
    JOIN payroll_enforcement_cases enforcement_case
      ON enforcement_case.supplier_id = claim.supplier_id
     AND enforcement_case.id = claim.case_id
   WHERE claim.supplier_id = NEW.supplier_id
     AND claim.id = NEW.claim_id
     AND claim.case_id = NEW.case_id
     AND enforcement_case.status = 'received';

  IF claim_total IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim breakdown requires an unused received claim';
  END IF;
  IF NEW.principal_minor_units + NEW.interest_minor_units
       + NEW.costs_minor_units + NEW.maintenance_minor_units <> claim_total THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim breakdown total differs from claim balance';
  END IF;
  IF NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.id = NEW.source_document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.source_document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim breakdown requires an active tenant DMS document';
  END IF;
  IF EXISTS (
    SELECT 1 FROM payroll_enforcement_allocations allocation
     WHERE allocation.supplier_id = NEW.supplier_id
       AND allocation.claim_id = NEW.claim_id
  ) OR EXISTS (
    SELECT 1 FROM payroll_enforcement_ledger ledger
     WHERE ledger.supplier_id = NEW.supplier_id
       AND ledger.claim_id = NEW.claim_id
  ) OR EXISTS (
    SELECT 1 FROM payroll_payment_liabilities liability
     WHERE liability.supplier_id = NEW.supplier_id
       AND liability.liability_kind = 'enforcement'
       AND liability.liability_reference = CONCAT('enforcement:c', NEW.case_id, ':cl', NEW.claim_id)
  ) OR EXISTS (
    SELECT 1 FROM payroll_enforcement_month_results result
     WHERE result.supplier_id = NEW.supplier_id
       AND JSON_SEARCH(result.input_snapshot_json, 'one', (
             SELECT claim.claim_key FROM payroll_enforcement_claims claim
              WHERE claim.supplier_id = NEW.supplier_id AND claim.id = NEW.claim_id
           ), NULL, '$.claims[*].id') IS NOT NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim with retained footprint cannot receive a breakdown';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_claim_breakdown_immutable_update
BEFORE UPDATE ON payroll_enforcement_claim_breakdowns
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement claim breakdowns are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_claim_breakdown_immutable_delete
BEFORE DELETE ON payroll_enforcement_claim_breakdowns
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement claim breakdowns are append-only';
END//

DELIMITER ;
