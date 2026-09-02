-- MyÚčto.cz — omylem ukončená nebo zrušená dohoda o srážkách jde vrátit.
--
-- „Ukončit“ šlo zmáčknout z návrhu, z aktivní i z pozastavené dohody jedním
-- klikem, a zpátky nevedlo nic. Nový přechod `reopened` vrací dohodu do
-- POZASTAVENÉHO stavu — do mzdového běhu vstupuje jen aktivní dohoda, takže
-- se srážky samy nerozjedou; historie ledgeru zůstává nedotčená.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreement_versions
  MODIFY COLUMN change_kind ENUM(
    'created','updated','activated','paused','resumed','ended','cancelled','reopened'
  ) NOT NULL;
