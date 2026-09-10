-- Externí čárový kód dokladu (nálepka z předchozího systému nebo podatelny).
--
-- Papírový doklad často nese čárový kód, který předchozí účetní systém uložil
-- k dokladu a sken se pojmenoval podle něj. Při převodu dokladů se kód převezme
-- sem a párování skenů ho pak použije jako JISTÝ klíč (sken ↔ doklad), dřív než
-- číslo dokladu nebo vytěžený obsah.
--
-- Index je per firma: kód je jednoznačný jen v rámci jedné agendy.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS external_barcode VARCHAR(64) NULL
        COMMENT 'čárový kód dokladu z předchozího systému (párování skenů)';

ALTER TABLE purchase_invoices
    ADD INDEX IF NOT EXISTS idx_pi_supplier_external_barcode (supplier_id, external_barcode);

ALTER TABLE cash_documents
    ADD COLUMN IF NOT EXISTS external_barcode VARCHAR(64) NULL
        COMMENT 'čárový kód dokladu z předchozího systému (párování skenů)';

ALTER TABLE cash_documents
    ADD INDEX IF NOT EXISTS idx_cashdoc_supplier_external_barcode (supplier_id, external_barcode);
