-- MyÚčto.cz — roční zúčtování: nezdanitelné části základu daně (§ 15), sleva
-- za zastavenou exekuci (§ 35 odst. 4) a sleva na manžela (§ 35ba odst. 1
-- písm. b), § 35bb).
--
-- Plátce k těmto položkám při výpočtu záloh nepřihlíží (§ 38h odst. 6) a
-- uplatní je až při ročním zúčtování. Dosud je modul neuměl a zúčtování
-- zastavoval stavem `present_unsupported`. Tady je evidence, ze které je výpočet
-- vezme; stav `present` říká, že poplatník něco uplatňuje A že je to zadané.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_annual_settlement_deductions (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  tax_year                    SMALLINT UNSIGNED NOT NULL,

  kind                        ENUM(
                                'donation',
                                'blood_donation',
                                'organ_donation',
                                'stem_cell_donation',
                                'housing_interest',
                                'pension_savings',
                                'life_insurance',
                                'long_term_investment',
                                'long_term_care_insurance',
                                'enforcement_stop'
                              ) NOT NULL
                              COMMENT '§ 15 a § 35 odst. 4 — hodnoty jako AnnualNonTaxableKind.',
  amount_minor                BIGINT NULL
                              COMMENT 'Uplatňovaná částka v haléřích. U odběrů NULL (oceňuje se zákonem).',
  quantity                    SMALLINT UNSIGNED NULL
                              COMMENT 'Počet odběrů (krev, orgán, krvetvorné buňky).',
  months_mask                 SMALLINT UNSIGNED NULL
                              COMMENT 'Jen úroky: bit 0 = leden, měsíce placení úroků.',
  housing_acquired_before_2021 TINYINT(1) NOT NULL DEFAULT 0
                              COMMENT 'Jen úroky: bytová potřeba obstaraná před 1. 1. 2021.',
  counterparty_name           VARCHAR(191) NULL
                              COMMENT 'Obdarovaný, banka, fond nebo pojišťovna z dokladu.',

  evidence_status             ENUM('unverified','verified')
                              NOT NULL DEFAULT 'unverified'
                              COMMENT '§ 38ch odst. 3 — do výpočtu smí jen doložená položka.',
  evidence_reference          VARCHAR(500) NULL
                              COMMENT 'Čím je položka doložená (potvrzení o daru, o úrocích, fondu).',
  note                        VARCHAR(500) NULL,
  sort_order                  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_deduction_supplier_id (supplier_id, id),
  KEY idx_payroll_annual_settlement_deduction_scope
    (supplier_id, employee_id, tax_year),
  KEY fk_payroll_annual_settlement_deduction_creator (created_by),
  KEY fk_payroll_annual_settlement_deduction_editor (updated_by),

  CONSTRAINT fk_payroll_annual_settlement_deduction_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_annual_settlement_deduction_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_annual_settlement_deduction_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_annual_settlement_deductions
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_deduction_year;
ALTER TABLE payroll_annual_settlement_deductions
  ADD CONSTRAINT chk_payroll_annual_settlement_deduction_year
    CHECK (tax_year BETWEEN 2000 AND 2199);

ALTER TABLE payroll_annual_settlement_deductions
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_deduction_amount;
ALTER TABLE payroll_annual_settlement_deductions
  ADD CONSTRAINT chk_payroll_annual_settlement_deduction_amount
    CHECK (amount_minor IS NULL OR amount_minor > 0);

ALTER TABLE payroll_annual_settlement_deductions
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_deduction_evidence;
ALTER TABLE payroll_annual_settlement_deductions
  ADD CONSTRAINT chk_payroll_annual_settlement_deduction_evidence
    CHECK (
      evidence_status <> 'verified'
      OR (evidence_reference IS NOT NULL AND evidence_reference <> '')
    );

CREATE TABLE IF NOT EXISTS payroll_annual_settlement_spouse_claims (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  tax_year                    SMALLINT UNSIGNED NOT NULL,

  spouse_dependant_id         BIGINT UNSIGNED NOT NULL
                              COMMENT 'Manžel/ka v evidenci vyživovaných osob.',
  child_dependant_id          BIGINT UNSIGNED NOT NULL
                              COMMENT 'Vyživované dítě do 3 let (§ 35bb odst. 2 písm. a).',
  months_mask                 SMALLINT UNSIGNED NOT NULL
                              COMMENT 'Bit 0 = leden. Měsíce, v nichž byly podmínky splněné.',
  ztp_p_months_mask           SMALLINT UNSIGNED NOT NULL DEFAULT 0
                              COMMENT 'Měsíce s průkazem ZTP/P manžela (podmnožina months_mask).',
  spouse_income_minor         BIGINT NULL
                              COMMENT 'Vlastní příjem manžela za rok; NULL = nevíme.',

  evidence_status             ENUM('unverified','verified')
                              NOT NULL DEFAULT 'unverified',
  evidence_reference          VARCHAR(500) NULL,
  note                        VARCHAR(500) NULL,

  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_spouse_supplier_id (supplier_id, id),
  -- Jedna sleva na manžela na zaměstnance a rok.
  UNIQUE KEY uq_payroll_annual_settlement_spouse_scope
    (supplier_id, employee_id, tax_year),
  KEY fk_payroll_annual_settlement_spouse_spouse (supplier_id, spouse_dependant_id),
  KEY fk_payroll_annual_settlement_spouse_child (supplier_id, child_dependant_id),
  KEY fk_payroll_annual_settlement_spouse_creator (created_by),
  KEY fk_payroll_annual_settlement_spouse_editor (updated_by),

  CONSTRAINT fk_payroll_annual_settlement_spouse_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  -- RESTRICT: smazání osoby, na které stojí uplatněná sleva, nesmí slevu
  -- tiše odstranit.
  CONSTRAINT fk_payroll_annual_settlement_spouse_spouse
    FOREIGN KEY (supplier_id, spouse_dependant_id)
    REFERENCES payroll_dependants (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_settlement_spouse_child
    FOREIGN KEY (supplier_id, child_dependant_id)
    REFERENCES payroll_dependants (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_settlement_spouse_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_annual_settlement_spouse_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_annual_settlement_spouse_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_spouse_year;
ALTER TABLE payroll_annual_settlement_spouse_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_spouse_year
    CHECK (tax_year BETWEEN 2000 AND 2199);

ALTER TABLE payroll_annual_settlement_spouse_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_spouse_months;
ALTER TABLE payroll_annual_settlement_spouse_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_spouse_months
    CHECK (
      months_mask BETWEEN 1 AND 4095
      AND ztp_p_months_mask BETWEEN 0 AND 4095
    );

ALTER TABLE payroll_annual_settlement_spouse_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_spouse_income;
ALTER TABLE payroll_annual_settlement_spouse_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_spouse_income
    CHECK (spouse_income_minor IS NULL OR spouse_income_minor >= 0);

ALTER TABLE payroll_annual_settlement_spouse_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_spouse_evidence;
ALTER TABLE payroll_annual_settlement_spouse_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_spouse_evidence
    CHECK (
      evidence_status <> 'verified'
      OR (evidence_reference IS NOT NULL AND evidence_reference <> '')
    );

-- Žádost nově smí nést `present` — položky jsou evidované a výpočet je uplatní.
ALTER TABLE payroll_annual_settlement_requests
  MODIFY annual_claims ENUM('unknown','none','present','present_unsupported')
    NOT NULL DEFAULT 'unknown'
    COMMENT '§ 38h odst. 6 — § 15, § 35 odst. 4, sleva na manžela. present = evidované v aplikaci, present_unsupported = aplikace neumí.';
