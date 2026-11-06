<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

/**
 * Ostatní pohledávky a závazky, kterým je bankovní pohyb přiřazen (nestornovaná
 * přiřazení z `other_item_allocations`). Řádek pohybu podle nich ukáže navázanou
 * položku místo „Bez dokladu" — `match_status` zůstává `unmatched`, protože ten
 * popisuje jen párování s fakturou.
 */
final class OtherItemPaymentMatchReader
{
    /**
     * @param list<int> $transactionIds
     * @return array<int,list<array{other_item_id:int,document_no:?string,side:string,partner_name:?string,amount:float}>>
     */
    public static function byTransactions(PDO $pdo, int $supplierId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT a.bank_transaction_id AS tx_id, a.other_item_id, a.amount + a.interest_amount + a.fee_amount AS amount,
                    oi.document_no, oi.side, COALESCE(NULLIF(oi.partner_name, ''), oi.title) AS partner_name
               FROM other_item_allocations a
               JOIN other_items oi ON oi.id = a.other_item_id AND oi.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.reversed_on IS NULL
                AND a.bank_transaction_id IN ($placeholders)
           ORDER BY a.id"
        );
        $stmt->execute(array_merge([$supplierId], $transactionIds));
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $matches[(int) $row['tx_id']][] = [
                'other_item_id' => (int) $row['other_item_id'],
                'document_no' => ($row['document_no'] ?? '') !== '' ? (string) $row['document_no'] : null,
                'side' => (string) $row['side'],
                'partner_name' => ($row['partner_name'] ?? '') !== '' ? (string) $row['partner_name'] : null,
                'amount' => (float) $row['amount'],
            ];
        }
        return $matches;
    }
}
