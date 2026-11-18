-- MyÚčto.cz — korunová úhrada cizoměnového dokladu z pokladny.
--
-- Pokladní doklad korunové pokladny může uhradit vydanou i přijatou fakturu (účtenku)
-- v cizí měně. Doklad nese skutečně přijaté nebo vydané koruny (`total_amount`), ale
-- faktura se tím uhradí ve své měně. Přepočet korun kurzem dokladu by úhradu zkreslil
-- o kurzový rozdíl (korunová úhrada vyšším kurzem by vypadala jako přeplatek), proto se
-- uhrazená částka v měně hrazeného dokladu ukládá při zaúčtování. Kurz, kterým se
-- saldokonto odúčtovalo, nese cizoměnová stopa saldokontního řádku zápisu.
--
-- NULL = úhrada ve shodné měně (korunová faktura z korunové pokladny); stávající doklady
-- se nemění.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE cash_documents
  ADD COLUMN IF NOT EXISTS settled_document_amount DECIMAL(15,2) NULL
      COMMENT 'uhrazená částka v měně hrazeného dokladu u korunové úhrady cizoměnové faktury; NULL = shodná měna'
      AFTER amount_foreign;
