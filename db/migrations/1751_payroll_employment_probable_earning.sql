-- Pravděpodobný výdělek (§ 355 zákoníku práce) evidovaný u pracovního vztahu.
--
-- Neodpracoval-li zaměstnanec v rozhodném období alespoň 21 dnů, průměrný
-- výdělek se nezjišťuje, ale STANOVUJE jako pravděpodobný. Aplikace ho z
-- evidence odvodit nemůže (žádný uzavřený běh za rozhodné období neexistuje),
-- takže ho musí zadat účetní. Typicky jde o DPP v prvním měsíci nebo o odměnu
-- za úkol bez odpracovaných hodin — a bez toho čísla nelze vyplnit povinný
-- atribut JMHZ 10345 (`vydelekPrumernyHod`).
--
-- Údaj patří k PODMÍNKÁM pracovního vztahu, ne k jednorázovému snapshotu:
-- vzniká ze sjednané odměny a je platný, dokud se odměna nezmění. Tím pádem
-- se zmrazuje stejně jako ostatní podmínky — do revize `payroll_employment_terms`
-- s vlastním `effective_from`, a starší revize si drží původní hodnotu.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS probable_hourly_earning_minor BIGINT UNSIGNED NULL
    AFTER leave_entitlement_weeks_override,
  ADD COLUMN IF NOT EXISTS probable_earning_rationale VARCHAR(1000) NULL
    AFTER probable_hourly_earning_minor;

-- Pravděpodobný výdělek bez odůvodnění je jen číslo, které nikdo neobhájí:
-- § 355 odst. 2 ZP po zaměstnavateli chce, aby vycházel z obvyklé výše složek
-- mzdy nebo z odměny srovnatelných zaměstnanců. Stejnou dvojici hlídá i
-- `payroll_average_earning_snapshots.chk_payroll_average_probable_rationale`.
ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_probable_earning;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_term_probable_earning CHECK (
    probable_hourly_earning_minor IS NULL
    OR (
      probable_hourly_earning_minor > 0
      AND probable_earning_rationale IS NOT NULL
      AND CHAR_LENGTH(TRIM(probable_earning_rationale)) > 0
    )
  );
