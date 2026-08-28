-- MyÚčto.cz — W14: výstupní checklist zná ELDP i potvrzení o zdanitelných
-- příjmech a termíny položek vycházejí ze ZÁKONNÉ LHŮTY, ne ze dne události.
--
-- Co bylo špatně
-- --------------
-- 1. `PayrollEmploymentRepository::ensureChecklist()` seedovalo všechny
--    položky fáze na den události (nástup / účinnost změny / skončení).
--    U přihlášky ČSSZ i u oznámení zdravotní pojišťovně je to o osm dnů
--    vedle, u evidenčního listu o měsíce. Varování, které lže, se přestane
--    číst a obsluha se řídí datem, které se zákonem nesouvisí.
-- 2. Výstupní checklist neobsahoval ani evidenční list důchodového pojištění,
--    ani potvrzení o zdanitelných příjmech, takže hlásil „hotovo" u vztahu,
--    u kterého se ELDP vůbec nepodal.
--
-- Co migrace dělá
-- ---------------
-- a) Přidá k položce citaci pramene lhůty (ruleset, paragraf, stav pramene),
--    aby bylo u termínu vidět, ODKUD je — stejně jako u lhůt podání.
-- b) Doplní do výstupní fáze `eldp_submission` a `taxable_income_confirmation`
--    u vztahů, které už výstupní checklist mají.
-- c) Přepočte termíny NEVYŘÍZENÝCH položek na zákonnou lhůtu. Vyřízené
--    a „netýká se" se nesahají — jejich termín je součást historie.
--
-- Meze, které migrace vědomě drží
-- -------------------------------
-- * `eldp_submission` se zakládá jen tam, kde se samostatný evidenční list
--   podle *Pravidel podání JMHZ 1.4.4* opravdu vede: za roky před 2026
--   a při skončení účasti před 1. 4. 2026. Od té doby ho sestavuje ČSSZ
--   z měsíčního hlášení a zakládat povinnost, která nevznikne, je planý
--   poplach. Větev „na výzvu ČSSZ" tudy nejde — výzva je událost, ne stav.
-- * `taxable_income_confirmation` má termín NULL: § 38j odst. 3 ZDP dává
--   deset dnů od ŽÁDOSTI zaměstnance, a den žádosti aplikace neeviduje.
--   Prázdno je poctivější než dohadované datum.
-- * Položky bez zákonné lhůty (kontrola exekucí a insolvence, kontrola
--   pozdějšího doplatku, dodatek ke smlouvě) termín ZTRÁCEJÍ. Doteď měly
--   den události, což vypadalo jako lhůta a žádná nebyla.
-- * Změnová fáze se nepřepočítává vůbec: den účinnosti změny se z vztahu
--   zpětně dohledat nedá a odhad by byl další vymyšlený termín.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, INSERT IGNORE, UPDATE nad
-- deterministicky spočítanou hodnotou.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_checklist_items
  ADD COLUMN IF NOT EXISTS deadline_ruleset_id VARCHAR(96) NULL AFTER due_date,
  ADD COLUMN IF NOT EXISTS deadline_source VARCHAR(255) NULL AFTER deadline_ruleset_id,
  ADD COLUMN IF NOT EXISTS deadline_source_status VARCHAR(32) NULL AFTER deadline_source;

-- --------------------------------------------------------------------------
-- b) Nové položky výstupní fáze
-- --------------------------------------------------------------------------

-- Potvrzení o zdanitelných příjmech — vydává se každému, kdo o ně požádá,
-- bez ohledu na druh vztahu.
INSERT IGNORE INTO payroll_employment_checklist_items (
  supplier_id, employment_id, phase, item_key, status, due_date,
  deadline_ruleset_id, deadline_source, deadline_source_status
)
SELECT DISTINCT existing.supplier_id,
       existing.employment_id,
       'offboarding',
       'taxable_income_confirmation',
       'pending',
       NULL,
       NULL,
       NULL,
       'not_derived'
  FROM payroll_employment_checklist_items existing
 WHERE existing.phase = 'offboarding';

-- Evidenční list — jen tam, kde ho zaměstnavatel podle přechodných pravidel
-- opravdu vede sám.
INSERT IGNORE INTO payroll_employment_checklist_items (
  supplier_id, employment_id, phase, item_key, status, due_date,
  deadline_ruleset_id, deadline_source, deadline_source_status
)
SELECT DISTINCT existing.supplier_id,
       existing.employment_id,
       'offboarding',
       'eldp_submission',
       'pending',
       LEAST(
         DATE_ADD(employment.end_date, INTERVAL 1 MONTH),
         DATE(CONCAT(YEAR(employment.end_date) + 1, '-01-31'))
       ),
       'cz-eldp-deadlines.termination.v1',
       'Zákon č. 582/1991 Sb., § 38 odst. 4 ve znění účinném do 31. 12. 2025',
       'external_unverified'
  FROM payroll_employment_checklist_items existing
  JOIN payroll_employments employment
    ON employment.supplier_id = existing.supplier_id
   AND employment.id = existing.employment_id
 WHERE existing.phase = 'offboarding'
   AND employment.end_date IS NOT NULL
   AND (YEAR(employment.end_date) < 2026
        OR employment.end_date < '2026-04-01');

-- --------------------------------------------------------------------------
-- c) Přepočet termínů nevyřízených položek na zákonnou lhůtu
-- --------------------------------------------------------------------------

-- Pracovní smlouva / dohoda: uzavírá se písemně, tedy nejpozději v den nástupu.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = COALESCE(employment.actual_start_date, employment.start_date),
       item.deadline_ruleset_id = 'cz-payroll-checklist-deadlines.contract.v1',
       item.deadline_source = '§ 34 odst. 2 zákona č. 262/2006 Sb.',
       item.deadline_source_status = 'external_unverified'
 WHERE item.phase = 'onboarding'
   AND item.item_key = 'employment_contract'
   AND item.status = 'pending'
   AND COALESCE(employment.actual_start_date, employment.start_date) IS NOT NULL;

-- Prohlášení poplatníka: do 30 dnů po vstupu do zaměstnání.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = DATE_ADD(
         COALESCE(employment.actual_start_date, employment.start_date),
         INTERVAL 30 DAY
       ),
       item.deadline_ruleset_id = 'cz-payroll-checklist-deadlines.tax-declaration.v1',
       item.deadline_source = '§ 38k odst. 4 zákona č. 586/1992 Sb.',
       item.deadline_source_status = 'external_unverified'
 WHERE item.phase = 'onboarding'
   AND item.item_key = 'tax_declaration'
   AND item.status = 'pending'
   AND COALESCE(employment.actual_start_date, employment.start_date) IS NOT NULL;

-- Oznámení nástupu zdravotní pojišťovně: osm dnů (§ 10 zák. č. 48/1997 Sb.),
-- u dohod 20. den následujícího měsíce podle metodiky VZP.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = CASE
         WHEN employment.relation_type IN ('dpp', 'dpc')
           THEN DATE_FORMAT(
                  DATE_ADD(
                    COALESCE(employment.actual_start_date, employment.start_date),
                    INTERVAL 1 MONTH
                  ),
                  '%Y-%m-20'
                )
         ELSE DATE_ADD(
                COALESCE(employment.actual_start_date, employment.start_date),
                INTERVAL 8 DAY
              )
       END,
       item.deadline_ruleset_id = 'cz-health-insurance-notification-deadlines.v1',
       item.deadline_source = CASE
         WHEN employment.relation_type IN ('dpp', 'dpc')
           THEN 'metodika VZP k oznamovací povinnosti u dohod (DPP a DPČ)'
         ELSE '§ 10 zákona č. 48/1997 Sb.'
       END,
       item.deadline_source_status = CASE
         WHEN employment.relation_type IN ('dpp', 'dpc')
           THEN 'external_unverified'
         ELSE 'statute_verified'
       END
 WHERE item.phase = 'onboarding'
   AND item.item_key = 'health_insurance_registration'
   AND item.status = 'pending'
   AND COALESCE(employment.actual_start_date, employment.start_date) IS NOT NULL;

-- Oznámení skončení zdravotní pojišťovně: stejné pravidlo od dne skončení.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = CASE
         WHEN employment.relation_type IN ('dpp', 'dpc')
           THEN DATE_FORMAT(
                  DATE_ADD(employment.end_date, INTERVAL 1 MONTH),
                  '%Y-%m-20'
                )
         ELSE DATE_ADD(employment.end_date, INTERVAL 8 DAY)
       END,
       item.deadline_ruleset_id = 'cz-health-insurance-notification-deadlines.v1',
       item.deadline_source = CASE
         WHEN employment.relation_type IN ('dpp', 'dpc')
           THEN 'metodika VZP k oznamovací povinnosti u dohod (DPP a DPČ)'
         ELSE '§ 10 zákona č. 48/1997 Sb.'
       END,
       item.deadline_source_status = CASE
         WHEN employment.relation_type IN ('dpp', 'dpc')
           THEN 'external_unverified'
         ELSE 'statute_verified'
       END
 WHERE item.phase = 'offboarding'
   AND item.item_key = 'health_insurance_deregistration'
   AND item.status = 'pending'
   AND employment.end_date IS NOT NULL;

-- Přihláška pracovního vztahu u ČSSZ: podává se před zahájením práce, tedy
-- nejpozději v den nástupu. Pro nástup před 1. 7. 2026 registrační povinnost
-- u zaměstnance neexistovala — termín se nedoplňuje.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = COALESCE(employment.actual_start_date, employment.start_date),
       item.deadline_ruleset_id = 'cz-employee-registration-2026-07.v1',
       item.deadline_source = '§ 19 odst. 1 zákona č. 323/2025 Sb.',
       item.deadline_source_status = 'external_unverified'
 WHERE item.phase = 'onboarding'
   AND item.item_key = 'social_jmhz_registration'
   AND item.status = 'pending'
   AND COALESCE(employment.actual_start_date, employment.start_date) >= '2026-07-01';

-- Odhláška u ČSSZ (REGZEC A2/A8): do osmi dnů od skončení, od účinnosti
-- navazujících hlášení.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = DATE_ADD(employment.end_date, INTERVAL 8 DAY),
       item.deadline_ruleset_id = 'cz-regzec-follow-up-2026-04.v1',
       item.deadline_source = '§ 19 zákona č. 323/2025 Sb. — navazující hlášení REGZEC A2 až A8',
       item.deadline_source_status = 'external_unverified'
 WHERE item.phase = 'offboarding'
   AND item.item_key = 'social_jmhz_deregistration'
   AND item.status = 'pending'
   AND employment.end_date >= '2026-04-01';

-- Potvrzení o zaměstnání (zápočtový list) se vydává při skončení.
UPDATE payroll_employment_checklist_items item
  JOIN payroll_employments employment
    ON employment.supplier_id = item.supplier_id
   AND employment.id = item.employment_id
   SET item.due_date = employment.end_date,
       item.deadline_ruleset_id = NULL,
       item.deadline_source = '§ 313 odst. 1 zákona č. 262/2006 Sb.',
       item.deadline_source_status = 'external_unverified'
 WHERE item.phase = 'offboarding'
   AND item.item_key = 'termination_document'
   AND item.status = 'pending'
   AND employment.end_date IS NOT NULL;

-- Položky bez zákonné lhůty termín ztrácejí — den události lhůtou není.
UPDATE payroll_employment_checklist_items item
   SET item.due_date = NULL,
       item.deadline_ruleset_id = NULL,
       item.deadline_source = NULL,
       item.deadline_source_status = 'not_derived'
 WHERE item.status = 'pending'
   AND item.item_key IN (
     'contract_amendment',
     'enforcement_insolvency_review',
     'later_income_review',
     'legacy_start_date'
   );
