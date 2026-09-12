-- MyÚčto.cz: očekávaný a skutečný den porodu u peněžité pomoci v mateřství.
--
-- PROČ
--
-- Vyloučenou dobou evidenčního listu je u PPM jen doba PŘED porodem, nejdříve
-- od začátku osmého týdne před očekávaným dnem porodu do dne, který
-- bezprostředně předcházel dni porodu (§ 16 odst. 4 věta třetí písm. a)
-- zákona č. 155/1995 Sb.). Bez obou dnů se podpůrčí doba rozdělit nedala,
-- a proto PPM zablokovala roční ELDP i měsíční hlášení.
--
-- `childbirth_date` se smí doplnit dodatečně (porod nastane až po zápisu
-- nepřítomnosti), proto stopa kdo a kdy ho doplnil.
--
-- CHECK hlídá jen to, co platí i pro dřív zapsaná data: jiný druh než PPM
-- oba dny nemá. Starší PPM bez očekávaného dne porodu migrace netrestá,
-- zastaví ji až odvození ELDP srozumitelným blokátorem.
--
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže se omezení
-- nejdřív zahodí a založí znovu. Jen tak je migrace opakovatelná.

SET NAMES utf8mb4;

ALTER TABLE payroll_absences
  ADD COLUMN IF NOT EXISTS expected_childbirth_date DATE NULL AFTER date_to,
  ADD COLUMN IF NOT EXISTS childbirth_date DATE NULL AFTER expected_childbirth_date,
  ADD COLUMN IF NOT EXISTS childbirth_recorded_by INT UNSIGNED NULL AFTER decided_at,
  ADD COLUMN IF NOT EXISTS childbirth_recorded_at DATETIME NULL AFTER childbirth_recorded_by;

ALTER TABLE payroll_absences
  DROP CONSTRAINT IF EXISTS chk_payroll_absence_childbirth_ppm_only,
  DROP CONSTRAINT IF EXISTS chk_payroll_absence_childbirth_recorded;

ALTER TABLE payroll_absences
  ADD CONSTRAINT chk_payroll_absence_childbirth_ppm_only CHECK (
    absence_type = 'ppm'
    OR (expected_childbirth_date IS NULL AND childbirth_date IS NULL)
  ),
  ADD CONSTRAINT chk_payroll_absence_childbirth_recorded CHECK (
    childbirth_recorded_at IS NULL OR childbirth_date IS NOT NULL
  );
