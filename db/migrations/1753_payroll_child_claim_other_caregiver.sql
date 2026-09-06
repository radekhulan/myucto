-- JMHZ 10453 + kontrola 127: měsíční blok `zvyhodneniDetiMesic` se neptá jen na
-- pořadí a ZTP/P, ale i na to, zda tytéž děti v téže společně hospodařící
-- domácnosti vyživuje i JINÁ osoba — a je-li odpověď „ano", chce ji ČSSZ
-- pojmenovat (10431 jméno, 10432 příjmení, 10433 datum narození nebo 10434
-- rodné číslo). Roční větev tenhle fakt už evidovala u žádosti o roční
-- zúčtování (migrace 1578); měsíční ho neměla kde vzít, takže se celý blok
-- nedal sestavit a zvýhodnění na děti blokovalo hlášení celé firmy.
--
-- Stav je tri-state záměrně: `unknown` je výchozí hodnota u řádků založených
-- dřív a od vědomého „ne" se musí dát odlišit — jinak by se do podání dostalo
-- tvrzení, které nikdo neučinil. Rodné číslo jiné osoby se needviduje: datum
-- narození kontrole 127 stačí a je to citlivý údaj navíc.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_tax_child_claims
  ADD COLUMN IF NOT EXISTS other_household_caregiver_status
    ENUM('unknown','none','present') NOT NULL DEFAULT 'unknown'
    AFTER other_claimant_excluded,
  ADD COLUMN IF NOT EXISTS other_caregiver_given_name VARCHAR(100) NULL
    AFTER other_household_caregiver_status,
  ADD COLUMN IF NOT EXISTS other_caregiver_family_name VARCHAR(100) NULL
    AFTER other_caregiver_given_name,
  ADD COLUMN IF NOT EXISTS other_caregiver_birth_date DATE NULL
    AFTER other_caregiver_family_name;

ALTER TABLE payroll_person_tax_child_claims
  DROP CONSTRAINT IF EXISTS chk_pp_tax_child_other_caregiver;

ALTER TABLE payroll_person_tax_child_claims
  ADD CONSTRAINT chk_pp_tax_child_other_caregiver CHECK (
    (
      other_household_caregiver_status = 'present'
      AND other_caregiver_given_name IS NOT NULL
      AND CHAR_LENGTH(TRIM(other_caregiver_given_name)) > 0
      AND other_caregiver_family_name IS NOT NULL
      AND CHAR_LENGTH(TRIM(other_caregiver_family_name)) > 0
      AND other_caregiver_birth_date IS NOT NULL
    )
    OR
    (
      other_household_caregiver_status <> 'present'
      AND other_caregiver_given_name IS NULL
      AND other_caregiver_family_name IS NULL
      AND other_caregiver_birth_date IS NULL
    )
  );
