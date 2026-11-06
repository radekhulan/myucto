<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Napojení bankovního důkazu na již zachycenou platební událost. Doplní jen stav
 * bankovního ověření a vazbu na pohyb; nevytváří druhou tržbu ani druhou událost.
 *
 * Jediné místo napojení je {@see \MyInvoice\Service\Invoice\InvoicePaymentService}
 * při zápisu bankovní úhrady (`source = bank` s pohybem): tudy vedou všechny cesty
 * párování (automatické, ruční, sloučené, návrhy, import iDokladu, oprava vazeb).
 * Účetní úhradu faktury tak zakládá bankovní párování svou běžnou cestou a tady se
 * jen přiváže k potvrzenému QR příkazu, aby událost přešla do
 * `accounting_settlement_status = settled`. Alokace s bankovním pohybem není
 * fiskálním zachycením platby, takže ochrana proti smazání úhrady s evidencí EET
 * se na ni nevztahuje; zrušení spárování událost vrátí do stavu před párováním
 * ({@see releaseInvoicePayment()}).
 */
final class PaymentEventReconciliation
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
    ) {}

    /**
     * Bankovní úhrada faktury právě vznikla: přiváže ji k nejstaršímu potvrzenému QR
     * příkazu téže faktury, který čeká na banku a jehož dosud neopravenou částku
     * úhrada pokryje ({@see PaymentEventService::openConfirmedIntents()}). Přebytek
     * úhrady zůstává běžnou úhradou faktury. Menší úhrada QR příkaz nevypořádá
     * (zůstává čekat) a zapíše se varování. Volá se v transakci zápisu úhrady
     * (faktura je zamčená). Vrací id události, nebo null.
     *
     * Firma bez nastavení EET QR příkazy mít nemůže: dotaz se zámkem nad
     * payment_events se u ní vůbec nespouští (gap lock by zbytečně zdržoval párování).
     */
    public function attachBankPayment(int $supplierId, int $invoiceId, int $invoicePaymentId, int $bankTransactionId, float $amount): ?int
    {
        $hasEet = $this->db->pdo()->prepare('SELECT 1 FROM eet_settings WHERE supplier_id = ?');
        $hasEet->execute([$supplierId]);
        if ($hasEet->fetchColumn() === false) {
            return null;
        }
        $cents = (int) round($amount * 100);
        $smaller = null;
        foreach ($this->events->openConfirmedIntents($supplierId, $invoiceId, true) as $intent) {
            if ($intent['blocked_cents'] <= 0) {
                continue;
            }
            if ($intent['blocked_cents'] > $cents) {
                $smaller ??= $intent;
                continue;
            }
            if ($this->attachBankTransaction($supplierId, $intent['id'], $bankTransactionId,
                EetValues::fromCents($intent['blocked_cents']), $invoicePaymentId)) {
                return $intent['id'];
            }
        }
        if ($smaller !== null) {
            error_log(sprintf(
                'EET: bankovní úhrada %s Kč faktury #%d je nižší než potvrzený QR příkaz (událost #%d, %s Kč); QR příkaz dál čeká na banku.',
                EetValues::fromCents($cents), $invoiceId, $smaller['id'], EetValues::fromCents($smaller['blocked_cents']),
            ));
        }
        return null;
    }

    /**
     * Volat v transakci párování. Vrací false, když událost neexistuje nebo nejde
     * o čekající bezhotovostní platbu (hotovost se bankou neověřuje). Zamýšlenou
     * alokaci z potvrzení QR příkazu doplní, jinak založí novou.
     */
    public function attachBankTransaction(int $supplierId, int $paymentEventId, int $bankTransactionId, string $amount, ?int $invoicePaymentId = null): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "UPDATE payment_events
                SET bank_verification_status = 'verified',
                    payment_order_status = IF(payment_order_status = 'operator_confirmed', 'settled', payment_order_status),
                    accounting_settlement_status = IF(? IS NULL, accounting_settlement_status, 'settled')
              WHERE id = ? AND supplier_id = ? AND bank_verification_status = 'pending'
                AND payment_order_status IN ('operator_confirmed', 'settled')"
        );
        $stmt->execute([$invoicePaymentId, $paymentEventId, $supplierId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $intended = $pdo->prepare(
            'SELECT id FROM payment_event_allocations
              WHERE supplier_id = ? AND payment_event_id = ?
                AND invoice_payment_id IS NULL AND bank_transaction_id IS NULL AND cash_document_id IS NULL
              ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $intended->execute([$supplierId, $paymentEventId]);
        $allocationId = $intended->fetchColumn();
        if ($allocationId !== false) {
            $pdo->prepare('UPDATE payment_event_allocations SET invoice_payment_id = ?, bank_transaction_id = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$invoicePaymentId, $bankTransactionId, (int) $allocationId, $supplierId]);
            return true;
        }
        $invoice = $pdo->prepare('SELECT invoice_id FROM payment_events WHERE id = ? AND supplier_id = ?');
        $invoice->execute([$paymentEventId, $supplierId]);
        $invoiceId = $invoice->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payment_event_allocations
               (supplier_id, payment_event_id, invoice_id, invoice_payment_id, bank_transaction_id, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $paymentEventId,
            $invoiceId !== false && $invoiceId !== null ? (int) $invoiceId : null,
            $invoicePaymentId, $bankTransactionId, EetValues::amount($amount),
        ]);
        return true;
    }

    /**
     * Mazaná bankovní úhrada (zrušení spárování): QR příkaz, ke kterému byla
     * přivázaná, se vrací do stavu „potvrzeno obsluhou, čeká na banku". Tržba zůstává,
     * zbývající dluh faktury ji dál odečítá. Volat v transakci mazání úhrady.
     */
    public function releaseInvoicePayment(int $supplierId, int $invoicePaymentId): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT a.id, a.payment_event_id
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id
              WHERE a.invoice_payment_id = ? AND a.supplier_id = ? AND a.bank_transaction_id IS NOT NULL
                AND e.origin = 'payment_intent'
              FOR UPDATE"
        );
        $stmt->execute([$invoicePaymentId, $supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pdo->prepare('UPDATE payment_event_allocations SET invoice_payment_id = NULL, bank_transaction_id = NULL WHERE id = ? AND supplier_id = ?')
                ->execute([(int) $row['id'], $supplierId]);
            $pdo->prepare(
                "UPDATE payment_events
                    SET payment_order_status = 'operator_confirmed', bank_verification_status = 'pending',
                        accounting_settlement_status = 'pending'
                  WHERE id = ? AND supplier_id = ?"
            )->execute([(int) $row['payment_event_id'], $supplierId]);
        }
    }
}
