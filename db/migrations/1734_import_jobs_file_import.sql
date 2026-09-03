-- Import nahraných souborů (Pohoda XML / ISDOC / ZIP) jako background job.
--
-- Dávka z jiného systému má běžně tisíce dokladů — synchronní POST /api/admin/import
-- na ni nestačí (request timeout uprostřed běhu nechá polovinu dokladů založenou,
-- ale nedoběhne dorovnání číselných řad ani přepočet statistik klientů, protože
-- obojí je až na konci importBundle()). Job běží ve workeru bez request lifecycle
-- a UI polluje stav stejně jako u iDokladu a Fakturoidu.
--
-- Rozšiřuje ENUM import_jobs.source o nový typ. MODIFY je idempotentní.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import'
    ) NOT NULL;
