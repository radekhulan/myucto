-- MyÚčto.cz — W28 / V-25: potvrzení od předchozího plátce nese období od–do.
--
-- PROČ
--
-- § 38ch odst. 1 dovoluje roční zúčtování jen poplatníkovi, který ve
-- zdaňovacím období „pobíral mzdu pouze od jednoho nebo od více plátců daně
-- POSTUPNĚ". Totéž slovo nese § 38g odst. 2 při vymezení, kdy poplatník NENÍ
-- povinen podat daňové přiznání. Postupně znamená za sebou; má-li poplatník
-- příjmy podle § 6 od dvou plátců SOUČASNĚ, výjimka se neuplatní, přiznání
-- podat musí (§ 38g odst. 1) a plátce zúčtování podle § 38ch odst. 1 věty
-- druhé provést nesmí.
--
-- Rozdíl mezi „postupně“ a „souběžně“ je tedy hranicí mezi provedeným
-- a neprovedeným ročním zúčtováním. Doklad od předchozího plátce ale dosud
-- nenesl ŽÁDNÝ údaj o období, za které byl vystaven — jen částky a datum
-- převzetí. Souběh z něj nešlo poznat ani teoreticky a modul se musel spolehnout
-- výhradně na to, co o sobě poplatník prohlásí.
--
-- Tiskopis 25 5460 MFin 5460 (Potvrzení o zdanitelných příjmech ze závislé
-- činnosti) přitom období „za období od – do“ obsahuje, takže se zadává něco,
-- co účetní má před sebou na papíře.
--
-- CO SE TÍM ZAVŘE A CO NE
--
-- Zavře se tichá slepota: jsou-li období vyplněná a překrývají se, roční
-- zúčtování se zastaví na `must_file_tax_return`. Nezavře se povinnost údaje
-- vyplnit — sloupce jsou NULL, protože historicky uložená potvrzení je nemají
-- a fail-closed na chybějící období by zablokovalo i případy, kde souběh
-- prokazatelně nenastal a poplatník to podle § 38k odst. 4 prohlásil. Prázdné
-- období tedy znamená „nevíme“, ne „souběh nebyl“; to, že se souběh bez období
-- prokázat nedá, zůstává vlastností podkladu, ne chybou výpočtu.
--
-- ZPĚTNÁ KOMPATIBILITA
--
-- Oba sloupce jsou NULL a bez DEFAULT, existující řádky se tedy nemění a žádná
-- čtecí cesta se nerozbije.
--
-- IDEMPOTENCE
--
-- MariaDB umí `ADD COLUMN IF NOT EXISTS`; CHECK se řeší vzorem „nejdřív
-- zahodit, pak přidat“, protože `ADD CONSTRAINT IF NOT EXISTS` u CHECK neumí.
-- Migrace obsahuje jen DDL — runner čte příkazy NEBUFFEROVANĚ a jakýkoli SELECT
-- (i schovaný v `SET @x := (SELECT …)` nebo v `PREPARE`) by po sobě nechal
-- nedočtený kurzor a další příkaz by spadl.

SET NAMES utf8mb4;

ALTER TABLE payroll_annual_settlement_certificates
  ADD COLUMN IF NOT EXISTS employment_from DATE NULL
    COMMENT 'Tiskopis „za období od“ — začátek zaměstnání u předchozího plátce.',
  ADD COLUMN IF NOT EXISTS employment_to DATE NULL
    COMMENT 'Tiskopis „za období do“ — konec zaměstnání u předchozího plátce.';

ALTER TABLE payroll_annual_settlement_certificates
  DROP CONSTRAINT IF EXISTS chk_payroll_certificate_employment_period;

ALTER TABLE payroll_annual_settlement_certificates
  ADD CONSTRAINT chk_payroll_certificate_employment_period
  CHECK (
    employment_from IS NULL
    OR employment_to IS NULL
    OR employment_to >= employment_from
  );
