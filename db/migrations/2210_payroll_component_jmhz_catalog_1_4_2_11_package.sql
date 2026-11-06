-- MyÚčto.cz: zařazení mzdových složek do JMHZ přechází na balík specifikace
-- s katalogem kontrol 1.4.2.11.
--
-- Katalog kontrol je součástí identity balíku specifikace, takže nový katalog
-- (kontroly ELDP 368 a 369, informativní kontrola cizinců 371 a opravená
-- kontrola 333) znamená nový balík. Zařazení složek je na balík vázané
-- a snímek měsíčního hlášení čte jen balík, který aplikace používá
-- (`PayrollComponentJmhzTargetCatalog::PACKAGE_KEY`). Migrace 1903, 1926, 1931
-- a 1983 míří na balík 1.4.2.10; tahle dělá totéž pro balík 1.4.2.11:
--
--  1. Převzetí rozhodnutí ze staršího balíku, doslova pravidlo
--     `PayrollComponentJmhzMappingRepository::adoptLegacy()` (jako 1840 a 1903).
--     Shodu hlídá `PayrollComponentJmhzLegacyPackageAdoptionTest`.
--  2. Výchozí zařazení složek, které žádný záznam zařazení nemají, doslova
--     `PayrollComponentJmhzMappingDefaults::targetFor()` (jako 1839 a 1903).
--     Seznam kódů je úplný včetně složek, které 1926, 1931 a 1983 doplnily
--     jen do balíku 1.4.2.10. Shodu hlídají
--     `PayrollComponentJmhzKindDefaultsMigrationTest`
--     a `PayrollComponentJmhzCreationDefaultsTest`.
--
-- Když balík 1.4.2.11 ještě nainstalovaný není, neudělá se nic a převzetí
-- i výchozí zařazení provede aplikace sama při čtení složek. Opakované
-- spuštění nic nemění.

SET NAMES utf8mb4;

INSERT INTO payroll_component_jmhz_mappings
    (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
     is_active, disabled_at, created_by, updated_by)
SELECT legacy.supplier_id,
       legacy.component_definition_id,
       attribute.package_id,
       attribute.attribute_id,
       legacy.is_active,
       legacy.disabled_at,
       legacy.created_by,
       legacy.updated_by
  FROM (
        SELECT mapping.supplier_id,
               mapping.component_definition_id,
               mapping.spec_package_id,
               mapping.target_attribute_id,
               mapping.is_active,
               mapping.disabled_at,
               mapping.created_by,
               mapping.updated_by,
               ROW_NUMBER() OVER (
                 PARTITION BY mapping.supplier_id, mapping.component_definition_id
                 ORDER BY mapping.is_active DESC, mapping.spec_package_id DESC
               ) AS pick
          FROM payroll_component_jmhz_mappings mapping
       ) legacy
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1'
   AND package.manifest_sha256 = '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8'
  JOIN payroll_component_definitions definition
    ON definition.supplier_id = legacy.supplier_id
   AND definition.id = legacy.component_definition_id
   AND definition.jmhz_treatment = 'included'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = legacy.target_attribute_id
 WHERE legacy.pick = 1
   AND legacy.spec_package_id <> package.id
   AND NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = legacy.supplier_id
            AND existing.component_definition_id = legacy.component_definition_id
            AND existing.spec_package_id = package.id
       );

UPDATE payroll_component_jmhz_mappings legacy
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1'
   AND package.manifest_sha256 = '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8'
  JOIN payroll_component_jmhz_mappings current_mapping
    ON current_mapping.supplier_id = legacy.supplier_id
   AND current_mapping.component_definition_id = legacy.component_definition_id
   AND current_mapping.spec_package_id = package.id
   SET legacy.is_active = 0,
       legacy.disabled_at = CURRENT_TIMESTAMP,
       legacy.updated_by = NULL,
       legacy.row_version = legacy.row_version + 1
 WHERE legacy.spec_package_id <> package.id
   AND legacy.is_active = 1;

INSERT INTO payroll_component_jmhz_mappings
    (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
     created_by, updated_by)
SELECT target.supplier_id,
       target.id,
       attribute.package_id,
       attribute.attribute_id,
       NULL,
       NULL
  FROM (
        SELECT definition.supplier_id,
               definition.id,
               COALESCE(
                 by_code.attribute_id,
                 CASE
                   WHEN definition.component_kind IN ('base_wage', 'hourly_wage', 'task_wage') THEN '10329'
                   WHEN definition.component_kind = 'premium' THEN '10332'
                   WHEN definition.component_kind = 'compensation'
                    AND definition.tax_treatment = 'included' THEN '10337'
                   WHEN definition.component_kind = 'bonus'
                    AND definition.frequency_kind = 'one_off' THEN '10331'
                   WHEN definition.component_kind = 'bonus'
                    AND definition.frequency_kind = 'regular' THEN '10330'
                 END
               ) AS attribute_id
          FROM payroll_component_definitions definition
          LEFT JOIN (
                SELECT 'MZDA_MESICNI' AS code, '10329' AS attribute_id
          UNION SELECT 'MZDA_HODINOVA', '10329'
          UNION SELECT 'MZDA_UKOLOVA', '10329'
          UNION SELECT 'ODMENA', '10331'
          UNION SELECT 'PREMIE_PRIPLATKY', '10332'
          UNION SELECT 'PRIPLATEK_PRESCAS', '10333'
          UNION SELECT 'PRIPLATEK_NOCNI', '10334'
          UNION SELECT 'PRIPLATEK_VIKEND', '10335'
          UNION SELECT 'PRIPLATEK_SVATEK', '10336'
          UNION SELECT 'PRIPLATEK_ZTIZENE_PROSTREDI', '10332'
          UNION SELECT 'ODMENA_POHOTOVOST', '10343'
          UNION SELECT 'STRAVOVANI_ZDANITELNE', '10328'
          UNION SELECT 'PRISPEVEK_STRAVOVANI_ZDANITELNY', '10328'
          UNION SELECT 'NAHRADA_MZDY', '10337'
          UNION SELECT 'NAHRADA_MZDY_DOVOLENA', '10338'
          UNION SELECT 'NAHRADA_MZDY_DOVOLENA_VYROVNANI', '10338'
          UNION SELECT 'NAHRADA_MZDY_SVATEK', '10339'
          UNION SELECT 'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', '10340'
          UNION SELECT 'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC', '10341'
          UNION SELECT 'NAHRADA_MZDY_DPN', '10342'
          UNION SELECT 'PRISPEVEK_DLOUHODOBA_PECE', '10418'
               ) by_code ON by_code.code = definition.code
         WHERE definition.jmhz_treatment = 'included'
       ) target
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1'
   AND package.manifest_sha256 = '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = target.attribute_id
 WHERE target.attribute_id IS NOT NULL
   AND NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = target.supplier_id
            AND existing.component_definition_id = target.id
       );
