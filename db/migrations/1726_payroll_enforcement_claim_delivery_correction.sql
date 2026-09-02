-- MyÚčto.cz — překlep v datu doručení prvnímu plátci jde opravit, dokud se
-- o pohledávku nic neopírá.
--
-- Trigger z 1594 zamykal `first_payer_delivered_on` od první vteřiny: i u
-- pohledávky v případu ve stavu „přijato“, která ještě nevstoupila do žádného
-- výpočtu. Jediná cesta ven vedla přes smazání a nové založení pohledávky —
-- a to hláška neřekla. Ochrana pořadí zůstává beze změny: stopa (alokace,
-- ledger, zmrazený mzdový výsledek, platební závazek) i aktivovaný případ
-- pohledávku pořád zamknou, tady se ta podmínka nijak nerozvolňuje.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_enforcement_claim_mutable_update//

CREATE TRIGGER trg_payroll_enforcement_claim_mutable_update
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
    -- Datum doručení smí zmizet jen tam, kde nikdy nebylo. Beze změny zůstává,
    -- že bez něj nesmí poskočit odvozené pořadí.
    IF NEW.first_payer_delivered_on IS NULL THEN
      IF OLD.first_payer_delivered_on IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Statutory enforcement claim cannot drop its first payer delivery date';
      END IF;
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
