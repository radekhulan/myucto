-- Bezpečné smazání GoPay XML včetně účetních zápisů, které modul sám vytvořil.

SET NAMES utf8mb4;

ALTER TABLE gopay_clearings
  ADD COLUMN IF NOT EXISTS bank_journal_entry_owned TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = bankovní zápis převodu vytvořil GoPay a smí jej při smazání clearingu odstranit'
      AFTER bank_journal_entry_id;

UPDATE gopay_clearings gc
JOIN journal_entries je ON je.id = gc.bank_journal_entry_id
   AND je.supplier_id = gc.supplier_id
   AND je.source_type = 'bank'
   AND je.source_id = gc.bank_transaction_id
   SET gc.bank_journal_entry_owned = 1
 WHERE gc.bank_journal_entry_owned = 0
   AND je.description = CONCAT('Přijetí vyúčtování GoPay ', gc.clearing_id);

UPDATE gopay_clearings gc
LEFT JOIN bank_transactions bt ON bt.id = gc.payout_match_transaction_id
   SET gc.payout_match_transaction_id = NULL
 WHERE gc.payout_match_transaction_id IS NOT NULL
   AND bt.id IS NULL;

ALTER TABLE gopay_clearings
  ADD FOREIGN KEY IF NOT EXISTS fk_gopay_clearing_payout_match (payout_match_transaction_id)
      REFERENCES bank_transactions(id) ON DELETE SET NULL;
