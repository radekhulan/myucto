-- Srovnání výchozího výplatního účtu GOPAY po dřívější podobě migrace 1700.
--
-- Migrace 1700 krátce (2. 9. 2026) zakládala gopay_settings.payout_account_number
-- s prázdným defaultem; revert ho týž den vrátil na výplatní účet GOPAY. Migrace se
-- eviduje podle názvu souboru, takže instalace, které 1700 prošly v mezidobí, mají
-- dodnes prázdný default a kontrola struktury je hlásí jako odchylku. Výchozí hodnota
-- ovlivňuje jen nově zakládané řádky nastavení; existující nastavení se nemění.

ALTER TABLE gopay_settings
  ALTER COLUMN payout_account_number SET DEFAULT '115-1391640287';
