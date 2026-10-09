<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;

final class LegacyBankPaymentReconciler
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePaymentService $payments,
    ) {}

    public function reconcileMatchedIncoming(int $supplierId, int $txId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT bt.id, bt.source, bt.amount, bt.currency AS tx_currency,
                    bt.variable_symbol, bt.bank_ref, bt.match_status, bt.matched_invoice_id,
                    bs.currency AS statement_currency,
                    i.id AS invoice_id, i.supplier_id, i.invoice_type, i.status,
                    i.amount_to_pay, i.paid_total,
                    cur.code AS invoice_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN invoices i ON i.id = bt.matched_invoice_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE bt.id = ? AND i.supplier_id = ?
              FOR UPDATE"
        );
        $stmt->execute([$txId, $supplierId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tx === false
            || (string) $tx['source'] !== 'statement'
            || (float) $tx['amount'] <= 0.0
            || !in_array((string) $tx['match_status'], ['auto_exact', 'auto_partial', 'manual'], true)
            || !in_array((string) $tx['invoice_type'], ['invoice', 'proforma'], true)
            || (string) $tx['status'] !== 'paid'
            || (float) $tx['amount_to_pay'] <= 0.0
            || abs((float) $tx['paid_total'] - (float) $tx['amount_to_pay']) > 0.05
        ) {
            return false;
        }

        $txCurrency = strtoupper(trim((string) ($tx['tx_currency'] ?: $tx['statement_currency'])));
        if ($txCurrency === '' || $txCurrency !== strtoupper((string) $tx['invoice_currency'])) {
            return false;
        }

        // Úhrady vyrovnané jinou cestou než bankou (zápočet dobropisu, zápočet proti účtu,
        // vzájemný zápočet, pokladna) se k pohybu nevážou a nerozhodují: zbytek faktury po
        // nich musí krýt právě jedna evidenční platba, kterou pohyb potvrzuje.
        $paymentStmt = $pdo->prepare(
            'SELECT p.id, p.source, p.bank_transaction_id, p.amount, p.currency,
                    ' . InvoicePaymentService::bankSettleableSql('p') . ' AS bank_settleable
               FROM invoice_payments p
              WHERE p.supplier_id = ? AND p.invoice_id = ?
              ORDER BY p.id
              FOR UPDATE'
        );
        $paymentStmt->execute([$supplierId, (int) $tx['invoice_id']]);
        $paymentRows = [];
        $settledElsewhere = 0.0;
        foreach ($paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ((int) $row['bank_settleable'] === 1) {
                $paymentRows[] = $row;
            } else {
                $settledElsewhere += (float) $row['amount'];
            }
        }
        if (count($paymentRows) !== 1) {
            return false;
        }
        $payment = $paymentRows[0];
        $paymentSource = (string) $payment['source'];
        if (!in_array($paymentSource, ['legacy', 'manual', 'mark_paid'], true)
            || $payment['bank_transaction_id'] !== null
            || (float) $payment['amount'] <= 0.0
            || abs((float) $payment['amount'] - (float) $tx['amount']) > 0.05
            || abs((float) $payment['amount'] + $settledElsewhere - (float) $tx['paid_total']) > 0.01
        ) {
            return false;
        }
        $paymentCurrency = strtoupper((string) $payment['currency']);
        if ($paymentCurrency !== $txCurrency) {
            if ($paymentSource !== 'legacy'
                || $txCurrency !== strtoupper((string) $tx['invoice_currency'])
            ) {
                return false;
            }
            $currencyFix = $pdo->prepare(
                'UPDATE invoice_payments SET currency = ? WHERE id = ? AND bank_transaction_id IS NULL AND source = "legacy"'
            );
            $currencyFix->execute([$txCurrency, (int) $payment['id']]);
            if ($currencyFix->rowCount() !== 1) {
                return false;
            }
        }

        $duplicateStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM bank_transactions other
              WHERE other.matched_invoice_id = ? AND other.source = 'statement'
                AND other.match_status IN ('auto_exact','auto_partial','manual')"
        );
        $duplicateStmt->execute([(int) $tx['invoice_id']]);
        if ((int) $duplicateStmt->fetchColumn() !== 1) {
            return false;
        }

        $allocatedStmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.bank_transaction_id = ?)
                  + (SELECT COUNT(*) FROM payment_matches pm WHERE pm.bank_transaction_id = ?)'
        );
        $allocatedStmt->execute([$txId, $txId]);
        if ((int) $allocatedStmt->fetchColumn() !== 0) {
            return false;
        }

        if ((string) $tx['invoice_type'] !== 'proforma') {
            $predpisStmt = $pdo->prepare(
                "SELECT 1 FROM journal_entries
                  WHERE supplier_id = ? AND source_type = 'invoice' AND source_id = ?
                    AND posted_at IS NOT NULL AND reversed_by IS NULL
                  LIMIT 1"
            );
            $predpisStmt->execute([$supplierId, (int) $tx['invoice_id']]);
            if ($predpisStmt->fetchColumn() === false) {
                return false;
            }
        }

        $linked = $this->payments->reconcileToBankTransaction((int) $tx['invoice_id'], $txId, [
            'variable_symbol' => $tx['variable_symbol'],
            'bank_reference'  => $tx['bank_ref'],
        ]);
        return (int) $linked['payment_id'] === (int) $payment['id'];
    }
}
