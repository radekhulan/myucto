-- Rozvaha vyhl. 500: účty, které převody z POHODY běžně nesou a mapa je neznala.
--
-- 349 je vyrovnávací účet POHODY pro DPH (samovyměření a daň z poskytnutých plnění);
-- před zúčtováním přiznání na něm zůstává zůstatek proti 343, proto se řadí jako 343.
-- 358 jsou pohledávky za společníky sdruženými ve společnosti, řádek jako 355.
-- Bez nich rozvaha hlásila nepřiřazený účet a nebyla v rovnováze.

SET @v := (SELECT id FROM statement_versions WHERE statement_type = 'balance_sheet' AND framework = 'cz_500' LIMIT 1);

INSERT IGNORE INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign) VALUES
    (@v, 'C.II.2.4.3.', '349', 'gross', 'debit', 1),
    (@v, 'P.C.II.8.5.', '349', 'gross', 'credit', 1),
    (@v, 'C.II.2.4.1.', '358', 'gross', 'any', 1);
