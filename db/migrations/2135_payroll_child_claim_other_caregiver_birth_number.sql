-- Rodné číslo jiné osoby vyživující tytéž děti (JMHZ 10434). Měsíční hlášení
-- ostatních mzdových programů (PAMICA, Premier, Money S3, ABRA, AVENSIO) tu
-- osobu identifikují rodným číslem; podle něj ČSSZ a správce daně párují
-- nárok mezi poplatníky. Datum narození (10433) zůstává povinné, rodné číslo
-- je nepovinné a ukládá se šifrované s otiskem a maskou jako u vyživované
-- osoby. Vést se smí jen u kladné odpovědi (`present`).

SET NAMES utf8mb4;

ALTER TABLE payroll_person_tax_child_claims
  ADD COLUMN IF NOT EXISTS other_caregiver_birth_number_ciphertext VARCHAR(512) NULL
    AFTER other_caregiver_birth_date,
  ADD COLUMN IF NOT EXISTS other_caregiver_birth_number_hash BINARY(32) NULL
    AFTER other_caregiver_birth_number_ciphertext,
  ADD COLUMN IF NOT EXISTS other_caregiver_birth_number_masked VARCHAR(191) NULL
    AFTER other_caregiver_birth_number_hash;

ALTER TABLE payroll_person_tax_child_claims
  DROP CONSTRAINT IF EXISTS chk_pp_tax_child_other_caregiver_birth_number;

ALTER TABLE payroll_person_tax_child_claims
  ADD CONSTRAINT chk_pp_tax_child_other_caregiver_birth_number CHECK (
    (
      other_caregiver_birth_number_ciphertext IS NULL
      AND other_caregiver_birth_number_hash IS NULL
      AND other_caregiver_birth_number_masked IS NULL
    )
    OR
    (
      other_household_caregiver_status = 'present'
      AND other_caregiver_birth_number_ciphertext IS NOT NULL
      AND other_caregiver_birth_number_hash IS NOT NULL
      AND other_caregiver_birth_number_masked IS NOT NULL
    )
  );
