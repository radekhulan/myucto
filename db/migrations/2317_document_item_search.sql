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

