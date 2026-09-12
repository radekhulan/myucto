-- MyÚčto.cz — hodiny čerpaného náhradního volna za práci přesčas v pracovním souhrnu.
--
-- Měsíc s náhradním volnem dosud zablokoval ELDP řez měsíčního hlášení
-- (`jmhz_eldp_absences_unsupported`), protože souhrn neměl kam jeho hodiny
-- zapsat. Za dobu čerpání mzda nepřísluší (§ 114 odst. 1 zákoníku práce),
-- takže hodiny patří jen do úhrnu 10275, ne do 10276, a vlastní blok
-- 10277–10280 nemají.
--
-- Nová verze odvození `jmhz-work-month.v5` je nutná ze stejného důvodu jako
-- u v3 a v4: obsahový otisk souhrnu se počítá z kanonického JSONu hodnot,
-- takže rozšíření výčtu klíčů by u dřív zmrazených souhrnů otisk rozbilo.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS compensatory_time_off_millihours INT UNSIGNED NULL
    AFTER unexcused_millihours;

-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže se dotčená
-- omezení nejdřív zahodí a založí znovu — jen tak je migrace opakovatelná.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_absence_evidence,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_worked_breakdown,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_compensatory_time_off;

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
        'jmhz-work-month.v5'
      )
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_absence_evidence CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5'
      )
      AND maternity_millihours IS NULL
      AND paternity_millihours IS NULL
      AND parental_millihours IS NULL
      AND unpaid_leave_millihours IS NULL
      AND unexcused_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5'
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
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_worked_breakdown CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v4', 'jmhz-work-month.v5')
      AND worked_days IS NULL
      AND overtime_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v4', 'jmhz-work-month.v5')
      AND worked_days IS NOT NULL
      AND worked_days <= 31
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    )
  ),
  -- Náhradní volno nese jen v5 a jen při interakci IN07: jsou to neodpracované
  -- hodiny, které vstupují do úhrnu 10275.
  ADD CONSTRAINT chk_payroll_jmhz_work_month_compensatory_time_off CHECK (
    (
      derivation_version <> 'jmhz-work-month.v5'
      AND compensatory_time_off_millihours IS NULL
    ) OR (
      derivation_version = 'jmhz-work-month.v5'
      AND (unworked_hours_occurred = 1 OR compensatory_time_off_millihours IS NULL)
      AND (
        compensatory_time_off_millihours IS NULL
        OR compensatory_time_off_millihours <= 99999999
      )
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_control_binding CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND control_catalog_key IS NULL
      AND control_manifest_sha256 IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5'
      )
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
