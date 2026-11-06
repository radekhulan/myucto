-- Import historických skladových dokladů z jiného skladového programu (příjemky, výdejky,
-- převodky, inventurní rozdíly s původním číslem a datem).
--
-- `stock_documents.history_import_job_id` označí převzatý doklad id aplikační úlohy importu
-- (`catalog_jobs`). Záměrně bez FK: archiv a obnova firmy (`ArchiveService`) úlohy
-- nepřenášejí a vrácení importu maže doklady podle tohoto sloupce, ne podle úlohy.
-- Převzaté doklady se vyřazují z Intrastatu a z uzávěrkového seznamu příjemek bez PF.
--
-- `supplier.stock_history_locked_until` je poslední den převzaté historie. Skladový doklad
-- s datem do tohoto dne nejde zaúčtovat ani stornovat: přecenil by převzatou historii
-- a rozbil vyrovnání se stavem, ze kterého firma v MyÚčtu pokračuje.
SET NAMES utf8mb4;

ALTER TABLE stock_documents
  ADD COLUMN IF NOT EXISTS history_import_job_id BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'úloha importu historie (catalog_jobs.id), NULL = doklad vzniklý v MyÚčtu',
  ADD INDEX IF NOT EXISTS idx_sd_history_import (supplier_id, history_import_job_id, doc_date);

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS stock_history_locked_until DATE NULL DEFAULT NULL
    COMMENT 'poslední den převzaté skladové historie; doklady do tohoto dne nejde zaúčtovat ani stornovat';

-- Ocenění převzaté historie čte karty úlohy po dávkách (supplier_id, job_id, stock_item_id > kurzor).
ALTER TABLE catalog_job_items
  ADD INDEX IF NOT EXISTS ix_catalog_job_items_item (supplier_id, job_id, stock_item_id);
