-- MyÚčto.cz — omylem založený exekuční případ bez právní, mzdové nebo platební stopy lze smazat.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_enforcement_case_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_enforcement_case_immutable_delete
BEFORE DELETE ON payroll_enforcement_cases
FOR EACH ROW
BEGIN
  IF OLD.status <> 'received'
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_claims
        WHERE supplier_id = OLD.supplier_id AND case_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_events
        WHERE supplier_id = OLD.supplier_id AND case_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_case_documents
        WHERE supplier_id = OLD.supplier_id AND case_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_allocations
        WHERE supplier_id = OLD.supplier_id AND case_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_enforcement_ledger
        WHERE supplier_id = OLD.supplier_id AND case_id = OLD.id
     )
     OR EXISTS (
       SELECT 1 FROM payroll_payment_liabilities
        WHERE supplier_id = OLD.supplier_id
          AND liability_kind = 'enforcement'
          AND liability_reference LIKE CONCAT('enforcement:c', OLD.id, ':%')
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement case has a retained footprint';
  END IF;
END//

DELIMITER ;
