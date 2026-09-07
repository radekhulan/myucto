ALTER TABLE bank_payment_order_submissions
    MODIFY COLUMN status ENUM('accepted_awaiting_authorization','rejected','unknown','import_started') NOT NULL;
