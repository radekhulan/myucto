-- Značka importní dávky u vydaných faktur.
--
-- Zákazník migrující účetnictví si první dávku téměř nikdy nenahraje správně (chybně
-- zadaný export z původního systému, jiný rozsah období) a potřebuje ji zahodit a nahrát
-- znovu. Bez značky dávky nejde odlišit naimportovaný doklad od dokladu vystaveného
-- v aplikaci, takže hromadné smazání „toho, co jsem právě naimportoval" nemá o co opřít.
--
-- Přijatá strana takový sloupec má od migrace 0141 (hromadný AI import); tohle je jeho
-- protějšek na vydané straně, se stejným tvarem i stejným významem NULL = doklad
-- nevznikl importem.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS import_batch_id VARCHAR(32) NULL DEFAULT NULL;

ALTER TABLE invoices
    ADD INDEX IF NOT EXISTS idx_invoices_import_batch (supplier_id, import_batch_id);
