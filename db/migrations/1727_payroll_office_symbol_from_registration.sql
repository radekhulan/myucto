-- Doplní VS ČSSZ do payroll_offices z historie registrace mzdové účtárny.
--
-- Variabilní symbol se zadává v historii registrace účtárny, ale předpis
-- sociálního pojištění, měsíční hlášení JMHZ i přehled PVPOJ čtou sloupec
-- `payroll_offices.social_security_variable_symbol`. Ten zůstával prázdný a
-- hromadné uložení nastavení účtárny ho nepřepíše, takže z aplikace nebylo
-- kudy ho doplnit — příprava plateb spadla na „social_security_variable_symbol
-- není neprázdný text".
--
-- Od teď sloupec udržuje PayrollOfficeRegistrationRepository::syncOfficeSymbol();
-- tahle migrace dorovná existující instalace. Idempotentní: přepisuje jen
-- prázdné hodnoty a bere poslední účinnou verzi.
UPDATE payroll_offices office
   SET office.social_security_variable_symbol = (
       SELECT version.social_security_variable_symbol
         FROM payroll_office_registration_versions version
        WHERE version.supplier_id = office.supplier_id
          AND version.office_id = office.id
        ORDER BY version.effective_from DESC, version.id DESC
        LIMIT 1
   )
 WHERE (office.social_security_variable_symbol IS NULL
        OR office.social_security_variable_symbol = '')
   AND EXISTS (
       SELECT 1
         FROM payroll_office_registration_versions version
        WHERE version.supplier_id = office.supplier_id
          AND version.office_id = office.id
   );
