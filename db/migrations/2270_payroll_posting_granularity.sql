-- MyÚčto.cz — zaúčtování mzdového předpisu souhrnně, nebo po zaměstnancích.
--
-- 'summary' (výchozí) = dosavadní chování: předpis se zaúčtuje souhrnně za firmu
-- a středisko. 'employee' = každý zaměstnanec má v předpisu vlastní řádky
-- (ve formě single vlastní dvojice) s osobním číslem v textu řádku a nákladové
-- pojistné zaměstnavatele se rozdělí na pracovní vztahy; vyhovuje controllingu.
--
-- Volba se zmrazuje do vstupního snapshotu běhu (employer.posting_granularity)
-- jen u hodnoty 'employee'. Firma, která ji nezmění, má snapshot, otisky revizí
-- i zaúčtování beze změny.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS posting_granularity VARCHAR(16) NOT NULL DEFAULT 'summary'
    COMMENT 'summary = předpis souhrnně za firmu a středisko, employee = po zaměstnancích'
    AFTER wage_rounding;

ALTER TABLE payroll_employer_settings
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_posting_granularity;

ALTER TABLE payroll_employer_settings
  ADD CONSTRAINT chk_payroll_employer_posting_granularity
    CHECK (posting_granularity IN ('summary', 'employee'));
