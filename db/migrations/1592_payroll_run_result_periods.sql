-- MZ-30-W03: období výsledku je striktně odvozeno z revize → mzdového běhu.
-- Čitelné období na výsledku umožní roční dotazy bez dekódování snapshotů.

ALTER TABLE payroll_run_persons
  ADD COLUMN IF NOT EXISTS period_start DATE NULL
    AFTER revision_id;
ALTER TABLE payroll_run_persons
  ADD KEY IF NOT EXISTS idx_payroll_run_persons_period_employee
    (supplier_id, employee_id, period_start, revision_id);

ALTER TABLE payroll_run_employments
  ADD COLUMN IF NOT EXISTS period_start DATE NULL
    AFTER revision_id;
ALTER TABLE payroll_run_employments
  ADD KEY IF NOT EXISTS idx_payroll_run_employments_period_employee
    (supplier_id, employment_id, period_start, revision_id);

ALTER TABLE payroll_net_results
  ADD COLUMN IF NOT EXISTS period_start DATE NULL
    AFTER revision_id;
ALTER TABLE payroll_net_results
  ADD KEY IF NOT EXISTS idx_payroll_net_results_period_employee
    (supplier_id, employee_id, period_start, revision_id);

UPDATE payroll_run_persons result
JOIN payroll_run_revisions revision
  ON revision.supplier_id = result.supplier_id
 AND revision.id = result.revision_id
JOIN payroll_runs run
  ON run.supplier_id = revision.supplier_id
 AND run.id = revision.run_id
   SET result.period_start = run.period_start
 WHERE result.period_start IS NULL OR result.period_start <> run.period_start;

UPDATE payroll_run_employments result
JOIN payroll_run_revisions revision
  ON revision.supplier_id = result.supplier_id
 AND revision.id = result.revision_id
JOIN payroll_runs run
  ON run.supplier_id = revision.supplier_id
 AND run.id = revision.run_id
   SET result.period_start = run.period_start
 WHERE result.period_start IS NULL OR result.period_start <> run.period_start;

UPDATE payroll_net_results result
JOIN payroll_run_revisions revision
  ON revision.supplier_id = result.supplier_id
 AND revision.id = result.revision_id
JOIN payroll_runs run
  ON run.supplier_id = revision.supplier_id
 AND run.id = revision.run_id
   SET result.period_start = run.period_start
 WHERE result.period_start IS NULL OR result.period_start <> run.period_start;

ALTER TABLE payroll_run_persons
  MODIFY COLUMN period_start DATE NOT NULL AFTER revision_id;
ALTER TABLE payroll_run_employments
  MODIFY COLUMN period_start DATE NOT NULL AFTER revision_id;
ALTER TABLE payroll_net_results
  MODIFY COLUMN period_start DATE NOT NULL AFTER revision_id;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_run_person_period_insert
BEFORE INSERT ON payroll_run_persons
FOR EACH ROW
BEGIN
  DECLARE canonical_period_start DATE;
  SELECT run.period_start INTO canonical_period_start
    FROM payroll_run_revisions revision
    JOIN payroll_runs run
      ON run.supplier_id = revision.supplier_id
     AND run.id = revision.run_id
   WHERE revision.supplier_id = NEW.supplier_id
     AND revision.id = NEW.revision_id
   LIMIT 1;
  IF canonical_period_start IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll result revision has no parent run period';
  END IF;
  SET NEW.period_start = canonical_period_start;
END//

CREATE OR REPLACE TRIGGER trg_payroll_run_person_period_update
BEFORE UPDATE ON payroll_run_persons
FOR EACH ROW
BEGIN
  DECLARE canonical_period_start DATE;
  SELECT run.period_start INTO canonical_period_start
    FROM payroll_run_revisions revision
    JOIN payroll_runs run
      ON run.supplier_id = revision.supplier_id
     AND run.id = revision.run_id
   WHERE revision.supplier_id = NEW.supplier_id
     AND revision.id = NEW.revision_id
   LIMIT 1;
  IF canonical_period_start IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll result revision has no parent run period';
  END IF;
  SET NEW.period_start = canonical_period_start;
END//

CREATE OR REPLACE TRIGGER trg_payroll_run_employment_period_insert
BEFORE INSERT ON payroll_run_employments
FOR EACH ROW
BEGIN
  DECLARE canonical_period_start DATE;
  SELECT run.period_start INTO canonical_period_start
    FROM payroll_run_revisions revision
    JOIN payroll_runs run
      ON run.supplier_id = revision.supplier_id
     AND run.id = revision.run_id
   WHERE revision.supplier_id = NEW.supplier_id
     AND revision.id = NEW.revision_id
   LIMIT 1;
  IF canonical_period_start IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll result revision has no parent run period';
  END IF;
  SET NEW.period_start = canonical_period_start;
END//

CREATE OR REPLACE TRIGGER trg_payroll_run_employment_period_update
BEFORE UPDATE ON payroll_run_employments
FOR EACH ROW
BEGIN
  DECLARE canonical_period_start DATE;
  SELECT run.period_start INTO canonical_period_start
    FROM payroll_run_revisions revision
    JOIN payroll_runs run
      ON run.supplier_id = revision.supplier_id
     AND run.id = revision.run_id
   WHERE revision.supplier_id = NEW.supplier_id
     AND revision.id = NEW.revision_id
   LIMIT 1;
  IF canonical_period_start IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll result revision has no parent run period';
  END IF;
  SET NEW.period_start = canonical_period_start;
END//

CREATE OR REPLACE TRIGGER trg_payroll_net_result_period_insert
BEFORE INSERT ON payroll_net_results
FOR EACH ROW
BEGIN
  DECLARE canonical_period_start DATE;
  SELECT run.period_start INTO canonical_period_start
    FROM payroll_run_revisions revision
    JOIN payroll_runs run
      ON run.supplier_id = revision.supplier_id
     AND run.id = revision.run_id
   WHERE revision.supplier_id = NEW.supplier_id
     AND revision.id = NEW.revision_id
   LIMIT 1;
  IF canonical_period_start IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll result revision has no parent run period';
  END IF;
  SET NEW.period_start = canonical_period_start;
END//

CREATE OR REPLACE TRIGGER trg_payroll_net_result_period_update
BEFORE UPDATE ON payroll_net_results
FOR EACH ROW
BEGIN
  DECLARE canonical_period_start DATE;
  SELECT run.period_start INTO canonical_period_start
    FROM payroll_run_revisions revision
    JOIN payroll_runs run
      ON run.supplier_id = revision.supplier_id
     AND run.id = revision.run_id
   WHERE revision.supplier_id = NEW.supplier_id
     AND revision.id = NEW.revision_id
   LIMIT 1;
  IF canonical_period_start IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll result revision has no parent run period';
  END IF;
  SET NEW.period_start = canonical_period_start;
END//

DELIMITER ;
