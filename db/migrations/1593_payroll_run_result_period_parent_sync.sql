-- Období výsledků zůstává odvozené i při změně období dosud editovatelného běhu.
-- Propagace proběhne atomicky ve stejné transakci jako změna parent řádku.

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_run_result_period_propagate
AFTER UPDATE ON payroll_runs
FOR EACH ROW
BEGIN
  IF NEW.period_start <> OLD.period_start THEN
    UPDATE payroll_run_persons result
       JOIN payroll_run_revisions revision
         ON revision.supplier_id = result.supplier_id
        AND revision.id = result.revision_id
       SET result.period_start = NEW.period_start
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.run_id = NEW.id;

    UPDATE payroll_run_employments result
       JOIN payroll_run_revisions revision
         ON revision.supplier_id = result.supplier_id
        AND revision.id = result.revision_id
       SET result.period_start = NEW.period_start
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.run_id = NEW.id;

    UPDATE payroll_net_results result
       JOIN payroll_run_revisions revision
         ON revision.supplier_id = result.supplier_id
        AND revision.id = result.revision_id
       SET result.period_start = NEW.period_start
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.run_id = NEW.id;
  END IF;
END//

DELIMITER ;
