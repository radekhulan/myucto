-- MyÚčto.cz — W7/Ú-06, Ú-07 a Ú-08: dokončení kontační matice mezd.
--
-- ── Ú-06 non_deductible_benefit_debit (528) ─────────────────────────────────
-- Benefit v koši § 6 odst. 9 písm. d) ZDP se dosud účtoval JEDNOU částkou na
-- JEDEN účet (výchozí 521), přestože modul osvobozenou a nadlimitní část zná
-- (`benefit_exempt_minor` / `benefit_taxable_minor`, migrace 1480). Celý benefit
-- tak seděl mezi daňově uznatelnými mzdovými náklady.
--
-- § 25 odst. 1 písm. h) ZDP ve znění od 1. 1. 2024 (z. č. 349/2023 Sb.) přitom
-- vylučuje z nákladů nepeněžní plnění ve formě rekreace, zájezdu, sportu,
-- kultury, tištěných knih a zdravotnických / vzdělávacích / rekreačních zařízení
-- „a to v rozsahu, ve kterém je toto plnění u zaměstnance osvobozeno od daně
-- z příjmů". Nedaňová je tedy OSVOBOZENÁ část, ne nadlimitní — nadlimitní část
-- se zaměstnanci zdaní a zaměstnavateli je uznatelná podle § 24 odst. 2 písm. j)
-- bodu 4. Zápis se proto dělí opačně, než by se na první pohled zdálo:
--   osvobozená část  → 528 (Ostatní sociální náklady, nedaňové)
--   nadlimitní část  → dosavadní účet (521 nebo vlastní předkontace složky)
-- 528 je v ChartOfAccountsTemplate::NON_DEDUCTIBLE_SYNTHETICS, takže se částka
-- sama propíše do nedaňových nákladů DPPO (DppoReturnDataProvider → ř. 40).
--
-- Dělí se JEN koše `non_cash_health` a `non_cash_leisure`. Stravování (písm. b),
-- produkty spoření na stáří (písm. m) a přechodné ubytování (písm. i) jsou podle
-- § 24 odst. 2 písm. j) uznatelné a dělit se nesmí.
--
-- ── Ú-07 travel_expense_debit (512) ─────────────────────────────────────────
-- Seedované složky CESTOVNI_NAHRADA / _LIMIT / _NADLIMIT předkontaci nemají,
-- takže propadly na výchozí účet hrubé mzdy a náhrada výdaje podle části sedmé
-- zákoníku práce skončila na 521. Účet 512 (Cestovné) se v mzdovém modulu
-- nevyskytoval vůbec. Nově se složky druhu `travel_reimbursement` bez vlastní
-- předkontace účtují na 512.
--
-- PROTIÚČET ZŮSTÁVÁ ZÁVAZKOVÝ ÚČET VZTAHU (331, u společníka a člena orgánu
-- 366), NE 333. Zvažováno bylo, účetně by 333 (Ostatní závazky vůči
-- zaměstnancům) bylo čistší, ale znamenalo by to tři další zásahy do jádra:
--   1. Náhrada je součástí čisté výplaty a platebního závazku `net_wage`.
--      Kategorie `net_wage` v reconciliaci mzdového účtování počítá jen prefixy
--      331/366 (PayrollPostingAccountPolicy::NET_WAGE_PREFIXES), takže by
--      každé období s cestovní náhradou hlásilo rozdíl proti deníku.
--   2. Poměrové rozdělení srážek (PayrollPostingLineBuilder::addEmployeeCharge)
--      váží závazkové účty peněžním příjmem vstupu. Účet 333 by dostal poměrný
--      díl zálohové daně a pojistného, přestože se z náhrady nesráží nic.
--   3. Nadlimitní náhrada zdanitelným příjmem JE a do vyměřovacích základů
--      vstupuje, takže by rozdělení muselo být ještě uvnitř složky.
-- Rozdělení protiúčtu je proto samostatná práce s vlastními testy; tahle
-- migrace řeší chybu, která je jednoznačná a bezpečná — nákladový druh.
--
-- ── Ú-08 336.100 / 336.200 ──────────────────────────────────────────────────
-- `social_insurance_credit` i `health_insurance_credit` mířily na 336. Závazek
-- vůči ČSSZ a vůči zdravotním pojišťovnám se na jednom syntetickém účtu
-- vzájemně vynetuje a saldo proti dvěma (a s více pojišťovnami i více) platbám
-- nesedí.
--
-- ZPĚTNÁ KOMPATIBILITA JE TU KRITICKÁ a řeší se KONZERVATIVNĚ:
--   * Tahle migrace NEMĚNÍ žádné existující nastavení firmy. Kdo má uloženou
--     336, má ji dál.
--   * Zaúčtované revize se nemění vůbec: zmrazený snapshot nese vlastní sadu
--     předkontací a účtuje se z ní (PayrollApprovedRevisionPostingService).
--   * Mění se jen VÝCHOZÍ hodnota v PayrollAccountingDefaults, tedy to, co se
--     předvyplní NOVĚ zakládané firmě.
--   * Obě analytiky se stávajícím firmám do osnovy VĚDOMĚ NEDOPLŇUJÍ (rozbor
--     u příslušné sekce níž). Firma bez nich dostane degradaci na syntetiku
--     336, tedy přesně dosavadní stav; kdo rozpad chce, analytiky si založí.
-- Reconciliace mzdového účtování je vůči rozpadu imunní: kategorie se párují
-- přes LEFT(account_code, 3), takže 336.100 i 336.200 spadnou pod prefix 336.
--
-- IDEMPOTENCE: `ADD COLUMN IF NOT EXISTS` a doplnění osnovy přes
-- `LEFT JOIN … IS NULL`, takže opakované spuštění nic nemění. Žádný CHECK se
-- tu nezakládá; tečkovaný tvar účtu povolil už opravený CHECK migrace 1613
-- a `payroll_employer_settings` CHECK na účty nemá.

SET NAMES utf8mb4;

-- ── Nové firemní předkontace ────────────────────────────────────────────────
-- Sloupce jsou NOT NULL s výchozím účtem, takže existující firmy nemusí nic
-- vyplňovat a nastavení mezd jde uložit i ze staršího klienta (chybějící klíč
-- doplní PayrollEmployerSettingsValidator ze směrné osnovy — viz
-- PayrollAccountingDefaults::OPTIONAL_ACCOUNTS).
--
-- Samotné doplnění výchozí hodnoty ale zápis NEZMĚNÍ: obě předkontace jsou
-- navíc v PayrollAccountingDefaults::SNAPSHOT_GATED_ACCOUNTS, takže se nové
-- dělení použije jen tehdy, když klíč nese ZMRAZENÝ snapshot revize. Revize
-- schválené dřív se přeúčtují byte-identicky a nespadnou na kontrolu cílového
-- otisku v PayrollPostingAdapter.
ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS non_deductible_benefit_debit_account VARCHAR(16) NOT NULL DEFAULT '528'
      COMMENT 'Daňově neuznatelná (osvobozená) část benefitu — § 25 odst. 1 písm. h) ZDP'
      AFTER employee_receivable_debit_account,
  ADD COLUMN IF NOT EXISTS travel_expense_debit_account VARCHAR(16) NOT NULL DEFAULT '512'
      COMMENT 'Cestovné — náhrada výdaje zaměstnance, není mzdový náklad'
      AFTER non_deductible_benefit_debit_account;

-- ── Účty do osnovy firmám, které je nemají ─────────────────────────────────
-- PayrollEmployerSettingsValidator vyžaduje, aby KAŽDÁ předkontace v osnově
-- tenanta existovala a byla aktivní; bez tohohle dorovnání by firma bez 528
-- nebo 512 najednou nemohla uložit nastavení mezd jen proto, že přibyly nové
-- předkontace. Stejný vzor jako migrace 1614 u účtů 527 a 335.
INSERT INTO chart_of_accounts
  (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, '528', 'Ostatní sociální náklady', 'expense', 'debit', 1, NULL, 1, 'non_deductible'
FROM (
  SELECT DISTINCT p.supplier_id AS id
    FROM chart_of_accounts p
    LEFT JOIN chart_of_accounts c
           ON c.supplier_id = p.supplier_id AND c.account_code = '528'
   WHERE c.id IS NULL
) AS s;

INSERT INTO chart_of_accounts
  (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, '512', 'Cestovné', 'expense', 'debit', 1, NULL, 1, 'deductible'
FROM (
  SELECT DISTINCT p.supplier_id AS id
    FROM chart_of_accounts p
    LEFT JOIN chart_of_accounts c
           ON c.supplier_id = p.supplier_id AND c.account_code = '512'
   WHERE c.id IS NULL
) AS s;

-- ── Analytiky 336.100 / 336.200 se stávajícím firmám VĚDOMĚ NEDOPLŇUJÍ ──────
-- Jsou v ChartOfAccountsTemplate, takže je dostane každá nově seedovaná osnova.
-- Doplnit je zpětně všem by ale TIŠE ZMĚNILO zaúčtování mzdové rekapitulace
-- (starší větev): PayrollPostingAccountResolver bere existenci 336.100/336.200
-- v osnově jako projev vůle účetní a automaticky na ně přepne. Firma, která si
-- analytiku nikdy nezaložila, by od nasazení účtovala pojistné jinam než
-- v předchozích měsících a saldo 336 by se rozpadlo na tři účty uprostřed roku.
--
-- Kdo rozpad chce, založí si obě analytiky v osnově (nebo si je vybere
-- v nastavení mezd) a od té chvíle se použijí. Firma bez nich dostane
-- degradaci na syntetiku 336 — PayrollEmployerSettingsRepository::defaultAccounts()
-- a PayrollPostingAccountResolver ji řeší obě, takže se nikomu nic nemění ani
-- nespadne na `unknown_account`.
