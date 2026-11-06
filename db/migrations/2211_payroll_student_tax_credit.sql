-- MyÚčto.cz — sleva na studenta od 1. 1. 2027 (§ 35ba odst. 1 písm. f) a § 35bc
-- ZDP ve znění zákona č. 180/2026 Sb.).
--
-- Dva nové druhy nároku v evidenci slev: soustavná příprava na budoucí povolání
-- do 26 let (`student`) a prezenční doktorské studium do 28 let
-- (`student-doctoral`). Liší se věkovou hranicí a JMHZ je odlišuje příznakem
-- doktoranda. Stávající hodnoty i pořadí ENUM zůstávají, MODIFY je opakovatelný.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_tax_credit_claims
  MODIFY COLUMN credit_kind ENUM(
    'taxpayer',
    'disability-basic',
    'disability-extended',
    'ztp-p',
    'student',
    'student-doctoral'
  ) NOT NULL;
