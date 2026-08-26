-- JMHZ: časově účinné nastavení neobvyklých měsíčních situací na pracovním vztahu.
-- Běžný vztah má všechny příznaky vypnuté a při přípravě hlášení se doloží
-- automaticky. Zapnutý příznak vyžádá měsíční podrobnosti pouze u výjimky.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_orchard_discount_eligible
    TINYINT(1) NOT NULL DEFAULT 0
    AFTER jmhz_temporary_assignment_status,
  ADD COLUMN IF NOT EXISTS jmhz_specific_legal_fact_applies
    TINYINT(1) NOT NULL DEFAULT 0
    AFTER jmhz_orchard_discount_eligible,
  ADD COLUMN IF NOT EXISTS jmhz_ozp_employment_support_applies
    TINYINT(1) NOT NULL DEFAULT 0
    AFTER jmhz_specific_legal_fact_applies,
  ADD COLUMN IF NOT EXISTS jmhz_deep_mining_work_applies
    TINYINT(1) NOT NULL DEFAULT 0
    AFTER jmhz_ozp_employment_support_applies;

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_terms_jmhz_ordinary_profile;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_terms_jmhz_ordinary_profile CHECK (
    jmhz_orchard_discount_eligible IN (0, 1)
    AND jmhz_specific_legal_fact_applies IN (0, 1)
    AND jmhz_ozp_employment_support_applies IN (0, 1)
    AND jmhz_deep_mining_work_applies IN (0, 1)
  );
