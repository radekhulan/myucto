-- Placené náhradní volno za svátek (§ 115) v pracovním souhrnu JMHZ.
-- v11 = intervaly, v12 = ruční zdroj. Staré snímky a jejich hash zůstávají stejné.
-- Technický rozpad patří do úhrnů 10275/10276, nevytváří nový atribut XML.
SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS holiday_time_off_millihours INT UNSIGNED NULL
    AFTER holiday_unpaid_millihours;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_conditional_confirmation CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND conditional_blocks_confirmed IS NULL
      AND unworked_hours_occurred IS NULL
      AND work_obstacles_occurred IS NULL
      AND unworked_total_millihours IS NULL
      AND unworked_paid_millihours IS NULL
      AND dpn_without_employer_compensation_millihours IS NULL
      AND dpn_with_employer_compensation_millihours IS NULL
      AND vacation_millihours IS NULL
      AND care_millihours IS NULL
      AND employee_obstacle_paid_millihours IS NULL
      AND employer_obstacle_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_absence_evidence;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_absence_evidence CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5',
        'jmhz-work-month.v6', 'jmhz-work-month.v7', 'jmhz-work-month.v8',
        'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND maternity_millihours IS NULL
      AND paternity_millihours IS NULL
      AND parental_millihours IS NULL
      AND unpaid_leave_millihours IS NULL
      AND unexcused_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5',
        'jmhz-work-month.v6', 'jmhz-work-month.v7', 'jmhz-work-month.v8',
        'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND (
        unworked_hours_occurred = 1
        OR (
          maternity_millihours IS NULL
          AND paternity_millihours IS NULL
          AND parental_millihours IS NULL
          AND unpaid_leave_millihours IS NULL
          AND unexcused_millihours IS NULL
        )
      )
      AND (maternity_millihours IS NULL OR maternity_millihours <= 99999999)
      AND (paternity_millihours IS NULL OR paternity_millihours <= 99999999)
      AND (parental_millihours IS NULL OR parental_millihours <= 99999999)
      AND (unpaid_leave_millihours IS NULL OR unpaid_leave_millihours <= 99999999)
      AND (unexcused_millihours IS NULL OR unexcused_millihours <= 99999999)
    )
  );

-- Intervalové souhrny mají dny povinné, importované a ruční je nemusí znát.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_worked_breakdown;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_worked_breakdown CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v6',
        'jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND worked_days IS NULL
      AND overtime_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v7',
        'jmhz-work-month.v9', 'jmhz-work-month.v11'
      )
      AND worked_days IS NOT NULL
      AND worked_days <= 31
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    ) OR (
      derivation_version IN ('jmhz-work-month.v6', 'jmhz-work-month.v8', 'jmhz-work-month.v10', 'jmhz-work-month.v12')
      AND (worked_days IS NULL OR worked_days <= 31)
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_compensatory_time_off;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_compensatory_time_off CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND compensatory_time_off_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND (unworked_hours_occurred = 1 OR compensatory_time_off_millihours IS NULL)
      AND (
        compensatory_time_off_millihours IS NULL
        OR compensatory_time_off_millihours <= 99999999
      )
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_holidays;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_holidays CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12')
      AND holiday_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12')
      AND (unworked_hours_occurred = 1 OR holiday_millihours IS NULL)
      AND (holiday_millihours IS NULL OR holiday_millihours <= 99999999)
    )
  );

-- Svátek uvnitř nepřítomnosti bez mzdy nesou intervalové v9/v11 při IN07.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_unpaid_holidays;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_unpaid_holidays CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v9', 'jmhz-work-month.v11')
      AND holiday_unpaid_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v9', 'jmhz-work-month.v11')
      AND (unworked_hours_occurred = 1 OR holiday_unpaid_millihours IS NULL)
      AND (holiday_unpaid_millihours IS NULL OR holiday_unpaid_millihours <= 99999999)
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_control_binding CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND control_catalog_key IS NULL
      AND control_manifest_sha256 IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10', 'jmhz-work-month.v11', 'jmhz-work-month.v12'
      )
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_holiday_time_off;
ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_holiday_time_off CHECK (
    (derivation_version NOT IN ('jmhz-work-month.v11', 'jmhz-work-month.v12')
      AND holiday_time_off_millihours IS NULL)
    OR (derivation_version IN ('jmhz-work-month.v11', 'jmhz-work-month.v12')
      AND (holiday_time_off_millihours IS NULL OR (
        holiday_time_off_millihours <= 99999999
        AND unworked_hours_occurred = 1
        AND unworked_total_millihours IS NOT NULL
        AND unworked_paid_millihours IS NOT NULL
        AND unworked_total_millihours >= holiday_time_off_millihours
        AND unworked_paid_millihours >= holiday_time_off_millihours
      )))
  );
