<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Datum pro číslování přijatého dokladu — jediné místo, které ví, z kterého data plyne
 * měsíc a rok interního čísla (a tedy období řady).
 *
 * Firma volí v `supplier.purchase_numbering_date_basis` ({@see BASES}): datum vystavení
 * (výchozí), datum přijetí (`received_at`) nebo DUZP (`tax_date`). Doklad bez zvoleného
 * data (prázdné nebo nulové datum ze starších dat) se čísluje podle data vystavení.
 *
 * Volá ho přidělení čísla novému dokladu ({@see \MyInvoice\Repository\PurchaseInvoiceRepository::ensureVarsymbol()}),
 * přečíslování ({@see PurchaseInvoiceRenumberService}), kontrola úplnosti nové řady
 * číselníku ({@see \MyInvoice\Service\NumberSeries\NativeSeriesCompleteness}) i ukázková data.
 */
final class PurchaseNumberingDate
{
    public const COLUMN = 'purchase_numbering_date_basis';

    /** Datum vystavení (výchozí), datum přijetí, DUZP. */
    public const BASES = ['issue', 'received', 'tax'];

    public const DEFAULT = 'issue';

    private const DATE_COLUMNS = ['issue' => 'issue_date', 'received' => 'received_at', 'tax' => 'tax_date'];

    public static function isValid(mixed $basis): bool
    {
        return is_string($basis) && in_array($basis, self::BASES, true);
    }

    /** Uložená volba firmy, bez ní (nebo před migrací 2336) datum vystavení. */
    public static function stored(Connection $db, int $supplierId): string
    {
        if (!$db->hasColumn('supplier', self::COLUMN)) {
            return self::DEFAULT;
        }
        $stmt = $db->pdo()->prepare('SELECT ' . self::COLUMN . ' FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $basis = $stmt->fetchColumn();
        return self::isValid($basis) ? (string) $basis : self::DEFAULT;
    }

    /** Sloupec `purchase_invoices` se zvoleným datem. */
    public static function column(string $basis): string
    {
        return self::DATE_COLUMNS[$basis] ?? self::DATE_COLUMNS[self::DEFAULT];
    }

    /**
     * Datum pro číslování dokladu (Y-m-d): zvolené datum, bez něj datum vystavení.
     *
     * @param array<string,mixed> $doc řádek purchase_invoices (issue_date, received_at, tax_date)
     */
    public static function of(array $doc, string $basis): string
    {
        return self::chosen($doc, $basis) ?? substr((string) ($doc['issue_date'] ?? ''), 0, 10);
    }

    /** @param array<string,mixed> $doc */
    public static function usesFallback(array $doc, string $basis): bool
    {
        return $basis !== 'issue' && self::chosen($doc, $basis) === null;
    }

    /**
     * Období čísla dokladu (YYYYMM), ze kterého šablona řady bere {YYYY}/{YY}/{MM}.
     *
     * @param array<string,mixed> $doc
     */
    public static function period(array $doc, string $basis): string
    {
        return str_replace('-', '', substr(self::of($doc, $basis), 0, 7));
    }

    /**
     * Totéž jako {@see of()} jako výraz SQL nad `purchase_invoices` (alias s tečkou, např. 'p.').
     * Ve WHERE jen tam, kde výběr už zúží index (firma, řada), sloupec je zabalený do CASE.
     */
    public static function sql(string $basis, string $alias = ''): string
    {
        if (!self::isValid($basis) || $basis === 'issue') {
            return $alias . 'issue_date';
        }
        $col = $alias . self::DATE_COLUMNS[$basis];
        return "CASE WHEN {$col} >= '1000-01-01' THEN {$col} ELSE {$alias}issue_date END";
    }

    /** @param array<string,mixed> $doc */
    private static function chosen(array $doc, string $basis): ?string
    {
        $value = substr((string) ($doc[self::column($basis)] ?? ''), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && $value >= '1000-01-01' ? $value : null;
    }
}
