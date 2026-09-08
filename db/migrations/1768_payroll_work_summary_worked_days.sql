-- MyÚčto.cz — rozpad odpracované doby v pracovním souhrnu: dny a přesčas.
--
-- Měsíční hlášení ČSSZ má vedle odpracovaných hodin (10268) ještě počet
-- odpracovaných DNŮ (10267) a přesčasové hodiny (10269). Matice povinností je
-- vede jako nepovinné, ale v reálně přijatých hlášeních jsou vyplněné vždy —
-- účetní je tedy dopisovala ručně, přestože obojí vzniká ze stejných časových
-- záznamů, ze kterých se počítají hodiny 10268.
--
-- Nová verze odvození `jmhz-work-month.v4` je nutná ze stejného důvodu jako
-- u v3: obsahový otisk souhrnu (`summary_sha256`) se počítá z kanonického
-- JSONu hodnot, takže rozšíření výčtu klíčů by u dřív zmrazených souhrnů
-- otisk rozbilo. Řádky v2 a v3 proto zůstávají beze změny a nové sloupce
-- u nich mají NULL (= NEUVEDENO, ne nula).
--
-- Přesčas smí být NULL i u v4: minuty nedělitelné třemi se na celé
-- millihodiny nepřevedou a nepovinný atribut se pak radši neuvádí, než aby
-- se zaokrouhloval.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS worked_days INT UNSIGNED NULL
    AFTER worked_millihours,
  ADD COLUMN IF NOT EXISTS overtime_millihours INT UNSIGNED NULL
    AFTER worked_days;

-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže se dotčená
-- omezení nejdřív zahodí a založí znovu — jen tak je migrace opakovatelná.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_absence_evidence,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_worked_breakdown,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding;

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
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4'
      )
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  ),
  -- Hodiny bez atributu hlášení nese v3 i v4 a jen tehdy, když interakce
  -- IN07 nastala: jsou to neodpracované hodiny, které vstupují do úhrnu 10275.
  ADD CONSTRAINT chk_payroll_jmhz_work_month_absence_evidence CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v3', 'jmhz-work-month.v4')
      AND maternity_millihours IS NULL
      AND paternity_millihours IS NULL
      AND parental_millihours IS NULL
      AND unpaid_leave_millihours IS NULL
      AND unexcused_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v3', 'jmhz-work-month.v4')
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
  -- Dny a přesčas smí nést jen v4. Dny jsou u v4 vždy vyplněné (odvozují se
  -- bez zásahu člověka), přesčas smí chybět. Měsíc nemá víc než 31 dnů a
  -- přesčas je částí odpracovaných hodin, takže je nesmí přerůst.
  ADD CONSTRAINT chk_payroll_jmhz_work_month_worked_breakdown CHECK (
    (
      derivation_version <> 'jmhz-work-month.v4'
      AND worked_days IS NULL
      AND overtime_millihours IS NULL
    ) OR (
      derivation_version = 'jmhz-work-month.v4'
      AND worked_days IS NOT NULL
      AND worked_days <= 31
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
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
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4'
      )
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
