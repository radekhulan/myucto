-- MyÚčto.cz — jaký doklad má vzniknout po úhradě zálohové faktury (issue #39).
--
-- Automatika dosud rozhodovala podle toho, jestli platba proformu DOPLATILA:
-- doplacená → koncept vyúčtovací faktury, částečně uhrazená → daňový doklad
-- k přijaté platbě. Jenže „doplacená proforma" není totéž co „uskutečněné
-- plnění" — u zakázkové výroby je proforma dílčí akontace na budoucí dílo
-- (např. 70 000 Kč ze zakázky za 100 000 Kč) a její plná úhrada nic nedokončuje.
-- Odběratel v takové chvíli potřebuje daňový doklad k přijaté platbě, aby si
-- uplatnil odpočet; vyúčtovací fakturu na nepředané dílo jeho účetní odmítne.
--
-- U rychlého prodeje zboží, kde proforma kryje celou objednávku a expeduje se
-- ihned, je naopak dnešní chování správné a pohodlnější (jeden doklad místo dvou).
-- Rozdíl je v obchodním modelu, ne v datech dokladu, takže ho nejde spolehlivě
-- odvodit — je z něj volba na firmě.
--
-- DEFAULT ZÁMĚRNĚ 'final_on_full_payment' = dnešní chování. Existujícím firmám se
-- tím nic nemění pod rukama; kdo potřebuje druhou variantu, přepne si ji.

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS proforma_payment_document
      ENUM('final_on_full_payment', 'always_tax_document')
      NOT NULL DEFAULT 'final_on_full_payment'
      COMMENT 'doklad po úhradě proformy: final_on_full_payment = doplacená proforma zakládá vyúčtovací fakturu (rychlý prodej), always_tax_document = vždy daňový doklad k přijaté platbě (zakázková výroba, § 20a)'
      AFTER payroll_enabled;
