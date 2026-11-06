-- Placené náhradní volno za svátek (§ 115) se nesmí zaměnit za volno za přesčas.
ALTER TABLE payroll_absences
  MODIFY COLUMN absence_type ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle',
    'compensatory_time_off','unexcused','other',
    'public_function','employee_obstacle_unpaid','invalid_termination',
    'holiday_compensatory_time_off'
  ) NOT NULL,
  MODIFY COLUMN compensation_policy ENUM(
    'none','average_100','average_custom','dpn','statutory_manual_review','hourly_claim'
  ) NOT NULL DEFAULT 'statutory_manual_review';

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
                SELECT 'MZDA_HODINOVA_ZAKLAD' AS code, '10329' AS attribute_id
                UNION ALL SELECT 'MZDA_DOSAZENA_PRESCAS', '10329'
                UNION ALL SELECT 'NAHRADA_MZDY_VOLNO_ZA_SVATEK', '10339'
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
