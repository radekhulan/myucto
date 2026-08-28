-- MyÚčto.cz — W3/Ú-01 a Ú-05: tři nové firemní předkontace mezd.
--
-- 1) risky_savings_debit / risky_savings_credit (527 / 379)
--    Povinný příspěvek zaměstnavatele na spoření u rizikové práce podle
--    z. č. 324/2025 Sb. (4 % vyměřovacího základu od 1. 1. 2026) se dosud
--    spočítal, zmrazil do `payroll_risky_savings_contributions` a zaplatil
--    jako závazek `liability_kind = 'risky_savings'` — ale v účetnictví po
--    něm nezůstala ANI STOPA: žádný náklad, žádný závazek.
--
--    Kontace: 527 (zákonné sociální náklady) MD / 379 (jiné závazky) D.
--    Je to zákonný náklad ZAMĚSTNAVATELE, ne mzda zaměstnance — nevyplácí se
--    mu, posílá se penzijní společnosti, takže do 331 nepatří. 336 taky ne:
--    penzijní společnost není institucí sociálního zabezpečení ani zdravotní
--    pojišťovnou. Kdo chce příspěvek oddělit od ostatních zákonných nákladů,
--    přemapuje si sloupce na vlastní analytiku (527.100 / 379.100) — proto
--    jsou to konfigurovatelné předkontace, ne natvrdo napsané účty.
--
-- 2) employee_receivable_debit (335)
--    Záporná čistá mzda je legitimní stav, ne chyba: zaměstnanec celý měsíc
--    na neplaceném volnu platí doplatek ZP do minimálního vyměřovacího
--    základu (§ 3 odst. 10 z. 592/1992 Sb.) a nemá z čeho. Závazkový účet
--    mzdy by zůstal debetní, což je v rozvaze nesmysl — přeplatek se překlopí
--    na pohledávku za zaměstnancem (MD 335 / D 331, resp. 366).
--
-- Sloupce jsou NOT NULL s výchozím účtem, takže existující firmy nemusí nic
-- vyplňovat a nastavení mezd jde uložit i ze staršího klienta (chybějící klíč
-- doplní PayrollEmployerSettingsValidator ze směrné osnovy — viz
-- PayrollAccountingDefaults::OPTIONAL_ACCOUNTS).
--
-- IDEMPOTENCE: `ADD COLUMN IF NOT EXISTS` a doplnění osnovy přes
-- `LEFT JOIN ... IS NULL`, takže opakované spuštění nic nemění.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS risky_savings_debit_account VARCHAR(16) NOT NULL DEFAULT '527'
      COMMENT 'Zákonné sociální náklady — povinný příspěvek na spoření u rizikové práce'
      AFTER partner_settlement_credit_account,
  ADD COLUMN IF NOT EXISTS risky_savings_credit_account VARCHAR(16) NOT NULL DEFAULT '379'
      COMMENT 'Závazek z povinného příspěvku na spoření u rizikové práce'
      AFTER risky_savings_debit_account,
  ADD COLUMN IF NOT EXISTS employee_receivable_debit_account VARCHAR(16) NOT NULL DEFAULT '335'
      COMMENT 'Pohledávka za zaměstnancem — přeplatek čisté mzdy'
      AFTER risky_savings_credit_account;

-- Účty 527 a 335 do osnovy firmám, které je nemají.
-- PayrollEmployerSettingsValidator vyžaduje, aby KAŽDÁ předkontace v osnově
-- tenanta existovala a byla aktivní; bez tohohle dorovnání by firma bez 527
-- nebo 335 najednou nemohla uložit nastavení mezd jen proto, že přibyly nové
-- předkontace. Oba účty jsou v ChartOfAccountsTemplate, takže se jen doplňují
-- starší tenanti. Stejný vzor jako migrace 1374 u účtu 365.
INSERT INTO chart_of_accounts
  (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, '527', 'Zákonné sociální náklady', 'expense', 'debit', 1, NULL, 1, 'deductible'
FROM (
  SELECT DISTINCT p.supplier_id AS id
    FROM chart_of_accounts p
    LEFT JOIN chart_of_accounts c
           ON c.supplier_id = p.supplier_id AND c.account_code = '527'
   WHERE c.id IS NULL
) AS s;

INSERT INTO chart_of_accounts
  (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, '335', 'Pohledávky za zaměstnanci', 'asset', 'debit', 1, NULL, 1, 'deductible'
FROM (
  SELECT DISTINCT p.supplier_id AS id
    FROM chart_of_accounts p
    LEFT JOIN chart_of_accounts c
           ON c.supplier_id = p.supplier_id AND c.account_code = '335'
   WHERE c.id IS NULL
) AS s;
