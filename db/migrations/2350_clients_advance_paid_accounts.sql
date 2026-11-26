SET NAMES utf8mb4;

-- Účty poskytnutých záloh podle dodavatele: karta dodavatele může vést své zálohy
-- na vlastní analytice 314 (např. 314.610) a daň z daňového dokladu k platbě (DDKP)
-- na jiné analytice 314 (např. 314.611). Úhrada zálohy (banka, pokladna, zápočet)
-- jde na účet zálohy dodavatele, DDKP čerpá daň z jeho účtu DPH zálohy a konečná
-- faktura odúčtuje účty, na kterých úhrada a DDKP skutečně leží (AdvancePaidAccount).
--
-- Výchozí NULL = beze změny, platí předkontace firmy (advance.paid.payment,
-- advance.paid.vatdocument).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS advance_paid_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Účet poskytnutých záloh dodavatele (analytika 314), NULL = předkontace firmy.',
  ADD COLUMN IF NOT EXISTS advance_paid_vat_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Účet 314, ze kterého DDKP k záloze dodavatele čerpá daň, NULL = předkontace firmy.';
