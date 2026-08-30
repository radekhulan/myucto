-- MyÚčto.cz — Ú-13: zálohová a srážková daň přestávají sdílet jediný účet 342.
--
-- Mzdový můstek posílal zálohovou daň, srážkovou daň, daňový bonus i doplatek
-- z ročního zúčtování na TÝŽ `income_tax_credit` (342) a rozlišoval je jen
-- `allocation_key`, který se do `journal_entry_lines` nepromítá. Saldo 342 tak
-- neslo obě daně slité dohromady, přestože:
--   * se odvádějí DVĚMA platbami na různá předčíslí účtu FÚ (7704 záloha,
--     7720 srážková),
--   * mají jiný termín odvodu (§ 38h odst. 10 vs. § 38d odst. 3 ZDP),
--   * vykazují se jiným hlášením (vyúčtování § 38j vs. § 38d odst. 3),
--   * a platební vrstva mezd je odjakživa rozlišuje
--     (`advance_tax` / `withholding_tax` v PayrollPostingReconciliationService).
-- Rozdíl mezi zůstatkem účtu a odvedenými platbami proto nešlo přiřadit
-- k jedné z obou daní.
--
-- Nový klíč předkontace `withholding_tax_credit` (výchozí 342.200) míří
-- srážkovou daň na vlastní analytiku; zálohová daň, bonus (§ 35d odst. 9) i
-- doplatek z ročního zúčtování (§ 38ch odst. 5) zůstávají na
-- `income_tax_credit`, protože se všechny vracejí ze ZÁLOH.
--
-- ZPĚTNÁ KOMPATIBILITA — stejně konzervativně jako u rozpadu 336 (migrace 1618):
--   * Tahle migrace NEMĚNÍ žádnou existující kontaci. Nový sloupec se u
--     stávajících firem srovná na účet, na kterém obě daně visely dosud, takže
--     se dál účtují společně.
--   * Zaúčtované revize se nemění vůbec: zmrazený snapshot nese vlastní sadu
--     předkontací a `withholding_tax_credit` je v
--     PayrollAccountingDefaults::SNAPSHOT_GATED_ACCOUNTS — dokud klíč snapshot
--     nenese, účtuje se srážková daň na účet zálohové jako dřív a opakované
--     zaúčtování dřív schválené revize nespadne na kontrolu cílového otisku.
--   * Mění se jen VÝCHOZÍ hodnota v PayrollAccountingDefaults (342.100 / 342.200),
--     tedy to, co se předvyplní NOVĚ zakládané firmě.
--   * Analytiky 342.100 / 342.200 se stávajícím firmám do osnovy VĚDOMĚ
--     NEDOPLŇUJÍ (rozbor níž).
-- Reconciliace mzdového účtování je vůči rozpadu imunní: kategorie se párují
-- přes LEFT(account_code, 3), takže 342.100 i 342.200 spadnou pod prefix 342.
--
-- IDEMPOTENCE: `ADD COLUMN IF NOT EXISTS` a UPDATE, který po prvním běhu už
-- žádný řádek nepotká (nebo mu nastaví touž hodnotu). Žádný CHECK se tu
-- nezakládá; tečkovaný tvar účtu povolil opravený CHECK migrace 1613 a
-- `payroll_employer_settings` CHECK na účty nemá.

SET NAMES utf8mb4;

-- ── Nová firemní předkontace ────────────────────────────────────────────────
-- Výchozí hodnota sloupce je SYNTETIKA 342, ne 342.200: DEFAULT se propíše do
-- všech STÁVAJÍCÍCH řádků a analytiku v osnově nikdo z nich mít nemusí. Nově
-- zakládaná firma dostane 342.200 z PayrollAccountingDefaults, protože nastavení
-- se zakládá výslovnými hodnotami, ne DEFAULTem sloupce.
ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS withholding_tax_credit_account VARCHAR(16) NOT NULL DEFAULT '342'
      COMMENT 'Srážková daň ze závislé činnosti — § 6 odst. 4 a § 36 odst. 2 písm. p) ZDP'
      AFTER income_tax_credit_account;

-- ── Stávající firmy: srážková daň zůstává tam, kde dosud byla ───────────────
-- Firma, která si účet zálohové daně přenastavila na vlastní analytiku
-- (342.200, 342.900 …), by s DEFAULTem '342' dostala rozpad, o který nežádala.
-- Srovnání na `income_tax_credit_account` drží zápis PŘESNĚ takový, jaký byl —
-- obě daně na jednom účtu — dokud si účetní rozpad sama nenastaví.
--
-- Podmínka je zároveň zámek idempotence: po prvním běhu už řádek s analytickým
-- účtem zálohy tuhle hodnotu nemá, a řádek se syntetikou dostane touž hodnotu.
UPDATE payroll_employer_settings
   SET withholding_tax_credit_account = income_tax_credit_account
 WHERE withholding_tax_credit_account = '342';

-- ── Analytiky 342.100 / 342.200 se stávajícím firmám VĚDOMĚ NEDOPLŇUJÍ ──────
-- Jsou v ChartOfAccountsTemplate, takže je dostane každá nově seedovaná osnova.
-- Doplnit je zpětně všem by ale TIŠE ZMĚNILO zaúčtování mzdové rekapitulace
-- (starší větev): PayrollPostingAccountResolver bere existenci 342.100/342.200
-- v osnově jako projev vůle účetní a automaticky na ně přepne. Firma, která si
-- analytiku nikdy nezaložila, by od nasazení účtovala daň jinam než
-- v předchozích měsících a saldo 342 by se rozpadlo uprostřed roku.
--
-- Kdo rozpad chce, založí si obě analytiky v osnově (nebo si je vybere
-- v nastavení mezd) a od té chvíle se použijí. Firma bez nich dostane
-- degradaci na syntetiku 342 — PayrollEmployerSettingsRepository::defaultAccounts(),
-- PayrollEmployerSettingsValidator i PayrollPostingAccountResolver ji řeší
-- všechny tři, takže se nikomu nic nemění ani nespadne na `unknown_account`.
