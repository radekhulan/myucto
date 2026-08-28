-- MyÚčto.cz — MZ-14: pořadí zákonné srážky je datum doručení prvnímu plátci.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_claims
  ADD COLUMN IF NOT EXISTS first_payer_delivered_on DATE NULL
    AFTER priority_date,
  ADD INDEX IF NOT EXISTS idx_payroll_enforcement_claim_first_payer_delivery
    (supplier_id, case_id, first_payer_delivered_on, id);

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_enforcement_claim_priority_insert
BEFORE INSERT ON payroll_enforcement_claims
FOR EACH ROW
BEGIN
  IF NEW.legal_basis = 'statutory' THEN
    IF NEW.first_payer_delivered_on IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Statutory enforcement claim requires first payer delivery date';
    END IF;
    SET NEW.priority_date = NEW.first_payer_delivered_on;
  ELSEIF NEW.first_payer_delivered_on IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Voluntary enforcement claim cannot have first payer delivery date';
  END IF;
END//

CREATE OR REPLACE TRIGGER trg_payroll_enforcement_claim_mutable_update
BEFORE UPDATE ON payroll_enforcement_claims
FOR EACH ROW
BEGIN
  DECLARE is_priority_backfill TINYINT DEFAULT 0;

  SET is_priority_backfill =
       OLD.legal_basis = 'statutory'
   AND OLD.first_payer_delivered_on IS NULL
   AND OLD.priority_date IS NOT NULL
   AND NEW.first_payer_delivered_on <=> OLD.priority_date
   AND NEW.enforcement_order_key <=> OLD.enforcement_order_key
   AND NEW.legal_basis <=> OLD.legal_basis
   AND NEW.category <=> OLD.category
   AND NEW.outstanding_minor_units <=> OLD.outstanding_minor_units
   AND NEW.maintenance_weight_minor_units <=> OLD.maintenance_weight_minor_units
   AND NEW.priority_date <=> OLD.priority_date
   AND NEW.order_issued_on <=> OLD.order_issued_on
   AND NEW.legal_title_verified <=> OLD.legal_title_verified
   AND NEW.order_or_notice_delivered <=> OLD.order_or_notice_delivered
   AND NEW.priority_classification_verified <=> OLD.priority_classification_verified
   AND NEW.agreement_verified <=> OLD.agreement_verified
   AND NEW.due_monetary_claim_verified <=> OLD.due_monetary_claim_verified
   AND NEW.is_active <=> OLD.is_active
   AND NEW.row_version <=> OLD.row_version;

  IF is_priority_backfill = 0
     AND (NEW.id <> OLD.id
     OR NEW.supplier_id <> OLD.supplier_id
     OR NEW.case_id <> OLD.case_id
     OR NEW.claim_key <> OLD.claim_key
     OR NEW.created_at <> OLD.created_at
     OR NOT EXISTS (
       SELECT 1 FROM payroll_enforcement_cases enforcement_case
        WHERE enforcement_case.supplier_id = OLD.supplier_id
          AND enforcement_case.id = OLD.case_id
          AND enforcement_case.status = 'received'
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_month_results result
        WHERE result.supplier_id = OLD.supplier_id
          AND JSON_SEARCH(
                result.input_snapshot_json,
                'one',
                OLD.claim_key,
                NULL,
                '$.claims[*].id'
              ) IS NOT NULL
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_allocations allocation
        WHERE allocation.supplier_id = OLD.supplier_id
          AND allocation.claim_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_ledger ledger
        WHERE ledger.supplier_id = OLD.supplier_id
          AND ledger.claim_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_payment_liabilities liability
        WHERE liability.supplier_id = OLD.supplier_id
          AND liability.liability_kind = 'enforcement'
          AND liability.liability_reference = CONCAT(
                'enforcement:c', OLD.case_id, ':cl', OLD.id
              )
     ))
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim has a retained footprint';
  END IF;

  IF NEW.legal_basis = 'statutory' THEN
    IF OLD.first_payer_delivered_on IS NOT NULL
       AND NOT (NEW.first_payer_delivered_on <=> OLD.first_payer_delivered_on) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Statutory enforcement first payer delivery date is immutable';
    END IF;
    IF NEW.first_payer_delivered_on IS NULL THEN
      IF NOT (NEW.priority_date <=> OLD.priority_date) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Statutory enforcement priority requires first payer delivery date';
      END IF;
    ELSE
      SET NEW.priority_date = NEW.first_payer_delivered_on;
    END IF;
  ELSEIF NEW.first_payer_delivered_on IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Voluntary enforcement claim cannot have first payer delivery date';
  END IF;
END//

DELIMITER ;

-- Starší evidence znala pouze priority_date. Přenést ji lze jen tam, kde
-- stávající záznam výslovně potvrzuje doručení rozhodnutí nebo vyrozumění.
-- Trigger už je v nové verzi: přesně tento jednorázový denormalizační zápis
-- dovolí i u použité pohledávky, ale jakoukoli souběžnou změnu jejích dat odmítne.
UPDATE payroll_enforcement_claims
   SET first_payer_delivered_on = priority_date
 WHERE legal_basis = 'statutory'
   AND order_or_notice_delivered = 1
   AND priority_date IS NOT NULL
   AND first_payer_delivered_on IS NULL;
