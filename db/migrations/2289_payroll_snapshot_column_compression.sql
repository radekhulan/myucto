-- Komprese JSON snímků mzdového běhu ve sloupcích (MariaDB `COMPRESSED`).
--
-- PROČ
--
-- Měsíc agentury se 150 dohodami uloží asi 38 MB snímků. Většinu tvoří
-- vstupy zákonného výpočtu: snímek revize je u každého ze čtyř druhů výpočtu
-- (sociální, zdravotní, daň, čistá mzda) uložený znovu celý a vstup osoby
-- i vztahu také čtyřikrát. JSON se přitom dobře komprimuje (2,2 MB vstupu
-- revize má po zlib 0,1 MB).
--
-- CO SE MĚNÍ
--
-- Sloupce se snímky dostanou atribut `COMPRESSED`: MariaDB hodnotu při zápisu
-- zkomprimuje (zlib) a při čtení rozbalí. Aplikace čte i zapisuje tytéž
-- řetězce jako dřív, takže otisky snímků (`*_hash`), jejich ověření, kontroly
-- `json_valid` ani triggery neměnnosti se nemění. Staré řádky převede
-- `ALTER TABLE` při migraci. Na syntetické agentuře se 150 dohodami klesne
-- měsíc z 38 MB na zhruba 9 MB (zbytek jsou šifrované snímky JMHZ, které
-- se komprimovat nedají).
--
-- Opakované spuštění nastaví tutéž definici. Návrat: tytéž příkazy bez
-- `COMPRESSED`.

SET NAMES utf8mb4;

ALTER TABLE payroll_run_revisions
  MODIFY COLUMN input_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(input_snapshot_json)),
  MODIFY COLUMN result_snapshot_json LONGTEXT COMPRESSED DEFAULT NULL
    CHECK (result_snapshot_json IS NULL OR json_valid(result_snapshot_json));

ALTER TABLE payroll_statutory_results
  MODIFY COLUMN input_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(input_snapshot_json)),
  MODIFY COLUMN result_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(result_snapshot_json));

ALTER TABLE payroll_statutory_person_results
  MODIFY COLUMN input_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(input_snapshot_json)),
  MODIFY COLUMN result_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(result_snapshot_json));

ALTER TABLE payroll_statutory_relationship_results
  MODIFY COLUMN input_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(input_snapshot_json)),
  MODIFY COLUMN result_snapshot_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(result_snapshot_json));

ALTER TABLE payroll_run_employments
  MODIFY COLUMN input_json LONGTEXT COMPRESSED NOT NULL
    CHECK (json_valid(input_json)),
  MODIFY COLUMN result_json LONGTEXT COMPRESSED DEFAULT NULL
    CHECK (result_json IS NULL OR json_valid(result_json));

ALTER TABLE payroll_run_persons
  MODIFY COLUMN result_json LONGTEXT COMPRESSED DEFAULT NULL
    CHECK (result_json IS NULL OR json_valid(result_json));
