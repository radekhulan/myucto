-- Backport MyInvoice 0151: volitelná poznámka k ignorování bankovního pohybu.
ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS ignore_note VARCHAR(1000) NULL;
