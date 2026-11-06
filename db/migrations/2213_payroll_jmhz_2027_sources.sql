-- MyÚčto.cz — podklady měsíčního hlášení JMHZ 2027 (datový slovník 1.5.0.3),
-- které mzdy dosud neevidovaly.
--
-- Den podpisu Prohlášení poplatníka (10575, IN16): nese ho řádek evidence
-- prohlášení, protože se podepisuje (i znovu) kdykoli během vztahu. Uvádí se
-- jen u podepsaného prohlášení.
--
-- Plná adresa místa výkonu práce (10550 kód adresního místa, 10551 ulice,
-- 10552 číslo popisné, 10553 číslo orientační, 10554 PSČ): vztah dosud nesl
-- jen obec, kód obce a stát (10229 až 10231). U místa výkonu mimo ČR (kód
-- obce 111111, IN41) se adresa nevyplňuje.
--
-- Státní zástupce (10587): příznak formuláře činnosti K až S. NULL = účetní
-- zatím neodpověděla; hlášení 2027 se pak u takového vztahu nesestaví.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_tax_declarations
  ADD COLUMN IF NOT EXISTS signed_on DATE NULL DEFAULT NULL
    AFTER status;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_workplace_address_point_code VARCHAR(9) NULL DEFAULT NULL
    AFTER jmhz_workplace_country_code;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_workplace_street VARCHAR(50) NULL DEFAULT NULL
    AFTER jmhz_workplace_address_point_code;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_workplace_house_number VARCHAR(4) NULL DEFAULT NULL
    AFTER jmhz_workplace_street;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_workplace_orientation_number VARCHAR(12) NULL DEFAULT NULL
    AFTER jmhz_workplace_house_number;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_workplace_postal_code CHAR(5) NULL DEFAULT NULL
    AFTER jmhz_workplace_orientation_number;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_state_prosecutor TINYINT(1) NULL DEFAULT NULL
    AFTER jmhz_workplace_postal_code;
