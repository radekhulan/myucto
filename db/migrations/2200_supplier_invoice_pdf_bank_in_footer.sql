-- Bankovní spojení v patičce: per-supplier přepínač rozvržení platebních údajů na PDF faktury.
--
-- PROČ: část firem chce bankovní spojení jako hlavičkový údaj v patičce každé strany
-- a u součtu jen QR kód. Zapnutá volba přesune číslo účtu, IBAN, BIC a banku do
-- patičky každé strany a platební panel ukáže jen QR s popiskem. Variabilní symbol
-- zůstává v údajích dokladu. Stavy uhrazeno, hotově, kartou a vratka se nemění.
--
-- Výchozí 0 = PDF beze změny. Hodnota se čte z živého dodavatele (snapshot dokladu
-- ji nenese). Po změně se zneplatní PDF konceptů; už vygenerovaná PDF vystavených
-- dokladů zůstávají, dokud se nevytvoří znovu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_pdf_bank_in_footer TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_pdf_monochrome;
