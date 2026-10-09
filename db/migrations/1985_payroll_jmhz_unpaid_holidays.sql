-- MyÚčto.cz — měsíční hlášení JMHZ: svátek uvnitř nepřítomnosti bez mzdy v 10275.
--
-- Pokyny MPSV k vyplnění MH k 10275 počítají do celkového počtu neodpracovaných
-- hodin i svátky v jinak pracovní dny. Svátek uvnitř nepřítomnosti, za kterou se
-- mzda za svátek krátí (rodičovská, PPM, otcovská, ošetřovné, neplacené volno,
-- § 115 odst. 3 ZP), v úhrnu chyběl: celý měsíc rodičovské se svátkem hlásil
-- 176 h místo 184 h a neplatilo 10268 + 10275 = 10260. Do 10276 (s náhradou či
-- nekrácením mzdy) takový svátek nepatří.
--
-- Hodiny nese nový blok `holiday_unpaid_millihours`, který jde jen do úhrnu
-- 10275. Nová verze odvození `jmhz-work-month.v9` (z intervalů) je nutná ze
-- stejného důvodu jako u v3 až v8: obsahový otisk souhrnu se počítá z kanonického
-- JSONu hodnot, takže rozšíření výčtu klíčů by u dřív zmrazených souhrnů otisk
-- rozbilo.
--
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže se každé omezení
-- zvlášť nejdřív zahodí a založí znovu — jen tak je migrace opakovatelná.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS holiday_unpaid_millihours INT UNSIGNED NULL
    AFTER holiday_millihours;

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
        'jmhz-work-month.v8', 'jmhz-work-month.v9'
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
        'jmhz-work-month.v9'
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
        'jmhz-work-month.v9'
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

-- v4, v5, v7 a v9: dny povinně. v6 a v8 (import): dny smí zůstat neuvedené, ale
-- uvedené nesmí přerůst měsíc; přesčas je u všech částí odpracovaných hodin.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_worked_breakdown;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_worked_breakdown CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v6',
        'jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9'
      )
      AND worked_days IS NULL
      AND overtime_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v7',
        'jmhz-work-month.v9'
      )
      AND worked_days IS NOT NULL
      AND worked_days <= 31
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    ) OR (
      derivation_version IN ('jmhz-work-month.v6', 'jmhz-work-month.v8')
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
        'jmhz-work-month.v8', 'jmhz-work-month.v9'
      )
      AND compensatory_time_off_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9'
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
      derivation_version NOT IN ('jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9')
      AND holiday_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9')
      AND (unworked_hours_occurred = 1 OR holiday_millihours IS NULL)
      AND (holiday_millihours IS NULL OR holiday_millihours <= 99999999)
    )
  );

-- Svátek uvnitř nepřítomnosti bez mzdy nese jen v9 a jen při interakci IN07.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_unpaid_holidays;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_unpaid_holidays CHECK (
    (
      derivation_version <> 'jmhz-work-month.v9'
      AND holiday_unpaid_millihours IS NULL
    ) OR (
      derivation_version = 'jmhz-work-month.v9'
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
        'jmhz-work-month.v8', 'jmhz-work-month.v9'
      )
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
