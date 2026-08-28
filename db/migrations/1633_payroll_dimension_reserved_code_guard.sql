-- MyÚčto.cz — W26 / Ú-14: kód mzdové dimenze nesmí kolidovat s pseudonymem
-- srážky ani exekuce.
--
-- PROČ
--
-- Sloupec `cost_center` mzdového deníku nese DVĚ různé věci. U běžného zápisu
-- je to skutečné středisko (kód z `payroll_dimensions`), u srážky a exekuce je
-- to PSEUDONYM oprávněného, který si můstek odvozuje z alokačního klíče:
-- `MZ-SR-<16 hex>` pro ostatní srážky a `MZ-EX-<16 hex>` pro exekuce a
-- insolvence (PayrollPostingLineBuilder::deductionDimension). Samostatné
-- saldokonto per oprávněný datový model zatím nemá — celá 379 je jeden účet
-- a rozlišuje se právě tímhle pseudonymem.
--
-- Reconciliace účetního můstku podle toho zařazuje řádky do kategorií
-- `other_deductions` a `enforcement`:
--
--     CASE WHEN line.cost_center LIKE 'MZ-EX-%' THEN 'enforcement'
--          WHEN line.cost_center LIKE 'MZ-SR-%' THEN 'deduction'
--          ELSE 'none' END
--
-- Firma, která si založí středisko s kódem `MZ-SR-PRAHA`, tedy dostane vlastní
-- náklad zařazený mezi srážky a obě kategorie začnou tiše lhát. Aplikační cesta
-- to od téhle verze odmítá (PayrollDimensionService::normalize), jenže import,
-- ruční UPDATE v konzoli ani budoucí zapisovací API se aplikační cesty neptají.
-- Guard proto patří i do databáze — stejně jako u ostatních mzdových tabulek.
--
-- ZPĚTNÁ KOMPATIBILITA
--
-- Kontrola je NEGATIVNÍ (`NOT LIKE`), takže existující řádky s jiným kódem
-- projdou beze změny. Existující kódy se ZÁMĚRNĚ nepřejmenovávají: kód je
-- součástí zmrazených snapshotů schválených revizí a přejmenováním by se
-- snapshot rozešel s číselníkem. Takový případ (dosud se nevyskytl) musí
-- účetní vyřešit vědomě novým kódem.
--
-- IDEMPOTENCE
--
-- MariaDB neumí `ADD CONSTRAINT IF NOT EXISTS` u CHECK, umí ale
-- `DROP CONSTRAINT IF EXISTS`. Vzor je proto „nejdřív zahodit, pak přidat“ —
-- bez jediného dotazu do `information_schema`. Runner migrací čte příkazy
-- NEBUFFEROVANĚ: každý SELECT (i schovaný v `SET @x := (SELECT …)` nebo
-- v `PREPARE`) po sobě nechá nedočtený kurzor a další příkaz spadne na
-- „Cannot execute queries while other unbuffered queries are active“.
-- Migrace proto smí obsahovat jen DDL.

SET NAMES utf8mb4;

ALTER TABLE payroll_dimensions
  DROP CONSTRAINT IF EXISTS chk_payroll_dimension_code_not_reserved;

ALTER TABLE payroll_dimensions
  ADD CONSTRAINT chk_payroll_dimension_code_not_reserved
  CHECK (UPPER(code) NOT LIKE 'MZ-SR-%' AND UPPER(code) NOT LIKE 'MZ-EX-%');
