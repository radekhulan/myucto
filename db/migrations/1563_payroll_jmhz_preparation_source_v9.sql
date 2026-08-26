-- Příprava v9 zmrazuje stav žádosti a neměnné roční daňové revize, ze
-- kterých se sestavují lednové až březnové atributy JMHZ.

SET NAMES utf8mb4;

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
      'jmhz-preparation-source.v9'
    )
  );
