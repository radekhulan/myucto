-- MyÚčto.cz — Kniha jízd: řidič, režim užívání a režim odpočtu DPH u vozidla.
--
--   cars.driver_employee_id    → payroll_employees (řidič / zaměstnanec, jemuž je vůz svěřen)
--   cars.usage_mode            business = jen firemní, private = soukromé vozidlo použité
--                              pro firmu, mixed = firemní i soukromé užívání
--   cars.vat_deduction_mode    full / proportional / none — podmnožina slovníku řádků dokladů
--   cars.vat_deduction_percent (`vat_deduction` v purchase_invoice_vat_allocations,
--                              cash_document_vat_lines, migrace 1120). Jiné jméno sloupce je
--                              záměr: `reduced` (§ 76) sem nepatří — koeficient krácení je
--                              celofiremní a roční, ne vlastnost vozidla — takže doména je užší
--                              a nesmí se plést s `vat_deduction` dokladů.
--
-- Pravidla konzistence drží VehicleVatPolicy (aplikace) i CHECK níže (databáze):
--   private ⇒ none (0 %), mixed ⇒ proportional (0 < % < 100), full ⇒ 100 %.
-- Smíšené užívání s poměrným odpočtem zrcadlí pravidlo účetních alokací přijatých faktur
-- („Smíšené využití musí používat poměrný odpočet podle § 75").
--
-- Tenantová integrita řidiče: jednoduchý FK s SET NULL (propuštěný zaměstnanec nesmí
-- zablokovat vozidlo) + trigger na vlastnictví, stejný vzor jako 1801.

SET NAMES utf8mb4;

ALTER TABLE cars
  ADD COLUMN IF NOT EXISTS driver_employee_id BIGINT UNSIGNED NULL
      COMMENT 'Řidič — zaměstnanec, jemuž je vozidlo svěřeno' AFTER fuel_type,
  ADD COLUMN IF NOT EXISTS usage_mode ENUM('business','private','mixed') NOT NULL DEFAULT 'business'
      COMMENT 'Režim užívání vozidla' AFTER driver_employee_id,
  ADD COLUMN IF NOT EXISTS vat_deduction_mode ENUM('full','proportional','none') NOT NULL DEFAULT 'full'
      COMMENT 'Režim odpočtu DPH u nákladů vozidla (§ 72, § 75 ZDPH)' AFTER usage_mode,
  ADD COLUMN IF NOT EXISTS vat_deduction_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00
      COMMENT 'Poměr odpočtu v %, u proportional podíl podnikatelského užití' AFTER vat_deduction_mode;

ALTER TABLE cars
  ADD INDEX IF NOT EXISTS idx_cars_driver (supplier_id, driver_employee_id);

ALTER TABLE cars DROP FOREIGN KEY IF EXISTS fk_cars_driver_employee;
ALTER TABLE cars
  ADD CONSTRAINT fk_cars_driver_employee
    FOREIGN KEY (driver_employee_id) REFERENCES payroll_employees (id) ON DELETE SET NULL;

-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK — nejdřív zahodit, pak přidat.
ALTER TABLE cars DROP CONSTRAINT IF EXISTS chk_cars_vat_deduction;
ALTER TABLE cars
  ADD CONSTRAINT chk_cars_vat_deduction CHECK (
    (vat_deduction_mode = 'full' AND vat_deduction_percent = 100.00 AND usage_mode = 'business')
    OR (vat_deduction_mode = 'none' AND vat_deduction_percent = 0.00)
    OR (vat_deduction_mode = 'proportional' AND vat_deduction_percent > 0.00 AND vat_deduction_percent < 100.00
        AND usage_mode <> 'private')
  );

DELIMITER //

DROP TRIGGER IF EXISTS trg_cars_driver_tenant_insert//

CREATE TRIGGER trg_cars_driver_tenant_insert
BEFORE INSERT ON cars
FOR EACH ROW
BEGIN
  DECLARE owner_id BIGINT UNSIGNED DEFAULT NULL;

  IF NEW.driver_employee_id IS NOT NULL THEN
    SET owner_id = (SELECT pe.supplier_id FROM payroll_employees pe WHERE pe.id = NEW.driver_employee_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Car driver belongs to another supplier';
    END IF;
  END IF;
END//

DROP TRIGGER IF EXISTS trg_cars_driver_tenant_update//

CREATE TRIGGER trg_cars_driver_tenant_update
BEFORE UPDATE ON cars
FOR EACH ROW
BEGIN
  DECLARE owner_id BIGINT UNSIGNED DEFAULT NULL;

  IF NEW.driver_employee_id IS NOT NULL AND NOT (NEW.driver_employee_id <=> OLD.driver_employee_id) THEN
    SET owner_id = (SELECT pe.supplier_id FROM payroll_employees pe WHERE pe.id = NEW.driver_employee_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Car driver belongs to another supplier';
    END IF;
  END IF;
END//

DELIMITER ;
