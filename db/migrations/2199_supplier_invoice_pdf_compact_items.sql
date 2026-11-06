-- Kompaktní řádky položek: per-supplier přepínač vzhledu tabulky položek na PDF faktury.
--
-- PROČ: faktury s desítkami položek se s dnešním vzdušným řádkem tisknou na několik
-- stran. Zapnutá volba přidá tabulce položek třídu `items-compact` (menší odsazení
-- a písmo, užší mezery kolem tabulky) a sekundární texty (sériová čísla, rozpis
-- balení) zmenší, sériová čísla navíc pokračují na řádku popisu.
--
-- Výchozí 0 = PDF beze změny. Hodnota se čte z živého dodavatele (snapshot dokladu
-- ji nenese). Po změně se zneplatní PDF konceptů; už vygenerovaná PDF vystavených
-- dokladů zůstávají, dokud se nevytvoří znovu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_pdf_compact_items TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_pdf_monochrome;
