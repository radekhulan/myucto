-- MyÚčto.cz — doklady ke slevě za umístění dítěte (§ 35bd ZDP od roku 2027).
--
-- Plátce smí slevu v ročním zúčtování přiznat jen na základě prokázaných
-- skutečností (§ 38l odst. 2 písm. g) ZDP):
--   bod 2: potvrzení jiného poplatníka, který v roce vyživuje totéž dítě,
--          se jménem poplatníka, jménem dítěte a výší jím uplatněné slevy,
--   bod 3: písemná dohoda poplatníků o stropu slevy podle § 35bd odst. 2.
-- Doklady se evidují u nároku na dítě; bez nich je to překážka zúčtování.

SET NAMES utf8mb4;

ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD COLUMN IF NOT EXISTS other_supporting_taxpayer TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '§ 38l odst. 2 písm. g) bod 2: totéž dítě v roce vyživuje další poplatník.'
    AFTER grandchild_care_replaces_parents,
  ADD COLUMN IF NOT EXISTS other_taxpayer_name VARCHAR(191) NULL
    COMMENT 'Jméno jiného poplatníka z jeho potvrzení.'
    AFTER other_supporting_taxpayer,
  ADD COLUMN IF NOT EXISTS other_taxpayer_claimed_minor BIGINT NULL
    COMMENT 'Výše slevy uplatněné jiným poplatníkem podle potvrzení, v haléřích.'
    AFTER other_taxpayer_name,
  ADD COLUMN IF NOT EXISTS other_taxpayer_confirmation_reference VARCHAR(500) NULL
    COMMENT 'Odkaz na potvrzení jiného poplatníka (bod 2).'
    AFTER other_taxpayer_claimed_minor,
  ADD COLUMN IF NOT EXISTS agreement_reference VARCHAR(500) NULL
    COMMENT 'Odkaz na písemnou dohodu poplatníků o stropu slevy (bod 3).'
    AFTER other_taxpayer_confirmation_reference;

ALTER TABLE payroll_annual_settlement_preschool_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_preschool_amounts;
ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_preschool_amounts
    CHECK (
      expenses_minor > 0
      AND households_count BETWEEN 1 AND 9
      AND (agreed_cap_minor IS NULL OR agreed_cap_minor >= 0)
      AND (other_taxpayer_claimed_minor IS NULL OR other_taxpayer_claimed_minor >= 0)
    );

ALTER TABLE payroll_annual_settlement_preschool_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_preschool_flags;
ALTER TABLE payroll_annual_settlement_preschool_claims
  ADD CONSTRAINT chk_payroll_annual_settlement_preschool_flags
    CHECK (
      claimed_by_other_taxpayer IN (0, 1)
      AND claimed_as_business_expense IN (0, 1)
      AND grandchild_care_replaces_parents IN (0, 1)
      AND other_supporting_taxpayer IN (0, 1)
    );
