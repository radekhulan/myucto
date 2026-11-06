-- MyÚčto.cz — mzdy 2027 podle zákona č. 180/2026 Sb. (novela ZDP, účinnost
-- 1. 1. 2027): sleva za umístění dítěte (§ 35ba odst. 1 písm. g), § 35bd)
-- a koš osvobození rekreace a zájezdů (§ 6 odst. 9 písm. d) bod 3).
--
-- Sleva se při zálohách neuplatňuje (§ 38h odst. 6), jen v ročním zúčtování po
-- prohlášení do 15. února (§ 38k odst. 5 písm. h). Evidence je proto vedle
-- ostatních ročně uplatňovaných položek z migrace 2208. Jeden řádek = jedno
-- vyživované dítě za rok; údaje opisují potvrzení předškolního zařízení
-- (§ 38l odst. 2 písm. g).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_annual_settlement_preschool_claims (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  tax_year                    SMALLINT UNSIGNED NOT NULL,

  child_dependant_id          BIGINT UNSIGNED NOT NULL
                              COMMENT 'Vyživované dítě v evidenci vyživovaných osob.',
  facility_kind               ENUM('kindergarten','children_group','licensed_care')
                              NOT NULL
                              COMMENT '§ 35bd odst. 6 písm. a) až c).',
  facility_name               VARCHAR(191) NULL,
  expenses_minor              BIGINT NOT NULL
                              COMMENT '§ 35bd odst. 1: výdaje za rok podle potvrzení zařízení, v haléřích.',
  households_count            TINYINT UNSIGNED NOT NULL DEFAULT 1
                              COMMENT '§ 35bd odst. 2: počet domácností, v nichž dítě žije.',
  agreed_cap_minor            BIGINT NULL
                              COMMENT '§ 35bd odst. 2 věta druhá: strop z písemné dohody; NULL = bez dohody.',
  claimed_by_other_taxpayer   TINYINT(1) NOT NULL DEFAULT 0
                              COMMENT '§ 35bd odst. 3: v téže domácnosti uplatňuje jiný poplatník.',
  claimed_as_business_expense TINYINT(1) NOT NULL DEFAULT 0
                              COMMENT '§ 35bd odst. 4 písm. b).',
  grandchild_care_replaces_parents TINYINT(1) NOT NULL DEFAULT 0
                              COMMENT '§ 35bd odst. 5: vnuk v péči nahrazující péči rodičů.',

  evidence_status             ENUM('unverified','verified')
                              NOT NULL DEFAULT 'unverified',
  evidence_reference          VARCHAR(500) NULL,
  note                        VARCHAR(500) NULL,

  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_preschool_supplier_id (supplier_id, id),
  -- Jedna sleva na dítě, zaměstnance a rok.
  UNIQUE KEY uq_payroll_annual_settlement_preschool_scope
    (supplier_id, employee_id, tax_year, child_dependant_id),
  KEY fk_payroll_annual_settlement_preschool_child (supplier_id, child_dependant_id),
  KEY fk_payroll_annual_settlement_preschool_creator (created_by),
  KEY fk_payroll_annual_settlement_preschool_editor (updated_by),

  CONSTRAINT fk_payroll_annual_settlement_preschool_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  -- RESTRICT: smazání dítěte, na kterém stojí uplatněná sleva, ji nesmí tiše odstranit.
  CONSTRAINT fk_payroll_annual_settlement_preschool_child
    FOREIGN KEY (supplier_id, child_dependant_id)
    REFERENCES payroll_dependants (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_settlement_preschool_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_annual_settlement_preschool_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_annual_settlement_preschool_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_preschool_year;
ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_preschool_year
    CHECK (tax_year BETWEEN 2027 AND 2199);

ALTER TABLE payroll_annual_settlement_preschool_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_preschool_amounts;
ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_preschool_amounts
    CHECK (
      expenses_minor > 0
      AND households_count BETWEEN 1 AND 9
      AND (agreed_cap_minor IS NULL OR agreed_cap_minor >= 0)
    );

ALTER TABLE payroll_annual_settlement_preschool_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_preschool_flags;
ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_preschool_flags
    CHECK (
      claimed_by_other_taxpayer IN (0, 1)
      AND claimed_as_business_expense IN (0, 1)
      AND grandchild_care_replaces_parents IN (0, 1)
    );

ALTER TABLE payroll_annual_settlement_preschool_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_preschool_evidence;
ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_preschool_evidence
    CHECK (
      evidence_status <> 'verified'
      OR (evidence_reference IS NOT NULL AND evidence_reference <> '')
    );

-- Koš § 6 odst. 9 písm. d) bod 3 ZDP (rekreace a zájezd, 20 000 Kč za
-- zdaňovací období) a koš spropitného ze stravovacích služeb (§ 6 odst. 9
-- písm. g) ZDP), oba od roku 2027. Limit rekreace drží ruleset, limit
-- spropitného evidence níže; tady jen hodnoty výčtu.
ALTER TABLE payroll_component_definitions
  MODIFY COLUMN exemption_basket
    ENUM('non_cash_health','non_cash_leisure','old_age_savings',
         'meal_per_shift','temporary_accommodation','recreation',
         'food_service_tips') NULL;

ALTER TABLE payroll_inputs
  MODIFY COLUMN benefit_basket
    ENUM('non_cash_health','non_cash_leisure','old_age_savings',
         'meal_per_shift','temporary_accommodation','recreation',
         'food_service_tips') NULL;

-- Spropitné ze stravovacích služeb (§ 6 odst. 9 písm. g) a odst. 19 ZDP):
-- osvobozeno za kalendářní měsíc do podílu 7 % měsíčních příjmů zaměstnavatele
-- ze stravovacích služeb a počtu zaměstnanců, kteří spropitné obdrželi
-- (`per_capita`), nebo do výše určené zaměstnavatelem tak, aby úhrn nepřekročil
-- 7 % (`employer_determined`, stropy v payroll_food_service_tip_allocations).
CREATE TABLE IF NOT EXISTS payroll_food_service_tip_months (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  period_start                DATE NOT NULL
                              COMMENT 'První den kalendářního měsíce.',
  revenue_minor               BIGINT NOT NULL
                              COMMENT 'Měsíční příjmy ze stravovacích služeb zaměstnavatele, v haléřích.',
  allocation_mode             ENUM('per_capita','employer_determined') NOT NULL,
  recipients_count            SMALLINT UNSIGNED NULL
                              COMMENT 'per_capita: počet zaměstnanců, kteří v měsíci spropitné obdrželi.',
  evidence_reference          VARCHAR(500) NULL,
  note                        VARCHAR(500) NULL,

  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_food_service_tip_month_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_food_service_tip_month_period (supplier_id, period_start),
  KEY fk_payroll_food_service_tip_month_creator (created_by),
  KEY fk_payroll_food_service_tip_month_editor (updated_by),

  CONSTRAINT fk_payroll_food_service_tip_month_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_food_service_tip_month_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_food_service_tip_month_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_food_service_tip_months
  DROP CONSTRAINT IF EXISTS chk_payroll_food_service_tip_month;
ALTER TABLE payroll_food_service_tip_months
  ADD CONSTRAINT chk_payroll_food_service_tip_month
    CHECK (
      period_start >= '2027-01-01'
      AND DAYOFMONTH(period_start) = 1
      AND revenue_minor >= 0
      AND (
        (allocation_mode = 'per_capita' AND recipients_count >= 1)
        OR (allocation_mode = 'employer_determined' AND recipients_count IS NULL)
      )
    );

CREATE TABLE IF NOT EXISTS payroll_food_service_tip_allocations (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  tip_month_id                BIGINT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  exempt_cap_minor            BIGINT NOT NULL
                              COMMENT 'Strop osvobozeného spropitného zaměstnance za měsíc, v haléřích.',

  UNIQUE KEY uq_payroll_food_service_tip_allocation (supplier_id, tip_month_id, employee_id),
  KEY fk_payroll_food_service_tip_allocation_employee (supplier_id, employee_id),

  CONSTRAINT fk_payroll_food_service_tip_allocation_month
    FOREIGN KEY (supplier_id, tip_month_id)
    REFERENCES payroll_food_service_tip_months (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_food_service_tip_allocation_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_food_service_tip_allocations
  DROP CONSTRAINT IF EXISTS chk_payroll_food_service_tip_allocation_cap;
ALTER TABLE payroll_food_service_tip_allocations
  ADD CONSTRAINT chk_payroll_food_service_tip_allocation_cap
    CHECK (exempt_cap_minor >= 0);
