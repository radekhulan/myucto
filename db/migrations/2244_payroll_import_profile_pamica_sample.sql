-- MyÚčto.cz — Mzdy → Importy: Vzor PAMICA pro import docházky.
--
-- Vedle Vzoru GIRITON a Vzoru MyÚčto (migrace 2206) dostane firma Vzor PAMICA
-- pro docházku exportovanou pro mzdový program PAMICA (XML „Docházka
-- zaměstnanců" nebo denní CSV). Značka „firma vzor dostala" je po klíčích,
-- nová `supplier.payroll_attendance_pamica_sample_seeded_at` je u stávajících
-- firem prázdná, takže vzor dostanou jednou; smazaný se už znovu nezaloží.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS payroll_attendance_pamica_sample_seeded_at DATETIME NULL DEFAULT NULL
    COMMENT 'kdy aplikace založila Vzor PAMICA importu docházky';
