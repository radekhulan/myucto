ALTER TABLE payroll_employer_policies
    ALTER COLUMN four_eyes_required SET DEFAULT 0;

UPDATE payroll_employer_policies
   SET four_eyes_required = 0
 WHERE four_eyes_required <> 0;
