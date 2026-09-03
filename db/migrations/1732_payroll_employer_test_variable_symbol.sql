-- MyÚčto.cz — testovací variabilní symbol ČSSZ per mzdová účtárna.
--
-- ČSSZ potvrdila e-mailem (3. 9. 2026), že produkční a testovací prostředí
-- jsou u ní oddělená a zaměstnavatel má v TESTOVACÍM prostředí přidělený
-- VLASTNÍ variabilní symbol — podání pod jiným (typicky ostrým) symbolem
-- ČSSZ zamítne s hláškou o chybějícím pověření/registraci v OSSZ, která na
-- záměnu VS vůbec neukazuje.
--
-- Sloupec patří na `payroll_offices`, ne do historie registrace
-- (`payroll_office_registration_versions`). Historie modeluje VS, který
-- přiděluje ČSSZ K REGISTRACI zaměstnavatele a mění se v čase (odtud
-- `effective_from`); testovací VS je pevný technický identifikátor pro
-- sandbox ČSSZ, nemá žádnou "účinnost od" a mění se jen tehdy, když ho ČSSZ
-- přidělí znovu. Zbytečná verzovaná historie by tu jen komplikovala UI bez
-- odpovídajícího reálného významu.
SET NAMES utf8mb4;

ALTER TABLE payroll_offices
  ADD COLUMN IF NOT EXISTS test_social_security_variable_symbol VARCHAR(10) NULL
    AFTER social_security_variable_symbol;

ALTER TABLE payroll_offices
  DROP CONSTRAINT IF EXISTS chk_payroll_office_test_social_vs;

ALTER TABLE payroll_offices
  ADD CONSTRAINT chk_payroll_office_test_social_vs CHECK (
    test_social_security_variable_symbol IS NULL
    OR test_social_security_variable_symbol REGEXP '^[0-9]{1,10}$'
  );
