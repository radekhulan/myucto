<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class StockItemSalesOrderLineExpander implements SalesOrderLineExpander
{
    public function __construct(private readonly Connection $db) {}

    public function expand(int $supplierId, array $line, string $currencyCode): array
    {
        $itemId = (int) ($line['stock_item_id'] ?? 0);
        if ($itemId < 1) {
            return [
                'product_snapshot' => ['kind' => 'service', 'currency_code' => $currencyCode],
                'component_snapshot' => [],
            ];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sku, name, unit, item_type, vat_rate_id, row_version, is_active, is_stocked
               FROM stock_items WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item === false || !(bool) $item['is_active']) {
            throw new SalesOrderException('stock_item_not_found', 'Skladová karta neexistuje nebo není aktivní.', 404);
        }

        $snapshot = [
            'kind' => 'stock_item',
            'id' => (int) $item['id'],
            'sku' => (string) $item['sku'],
            'name' => (string) $item['name'],
            'unit' => (string) $item['unit'],
            'item_type' => (string) $item['item_type'],
            'vat_rate_id' => $item['vat_rate_id'] !== null ? (int) $item['vat_rate_id'] : null,
            'row_version' => (int) $item['row_version'],
            'currency_code' => $currencyCode,
        ];

        return [
            'product_snapshot' => $snapshot,
            'component_snapshot' => (bool) $item['is_stocked'] ? [[
                'stock_item_id' => (int) $item['id'],
                'quantity' => self::quantity((string) ($line['quantity'] ?? '0')),
                'sku' => (string) $item['sku'],
                'name' => (string) $item['name'],
            ]] : [],
        ];
    }

    private static function quantity(string $value): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/D', $value)
            || bccomp($value, '0', 3) <= 0) {
            throw new SalesOrderException('quantity_invalid', 'Množství musí být kladné a mít nejvýše tři desetinná místa.');
        }

        return number_format((float) $value, 3, '.', '');
    }
}
