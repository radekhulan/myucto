-- Souhrny mzdového běhu ve sloupcích, aby se kvůli nim nečetly megabajtové
-- snímky revizí.
--
-- 1) Seznam běhů potřebuje u každého běhu tři součty a příznak, zda jdou
--    materializovat výplaty. Dosud je MariaDB při každém výpisu vytahovala
--    z JSON snímků aktuální revize (u 150 osob 2 až 5 MB na běh). Souhrn se
--    teď ukládá k běhu při založení revize a při výpočtu. `summary_revision_id`
--    říká, ke které revizi souhrn patří: nesedí-li s aktuální revizí, čte se
--    postaru ze snímku, takže zastaralý souhrn nikdy nevrátí špatné číslo.
--    Do `payroll_run_revisions` souhrn uložit nejde: schválená revize je
--    neměnná a staré řádky by nešlo doplnit.
--
-- 2) Přehled osob ve spočítaném běhu se stránkuje a řadí podle částek na
--    serveru. Částky osoby jsou proto vedle jejího JSON výsledku i ve sloupcích.
--
-- Doplnění starých řádků je jednorázové čtení snímků; opakované spuštění už
-- nic nenajde (běh má souhrn své aktuální revize, osoba má hrubou mzdu).

ALTER TABLE payroll_runs
  ADD COLUMN IF NOT EXISTS summary_revision_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS summary_person_count INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS summary_payment_materialization TINYINT(1) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS summary_cash_payable_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS summary_enforcement_withheld_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS summary_payable_after_enforcement_minor BIGINT NULL DEFAULT NULL;

ALTER TABLE payroll_run_persons
  ADD COLUMN IF NOT EXISTS result_status VARCHAR(16) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS gross_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS employee_social_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS employee_health_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS advance_tax_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS withholding_tax_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tax_bonus_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS net_minor BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS payable_minor BIGINT NULL DEFAULT NULL;

UPDATE payroll_runs run
  JOIN payroll_run_revisions revision
    ON revision.supplier_id = run.supplier_id
   AND revision.run_id = run.id
   AND revision.revision_no = run.current_revision_no
   SET run.summary_revision_id = revision.id,
       run.summary_person_count =
           COALESCE(JSON_LENGTH(revision.input_snapshot_json, '$.people'), 0),
       run.summary_payment_materialization = (
           JSON_VALUE(revision.input_snapshot_json, '$.schema_version') = 'payroll-run-input.v2'
           AND JSON_TYPE(JSON_QUERY(revision.input_snapshot_json, '$.people')) = 'ARRAY'
           AND JSON_LENGTH(revision.input_snapshot_json, '$.people')
               = COALESCE(JSON_LENGTH(JSON_EXTRACT(
                     revision.input_snapshot_json, '$.people[*].payout_accounts'
                 )), 0)
       ),
       run.summary_cash_payable_minor =
           JSON_VALUE(revision.result_snapshot_json, '$.totals.cash_payable_minor'),
       run.summary_enforcement_withheld_minor =
           JSON_VALUE(revision.result_snapshot_json, '$.totals.enforcement_withheld_minor'),
       run.summary_payable_after_enforcement_minor =
           JSON_VALUE(revision.result_snapshot_json, '$.totals.payable_after_enforcement_minor'),
       run.updated_at = run.updated_at
 WHERE run.summary_revision_id IS NULL
    OR run.summary_revision_id <> revision.id;

-- Částky osoby jsou tytéž, jaké ukazuje rozpad čisté mzdy
-- (PayrollNetResultQueryService): hrubá = peněžní + nepeněžní příjem, čistá
-- před srážkami, k výplatě po srážkách a exekucích. Osoba bez zákonného
-- výsledku má jen hrubou ze součtu vstupů.
UPDATE payroll_run_persons
   SET result_status = JSON_VALUE(result_json, '$.statutory.status'),
       gross_minor = COALESCE(
           CAST(JSON_VALUE(result_json, '$.statutory.net_pay.cash_income_minor_units') AS SIGNED)
               + CAST(JSON_VALUE(result_json, '$.statutory.net_pay.non_cash_income_minor_units') AS SIGNED),
           JSON_VALUE(result_json, '$.totals.cash_payable_minor'),
           0
       ),
       employee_social_minor =
           JSON_VALUE(result_json, '$.statutory.net_pay.employee_social_minor_units'),
       employee_health_minor =
           JSON_VALUE(result_json, '$.statutory.net_pay.employee_health_minor_units'),
       advance_tax_minor =
           JSON_VALUE(result_json, '$.statutory.net_pay.advance_tax_minor_units'),
       withholding_tax_minor =
           JSON_VALUE(result_json, '$.statutory.net_pay.withholding_tax_minor_units'),
       tax_bonus_minor =
           JSON_VALUE(result_json, '$.statutory.net_pay.tax_bonus_minor_units'),
       net_minor =
           JSON_VALUE(result_json, '$.statutory.net_pay.net_before_deductions_minor_units'),
       payable_minor = JSON_VALUE(result_json, '$.payable_after_enforcement_minor')
 WHERE result_json IS NOT NULL
   AND gross_minor IS NULL;
