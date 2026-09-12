-- Dítě s pořadím „N": daňové zvýhodnění na dítě ve společně hospodařící
-- domácnosti uplatňuje jiná osoba (§ 35c odst. 9 ZDP).
--
-- Pořadí dítěte se podle § 35c odst. 1 určuje za CELOU domácnost. Kdo
-- uplatňuje jen druhé dítě, protože první uplatňuje partner, dostane sazbu
-- druhého dítěte a musí to první uvést s kódem „N" (pokyny MPSV k JMHZ 10440,
-- kontrola 110). Dosud se dal zapsat jen nárok, který si zaměstnanec sám
-- uplatňuje, takže pořadí 2 bez pořadí 1 skončilo v ruční kontrole a mzda
-- stála.
--
-- `credit_status`:
--   claimed          — zvýhodnění uplatňuje tento zaměstnanec (dosavadní stav),
--   claimed_by_other — dítě patří do domácnosti a drží své pořadí, ale
--                      zvýhodnění na ně uplatňuje jiná osoba; částka je nula.
-- `child_order` zůstává pořadím dítěte v domácnosti u obou stavů.
--
-- N nárok z povahy věci tvrdí, že tytéž děti vyživuje ještě někdo — proto
-- nesmí nést „nikdo jiný neuplatňuje", musí potvrdit společnou domácnost a
-- jmenovat jinou vyživující osobu (JMHZ 10453 = ANO, kontrola 127).

SET NAMES utf8mb4;

ALTER TABLE payroll_person_tax_child_claims
  ADD COLUMN IF NOT EXISTS credit_status
    ENUM('claimed','claimed_by_other') NOT NULL DEFAULT 'claimed'
    AFTER child_order;

ALTER TABLE payroll_person_tax_child_claims
  DROP CONSTRAINT IF EXISTS chk_pp_tax_child_credit_status;

ALTER TABLE payroll_person_tax_child_claims
  ADD CONSTRAINT chk_pp_tax_child_credit_status CHECK (
    credit_status = 'claimed'
    OR (
      credit_status = 'claimed_by_other'
      AND other_claimant_excluded = 0
      AND shared_household_confirmed = 1
      AND other_household_caregiver_status = 'present'
    )
  );
