-- Filtry podkladů PF: tenantové indexy archivu a vazeb, bez kopírování stavu příloh.
CREATE INDEX IF NOT EXISTS idx_pi_supplier_pdf_issue
  ON purchase_invoices (supplier_id, pdf_path, issue_date);
CREATE INDEX IF NOT EXISTS idx_pi_supplier_source_issue
  ON purchase_invoices (supplier_id, source_format, source_path, issue_date);
CREATE INDEX IF NOT EXISTS idx_dl_supplier_entity_document
  ON document_links (supplier_id, entity_type, entity_id, document_id);
