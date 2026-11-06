-- MyÚčto.cz — Mzdy → Importy: víc vestavěných vzorů profilu importu docházky.
--
-- Vedle Vzoru GIRITON (migrace 1835, 1836) má firma obecný Vzor MyÚčto pro
-- podklady ze šablony XLSX. `payroll_import_profiles.sample_key` říká, ze
-- kterého vzoru profil vznikl ('giriton' | 'myucto'); aktualizace i obnova
-- vzoru pracují po klíčích. Všechny dosavadní vzory jsou GIRITON.
--
-- Značka „firma už vzor dostala" je po klíčích: GIRITON zůstává na
-- `supplier.payroll_attendance_sample_seeded_at`, Vzor MyÚčto má vlastní
-- `supplier.payroll_attendance_myucto_sample_seeded_at`. Nová značka je
-- u stávajících firem prázdná, takže vzor MyÚčto dostanou jednou; smazaný se
-- už znovu nezaloží.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS, doplnění klíče jen tam, kde chybí.

SET NAMES utf8mb4;

ALTER TABLE payroll_import_profiles
  ADD COLUMN IF NOT EXISTS sample_key VARCHAR(32) NULL AFTER is_sample;

UPDATE payroll_import_profiles
   SET sample_key = 'giriton'
 WHERE is_sample = 1
   AND sample_key IS NULL;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS payroll_attendance_myucto_sample_seeded_at DATETIME NULL DEFAULT NULL
    COMMENT 'kdy aplikace založila Vzor MyÚčto importu docházky';
