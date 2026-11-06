-- Samostatné posouzení mzdy pro § 111 bez změny daňové klasifikace nebo starých snímků.
ALTER TABLE payroll_component_definitions
    ADD COLUMN IF NOT EXISTS minimum_wage_treatment ENUM('included', 'excluded', 'manual_review') NULL,
    ADD COLUMN IF NOT EXISTS minimum_wage_reason VARCHAR(500) NULL;

-- Dorovnání existujících složek odpovídá PayrollComponentJmhzMappingDefaults.
-- Výslovné zařazení účetní, včetně vypnutého nebo ze staršího balíku,
-- se nepřepisuje. Vyloučené a ručně posuzované složky zůstávají beze změny.
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
                SELECT 'DOPLATEK_MINIMALNI_MZDA' AS code, '10329' AS attribute_id
               ) by_code ON by_code.code = definition.code
         WHERE definition.jmhz_treatment = 'included'
       ) target
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1'
   AND package.manifest_sha256 = '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = target.attribute_id
 WHERE NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = target.supplier_id
            AND existing.component_definition_id = target.id
       );
