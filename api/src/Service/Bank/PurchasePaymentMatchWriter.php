<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

/**
 * Jediné místo, které zapisuje párování bankovního pohybu na PŘIJATÝ doklad do
 * `payment_matches`.
 *
 * Dvojice (pohyb, doklad) je jedna alokace. Druhý řádek pro tutéž dvojici by
 * {@see \MyInvoice\Support\Sql\PurchaseSettledExpr::settled()} sečetl a doklad by byl
 * „uhrazen" dvakrát — rozpadlo by se saldo i zaúčtování úhrady (součet alokací ≠
 * částka pohybu). Opakované párování tedy existující řádek aktualizuje.
 *
 * Unikátní index na dvojici záměrně nemáme (viz migrace 1090), takže tohle je
 * brána, přes kterou musí jít každý zápis.
 *
 * Přednost: ruční párování přebíjí automatické. Auto zápis ruční řádek nemění,
 * ruční zápis auto řádek převezme (stane se ručním).
 */
final class PurchasePaymentMatchWriter
{
    public static function record(
        PDO $pdo,
        int $supplierId,
        int $transactionId,
        int $purchaseInvoiceId,
        float $amount,
        string $matchType,
        ?int $confidence = null,
        ?int $userId = null,
    ): void {
        $existing = $pdo->prepare(
            'SELECT id, match_type FROM payment_matches
              WHERE supplier_id = ? AND bank_transaction_id = ? AND purchase_invoice_id = ?
                AND invoice_id IS NULL
              ORDER BY id LIMIT 1'
        );
        $existing->execute([$supplierId, $transactionId, $purchaseInvoiceId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $pdo->prepare(
                'INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence, matched_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId, $transactionId, $purchaseInvoiceId, $amount, $matchType,
                $matchType === 'manual' ? null : $confidence,
                $userId !== null && $userId > 0 ? $userId : null,
            ]);
            return;
        }

        if ($matchType === 'manual') {
            $pdo->prepare(
                "UPDATE payment_matches
                    SET amount = ?, match_type = 'manual', match_confidence = NULL, matched_by_user_id = ?
                  WHERE id = ?"
            )->execute([$amount, $userId !== null && $userId > 0 ? $userId : null, (int) $row['id']]);
            return;
        }

        if ((string) $row['match_type'] === 'auto') {
            $pdo->prepare(
                'UPDATE payment_matches SET amount = ?, match_confidence = ? WHERE id = ?'
            )->execute([$amount, $confidence, (int) $row['id']]);
        }
    }
}
