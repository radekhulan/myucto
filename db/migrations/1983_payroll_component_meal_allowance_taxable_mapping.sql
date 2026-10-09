-- MyÚčto.cz — výchozí zařazení složky PRISPEVEK_STRAVOVANI_ZDANITELNY do JMHZ.
--
-- Peněžitý příspěvek na stravování nad limit § 6 odst. 9 písm. b) ZDP (převod
-- z PAMICA: nadlimitní část Z21 a Z21a) je zdanitelný příjem a vyměřovací
-- základ, stejně jako STRAVOVANI_ZDANITELNE, takže stejná kolonka 10328. Složku
-- zakládá aplikace sama při čtení číselníku (`PayrollComponentRepository::ensureDefaults()`)
-- i se zařazením; tohle dorovná firmy, kde složka se stejným kódem už existuje
-- bez zařazení. Pravidlo je totéž jako v
-- `PayrollComponentJmhzMappingDefaults::targetFor()`; shodu hlídá
-- `PayrollComponentJmhzKindDefaultsMigrationTest`. Rozhodnutí účetní se
-- nepřepisuje a opakované spuštění nepřidá nic.

SET NAMES utf8mb4;

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
               by_code.attribute_id
          FROM payroll_component_definitions definition
          JOIN (
                SELECT 'PRISPEVEK_STRAVOVANI_ZDANITELNY' AS code, '10328' AS attribute_id
               ) by_code ON by_code.code = definition.code
         WHERE definition.jmhz_treatment = 'included'
       ) target
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.10_manifest-v1'
   AND package.manifest_sha256 = 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = target.attribute_id
 WHERE NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = target.supplier_id
            AND existing.component_definition_id = target.id
       );
