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

    /**
     * Doklady, se kterými se číslo a datum vystavení u dodavatele shodují — pro hlášku
     * o duplicitě (je-li shoda jen se stornovaným dokladem, nabízí se i vrácení ze storna).
     *
     * @return list<array{id:int, status:string, varsymbol:?string}>
     */
    public static function existing(
        PDO $pdo,
        int $supplierId,
        int $vendorId,
        string $vendorInvoiceNumber,
        string $issueDate,
        ?int $excludeId = null,
    ): array {
        $stmt = $pdo->prepare(
            'SELECT id, status, varsymbol
               FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ?
                AND id <> ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $vendorId, trim($vendorInvoiceNumber), $issueDate, $excludeId ?? 0]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'varsymbol' => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Doplňující údaje k chybě vendor_invoice_duplicate: stornovaný protějšek, je-li
     * jediný, a text pro uživatele (stornovaný doklad lze vrátit ze storna, nebo uložit
     * nový doklad přesto).
     *
     * @param list<array{id:int, status:string, varsymbol:?string}> $existing
     * @return array{message: ?string, details: array<string,mixed>}
     */
    public static function describe(array $existing, ?string $vendorInvoiceNumber): array
    {
        $active = array_values(array_filter($existing, static fn (array $r): bool => $r['status'] !== 'cancelled'));
        if ($existing === [] || $active !== []) {
            return ['message' => null, 'details' => ['duplicate_ids' => array_column($existing, 'id')]];
        }
        $cancelled = $existing[count($existing) - 1];
        $num = trim((string) ($vendorInvoiceNumber ?? ''));
        $label = $cancelled['varsymbol'] !== null && $cancelled['varsymbol'] !== '' ? $cancelled['varsymbol'] : '#' . $cancelled['id'];

        return [
            'message' => ($num !== '' ? "Přijatá faktura číslo '{$num}' od tohoto dodavatele s tímto datem vystavení" : 'Přijatá faktura s tímto číslem a datem vystavení')
                . " už existuje, ale je stornovaná ({$label}). Můžete ji vrátit ze storna, nebo uložit nový doklad přesto.",
            'details' => [
                'duplicate_ids' => array_column($existing, 'id'),
                'cancelled_duplicate_id' => $cancelled['id'],
                'cancelled_duplicate_number' => $label,
            ],
        ];
    }
}
