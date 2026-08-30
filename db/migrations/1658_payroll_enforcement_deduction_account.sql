-- MyÚčto.cz — Ú-14: exekuce, dobrovolné srážky a rizikové spoření přestávají
-- sdílet jediný účet 379.
--
-- Mzdový můstek posílal na TÝŽ `other_deductions_credit` (379) dobrovolné
-- srážky ze mzdy i exekuční a insolvenční srážky, a výchozí kontace
-- `risky_savings_credit` mířila na tutéž syntetiku. Na jednom účtu tak seděly
-- TŘI věcně různé závazky vůči TŘEM různým skupinám věřitelů:
--   * dobrovolné srážky (§ 146 písm. b) zákoníku práce) — oprávněný z dohody
--     o srážkách,
--   * exekuční a insolvenční srážky (§ 276 a násl. o. s. ř., § 398 odst. 3
--     insolvenčního zákona) — soudní exekutor, resp. insolvenční správce,
--   * povinný příspěvek na spoření u rizikové práce (z. č. 324/2025 Sb.) —
--     penzijní společnost; to není ani srážka zaměstnanci, je to zákonný
--     sociální náklad zaměstnavatele.
-- Platební vrstva všechny tři rozlišuje (`payroll_payment_liabilities.
-- liability_kind` = `deduction` / `enforcement` + `insolvency` /
-- `risky_savings`) a platí je třemi různými příkazy. V deníku je rozlišovala
-- jen pseudonymní dimenze `MZ-SR-…` / `MZ-EX-…` ve sloupci `cost_center`,
-- kterou rizikové spoření nemá vůbec — jeho závazková strana proto nespadla
-- do žádné kategorie reconciliace a kontrolovala se jen oklikou přes
-- nákladovou 527. Zůstatek 379 nešlo odsouhlasit proti žádné z plateb.
--
-- Nový klíč předkontace `enforcement_deductions_credit` (výchozí 379.200) míří
-- exekuce na vlastní analytiku; dobrovolné srážky zůstávají na
-- `other_deductions_credit` (nově výchozí 379.100) a rizikové spoření dostává
-- 379.300.
--
-- ZPĚTNÁ KOMPATIBILITA — stejně konzervativně jako u rozpadu 336 (migrace 1618)
-- a 342 (migrace 1648):
--   * Tahle migrace NEMĚNÍ žádnou existující kontaci. Nový sloupec se
--     u stávajících firem srovná na účet, na kterém obojí viselo dosud, takže
--     se dál účtují společně.
--   * Zaúčtované revize se nemění vůbec: zmrazený snapshot nese vlastní sadu
--     předkontací a `enforcement_deductions_credit` i `risky_savings_credit`
--     jsou v PayrollAccountingDefaults::SNAPSHOT_GATED_ACCOUNTS — dokud klíč
--     snapshot nenese, účtuje se exekuce na účet ostatních srážek a spoření na
--     doslovnou historickou '379' (PayrollAccountingDefaults::PRE_SPLIT_ACCOUNTS),
--     takže opakované zaúčtování dřív schválené revize nespadne na kontrolu
--     cílového otisku.
--   * Mění se jen VÝCHOZÍ hodnoty v PayrollAccountingDefaults
--     (379.100 / 379.200 / 379.300), tedy to, co se předvyplní NOVĚ zakládané
--     firmě.
--   * Analytiky 379.100 / 379.200 / 379.300 se stávajícím firmám do osnovy
--     VĚDOMĚ NEDOPLŇUJÍ (rozbor níž).
-- Reconciliace mzdového účtování je vůči rozpadu imunní: kategorie se párují
-- přes LEFT(account_code, 3), takže všechny tři analytiky spadnou pod prefix
-- 379 a dimenze `MZ-SR-`/`MZ-EX-` je uvnitř dělí dál stejně jako dosud.
--
-- IDEMPOTENCE: `ADD COLUMN IF NOT EXISTS` a UPDATE, který po prvním běhu už
-- žádný řádek nepotká (nebo mu nastaví touž hodnotu). Žádný CHECK se tu
-- nezakládá; tečkovaný tvar účtu povolil opravený CHECK migrace 1613
-- a `payroll_employer_settings` CHECK na účty nemá.

SET NAMES utf8mb4;

-- ── Nová firemní předkontace ────────────────────────────────────────────────
-- Výchozí hodnota sloupce je SYNTETIKA 379, ne 379.200: DEFAULT se propíše do
-- všech STÁVAJÍCÍCH řádků a analytiku v osnově nikdo z nich mít nemusí. Nově
-- zakládaná firma dostane 379.200 z PayrollAccountingDefaults, protože
-- nastavení se zakládá výslovnými hodnotami, ne DEFAULTem sloupce.
ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS enforcement_deductions_credit_account VARCHAR(16) NOT NULL DEFAULT '379'
      COMMENT 'Exekuční a insolvenční srážky — § 276 a násl. o. s. ř., § 398 odst. 3 IZ'
      AFTER other_deductions_credit_account;

-- ── Stávající firmy: exekuce zůstává tam, kde dosud byla ────────────────────
-- Firma, která si účet ostatních srážek přenastavila na vlastní analytiku
-- (379.200, 379.900 …), by s DEFAULTem '379' dostala rozpad, o který nežádala.
-- Srovnání na `other_deductions_credit_account` drží zápis PŘESNĚ takový, jaký
-- byl — obě srážky na jednom účtu — dokud si účetní rozpad sama nenastaví.
--
-- Podmínka je zároveň zámek idempotence: po prvním běhu už řádek s analytickým
-- účtem srážek tuhle hodnotu nemá, a řádek se syntetikou dostane touž hodnotu.
UPDATE payroll_employer_settings
   SET enforcement_deductions_credit_account = other_deductions_credit_account
 WHERE enforcement_deductions_credit_account = '379';

-- ── Rizikové spoření se stávajícím firmám NEPŘEPÍNÁ ─────────────────────────
-- `risky_savings_credit_account` (migrace 1614) má u všech stávajících firem
-- hodnotu '379' a ta tu ZÁMĚRNĚ zůstává. Přepnout ji hromadně na 379.300 by
-- znamenalo přesně tu tichou změnu kontace uprostřed roku, které se celý tenhle
-- postup vyhýbá — a navíc na účet, který firma v osnově nemá. Novou hodnotu
-- dostane jen nově zakládaná firma z PayrollAccountingDefaults.

-- ── Analytiky 379.100 / 379.200 / 379.300 se do osnovy VĚDOMĚ NEDOPLŇUJÍ ─────
-- Jsou v ChartOfAccountsTemplate, takže je dostane každá nově seedovaná osnova.
-- Doplnit je zpětně všem by ale bylo k ničemu i škodlivé zároveň: k ničemu,
-- protože kontace stávajících firem míří dál na syntetiku a nic by je na
-- analytiku nepřepnulo; škodlivé, protože by účetní v osnově našla tři nové
-- prázdné účty, o které nežádala, a `PayrollEmployerSettingsRepository::
-- defaultAccounts()` by je od té chvíle nabízel jako výchozí i firmě, která
-- rozpad nechce.
--
-- Kdo rozpad chce, založí si analytiky v osnově a vybere si je v nastavení
-- mezd. Firma bez nich dostane degradaci na syntetiku 379 —
-- PayrollEmployerSettingsRepository::defaultAccounts() i
-- PayrollEmployerSettingsValidator ji řeší obě, takže se nikomu nic nemění ani
-- nespadne na `unknown_account`.
