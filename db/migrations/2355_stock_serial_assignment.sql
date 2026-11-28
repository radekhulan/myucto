ALTER TABLE stock_documents MODIFY COLUMN origin
    ENUM('manual','invoice','credit_note','purchase_invoice','inventory','purchase_order','serial_assignment')
    NOT NULL DEFAULT 'manual';
