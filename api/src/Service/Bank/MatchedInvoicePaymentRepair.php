<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;

final class MatchedInvoicePaymentRepair
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePaymentService $payments,
    ) {}

    public function repair(int $transactionId): bool
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $query = $pdo->prepare(
                "SELECT bt.*, bs.supplier_id, COALESCE(NULLIF(bt.currency, ''), bs.currency) AS payment_currency
                   FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE bt.id = ? AND bs.source IN " . BankStatementSource::sqlList() . "
                    AND bt.source = 'statement' AND bt.match_status = 'auto_exact' AND bt.amount > 0
                    AND bt.matched_invoice_id IS NOT NULL FOR UPDATE"
            );
            $query->execute([$transactionId]);
            $tx = $query->fetch(PDO::FETCH_ASSOC);
            $repaired = false;
            if ($tx !== false) {
                $query = $pdo->prepare(
                    "SELECT i.id, i.amount_to_pay, i.exchange_rate, cur.code AS currency
                       FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
                      WHERE i.id = ? AND i.supplier_id = ?
                        AND i.invoice_type IN ('invoice', 'proforma')
                        AND NOT EXISTS (SELECT 1 FROM invoices fin WHERE fin.parent_invoice_id = i.id
                            AND i.invoice_type = 'proforma' AND fin.invoice_type = 'invoice'
                            AND fin.status IN ('issued', 'sent', 'reminded', 'paid'))
                        AND i.status IN ('issued', 'sent', 'reminded') AND i.paid_total = 0
                        AND i.amount_to_pay > 0
                        AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = i.id)
                        AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = ?)
                        AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = ?)
                      FOR UPDATE"
                );
                $query->execute([$tx['matched_invoice_id'], $tx['supplier_id'], $transactionId, $transactionId]);
                $invoice = $query->fetch(PDO::FETCH_ASSOC);
                if ($invoice !== false) {
                    $amount = (float) $tx['amount'];
                    $due = (float) $invoice['amount_to_pay'];
                    $currency = strtoupper((string) $tx['payment_currency']);
                    $sameCurrency = $currency !== '' && $currency === strtoupper((string) $invoice['currency']);
                    $full = $sameCurrency
                        ? abs($amount - $due) <= InvoicePaymentService::TOLERANCE
                        : FxPaymentSettlement::isFullCzkSettlement($amount, $due, (string) $invoice['currency'], (float) $invoice['exchange_rate'], $currency);
                    if ($full) {
                        $this->payments->recordPayment((int) $invoice['id'], $sameCurrency ? min($amount, $due) : $due, (string) $tx['posted_at'], [
                            'source' => 'bank',
                            'bank_transaction_id' => $transactionId,
                            'variable_symbol' => $tx['variable_symbol'],
                            'bank_reference' => $tx['bank_ref'],
                        ]);
                        $repaired = true;
                    }
                }
            }
            if ($owns) $pdo->commit();
            return $repaired;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
