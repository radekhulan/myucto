ALTER TABLE payroll_employer_policies
    ALTER COLUMN four_eyes_required SET DEFAULT 0;

-- `row_version` MUSÍ narůst: strážný trigger z migrace 1276 shodí každý UPDATE,
-- který verzi nezvýší (SQLSTATE 45000, „Payroll employer policy row version must
-- increase"). Bez toho tahle migrace spadla na každé instalaci, která měla aspoň
-- jednu politiku se zapnutým schvalováním čtyřma očima (issue #40) — a naopak
-- prošla tam, kde tabulka byla prázdná, takže se na to nepřišlo dřív.
--
-- Oprava je ZÁMĚRNĚ v tomhle souboru, ne v nové migraci: instalace, kde UPDATE
-- selhal, si migraci 1539 nezapsaly jako provedenou, takže by na ni migrátor
-- narazil znovu dřív, než by se k jakékoli novější dostal. Instalace, kde prošla,
-- měly nula dotčených řádků, takže pro ně je oprava no-op.
UPDATE payroll_employer_policies
   SET four_eyes_required = 0,
       row_version = row_version + 1
 WHERE four_eyes_required <> 0;
