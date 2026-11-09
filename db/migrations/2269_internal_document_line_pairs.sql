-- MyÚčto.cz — interní doklad po dvojicích: číslo a text dvojice na řádku konceptu.
--
-- Koncept interního dokladu zadaný jako dvojice (účet MD, účet Dal, částka) si
-- číslo dvojice a její text nese až do zaúčtování; ruční zápis deníku z nich
-- vytvoří dvojice zápisu. NULL = řádek složeného zápisu, firma bez volby
-- jednoduchého zápisu nepozná změnu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE internal_document_lines
    ADD COLUMN IF NOT EXISTS pair_no SMALLINT UNSIGNED NULL
        COMMENT 'Číslo dvojice (jednoduchý zápis); NULL = složený zápis'
        AFTER dimensions_json,
    ADD COLUMN IF NOT EXISTS line_text VARCHAR(255) NULL
        COMMENT 'Text dvojice (jednoduchý zápis)'
        AFTER pair_no;
