<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class BankPaymentCandidateScope
{
    public static function sql(int $supplierId): string
    {
        if ($supplierId <= 0) throw new \InvalidArgumentException('Neplatná firma.');
        return "(" . BankNoticeConfirmationScope::activeSql('bt') . " AND bt.match_status = 'unmatched' AND bt.matched_invoice_id IS NULL
            AND NOT " . NonInvoiceBankTransactionScope::sql($supplierId, 'bt.id') . "
            AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
            AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
            AND NOT EXISTS (SELECT 1 FROM other_item_allocations oa WHERE oa.bank_transaction_id = bt.id)
            AND NOT EXISTS (SELECT 1 FROM tax_advance_schedules ta WHERE ta.matched_transaction_id = bt.id)
            AND NOT EXISTS (SELECT 1 FROM bank_transfer_matches tm WHERE tm.in_transaction_id = bt.id OR tm.out_transaction_id = bt.id)
            AND NOT EXISTS (SELECT 1 FROM gopay_clearings gc WHERE gc.bank_transaction_id = bt.id OR gc.payout_match_transaction_id = bt.id))";
    }
}
