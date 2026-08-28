-- MyÚčto.cz — W3/Ú-02: analytická předkontace mzdové složky konečně projde.
--
-- CO BYLO ŠPATNĚ: migrace 1262 chtěla u předkontací složek povolit tečku
-- (521.100), ale sáhla na tabulku `payroll_components`, která NEEXISTUJE —
-- číselník složek se jmenuje `payroll_component_definitions` (1210). Díky
-- `ALTER TABLE IF EXISTS` se chyba spolkla a migrace proběhla zeleně, takže
-- dál platil původní CHECK z 1210 s regexem '^[0-9]{3,16}$', tedy BEZ tečky.
--
-- NÁSLEDEK: PHP formát schválilo (PayrollAccountCode::isValid povoluje
-- '^[0-9]{3}[.A-Z0-9]{0,13}$'), ale INSERT spadl na CHECK a účetní nemohla
-- složce nastavit analytiku. Chyba navíc chodila jako HTTP 500, protože
-- MariaDB hlásí CHECK jako HY000/3819 a repozitář hlídal jen 23000 — to řeší
-- PayrollComponentRepository::rethrowCheckViolation().
--
-- Regex je záměrně TENTÝŽ, jaký chtěla 1262 a jaký vynucuje aplikace:
-- třímístná syntetika + až 13 znaků analytiky z [.A-Z0-9].
--
-- IDEMPOTENCE: testy pouštějí migrace opakovaně a MariaDB neumí u CHECKu
-- `IF NOT EXISTS`, proto se omezení nejdřív zahodí (`DROP CONSTRAINT IF
-- EXISTS`) a teprve pak přidá. Tabulka se tu ADRESUJE BEZ `IF EXISTS`
-- schválně: kdyby se název zase rozešel, migrace má spadnout, ne mlčet.

SET NAMES utf8mb4;

ALTER TABLE payroll_component_definitions
  DROP CONSTRAINT IF EXISTS chk_payroll_component_accounts;

ALTER TABLE payroll_component_definitions
  ADD CONSTRAINT chk_payroll_component_accounts CHECK (
    (
      accounting_debit_code IS NULL
      OR accounting_debit_code REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'
    )
    AND
    (
      accounting_credit_code IS NULL
      OR accounting_credit_code REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'
    )
  );
