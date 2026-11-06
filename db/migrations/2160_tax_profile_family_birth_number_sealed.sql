-- Rodná čísla dětí a manžela/manželky v daňovém profilu (H28).
--
-- Profil daně z příjmů je držel otevřeně ve sloupci `birth_number`, ačkoliv
-- mzdy chrání tutéž třídu údajů šifrou, otiskem a maskou
-- (`payroll_dependants`, migrace 1312). Nové sloupce mají stejný tvar jako
-- tam: šifrový text (kontext `tax_family_identifier` + řádek), tenantový keyed
-- hash pro hledání duplicit a maska pro zobrazení.
--
-- Převod stávajících hodnot dělá PHP (šifrování SQL neumí):
-- `api/bin/backfill-tax-family-birth-numbers.php`, spouští ho i auto-backfill
-- v `migrate.php`. Otevřený sloupec se vyprázdní až po ověřeném zapečetění
-- řádku, do té doby z něj aplikace čte jako dřív. Sloupec `birth_number`
-- zůstává kvůli návratu na starší verzi a nedokončenému převodu.
--
-- Kontrola identity dítěte a manžela počítá i se zapečetěným číslem: řádek se
-- zakládá s otiskem a maskou a šifrový text, vázaný na id řádku, se dopíše
-- hned po INSERTu v téže transakci.
--
-- Idempotence: ADD COLUMN/KEY IF NOT EXISTS, constrainty DROP IF EXISTS + ADD.

SET NAMES utf8mb4;

ALTER TABLE tax_profile_children
  ADD COLUMN IF NOT EXISTS birth_number_ciphertext VARCHAR(512) NULL AFTER birth_number,
  ADD COLUMN IF NOT EXISTS birth_number_hash BINARY(32) NULL AFTER birth_number_ciphertext,
  ADD COLUMN IF NOT EXISTS birth_number_masked VARCHAR(191) NULL AFTER birth_number_hash,
  ADD KEY IF NOT EXISTS idx_tax_child_birth_number_hash (supplier_id, birth_number_hash);

ALTER TABLE tax_profile_children DROP CONSTRAINT IF EXISTS chk_tax_child_identity;
ALTER TABLE tax_profile_children
  ADD CONSTRAINT chk_tax_child_identity CHECK (
    birth_number IS NOT NULL OR birth_number_hash IS NOT NULL OR birth_date IS NOT NULL
  );

ALTER TABLE tax_profile_children DROP CONSTRAINT IF EXISTS chk_tax_child_birth_number_sealed;
ALTER TABLE tax_profile_children
  ADD CONSTRAINT chk_tax_child_birth_number_sealed CHECK (
    (birth_number_ciphertext IS NULL AND birth_number_hash IS NULL AND birth_number_masked IS NULL)
    OR (birth_number_hash IS NOT NULL AND birth_number_masked IS NOT NULL AND birth_number IS NULL)
  );

ALTER TABLE tax_profile_spouse_claims
  ADD COLUMN IF NOT EXISTS birth_number_ciphertext VARCHAR(512) NULL AFTER birth_number,
  ADD COLUMN IF NOT EXISTS birth_number_hash BINARY(32) NULL AFTER birth_number_ciphertext,
  ADD COLUMN IF NOT EXISTS birth_number_masked VARCHAR(191) NULL AFTER birth_number_hash;

ALTER TABLE tax_profile_spouse_claims DROP CONSTRAINT IF EXISTS chk_tax_spouse_identity;
ALTER TABLE tax_profile_spouse_claims
  ADD CONSTRAINT chk_tax_spouse_identity CHECK (
    birth_number IS NOT NULL OR birth_number_hash IS NOT NULL OR birth_date IS NOT NULL
  );

ALTER TABLE tax_profile_spouse_claims DROP CONSTRAINT IF EXISTS chk_tax_spouse_birth_number_sealed;
ALTER TABLE tax_profile_spouse_claims
  ADD CONSTRAINT chk_tax_spouse_birth_number_sealed CHECK (
    (birth_number_ciphertext IS NULL AND birth_number_hash IS NULL AND birth_number_masked IS NULL)
    OR (birth_number_hash IS NOT NULL AND birth_number_masked IS NOT NULL AND birth_number IS NULL)
  );
