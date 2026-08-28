-- MyÚčto.cz — explicitní zákonný režim stravenkového paušálu a audit rozdělení.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  ADD COLUMN IF NOT EXISTS meal_entitlement_basis
    ENUM('shift','calendar_day') NOT NULL DEFAULT 'shift'
    AFTER relation_type;

ALTER TABLE payroll_inputs
  ADD COLUMN IF NOT EXISTS benefit_allocation_json LONGTEXT NULL
    CHECK (benefit_allocation_json IS NULL OR JSON_VALID(benefit_allocation_json))
    AFTER benefit_taxable_minor;
