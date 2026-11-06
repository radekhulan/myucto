ALTER TABLE nonprofit_funding_revisions
    ADD INDEX IF NOT EXISTS ix_nfr_catalog_first (supplier_id, revision_no, id);
