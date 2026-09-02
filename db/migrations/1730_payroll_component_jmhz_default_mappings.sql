-- MyÚčto.cz — výchozí zařazení mzdových složek do JMHZ nesmí záviset na tom,
-- jestli někdo otevřel obrazovku složek.
--
-- `PayrollComponentJmhzMappingDefaults::apply()` doplňuje zařazení u složek
-- z dodaného číselníku, kde je cílový atribut jednoznačný. Volá se ale jen
-- z `GET /payroll/components/jmhz-mappings`, takže do té doby kontrola před
-- mzdovým během i příprava hlášení hlásily „složka nemá zařazení" — a nález
-- sám zmizel, jakmile účetní tu stránku otevřela. Odpověď kontroly tak
-- závisela na návštěvě nesouvisející obrazovky.
--
-- Migrace doplní totéž pro už založené firmy. Pravidla se drží beze zbytku:
-- složka, která JAKÝKOLI záznam zařazení má (i vědomě deaktivovaný), se
-- přeskočí, a `created_by` zůstává NULL, aby bylo poznat, že to vyplnila
-- aplikace. Balík specifikace se bere nejnovější, který cílový atribut zná.
-- Opakované spuštění nepřidá nic.

SET NAMES utf8mb4;

INSERT INTO payroll_component_jmhz_mappings
    (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
     created_by, updated_by)
SELECT definition.supplier_id,
       definition.id,
       attribute.package_id,
       attribute.attribute_id,
       NULL,
       NULL
  FROM payroll_component_definitions definition
  JOIN (
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
  UNION SELECT 'NAHRADA_MZDY', '10337'
  UNION SELECT 'NAHRADA_MZDY_DOVOLENA', '10338'
  UNION SELECT 'NAHRADA_MZDY_DPN', '10342'
       ) fallback ON fallback.code = definition.code
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.attribute_id = fallback.attribute_id
   AND attribute.package_id = (
         SELECT MAX(newest.package_id)
           FROM payroll_jmhz_dictionary_attributes newest
          WHERE newest.attribute_id = fallback.attribute_id
       )
 WHERE definition.jmhz_treatment = 'included'
   AND NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = definition.supplier_id
            AND existing.component_definition_id = definition.id
       );
