-- MyÚčto.cz — N-01: hodiny nepřítomností bez atributu hlášení v pracovním souhrnu.
--
-- Peněžitá pomoc v mateřství, otcovská, rodičovská dovolená, neplacené volno
-- a neomluvená absence nemají v měsíčním hlášení žádný z bloků 10275–10280
-- ani 10471/10472 — ČSSZ se hlásí po DNECH v evidenčním listu. Ordinary ELDP
-- řez ale páruje dny z evidence absencí s hodinami z publikovaných směn, takže
-- bez těchhle sloupců se nedaly proti ničemu doložit a blokovaly hlášení
-- CELÉ firmy (nález N-01 auditu mzdového modulu).
--
-- Nová verze odvození `jmhz-work-month.v3` je nutná: obsahový otisk souhrnu
-- (`summary_sha256`) se počítá z kanonického JSONu hodnot, takže rozšíření
-- výčtu klíčů by u dřív zmrazených v2 souhrnů otisk rozbilo. v2 řádky proto
-- zůstávají beze změny a nové sloupce mají NULL.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS maternity_millihours INT UNSIGNED NULL
    AFTER employer_obstacle_millihours,
  ADD COLUMN IF NOT EXISTS paternity_millihours INT UNSIGNED NULL
    AFTER maternity_millihours,
  ADD COLUMN IF NOT EXISTS parental_millihours INT UNSIGNED NULL
    AFTER paternity_millihours,
  ADD COLUMN IF NOT EXISTS unpaid_leave_millihours INT UNSIGNED NULL
    AFTER parental_millihours,
  ADD COLUMN IF NOT EXISTS unexcused_millihours INT UNSIGNED NULL
    AFTER unpaid_leave_millihours;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_unworked_block,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_obstacle_block,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_ranges,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_absence_evidence;

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
      derivation_version IN ('jmhz-work-month.v2', 'jmhz-work-month.v3')
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_unworked_block CHECK (
    derivation_version = 'jmhz-work-month-core.v1' OR (
      (
        unworked_hours_occurred = 0
        AND unworked_total_millihours IS NULL
        AND unworked_paid_millihours IS NULL
        AND dpn_without_employer_compensation_millihours IS NULL
        AND dpn_with_employer_compensation_millihours IS NULL
        AND vacation_millihours IS NULL
        AND care_millihours IS NULL
      ) OR (
        unworked_hours_occurred = 1
        AND unworked_total_millihours IS NOT NULL
        AND unworked_total_millihours > 0
      )
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_obstacle_block CHECK (
    derivation_version = 'jmhz-work-month-core.v1' OR (
      (
        work_obstacles_occurred = 0
        AND employee_obstacle_paid_millihours IS NULL
        AND employer_obstacle_millihours IS NULL
      ) OR (
        work_obstacles_occurred = 1
        AND unworked_hours_occurred = 1
        AND (
          employee_obstacle_paid_millihours IS NOT NULL
          OR employer_obstacle_millihours IS NOT NULL
        )
      )
    )
  ),
  -- Hodiny bez atributu hlášení smí nést jen v3 a jen tehdy, když interakce
  -- IN07 nastala: jsou to neodpracované hodiny, které vstupují do úhrnu 10275.
  ADD CONSTRAINT chk_payroll_jmhz_work_month_absence_evidence CHECK (
    (
      derivation_version <> 'jmhz-work-month.v3'
      AND maternity_millihours IS NULL
      AND paternity_millihours IS NULL
      AND parental_millihours IS NULL
      AND unpaid_leave_millihours IS NULL
      AND unexcused_millihours IS NULL
    ) OR (
      derivation_version = 'jmhz-work-month.v3'
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
  ADD CONSTRAINT chk_payroll_jmhz_work_month_conditional_ranges CHECK (
    (unworked_total_millihours IS NULL OR unworked_total_millihours <= 99999999)
    AND (unworked_paid_millihours IS NULL OR unworked_paid_millihours <= 99999999)
    AND (
      dpn_without_employer_compensation_millihours IS NULL
      OR dpn_without_employer_compensation_millihours <= 99999999
    )
    AND (
      dpn_with_employer_compensation_millihours IS NULL
      OR dpn_with_employer_compensation_millihours <= 99999999
    )
    AND (vacation_millihours IS NULL OR vacation_millihours <= 99999999)
    AND (care_millihours IS NULL OR care_millihours <= 99999999)
    AND (
      unworked_paid_millihours IS NULL
      OR vacation_millihours IS NULL
      OR unworked_paid_millihours >= vacation_millihours
    )
    AND (
      employee_obstacle_paid_millihours IS NULL
      OR employee_obstacle_paid_millihours <= agreed_fund_millihours
    )
    AND (
      employer_obstacle_millihours IS NULL
      OR employer_obstacle_millihours <= agreed_fund_millihours
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_control_binding CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND control_catalog_key IS NULL
      AND control_manifest_sha256 IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v2', 'jmhz-work-month.v3')
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
