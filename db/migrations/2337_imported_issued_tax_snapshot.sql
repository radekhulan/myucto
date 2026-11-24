ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS import_total_without_vat_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_total_vat_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_total_with_vat_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_amount_to_pay_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_advance_paid_czk DECIMAL(12,2) NULL;

ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS import_tax_base_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_tax_vat_czk DECIMAL(12,2) NULL;
