<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;

/**
 * Evidovaná platba vydané faktury (`invoice_payments`) k úhradě z banky či pokladny
 * převedené z Money. Z ní čte uhrazenou část vydané faktury saldokonto, kontrola
 * úplnosti i uvolnění pohybu; samotné párování (`payment_matches`, vazba pokladního
 * dokladu) ji nenahradí.
 *
 * Součet plateb nepřesáhne částku faktury: větší z uhrazené částky podle Money
 * (`paid_total`, `Uhrazeno` - Money vede úhradu jen datem, celou) a částky k úhradě.
 * Fakturu, kterou Money neoznačilo jako uhrazenou (zaplacenou po částech, nebo jen
 * spárovanou bez data úhrady), tak platby jejích úhrad nesníží jen do nuly - stejně jako
 * při párování v bance v MyÚčtu. Uhrazená částka a stav faktury se pak dopočítají jako
 * po zaevidované platbě ({@see sync()}); uhrazenou podle Money převod nesnižuje.
 *
 * Částka je v měně faktury. Platby se zakládají běžným a zálohovým fakturám (úhrada
 * proformy je inkasem zálohy na 324, čerpá ji konečná faktura navázaná {@see AdvanceLinker});
 * dobropis ani daňový doklad k platbě se v MyÚčtu neplatí. Opakovaný převod platbu nezdvojí
 * (banka: pohyb + faktura, pokladna a zápočet dobropisu: poznámka s číslem dokladu + faktura).
 */
final class IssuedPaymentRecorder
{
    /** Poznámka platby, kterou založil převod (a kterou smí převod upravit). */
    private const NOTE_PREFIX = 'Převzato z Money S3, ';

    /** @var array<int,array{status:string,type:string,paid_total:float,to_pay:float,rate:?float,currency:string,recorded:float}|null> */
    private array $invoices = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * Měna a kurz faktury pro přepočet úhrady; null = faktura platbu nedostane.
     *
     * @return array{currency:string,rate:?float}|null
     */
    public function invoiceCurrency(int $supplierId, int $invoiceId): ?array
    {
        $row = $this->invoice($supplierId, $invoiceId);
        return $row === null ? null : ['currency' => $row['currency'], 'rate' => $row['rate']];
    }

    /**
     * Částka úhrady v měně faktury: shodná měna platby se bere přímo, jinak se koruny
     * přepočtou kurzem dokladu. Null, když přepočet nejde (cizí měna bez kurzu).
     */
    public function toInvoiceCurrency(int $supplierId, int $invoiceId, float $czk, ?string $movementCurrency = null, ?float $movementAmount = null): ?float
    {
        $currency = $this->invoiceCurrency($supplierId, $invoiceId);
        if ($currency === null) {
            return null;
        }
        if ($movementCurrency !== null && $movementAmount !== null && $movementCurrency === $currency['currency']) {
            return abs($movementAmount);
        }
        if ($currency['currency'] === 'CZK') {
            return abs($czk);
        }
        return $currency['rate'] !== null ? abs($czk) / $currency['rate'] : null;
    }

    /**
     * @param 'bank'|'cash'|'credit_note'|'settlement' $kind
     * @return int|null id založené platby
     */
    public function record(ImportContext $ctx, int $invoiceId, string $kind, ?int $txId, ?int $cashId, float $amount, string $date, string $paymentNo): ?int
    {
        $row = $this->invoice($ctx->supplierId, $invoiceId);
        if ($row === null) {
            return null;
        }
        $pdo = $this->db->pdo();
        $note = self::note($kind, $paymentNo);
        if ($kind === 'bank') {
            $dup = $pdo->prepare('SELECT 1 FROM invoice_payments WHERE bank_transaction_id = ? AND invoice_id = ?');
            $dup->execute([$txId, $invoiceId]);
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM invoice_payments WHERE invoice_id = ? AND source = ? AND note = ?');
            $dup->execute([$invoiceId, $kind, $note]);
        }
        if ($dup->fetchColumn() !== false) {
            return null;
        }
        $amount = round(min(abs($amount), self::limit($row) - $row['recorded']), 2);
        if ($amount < 0.01) {
            return null;
        }
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, bank_transaction_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ctx->supplierId, $invoiceId, substr($date, 0, 10), number_format($amount, 2, '.', ''), $row['currency'],
            $note, $kind, $kind === 'bank' ? $txId : null, $ctx->userId > 0 ? $ctx->userId : null,
        ]);
        $paymentId = (int) $pdo->lastInsertId();
        if ($kind === 'cash') {
            $pdo->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ? AND invoice_id = ? AND invoice_payment_id IS NULL')
                ->execute([$paymentId, $cashId, $ctx->supplierId, $invoiceId]);
        }
        $this->invoices[$invoiceId]['recorded'] = $row['recorded'] + $amount;
        $this->sync($ctx->supplierId, $invoiceId);
        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'issued_payments_recorded');
        return $paymentId;
    }

    /**
     * Bankovní úhrada faktury po srovnání párování s deníkem Money: platbu, kterou k pohybu
     * založil převod, nastaví na novou částku (nejvýš do částky faktury), chybějící založí.
     * Platbu zadanou v MyÚčtu nemění.
     */
    public function setBank(ImportContext $ctx, int $invoiceId, int $txId, float $amount, string $date, string $paymentNo): void
    {
        $row = $this->invoice($ctx->supplierId, $invoiceId);
        if ($row === null) {
            return;
        }
        $pdo = $this->db->pdo();
        $current = $pdo->prepare("SELECT id, amount, note FROM invoice_payments WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id = ? AND source = 'bank'");
        $current->execute([$ctx->supplierId, $txId, $invoiceId]);
        $payments = $current->fetchAll(PDO::FETCH_ASSOC);
        if ($payments === []) {
            $this->record($ctx, $invoiceId, 'bank', $txId, null, $amount, $date, $paymentNo);
            return;
        }
        if (count($payments) !== 1 || !str_starts_with((string) $payments[0]['note'], self::NOTE_PREFIX)) {
            return;
        }
        $old = (float) $payments[0]['amount'];
        $new = round(min(abs($amount), self::limit($row) - $row['recorded'] + $old), 2);
        if ($new < 0.01 || abs($new - $old) < 0.005) {
            return;
        }
        $pdo->prepare('UPDATE invoice_payments SET amount = ? WHERE id = ? AND supplier_id = ?')
            ->execute([number_format($new, 2, '.', ''), (int) $payments[0]['id'], $ctx->supplierId]);
        $this->invoices[$invoiceId]['recorded'] = $row['recorded'] - $old + $new;
        $this->sync($ctx->supplierId, $invoiceId);
        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'issued_payments_adjusted');
    }

    /**
     * Smaže evidovanou bankovní úhradu faktury z pohybu, kterou zapsal převod (poznámka
     * převodu). Úhradu zadanou v MyÚčtu nemění. Uhrazenou částku převzatou z Money nesnižuje.
     */
    public function releaseBank(ImportContext $ctx, int $invoiceId, int $txId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND bank_transaction_id = ? AND source = 'bank' AND note LIKE ?"
        );
        $stmt->execute([$ctx->supplierId, $invoiceId, $txId, self::NOTE_PREFIX . '%']);
        if ($stmt->rowCount() > 0) {
            unset($this->invoices[$invoiceId]);
            $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'issued_payments_released', $stmt->rowCount());
        }
    }

    private static function note(string $kind, string $paymentNo): string
    {
        return self::NOTE_PREFIX . match ($kind) {
            'credit_note' => 'zápočet dobropisu ',
            'settlement' => 'zápočet interním dokladem ',
            default => 'úhrada ',
        } . $paymentNo;
    }

    /** @param array{paid_total:float,to_pay:float} $row */
    private static function limit(array $row): float
    {
        return max($row['paid_total'], $row['to_pay']);
    }

    /**
     * Uhrazená částka a stav faktury po změně plateb, stejně jako po zaevidované platbě
     * v MyÚčtu ({@see InvoicePaymentService::recordPayment()}): uhrazená částka je součet
     * plateb, faktura, kterou platby pokryjí, je uhrazená ke dni poslední platby. Uhrazenou
     * částku a stav, které převod převzal z Money, nesnižuje.
     */
    private function sync(int $supplierId, int $invoiceId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE invoices i
                SET i.paid_total = GREATEST(i.paid_total, (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = i.id))
              WHERE i.id = ? AND i.supplier_id = ?'
        )->execute([$invoiceId, $supplierId]);
        $pdo->prepare(
            "UPDATE invoices i
                SET i.status = 'paid', i.paid_at = COALESCE(i.paid_at, (SELECT MAX(p.paid_on) FROM invoice_payments p WHERE p.invoice_id = i.id))
              WHERE i.id = ? AND i.supplier_id = ? AND i.status IN ('issued', 'sent', 'reminded')
                AND i.amount_to_pay > 0 AND i.paid_total >= i.amount_to_pay - ?"
        )->execute([$invoiceId, $supplierId, InvoicePaymentService::TOLERANCE]);
        $paid = $pdo->prepare('SELECT paid_total, status FROM invoices WHERE id = ?');
        $paid->execute([$invoiceId]);
        $r = $paid->fetch(PDO::FETCH_ASSOC);
        if ($r !== false && isset($this->invoices[$invoiceId])) {
            $this->invoices[$invoiceId]['paid_total'] = (float) $r['paid_total'];
            $this->invoices[$invoiceId]['status'] = (string) $r['status'];
        }
    }

    /** @return array{status:string,type:string,paid_total:float,to_pay:float,rate:?float,currency:string,recorded:float}|null */
    private function invoice(int $supplierId, int $invoiceId): ?array
    {
        if (!array_key_exists($invoiceId, $this->invoices)) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT i.status, i.invoice_type, i.paid_total, i.amount_to_pay, i.exchange_rate, cur.code AS currency,
                        (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS recorded
                   FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.id = ? AND i.supplier_id = ?"
            );
            $stmt->execute([$invoiceId, $supplierId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->invoices[$invoiceId] = $r === false ? null : [
                'status' => (string) $r['status'],
                'type' => (string) $r['invoice_type'],
                'paid_total' => (float) $r['paid_total'],
                'to_pay' => (float) $r['amount_to_pay'],
                'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                'currency' => (string) $r['currency'],
                'recorded' => (float) $r['recorded'],
            ];
        }
        $row = $this->invoices[$invoiceId];
        if ($row === null || !in_array($row['type'], ['invoice', 'proforma'], true) || in_array($row['status'], ['draft', 'cancelled'], true)) {
            return null;
        }
        return $row;
    }
}
