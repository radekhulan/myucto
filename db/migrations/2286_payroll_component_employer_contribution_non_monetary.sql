-- MyÚčto.cz — příspěvek zaměstnavatele na penzijní a životní produkty a na
-- pojištění dlouhodobé péče se zaměstnanci nevyplácí.
--
-- § 6 odst. 9 písm. m) ZDP osvobozuje „příjem v podobě příspěvku uhrazeného
-- zaměstnavatelem na daňově podporované produkty spoření na stáří jeho
-- zaměstnance nebo na daňově podporované pojištění dlouhodobé péče, jehož
-- pojistníkem je jeho zaměstnanec, do úhrnné výše 50000 Kč ročně". Příspěvek
-- platí zaměstnavatel penzijní společnosti nebo pojišťovně. Výchozí složky
-- PRISPEVEK_PENZE_ZIVOTNI a PRISPEVEK_DLOUHODOBA_PECE ho ale vedly jako peněžní
-- plnění (`value_kind = monetary`), takže se přičetl k výplatě zaměstnance.
--
-- Výchozí číselník (`PayrollComponentDefaults`) je už nese jako `non_monetary`;
-- tohle dorovná složky, které firmy mají založené. Mění se jen složka, kterou
-- firma nechala v neposouzeném stavu (`manual_review`) nebo ji zařadila jako
-- osvobozenou: osvobození podle písm. m) příspěvek vyplacený zaměstnanci nemá.
-- Složka, kterou firma vede jako zdanitelnou peněžní (příspěvek vyplácený
-- zaměstnanci ke mzdě), zůstává beze změny. Schválené vstupy a spočtené běhy
-- nesou vlastní zmrazený snímek složky a nemění se.
--
-- Opakované spuštění nic nezmění (po první změně už podmínka neplatí).

SET NAMES utf8mb4;

UPDATE payroll_component_definitions
   SET value_kind = 'non_monetary',
       row_version = row_version + 1
 WHERE value_kind = 'monetary'
   AND tax_treatment IN ('manual_review', 'exempt')
   AND (
        (code = 'PRISPEVEK_PENZE_ZIVOTNI' AND component_kind = 'benefit_pension')
     OR (code = 'PRISPEVEK_DLOUHODOBA_PECE' AND component_kind = 'benefit_care')
   );
