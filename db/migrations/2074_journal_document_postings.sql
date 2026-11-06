-- Vlastnictví zápisů zaúčtování je oddělené od informativních vazeb „Souvisí“.
-- Jeden doklad smí mít více zápisů, jeden zápis smí účtovat jediný doklad.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_document_postings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    doc_type ENUM('invoice', 'purchase_invoice') NOT NULL,
    doc_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jdp_doc_entry (supplier_id, doc_type, doc_id, entry_id),
    UNIQUE KEY uq_jdp_entry (supplier_id, entry_id),
    CONSTRAINT fk_jdp_entry FOREIGN KEY (supplier_id, entry_id)
        REFERENCES journal_entries (supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jdp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doplnění starších převodů: pouze vazba vytvořená převodem, doložená mapou
-- téhož zdroje. Bankovní/pokladní úhrady ani otevírací zápisy nejsou předpis.
-- Nejednoznačné vazby zůstávají ke kontrole, nikdy se nevybere první doklad.
INSERT IGNORE INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id)
WITH imported AS (
    SELECT supplier_id, target_id, 'Převzato z Stereo NX' AS note FROM stereo_nx_import_map WHERE kind = 'accounting_journal'
    UNION
    SELECT supplier_id, target_id, 'Převzato z PREMIER' FROM premier_import_map WHERE kind = 'journal_entry'
    UNION
    SELECT supplier_id, target_id, 'Převzato z Money S3' FROM money_s3_import_map WHERE kind = 'journal_entry'
    UNION
    SELECT supplier_id, target_id, 'Převzato z Pohody' FROM pohoda_import_map WHERE kind = 'journal_entry'
    UNION
    SELECT supplier_id, target_id, 'Převzato z ABRA Flexi' FROM abra_flexi_import_map WHERE kind = 'ucetni-denik'
), candidates AS (
    SELECT DISTINCT dl.supplier_id, dl.doc_type, dl.doc_id, dl.entry_id
      FROM imported im
      JOIN journal_entry_document_links dl ON dl.supplier_id = im.supplier_id AND dl.entry_id = im.target_id AND dl.note = im.note
      JOIN journal_entries e ON e.supplier_id = dl.supplier_id AND e.id = dl.entry_id
 LEFT JOIN invoices i ON dl.doc_type = 'invoice' AND i.supplier_id = dl.supplier_id AND i.id = dl.doc_id
 LEFT JOIN purchase_invoices pi ON dl.doc_type = 'purchase_invoice' AND pi.supplier_id = dl.supplier_id AND pi.id = dl.doc_id
     WHERE dl.doc_type IN ('invoice', 'purchase_invoice')
       AND (i.id IS NOT NULL OR pi.id IS NOT NULL)
       AND ((e.source_type IN ('manual', dl.doc_type) AND e.source_id IS NULL)
            OR (e.source_type = dl.doc_type AND e.source_id = dl.doc_id))
), counted AS (
    SELECT candidates.*, COUNT(*) OVER (PARTITION BY supplier_id, entry_id) AS owners FROM candidates
)
SELECT supplier_id, doc_type, doc_id, entry_id FROM counted WHERE owners = 1;
