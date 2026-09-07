SET NAMES utf8mb4;

ALTER TABLE bank_payment_order_submissions
    MODIFY COLUMN payment_order_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS payroll_batch_id BIGINT UNSIGNED NULL AFTER payment_order_id,
    ADD UNIQUE KEY IF NOT EXISTS uq_bank_submission_payroll (supplier_id, payroll_batch_id),
    ADD FOREIGN KEY IF NOT EXISTS fk_bank_submission_payroll (supplier_id, payroll_batch_id)
        REFERENCES payroll_payment_batches (supplier_id, id) ON DELETE RESTRICT,
    DROP CONSTRAINT IF EXISTS chk_bank_submission_source,
    ADD CONSTRAINT chk_bank_submission_source
        CHECK ((payment_order_id IS NOT NULL) + (payroll_batch_id IS NOT NULL) = 1);
