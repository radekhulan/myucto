-- Hromadné akce nad položkami dokladů (agenda Položky dokladů).
--
-- Fulltextový index nad textem položky vydaných a přijatých faktur. Hledání textu
-- („místní poplatek") prochází nejdřív doklady firmy v období po složeném indexu
-- (supplier_id, datum) a text porovná na zúženém výběru; když tak projde příliš mnoho
-- řádků (řídký text v dlouhém období), dohledá položky přes tento index a firmu i období
-- ověří spojením s hlavičkou dokladu. Index drží slova od innodb_ft_min_token_size
-- (výchozí 3 znaky), kratší slova hledání porovná jen podřetězcem.
SET NAMES utf8mb4;

CREATE FULLTEXT INDEX IF NOT EXISTS ft_ii_description ON invoice_items (description);
CREATE FULLTEXT INDEX IF NOT EXISTS ft_pii_description ON purchase_invoice_items (description);

-- Kód `mimo` (mimo předmět DPH, migrace 1866) platí i pro vystavené doklady: položka
-- vydané faktury, která předmětem daně není (místní poplatek z pobytu, přefakturovaný
-- správní poplatek), jinak neměla jak zůstat mimo přiznání i KH. Bez vlastního kódu
-- převezme kód hlavičky a dostane se na ř. 1 nebo 2. Kód nemá řádek přiznání ani oddíl
-- KH, evidence DPH položku zná, ale do výkazů ji nepošle (stejně jako u přijatých).
UPDATE vat_classifications
   SET direction = 'both',
       label = 'Mimo předmět DPH – nevstupuje do přiznání ani do KH (např. místní poplatek, kurzový rozdíl k samovyměření)'
 WHERE supplier_id IS NULL AND code = 'mimo' AND direction = 'purchase';
