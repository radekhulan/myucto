<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Support\Sql\AdvanceDrawSql;
use PDO;

/**
 * Vazby vydané konečné faktury na zálohy (proformy) pro párování plateb, pokladnu
 * a navigaci mezi doklady. Čerpání zálohy nese vazba dokladu `parent_invoice_id`, nebo
 * u faktury čerpající víc záloh vyznačený odpočtový řádek ({@see AdvanceDrawSql}).
 * Všechny dotazy začínají omezením na firmu.
 */
final class AdvanceDrawLinks
{
    /**
     * Zálohy, které konečná faktura čerpá (vzestupně), jen vydané proformy firmy.
     *
     * @return list<int>
     */
    public static function advancesOfFinal(PDO $pdo, int $supplierId, int $finalId): array
    {
        $stmt = $pdo->prepare(
            "SELECT p.id
               FROM (SELECT f.parent_invoice_id AS advance_id FROM invoices f
                      WHERE f.id = ? AND f.supplier_id = ? AND f.invoice_type = 'invoice' AND f.parent_invoice_id IS NOT NULL
                     UNION
                     SELECT ii.advance_invoice_id FROM invoice_items ii
                       JOIN invoices f ON f.id = ii.invoice_id
                      WHERE ii.invoice_id = ? AND f.supplier_id = ? AND f.invoice_type = 'invoice'
                        AND ii.advance_invoice_id IS NOT NULL) a
               JOIN invoices p ON p.id = a.advance_id AND p.supplier_id = ? AND p.invoice_type = 'proforma'
              ORDER BY p.id"
        );
        $stmt->execute([$finalId, $supplierId, $finalId, $supplierId, $supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Čerpá konečná faktura zálohu `$advanceId` (vazbou dokladu nebo odpočtovým řádkem)? */
    public static function finalDrawsAdvance(PDO $pdo, int $supplierId, int $finalId, int $advanceId): bool
    {
        return in_array($advanceId, self::advancesOfFinal($pdo, $supplierId, $finalId), true);
    }

    /**
     * Z `$invoiceIds` ty, které čerpají zálohu jen vyznačeným odpočtovým řádkem (bez vazby
     * dokladu). Jeden dotaz pro celý seznam kandidátů.
     *
     * @param list<int> $invoiceIds
     * @return array<int,true>
     */
    public static function drawingByLines(PDO $pdo, array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $pdo->query(
            'SELECT DISTINCT invoice_id FROM invoice_items
              WHERE invoice_id IN (' . implode(',', $ids) . ') AND advance_invoice_id IS NOT NULL'
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    /**
     * První konečná faktura zálohy v daných stavech (vazba dokladu i odpočtový řádek).
     *
     * @param list<string> $statuses
     * @return array<string,mixed>|null řádek `invoices` s kódem měny `currency`
     */
    public static function firstFinalOf(PDO $pdo, int $supplierId, int $advanceId, array $statuses): ?array
    {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $pdo->prepare(
            'SELECT i.id, i.varsymbol, i.amount_to_pay, i.paid_total, i.exchange_rate, i.status, i.invoice_type,
                    i.parent_invoice_id, cur.code AS currency
               FROM (' . AdvanceDrawSql::finalIds('?', '?') . ") fx
               JOIN invoices i ON i.id = fx.id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.status IN ({$in})
              ORDER BY i.id LIMIT 1"
        );
        $stmt->execute([$supplierId, $advanceId, $advanceId, $supplierId, $supplierId, ...$statuses]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
