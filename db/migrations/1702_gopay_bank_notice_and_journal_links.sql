-- GoPay predbezne sparovani aviza a zpetne doplneni vazeb ucetnich zapisu.

SET NAMES utf8mb4;

ALTER TABLE gopay_clearings
  ADD COLUMN IF NOT EXISTS payout_match_transaction_id BIGINT UNSIGNED NULL AFTER issue_count,
  ADD UNIQUE KEY IF NOT EXISTS uq_gopay_clearing_payout_match (payout_match_transaction_id);

UPDATE gopay_clearings
   SET payout_match_transaction_id = bank_transaction_id
 WHERE payout_match_transaction_id IS NULL
   AND bank_transaction_id IS NOT NULL;

UPDATE gopay_movements gm
JOIN invoice_payments ip
  ON ip.id = gm.invoice_payment_id
 AND ip.supplier_id = gm.supplier_id
   SET gm.invoice_id = ip.invoice_id
 WHERE gm.invoice_id IS NULL;

UPDATE gopay_movements gm
JOIN journal_entries je
  ON je.supplier_id = gm.supplier_id
 AND je.source_type = 'gopay'
 AND je.source_id = gm.id
 AND je.reversed_by IS NULL
   SET gm.journal_entry_id = je.id,
       gm.status = 'posted',
       gm.issue_code = NULL,
       gm.issue_message = NULL
 WHERE gm.journal_entry_id IS NULL;
