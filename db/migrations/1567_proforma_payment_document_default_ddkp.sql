-- MyÚčto.cz — výchozím chováním je daňový doklad k přijaté platbě (issue #39).
--
-- Rešerše zákona ukázala, že původní výchozí hodnota (`final_on_full_payment`)
-- není správná bez podmínek, a to ani u rychlého prodeje:
--
--   § 20a odst. 2 — přijme-li plátce úplatu PŘED uskutečněním plnění, vzniká
--     povinnost přiznat daň ke dni přijetí úplaty (je-li plnění známo dostatečně
--     určitě dle odst. 3: předmět, sazba, místo plnění),
--   § 28 odst. 1 písm. d) — při přijetí takové úplaty JE PLÁTCE POVINEN vystavit
--     daňový doklad, a to podle odst. 8 do 15 dnů,
--   § 29 odst. 3 — doklad k úplatě je odlehčený (bez rozsahu plnění a jednotkové
--     ceny) a nese DEN PŘIJETÍ ÚPLATY, ne den uskutečnění plnění.
--
-- Vyúčtovací faktura s DUZP = den platby je tedy v pořádku jen tehdy, když plnění
-- opravdu nastalo týž den. Jakmile se zboží expeduje o pár dnů později, doklad
-- tvrdí něco, co se nestalo, a odběrateli komplikuje prokázání nároku na odpočet.
--
-- Sjednocení existujících firem je bezpečné: sloupec zavedla migrace 1565, která
-- se nikdy nedostala do vydané verze, takže dnešní hodnota u nikoho není vědomá
-- volba, jen výchozí stav. Kdo chce původní chování, přepne si ho v nastavení.

ALTER TABLE supplier
  MODIFY COLUMN proforma_payment_document
      ENUM('final_on_full_payment', 'always_tax_document', 'manual')
      NOT NULL DEFAULT 'always_tax_document'
      COMMENT 'doklad po úhradě proformy: always_tax_document = vždy daňový doklad k přijaté platbě (výchozí, § 20a + § 28), final_on_full_payment = doplacená proforma zakládá rovnou vyúčtovací fakturu (jen když plnění nastává úhradou), manual = nezakládat nic a připomenout v úkolech';

UPDATE supplier
   SET proforma_payment_document = 'always_tax_document'
 WHERE proforma_payment_document = 'final_on_full_payment';
