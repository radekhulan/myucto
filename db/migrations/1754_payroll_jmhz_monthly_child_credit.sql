-- MyÚčto.cz — měsíční daňové zvýhodnění na děti v JMHZ.
--
-- Blok `zvyhodneniDetiMesic` (10439 ZTP/P, 10440 pořadí, 10453 jiná vyživující
-- osoba) potřebuje vedle nároku i identitu dítěte, kterou nárok sám nenese.
-- Snímek přípravy proto nově drží `child_credit_evidence` u každé osoby, a tím
-- se mění jeho tvar — odtud v14. Starší snímky zůstávají beze změny a čtou se
-- dál, migrace jen rozšiřuje výčet povolených verzí.

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
      'jmhz-preparation-source.v9',
      'jmhz-preparation-source.v10',
      'jmhz-preparation-source.v11',
      'jmhz-preparation-source.v12',
      'jmhz-preparation-source.v13',
      'jmhz-preparation-source.v14'
    )
  );
