-- Roční kumulace zákonných výsledků se čte "bez nahrazených příspěvků":
-- ke každému příspěvku se hledá nástupce přes replaces_entry_id (čtení
-- kumulace při každém výpočtu i kontrola návaznosti opravné revize za každou
-- osobu a druh výpočtu). Jediný index s replaces_entry_id ho má až na šestém
-- místě za osobou, rokem, obdobím a druhem, takže hledání nástupce procházelo
-- všechny příspěvky firmy a s historií rostlo kvadraticky.

ALTER TABLE payroll_statutory_accumulator_entries
  ADD INDEX IF NOT EXISTS idx_payroll_statutory_entry_successor
    (supplier_id, replaces_entry_id);
