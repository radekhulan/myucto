-- Asistent nastavení účtování - složené tenantové vazby.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
  ADD UNIQUE KEY IF NOT EXISTS uq_import_jobs_supplier_id (supplier_id, id);
ALTER TABLE purchase_invoices
  ADD UNIQUE KEY IF NOT EXISTS uq_purchase_invoices_supplier_id (supplier_id, id);
ALTER TABLE accounting_setup_runs
  ADD UNIQUE KEY IF NOT EXISTS uq_accounting_setup_runs_supplier_id (supplier_id, id);
ALTER TABLE accounting_setup_rule_bundles
  ADD UNIQUE KEY IF NOT EXISTS uq_accounting_setup_bundles_supplier_id (supplier_id, id);

ALTER TABLE accounting_setup_runs DROP FOREIGN KEY IF EXISTS fk_accounting_setup_job_supplier;
ALTER TABLE accounting_setup_runs
  ADD CONSTRAINT fk_accounting_setup_job_supplier
  FOREIGN KEY (supplier_id, job_id) REFERENCES import_jobs (supplier_id, id) ON DELETE CASCADE;

ALTER TABLE accounting_setup_proposals DROP FOREIGN KEY IF EXISTS fk_accounting_setup_proposal_run_supplier;
ALTER TABLE accounting_setup_proposals
  ADD CONSTRAINT fk_accounting_setup_proposal_run_supplier
  FOREIGN KEY (supplier_id, run_id) REFERENCES accounting_setup_runs (supplier_id, id) ON DELETE CASCADE;

ALTER TABLE accounting_setup_rule_bundles DROP FOREIGN KEY IF EXISTS fk_accounting_setup_bundle_run_supplier;
ALTER TABLE accounting_setup_rule_bundles
  ADD CONSTRAINT fk_accounting_setup_bundle_run_supplier
  FOREIGN KEY (supplier_id, run_id) REFERENCES accounting_setup_runs (supplier_id, id) ON DELETE CASCADE;

ALTER TABLE accounting_reclassification_items DROP FOREIGN KEY IF EXISTS fk_accounting_reclass_job_supplier;
ALTER TABLE accounting_reclassification_items
  ADD CONSTRAINT fk_accounting_reclass_job_supplier
  FOREIGN KEY (supplier_id, job_id) REFERENCES import_jobs (supplier_id, id) ON DELETE CASCADE;

ALTER TABLE accounting_reclassification_items DROP FOREIGN KEY IF EXISTS fk_accounting_reclass_bundle_supplier;
ALTER TABLE accounting_reclassification_items
  ADD CONSTRAINT fk_accounting_reclass_bundle_supplier
  FOREIGN KEY (supplier_id, bundle_id) REFERENCES accounting_setup_rule_bundles (supplier_id, id);

ALTER TABLE accounting_reclassification_items DROP FOREIGN KEY IF EXISTS fk_accounting_reclass_purchase_supplier;
ALTER TABLE accounting_reclassification_items
  ADD CONSTRAINT fk_accounting_reclass_purchase_supplier
  FOREIGN KEY (supplier_id, purchase_invoice_id) REFERENCES purchase_invoices (supplier_id, id);

ALTER TABLE accounting_reclassification_items DROP FOREIGN KEY IF EXISTS fk_accounting_reclass_entry_supplier;
ALTER TABLE accounting_reclassification_items DROP FOREIGN KEY IF EXISTS fk_accounting_reclass_entry;
ALTER TABLE accounting_reclassification_items
  ADD CONSTRAINT fk_accounting_reclass_entry_supplier
  FOREIGN KEY (supplier_id, correction_entry_id) REFERENCES journal_entries (supplier_id, id);
