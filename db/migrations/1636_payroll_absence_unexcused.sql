-- MyÚčto.cz — W28 / V-15+V-16: neomluvená absence jako vlastní druh absence.
--
-- PROČ
--
-- § 223 odst. 1 zákoníku práce dovoluje krátit dovolenou JEN za neomluveně
-- zameškanou směnu, a to o počet neomluveně zameškaných hodin. Kniha dovolené
-- už uměla položku `shortening` a od W27 hlídá zákonné minimum dvou týdnů
-- (§ 223 odst. 2), jenže o DŮVOD krácení se neopírala vůbec: účetní mohla
-- zadat libovolné záporné číslo a modul mu věřil. Vynutit odstavec první
-- nešlo z jednoho prostého důvodu — `payroll_absences.absence_type` hodnotu
-- pro neomluvenou nepřítomnost neměl. Nebylo tedy vůči čemu krácení porovnat.
--
-- Enum se proto rozšiřuje o `unexcused`. Teprve nad ním může kniha dovolené
-- porovnat požadované krácení se skutečně evidovanými neomluvenými hodinami
-- (PayrollLeaveRepository::assertShorteningAllowed).
--
-- PROČ NE `other` NEBO `employee_obstacle`
--
-- `employee_obstacle` je PŘEKÁŽKA V PRÁCI na straně zaměstnance (§ 191 a násl.)
-- — tedy nepřítomnost OMLUVENÁ, za kterou se dovolená krátit nesmí a která se
-- podle § 348 odst. 1 může naopak počítat jako výkon práce. Kdyby neomluvená
-- absence spadla sem, krácení by se opíralo přesně o hodiny, o které se opírat
-- nesmí. `other` je zbytková kategorie, kterou firmy používají na cokoli;
-- odvozovat z ní právní následek by znamenalo trestat zaměstnance za to, jak si
-- účetní pojmenovala řádek.
--
-- ZPĚTNÁ KOMPATIBILITA
--
-- Přidání hodnoty do ENUM na konec seznamu existující řádky nemění a žádnou
-- hodnotu neruší. Absence dosud zadané jako `other` se ZÁMĚRNĚ nepřevádějí —
-- z databáze nejde poznat, které z nich neomluvené byly, a tichý převod by
-- vyrobil podklad pro krácení dovolené tam, kde ho nikdo netvrdil. Přeřazení
-- je vědomý úkon účetní.
--
-- Náhrada mzdy: `unexcused` nemá v aplikační cestě jinou `compensation_policy`
-- než `none` (PayrollAbsenceValidator) — za neomluveně zameškanou dobu mzda ani
-- náhrada nepřísluší.
--
-- IDEMPOTENCE
--
-- `MODIFY COLUMN` je z povahy idempotentní: opakované spuštění zapíše tutéž
-- definici sloupce. Migrace obsahuje jen DDL — runner čte příkazy
-- NEBUFFEROVANĚ a jakýkoli SELECT (i schovaný v `SET @x := (SELECT …)` nebo
-- v `PREPARE`) by po sobě nechal nedočtený kurzor a další příkaz by spadl.

SET NAMES utf8mb4;

ALTER TABLE payroll_absences
  MODIFY COLUMN absence_type ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle',
    'compensatory_time_off','unexcused','other'
  ) NOT NULL;
