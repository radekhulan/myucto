SET NAMES utf8mb4;

ALTER TABLE cash_journal_notes
    MODIFY COLUMN source_type
    ENUM('cash','bank','invoice_payment','purchase_invoice','gopay','credit_note_offset') NOT NULL;
