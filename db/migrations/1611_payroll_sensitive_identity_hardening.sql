-- MyÚčto.cz — W1: ochrana osobních údajů ve mzdách.
--
-- Dvě věci, které patří k opravám P-02 a P-06 v kódu:
--
--  1. `payroll_employees.birth_number` / `.address` se označují jako VYŘAZENÉ.
--     Legacy routa `/api/accounting/payroll/employees` je chráněná jen právem
--     `accounting`, takže je četl i uživatel bez jediného mzdového práva —
--     nešifrovaně, mimo `payroll_person_identifiers` a bez stopy o nahlédnutí.
--     Repository i akce je od téhle migrace nečtou ani nezapisují.
--
--     Sloupce se ZÁMĚRNĚ NEZAHAZUJÍ. Hodnoty v nich jsou pro instalace, které
--     používaly jen starou agendu, jediným výskytem rodného čísla; přesealovat
--     je do `payroll_person_identifiers` znamená zašifrovat je klíčem aplikace
--     a spočítat keyed hash, což v SQL udělat nelze. Zahodit je bez převodu by
--     byla nevratná ztráta dat. Převod proto musí udělat aplikační jednorázová
--     úloha, teprve po ní se sloupce smí zahodit další migrací. Do té doby je
--     jejich obsah nedostupný přes API.
--
--     `PayrollPersonAnonymizationRepository` je při odosobnění dál NULLuje;
--     to zůstává v platnosti a je to jediný zápis, který se jich smí dotknout.
--
--  2. Uložené masky osobních identifikátorů se zkracují na dvě viditelné
--     číslice. Maska se materializuje při zapečetění hodnoty, takže starší
--     řádky nesou ještě čtyřmístnou koncovku — a přepočítat ji z databáze
--     nejde, dešifrovat ciphertext v SQL nelze. Čtyři číslice ale nechrání nic:
--     ve stejné odpovědi chodí `birth_date` a `sex`, a české rodné číslo je
--     RRMMDD/XXXC, takže z data narození, pohlaví a čtyřmístné koncovky je
--     známé celé. Zkrácení je čistě řetězcová operace nad zobrazovaným
--     sloupcem; ciphertext ani lookup hash se nemění.
--
-- Idempotence: MODIFY COLUMN je zápis stejného tvaru, UPDATE má podmínku, která
-- po prvním průchodu přestane platit (třetí znak od konce je pak už odrážka).

SET NAMES utf8mb4;

ALTER TABLE payroll_employees
  MODIFY COLUMN birth_number VARCHAR(20) NULL
    COMMENT 'VYŘAZENO (migrace 1611) — nešifrované rodné číslo staré agendy; API je nečte ani nezapisuje, jediný platný zdroj je payroll_person_identifiers',
  MODIFY COLUMN address VARCHAR(255) NULL
    COMMENT 'VYŘAZENO (migrace 1611) — nešifrované bydliště staré agendy; API je nečte ani nezapisuje, jediný platný zdroj je payroll_person_addresses';

UPDATE payroll_person_identifiers
   SET value_masked = CONCAT(
         REPEAT('•', CHAR_LENGTH(value_masked) - 2),
         RIGHT(value_masked, 2)
       )
 WHERE CHAR_LENGTH(value_masked) >= 3
   AND SUBSTRING(value_masked, CHAR_LENGTH(value_masked) - 2, 1) <> '•';

UPDATE payroll_dependants
   SET birth_number_masked = CONCAT(
         REPEAT('•', CHAR_LENGTH(birth_number_masked) - 2),
         RIGHT(birth_number_masked, 2)
       )
 WHERE birth_number_masked IS NOT NULL
   AND CHAR_LENGTH(birth_number_masked) >= 3
   AND SUBSTRING(birth_number_masked, CHAR_LENGTH(birth_number_masked) - 2, 1) <> '•';
