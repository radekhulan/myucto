-- MyÚčto.cz — nevyplněné tri-state údaje vykonávané pozice (příspěvek APZ,
-- funkční požitky, dočasné přidělení) se v JMHZ vykládají jako „ne".
--
-- Uložená hodnota se NEPŘEPISUJE: v evidenci zůstane `unverified`, aby zpětně
-- bylo poznat, že to nikdo výslovně nepotvrdil. Zmrazený snímek proto nese
-- navíc `jmhz_default_interpretations` u každého vztahu, a tím se mění jeho
-- tvar — odtud v12. Starší snímky zůstávají beze změny a čtou se dál.

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
      'jmhz-preparation-source.v12'
    )
  );
