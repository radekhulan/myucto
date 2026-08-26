-- MyÚčto.cz — oprava nebo smazání omylem uložené pohledávky jen před spuštěním případu.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_enforcement_claim_mutable_update;
DROP TRIGGER IF EXISTS trg_payroll_enforcement_claim_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_enforcement_claim_mutable_update
BEFORE UPDATE ON payroll_enforcement_claims
FOR EACH ROW
BEGIN
  IF NEW.id <> OLD.id
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
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim has a retained footprint';
  END IF;
END//

CREATE TRIGGER trg_payroll_enforcement_claim_immutable_delete
BEFORE DELETE ON payroll_enforcement_claims
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
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
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement claim has a retained footprint';
  END IF;
END//

DELIMITER ;
