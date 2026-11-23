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
    /** Členské vazby čte ze stejného nativního okruhu jako reconciliation, bez vlastní alokační tabulky. */
    public static function membershipByTransactions(PDO $pdo, int $supplierId, array $transactionIds): array
    {
        if ($transactionIds === []) return [];
        $in = implode(',', array_fill(0, count($transactionIds), '?'));
        $q = $pdo->prepare("SELECT DISTINCT e.source_id tx_id,c.id claim_id,c.member_id,m.member_number,c.amount_cents,cc.title_key,
                p.pairing_id,e.id entry_id
            FROM journal_entries e
            JOIN journal_line_pairing_items p ON p.supplier_id=e.supplier_id AND p.entry_id=e.id
            JOIN journal_line_pairing_items ci ON ci.supplier_id=p.supplier_id AND ci.pairing_id=p.pairing_id
            JOIN nonprofit_membership_charge_operations o ON o.supplier_id=ci.supplier_id
                AND o.journal_entry_id=ci.entry_id AND o.operation='issue'
            JOIN journal_entry_lines cl ON cl.supplier_id=ci.supplier_id AND cl.entry_id=ci.entry_id
                AND cl.line_no=ci.line_no AND cl.account_id=ci.account_id AND cl.id=o.receivable_line_id
            JOIN nonprofit_membership_charge_claims c ON c.supplier_id=o.supplier_id AND c.operation_id=o.id AND c.kind='issue'
            JOIN nonprofit_membership_charge_cases cc ON cc.supplier_id=c.supplier_id AND cc.id=c.case_id
            JOIN nonprofit_members m ON m.supplier_id=c.supplier_id AND m.id=c.member_id
            WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id IN ($in) AND e.reversed_by IS NULL
            ORDER BY e.source_id,c.id");
        $q->execute([$supplierId, ...$transactionIds]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['tx_id']][] = [
            'claim_id' => (int) $r['claim_id'], 'member_id' => (int) $r['member_id'], 'member_number' => (string) $r['member_number'],
            'title_key' => $r['title_key'], 'amount_cents' => (string) $r['amount_cents'],
            'pairing_id' => (int) $r['pairing_id'], 'entry_id' => (int) $r['entry_id'],
        ];
        return $out;
    }

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
