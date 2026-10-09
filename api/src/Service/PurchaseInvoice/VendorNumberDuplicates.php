<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use PDO;

/**
 * Vědomě povolený duplikát čísla přijatého dokladu (issue #140).
 *
 * Platby jednoho platebního kalendáře (typicky energie) nesou stejného dodavatele,
 * stejné číslo dokladu a často i stejné datum vystavení. Unikátní index
 * `uq_pi_vendor_invoice` je proto rozšířený o pořadí `vendor_number_seq`: běžný doklad
 * má 0 a kontrola funguje přesně jako dřív. Pořadí > 0 dostane doklad jen tehdy,
 * když uživatel shodu výslovně potvrdí (`allow_duplicate_number`).
 */
final class VendorNumberDuplicates
{
    public static function allowed(array $body): bool
    {
        return filter_var($body['allow_duplicate_number'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Nejbližší volné pořadí pro dvojici číslo + datum vystavení u dodavatele, nebo 0,
     * když shoda neexistuje. Volat uvnitř transakce, řádky se zamykají.
     */
    public static function nextSeq(
        PDO $pdo,
        int $supplierId,
        int $vendorId,
        string $vendorInvoiceNumber,
        string $issueDate,
        ?int $excludeId = null,
    ): int {
        $stmt = $pdo->prepare(
            'SELECT MAX(vendor_number_seq)
               FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ?
                AND id <> ?
                FOR UPDATE'
        );
        $stmt->execute([$supplierId, $vendorId, trim($vendorInvoiceNumber), $issueDate, $excludeId ?? 0]);
        $max = $stmt->fetchColumn();

        return $max === null || $max === false ? 0 : (int) $max + 1;
    }
}
