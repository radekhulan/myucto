<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use PDO;

/**
 * Víceúčelový poukaz a dobití kreditu (§ 37c ZDPH) na řádku vydaného dokladu. Jediné
 * místo, které rozhoduje, zda řádek takovým poukazem je.
 *
 * Poznává se podle druhu skladové karty `stored_value` (tentýž druh, podle kterého EET
 * určuje část tržby „určeno k čerpání", {@see \MyInvoice\Service\Eet\EetStoredValueCalculator}).
 * Převod víceúčelového poukazu není plněním, daní se až plnění, za které se poukazem
 * zaplatí. Z toho plyne:
 *
 *   - řádek nenese klasifikaci DPH a nevstupuje do přiznání ani do KH, ani výchozí
 *     kód podle země odběratele ({@see \MyInvoice\Repository\InvoiceRepository::replaceItems()}, evidence DPH
 *     {@see \MyInvoice\Service\Report\VatLedgerService}),
 *   - nesmí mít nenulovou sazbu ({@see TAXED_LINE_MESSAGE}); poukaz na konkrétní plnění
 *     zdaněný už při prodeji je jednoúčelový (§ 37b, druh karty `specific_voucher`),
 *   - účtuje se na přijaté zálohy, ne do výnosů
 *     ({@see \MyInvoice\Service\Accounting\PostingService::voucherAdvanceAccount()}).
 *
 * Systémový slevový ani textový řádek kartu nenese, takže poukazem nikdy není.
 *
 * Obranné vyřazení z evidence DPH a zálohové účtování platí jen pro řádek BEZ daně
 * ({@see isUntaxedLine()}): kdyby zdaněný řádek s kartou poukazu přece jen existoval
 * (data z doby před validací), jeho daň se z přiznání ztratit nesmí a výnosem zůstává.
 */
final class MultiPurposeVoucher
{
    public const STOCK_KIND = 'stored_value';

    public const TAXED_LINE_MESSAGE = 'Víceúčelový poukaz a dobití kreditu se při prodeji nedaní (§ 37c ZDPH), '
        . 'řádek musí mít sazbu 0 %. Poukaz na konkrétní plnění, který se daní už při prodeji, '
        . 'má na skladové kartě druh „jednoúčelový poukaz".';

    /** Je řádek prodejem víceúčelového poukazu? Řádek nese `eet_item_kind` své skladové karty. */
    public static function isLine(array $row): bool
    {
        return ($row['eet_item_kind'] ?? null) === self::STOCK_KIND
            && InvoiceItemKind::of($row) === InvoiceItemKind::STANDARD;
    }

    /**
     * Řádek poukazu bez daně: mimo evidenci DPH, účtuje se na přijaté zálohy.
     * Shodně se SQL podmínkou {@see untaxedLineSql()}.
     */
    public static function isUntaxedLine(array $row): bool
    {
        return self::isLine($row)
            && (int) round(abs((float) ($row['vat_rate_snapshot'] ?? 0)) * 100) === 0
            && (int) round(abs((float) ($row['total_vat'] ?? 0)) * 100) === 0;
    }

    /**
     * SQL podmínka {@see isUntaxedLine()} nad řádkem `$item` a jeho kartou `$stock`
     * (připojenou přes {@see stockJoinSql()}). Nikdy nevrací NULL, takže jde i negovat.
     */
    public static function untaxedLineSql(string $item, string $stock): string
    {
        return "(COALESCE({$stock}.eet_item_kind, '') = '" . self::STOCK_KIND . "'"
            . " AND ROUND(ABS(COALESCE({$item}.vat_rate_snapshot, 0)), 2) = 0"
            . " AND ROUND(ABS(COALESCE({$item}.total_vat, 0)), 2) = 0)";
    }

    /** LEFT JOIN karty řádku `$item`, omezený na firmu dokladu (`$supplierExpr`). */
    public static function stockJoinSql(string $item, string $stock, string $supplierExpr): string
    {
        return "LEFT JOIN stock_items {$stock} ON {$stock}.id = {$item}.stock_item_id AND {$stock}.supplier_id = {$supplierExpr}";
    }

    /**
     * Které z karet jsou kartami víceúčelového poukazu firmy.
     *
     * @param iterable<mixed> $stockItemIds
     * @return array<int,true>
     */
    public static function stockItemIds(PDO $pdo, int $supplierId, iterable $stockItemIds): array
    {
        $ids = [];
        foreach ($stockItemIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id FROM stock_items WHERE supplier_id = ? AND eet_item_kind = ? AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute([$supplierId, self::STOCK_KIND, ...array_keys($ids)]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Doplní řádkům `eet_item_kind` podle jejich karty, ať o nich jde rozhodnout přes
     * {@see isLine()}. Řádky bez karty poukazu nechá beze změny.
     *
     * @param array<int|string,mixed> $items
     * @return array<int|string,mixed>
     */
    public static function annotate(PDO $pdo, int $supplierId, array $items): array
    {
        $vouchers = self::stockItemIds($pdo, $supplierId, array_map(
            static fn (mixed $item): int => is_array($item) ? (int) ($item['stock_item_id'] ?? 0) : 0,
            $items,
        ));
        if ($vouchers === []) {
            return $items;
        }
        foreach ($items as $key => $item) {
            if (is_array($item) && isset($vouchers[(int) ($item['stock_item_id'] ?? 0)])) {
                $items[$key]['eet_item_kind'] = self::STOCK_KIND;
            }
        }
        return $items;
    }

    /**
     * Řádky bez víceúčelových poukazů, v původním pořadí. Pro odhad klasifikace hlavičky:
     * poukaz plněním není, takže o převažující sazbě dokladu rozhodovat nesmí.
     *
     * @param array<int|string,mixed> $items
     * @return list<mixed>
     */
    public static function withoutLines(PDO $pdo, int $supplierId, array $items): array
    {
        return array_values(array_filter(
            self::annotate($pdo, $supplierId, $items),
            static fn (mixed $item): bool => !is_array($item) || !self::isLine($item),
        ));
    }
}
