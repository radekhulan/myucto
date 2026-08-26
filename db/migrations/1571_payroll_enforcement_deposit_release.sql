-- MyÚčto.cz — MZ-14-W08: doložené append-only uvolnění historického depozita.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_ledger
  MODIFY COLUMN entry_kind ENUM(
    'withheld','held','released_for_remittance','remitted',
    'released_to_employee','employer_fee','adjustment'
  ) NOT NULL,
  ADD COLUMN IF NOT EXISTS decision_event_id BIGINT UNSIGNED NULL
    AFTER actor_user_id,
  ADD KEY IF NOT EXISTS idx_payroll_enforcement_ledger_decision_event
    (supplier_id, decision_event_id),
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_ledger_owner,
  ADD CONSTRAINT chk_payroll_enforcement_ledger_owner
    CHECK (
      (entry_kind = 'employer_fee' AND case_id IS NULL AND claim_id IS NULL)
      OR (
        entry_kind = 'released_for_remittance'
        AND case_id IS NOT NULL AND claim_id IS NOT NULL
      )
      OR (
        entry_kind IN (
          'withheld','held','remitted','released_to_employee'
        )
        AND ((case_id IS NULL AND claim_id IS NULL)
          OR (case_id IS NOT NULL AND claim_id IS NOT NULL))
      )
      OR (
        entry_kind = 'adjustment'
        AND (claim_id IS NULL OR case_id IS NOT NULL)
      )
    ),
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_enforcement_ledger_decision_event
    CHECK (
      (entry_kind = 'released_for_remittance' AND decision_event_id IS NOT NULL)
      OR (entry_kind <> 'released_for_remittance' AND decision_event_id IS NULL)
    );

ALTER TABLE payroll_enforcement_ledger
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_ledger_decision_event;

ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT fk_payroll_enforcement_ledger_decision_event
    FOREIGN KEY (supplier_id, decision_event_id)
    REFERENCES payroll_enforcement_events (supplier_id, id)
    ON DELETE RESTRICT;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_enforcement_ledger_consistency_insert//

CREATE TRIGGER trg_payroll_enforcement_ledger_consistency_insert
BEFORE INSERT ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  DECLARE allocation_total BIGINT DEFAULT NULL;
  DECLARE held_total BIGINT DEFAULT 0;
  DECLARE released_total BIGINT DEFAULT 0;
  DECLARE remitted_total BIGINT DEFAULT 0;
  DECLARE returned_total BIGINT DEFAULT 0;
  DECLARE result_fee BIGINT DEFAULT NULL;
  DECLARE decision_case_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE decision_command VARCHAR(40) DEFAULT NULL;
  DECLARE decision_document_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE decision_evidence_hash CHAR(64) DEFAULT NULL;

  IF NEW.entry_kind = 'released_for_remittance' THEN
    SELECT decision.case_id, decision.command_name,
           decision.decision_document_id, decision.decision_evidence_hash
      INTO decision_case_id, decision_command, decision_document_id,
           decision_evidence_hash
      FROM payroll_enforcement_events decision
     WHERE decision.supplier_id = NEW.supplier_id
       AND decision.id = NEW.decision_event_id;

    IF decision_case_id IS NULL
       OR decision_case_id <> NEW.case_id
       OR decision_command IS NULL
       OR decision_command NOT IN ('authorize_remittance','resume_remittance')
       OR decision_document_id IS NULL
       OR decision_evidence_hash IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement release has no matching decision';
    END IF;
  END IF;

  IF NEW.entry_kind = 'employer_fee' THEN
    SELECT employer_fee_minor_units
      INTO result_fee
      FROM payroll_enforcement_month_results
     WHERE supplier_id = NEW.supplier_id
       AND id = NEW.month_result_id;

    IF result_fee IS NULL OR NEW.amount_minor_units <> result_fee THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement employer fee does not match result';
    END IF;
  ELSEIF NEW.entry_kind IN (
    'withheld','held','released_for_remittance','remitted','released_to_employee'
  ) THEN
    SELECT total_minor_units
      INTO allocation_total
      FROM payroll_enforcement_allocations
     WHERE supplier_id = NEW.supplier_id
       AND month_result_id = NEW.month_result_id
       AND case_id <=> NEW.case_id
       AND claim_id <=> NEW.claim_id
     LIMIT 1;

    IF allocation_total IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement ledger has no matching allocation';
    END IF;

    IF NEW.entry_kind IN ('withheld','held')
       AND NEW.amount_minor_units <> allocation_total THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement calculation entry differs from allocation';
    END IF;

    IF NEW.entry_kind IN ('released_for_remittance','released_to_employee') THEN
      SELECT COALESCE(SUM(CASE WHEN entry_kind = 'held'
             THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'released_for_remittance'
               THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'remitted'
               THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'released_to_employee'
               THEN amount_minor_units ELSE 0 END), 0)
        INTO held_total, released_total, remitted_total, returned_total
        FROM payroll_enforcement_ledger
       WHERE supplier_id = NEW.supplier_id
         AND month_result_id = NEW.month_result_id
         AND case_id <=> NEW.case_id
         AND claim_id <=> NEW.claim_id;

      IF returned_total
           + IF(NEW.entry_kind = 'released_to_employee', NEW.amount_minor_units, 0)
           + GREATEST(
               released_total
                 + IF(NEW.entry_kind = 'released_for_remittance',
                      NEW.amount_minor_units, 0),
               remitted_total
             ) > held_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement held amount is over-disposed';
      END IF;
    ELSEIF NEW.entry_kind = 'remitted' THEN
      SELECT COALESCE(SUM(CASE WHEN entry_kind = 'remitted'
               THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'released_to_employee'
               THEN amount_minor_units ELSE 0 END), 0)
        INTO remitted_total, returned_total
        FROM payroll_enforcement_ledger
       WHERE supplier_id = NEW.supplier_id
         AND month_result_id = NEW.month_result_id
         AND case_id <=> NEW.case_id
         AND claim_id <=> NEW.claim_id;

      IF remitted_total + returned_total + NEW.amount_minor_units
           > allocation_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement allocation is over-remitted';
      END IF;
    END IF;
  ELSEIF NEW.entry_kind = 'adjustment' AND NEW.actor_user_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement adjustment requires an actor';
  END IF;
END//

DELIMITER ;
