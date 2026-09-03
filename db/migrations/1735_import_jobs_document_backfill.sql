-- Doúčtování nezaúčtovaných dokladů jako background job.
--
-- Schopnost projít existující doklady a zaúčtovat je (DocumentBackfill) v aplikaci
-- byla, ale žila VÝHRADNĚ uvnitř průvodce aktivací účetnictví. Jakmile byla aktivace
-- hotová, nebylo odkud ji zavolat — a přitom je to přesně operace, kterou uživatel
-- potřebuje po každém importu historie. Hromadné zaúčtování ze seznamu má strop 500
-- dokladů na dávku a jede z výběru v UI, takže na tisíce dokladů nestačí.
--
-- Rozšiřuje ENUM import_jobs.source o nový typ. MODIFY je idempotentní.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill'
    ) NOT NULL;
