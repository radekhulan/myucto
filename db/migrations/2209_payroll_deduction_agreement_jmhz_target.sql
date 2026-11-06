-- Dohody o srážkách: věřitel a zařazení srážky pro měsíční hlášení JMHZ
-- (10349 až 10353, interakce IN24 a IN28).
--
-- PROČ: u zaměstnance, který je osobou se zdravotním postižením u zaměstnavatele
-- uznaného na chráněném trhu práce (IN28), se hlásí srážky ze mzdy určené
-- k uspokojení plnění ZAMĚSTNAVATELE, rozdělené do čtyř atributů: 10350 (dohoda
-- podle OZ), 10351 (náhrada škody), 10352 (závodní stravování § 236 ZP) a 10353
-- (jiný závazek podle § 146 písm. b) ZP). Pokyny MPSV k MH 1.4.14 (kap. 2.4.15)
-- uvádějí tentýž příklad (zápůjčka) u 10350 i 10353, takže zařazení nejde odvodit
-- z titulu srážky. Ukládá se proto výslovně.
--
--  - creditor_is_employer: NULL = nerozhodnuto (dosavadní dohody), 1 = věřitelem
--    je zaměstnavatel, 0 = třetí osoba. Nerozhodnutá dohoda, ze které se
--    zaměstnanci s IN28 v měsíci srazilo, hlášení zastaví; nikdy se nedomýšlí.
--  - jmhz_target: atribut JMHZ, do kterého srážka patří; jen u dohody ve
--    prospěch zaměstnavatele.
--
-- Srážky ze zákona (§ 147 odst. 1 písm. c) až e) ZP) zařazení nemají: 10353 je
-- podle datového slovníku 1.4.1.6 srážka „podle § 146 písm. b)", tedy z dohody.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, CHECK se nejdřív zahodí.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
    ADD COLUMN IF NOT EXISTS creditor_is_employer TINYINT(1) NULL DEFAULT NULL
        COMMENT 'věřitelem je zaměstnavatel (JMHZ 10349), NULL = nerozhodnuto'
        AFTER legal_basis,
    ADD COLUMN IF NOT EXISTS jmhz_target ENUM('10350', '10351', '10352', '10353') NULL DEFAULT NULL
        COMMENT 'atribut JMHZ srážky ve prospěch zaměstnavatele'
        AFTER creditor_is_employer;

ALTER TABLE payroll_deduction_agreement_versions
    ADD COLUMN IF NOT EXISTS creditor_is_employer TINYINT(1) NULL DEFAULT NULL
        AFTER legal_basis,
    ADD COLUMN IF NOT EXISTS jmhz_target ENUM('10350', '10351', '10352', '10353') NULL DEFAULT NULL
        AFTER creditor_is_employer;

ALTER TABLE payroll_deduction_agreements
    DROP CONSTRAINT IF EXISTS chk_payroll_deduction_agreement_jmhz_target;

ALTER TABLE payroll_deduction_agreements
    ADD CONSTRAINT chk_payroll_deduction_agreement_jmhz_target CHECK (
        ((creditor_is_employer = 1 AND jmhz_target IS NOT NULL)
            OR (COALESCE(creditor_is_employer, 0) = 0 AND jmhz_target IS NULL))
        AND (legal_basis = 'agreement' OR (creditor_is_employer IS NULL AND jmhz_target IS NULL))
    );
