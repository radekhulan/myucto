-- MyÚčto.cz — tenantová integrita DMS vazeb a důkazních doručenek.

ALTER TABLE documents
  ADD UNIQUE KEY IF NOT EXISTS uq_documents_supplier_id (supplier_id, id);

ALTER TABLE document_links
  ADD COLUMN IF NOT EXISTS supplier_id BIGINT UNSIGNED NULL AFTER document_id;

UPDATE document_links link_row
JOIN documents document ON document.id = link_row.document_id
SET link_row.supplier_id = document.supplier_id
WHERE link_row.supplier_id IS NULL;

ALTER TABLE document_links
  MODIFY COLUMN supplier_id BIGINT UNSIGNED NOT NULL,
  ADD INDEX IF NOT EXISTS idx_document_links_supplier_document (supplier_id, document_id);

ALTER TABLE document_links
  DROP FOREIGN KEY IF EXISTS fk_dl_doc;
ALTER TABLE document_links
  DROP FOREIGN KEY IF EXISTS fk_document_links_document;
ALTER TABLE document_links
  ADD CONSTRAINT fk_document_links_document
    FOREIGN KEY (supplier_id, document_id)
    REFERENCES documents (supplier_id, id) ON DELETE CASCADE;

ALTER TABLE submission_outbox
  DROP FOREIGN KEY IF EXISTS fk_submission_outbox_receipt_document;
ALTER TABLE submission_outbox
  ADD CONSTRAINT fk_submission_outbox_receipt_document
    FOREIGN KEY (receipt_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT;
