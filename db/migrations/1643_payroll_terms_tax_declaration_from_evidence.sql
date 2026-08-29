-- MyÚčto.cz — prohlášení k dani na smluvních podmínkách je jen zrcadlo evidence.
--
-- `payroll_employment_terms.tax_declaration_signed` bylo druhé, nezávisle
-- editovatelné místo pro tentýž údaj, který vede zákonná evidence osoby
-- (`payroll_person_tax_declarations`). Obě místa se rozcházela a mzdový běh na
-- to spadl blokátorem `tax_declaration_term_conflict` — past, ne kontrola:
-- prohlášení se podepisuje (i odvolává) kdykoliv v průběhu vztahu, kdežto
-- smluvní podmínky jsou verze smlouvy, kterou kvůli podpisu nikdo neverzuje.
--
-- Od téhle migrace je sloupec ODVOZENÝ: zapisovací cesta ho plní podle
-- evidence platné k začátku účinnosti verze (`PayrollEmploymentRepository`)
-- a mzdový snímek si ho stejně bere ze zákonné evidence osoby
-- (`PayrollRunSnapshotBuilder`). Tenhle příkaz dorovnává, co vzniklo dřív, aby
-- karta vztahu a časová osa neukazovaly hodnotu, kterou už nikdo nečte.
--
-- Sloupec se NERUŠÍ: nese ho snímek každé už zmrazené revize běhu a čte ho
-- podání JMHZ (`JmhzScenario1DocumentResolver`), které smí pracovat výhradně
-- se zmrazenými daty. Zmrazené snímky tahle migrace nemění.
--
-- Bez odpovídajícího záznamu v evidenci se bere prohlášení za NEPODEPSANÉ:
-- podle § 38k odst. 4 ZDP se bez něj měsíční sleva uplatnit nesmí a za
-- nesraženou zálohu ručí plátce (§ 38s ZDP).

SET NAMES utf8mb4;

UPDATE payroll_employment_terms terms
   SET terms.tax_declaration_signed = (
         SELECT CASE WHEN declaration.status = 'signed' THEN 1 ELSE 0 END
           FROM payroll_person_tax_declarations declaration
           JOIN payroll_employments employment
             ON employment.supplier_id = terms.supplier_id
            AND employment.id = terms.employment_id
          WHERE declaration.supplier_id = terms.supplier_id
            AND declaration.employee_id = employment.employee_id
            AND declaration.effective_from <= terms.effective_from
            AND (declaration.effective_to IS NULL
                 OR declaration.effective_to >= terms.effective_from)
          ORDER BY declaration.effective_from DESC
          LIMIT 1
       )
 WHERE EXISTS (
         SELECT 1
           FROM payroll_person_tax_declarations declaration
           JOIN payroll_employments employment
             ON employment.supplier_id = terms.supplier_id
            AND employment.id = terms.employment_id
          WHERE declaration.supplier_id = terms.supplier_id
            AND declaration.employee_id = employment.employee_id
            AND declaration.effective_from <= terms.effective_from
            AND (declaration.effective_to IS NULL
                 OR declaration.effective_to >= terms.effective_from)
       );

UPDATE payroll_employment_terms terms
   SET terms.tax_declaration_signed = 0
 WHERE terms.tax_declaration_signed = 1
   AND NOT EXISTS (
         SELECT 1
           FROM payroll_person_tax_declarations declaration
           JOIN payroll_employments employment
             ON employment.supplier_id = terms.supplier_id
            AND employment.id = terms.employment_id
          WHERE declaration.supplier_id = terms.supplier_id
            AND declaration.employee_id = employment.employee_id
            AND declaration.effective_from <= terms.effective_from
            AND (declaration.effective_to IS NULL
                 OR declaration.effective_to >= terms.effective_from)
       );
