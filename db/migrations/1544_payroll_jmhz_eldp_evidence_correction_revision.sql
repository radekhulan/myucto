-- ELDP evidence follows the current approved payroll revision, including an internal correction revision.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_eldp_insert_guard//
CREATE TRIGGER trg_payroll_jmhz_eldp_insert_guard
BEFORE INSERT ON payroll_jmhz_eldp_evidence_snapshots
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
      JOIN payroll_runs run
        ON run.supplier_id = revision.supplier_id
       AND run.id = revision.run_id
      JOIN payroll_employments employment
        ON employment.supplier_id = revision.supplier_id
       AND employment.id = NEW.employment_id
      JOIN payroll_run_employments frozen_employment
        ON frozen_employment.supplier_id = revision.supplier_id
       AND frozen_employment.revision_id = revision.id
       AND frozen_employment.employee_id = NEW.employee_id
       AND frozen_employment.employment_id = NEW.employment_id
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.source_revision_id
       AND revision.run_id = NEW.run_id
       AND revision.status = 'approved'
       AND revision.revision_kind IN ('regular', 'correction')
       AND revision.revision_no = run.current_revision_no
       AND run.period_start = NEW.period_start
       AND employment.employee_id = NEW.employee_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ELDP evidence requires current approved revision';
  END IF;
END//

DELIMITER ;
