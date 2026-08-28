-- MZ-22: jeden mzdový běh může obsahovat více JMHZ scénářů. Staré snapshoty
-- zůstávají beze změny; v11 nese kanonický, pravdivý seznam scénářů.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD COLUMN IF NOT EXISTS scenario_set_json MEDIUMTEXT NULL
    CHECK (scenario_set_json IS NULL OR JSON_VALID(scenario_set_json))
    AFTER scenario_key;

ALTER TABLE payroll_jmhz_preparation_snapshots
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_scenario;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD CONSTRAINT chk_payroll_jmhz_preparation_scenario CHECK (
    scenario_key IN ('scenario_1', 'mixed')
  );

ALTER TABLE payroll_jmhz_preparation_snapshots
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD CONSTRAINT chk_payroll_jmhz_preparation_builder CHECK (
    builder_version IN (
      'jmhz-preparation-source.v1',
      'jmhz-preparation-source.v2',
      'jmhz-preparation-source.v3',
      'jmhz-preparation-source.v4',
      'jmhz-preparation-source.v5',
      'jmhz-preparation-source.v6',
      'jmhz-preparation-source.v7',
      'jmhz-preparation-source.v8',
      'jmhz-preparation-source.v9',
      'jmhz-preparation-source.v10',
      'jmhz-preparation-source.v11'
    )
  );
