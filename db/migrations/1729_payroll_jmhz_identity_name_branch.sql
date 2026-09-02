-- MyÚčto.cz — zaměstnanec bez přiděleného OIČ se do JMHZ hlásí jménem.
--
-- `identifikaceType` (JMHZ 1.4.3.4) je `xs:choice`: buď OIČ (10051) + ID PPV
-- (10228), nebo příjmení, jméno, datum narození, datum nástupu a druh
-- činnosti. Obě čísla přiděluje ČSSZ až protokolem o přijetí registrace, takže
-- první hlášení za nově registrovaného zaměstnance je nemá odkud vzít. Snímek
-- přípravy proto nově smí nést `person_external_identifier` i
-- `jmhz_employment_external_identifier` jako NULL, a tím se mění jeho tvar —
-- odtud v13. Starší snímky zůstávají beze změny a čtou se dál.

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
      'jmhz-preparation-source.v13'
    )
  );
