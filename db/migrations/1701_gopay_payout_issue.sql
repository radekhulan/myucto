-- MyÚčto.cz - stav párování čistého GoPay převodu na běžný účet.

SET NAMES utf8mb4;

ALTER TABLE gopay_clearings
  ADD COLUMN IF NOT EXISTS payout_issue_code VARCHAR(80) NULL AFTER bank_journal_entry_id,
  ADD COLUMN IF NOT EXISTS payout_issue_message VARCHAR(500) NULL AFTER payout_issue_code;
