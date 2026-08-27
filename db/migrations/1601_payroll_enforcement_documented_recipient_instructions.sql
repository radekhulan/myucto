-- MyÚčto.cz — MZ-14-W01/W09: doložená instrukce příjemce exekuční platby.
--
-- Účet nepatří případu jen proto, že jej někdo vybral v katalogu. Instrukce
-- append-only spojuje konkrétní aktuální právní stranu s jedním účtem a
-- aktivním DMS podkladem. Starší případy se nikam automaticky nepřepisují:
-- pro novou aktivaci i materializaci je nutné vložit explicitní instrukci.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_enforcement_recipient_instructions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  case_id               BIGINT UNSIGNED NOT NULL,
  revision_no           INT UNSIGNED NOT NULL,
  effective_from        DATE NOT NULL,
  recipient_party_id    BIGINT UNSIGNED NOT NULL,
  payment_account_id    BIGINT UNSIGNED NOT NULL,
  source_document_id    BIGINT UNSIGNED NOT NULL,
  source_document_sha256 CHAR(64) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_recipient_instruction_revision
    (supplier_id, case_id, revision_no),
  UNIQUE KEY uq_payroll_enforcement_recipient_instruction_id (supplier_id, id),
  KEY idx_payroll_enforcement_recipient_instruction_effective
    (supplier_id, case_id, effective_from, revision_no),
  KEY idx_payroll_enforcement_recipient_instruction_account
    (supplier_id, payment_account_id),
  CONSTRAINT fk_payroll_enforcement_recipient_instruction_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_recipient_instruction_party
    FOREIGN KEY (supplier_id, recipient_party_id)
    REFERENCES payroll_enforcement_case_parties (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_recipient_instruction_account
    FOREIGN KEY (supplier_id, payment_account_id)
    REFERENCES payroll_institution_accounts (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_recipient_instruction_document
    FOREIGN KEY (source_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_recipient_instruction_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_recipient_instruction_hash
    CHECK (source_document_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_recipient_instruction_insert
BEFORE INSERT ON payroll_enforcement_recipient_instructions
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_enforcement_case_parties party
     WHERE party.supplier_id = NEW.supplier_id
       AND party.id = NEW.recipient_party_id
       AND party.case_id = NEW.case_id
       AND party.party_role IN ('executor', 'beneficiary')
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement recipient instruction requires executor or beneficiary of the same case';
  END IF;
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_institution_accounts account
      JOIN payroll_institutions institution
        ON institution.supplier_id = account.supplier_id
       AND institution.id = account.institution_id
     WHERE account.supplier_id = NEW.supplier_id
       AND account.id = NEW.payment_account_id
       AND institution.institution_type = 'other_recipient'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement recipient instruction requires other-recipient account';
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
      SET MESSAGE_TEXT = 'Payroll enforcement recipient instruction requires active tenant DMS document';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_recipient_instruction_immutable_update
BEFORE UPDATE ON payroll_enforcement_recipient_instructions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement recipient instructions are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_recipient_instruction_immutable_delete
BEFORE DELETE ON payroll_enforcement_recipient_instructions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement recipient instructions are append-only';
END//

DELIMITER ;
