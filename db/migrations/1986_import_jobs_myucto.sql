-- Nativní obnova MyÚčta používá společnou frontu a protokoly import_jobs.
ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import', 'pohoda_import',
        'premier_import', 'stereo_nx_import', 'money_s3_batch', 'abra_flexi_import', 'myucto_import'
    ) NOT NULL;
