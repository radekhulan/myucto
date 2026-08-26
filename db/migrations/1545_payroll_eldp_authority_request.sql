-- MyUcto.cz - ELDP na výzvu v průběhu roku.
--
-- Verze v2 uzavírá ELDP na posledním schváleném zúčtovaném měsíci a eviduje
-- osmidenní lhůtu od doručení výzvy. Starší neměnné snapshoty v1 zůstávají
-- platné a čitelné.

SET NAMES utf8mb4;

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_builder;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_builder
    CHECK (builder_version IN (
      'eldp-annual-statement.v1',
      'eldp-annual-statement.v2'
    ));
