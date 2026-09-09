<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogReadRepository
{
    private const BASE_FIELDS = ['sku', 'name', 'item_type', 'unit', 'ean', 'manufacturer_id', 'vat_rate_id', 'is_active', 'is_stocked', 'export_eshop', 'min_qty', 'weight_g', 'warranty_months', 'delivery_days'];
    private const SATELLITES = [
        'i18n' => ['stock_item_i18n', 'locale, name, short_desc, description, seo_title, seo_description, seo_slug', 'locale'],
        'categories' => ['stock_item_categories', 'category_id, is_primary, display_order', 'display_order, category_id'],
        'tag_ids' => ['stock_item_tags', 'tag_id', 'tag_id'],
        'attributes' => ['stock_item_attribute_values', 'attribute_id, option_id, value_text, value_num, value_bool, display_order', 'attribute_id, display_order, id'],
        'fees' => ['stock_item_fees', 'fee_type_id, amount, currency_code, vat_included', 'id'],
        'media' => ['stock_media', 'id, media_type, original_name, mime_type, size_bytes, title, alt_text, display_order, is_primary, export_eshop', 'is_primary DESC, display_order, id'],
    ];

    public function __construct(private readonly Connection $db) {}

    public function products(int $supplierId, array $request): array
    {
        $ids = $request['ids'];
        $fields = $request['fields'];
        $columns = ['id', 'row_version', ...array_intersect(self::BASE_FIELDS, $fields)];
        $rows = $this->query('SELECT ' . implode(', ', $columns) . ' FROM stock_items WHERE supplier_id = ? AND id IN (' . self::placeholders($ids) . ')', [$supplierId, ...$ids]);
        $products = [];
        foreach ($rows as $row) {
            $products[(int) $row['id']] = self::cast($row);
        }
        if ($products === []) {
            return [];
        }
        $ownedIds = array_keys($products);
        foreach (self::SATELLITES as $field => [$table, $projection, $order]) {
            if (!in_array($field, $fields, true)) {
                continue;
            }
            foreach ($products as &$product) {
                $product[$field] = [];
            }
            unset($product);
            $where = '';
            $params = [$supplierId, ...$ownedIds];
            if ($field === 'i18n') {
                $where = ' AND locale IN (' . self::placeholders($request['locales']) . ')';
                array_push($params, ...$request['locales']);
            }
            if ($field === 'fees') {
                $where = ' AND currency_code IN (' . self::placeholders($request['currencies']) . ')';
                array_push($params, ...$request['currencies']);
            }
            foreach ($this->query('SELECT stock_item_id, ' . $projection . ' FROM ' . $table
                . ' WHERE supplier_id = ? AND stock_item_id IN (' . self::placeholders($ownedIds) . ')' . $where
                . ' ORDER BY stock_item_id, ' . $order, $params) as $row) {
                $id = (int) $row['stock_item_id'];
                unset($row['stock_item_id']);
                $row = self::cast($row);
                if ($field === 'media') {
                    $row['url'] = '/api/v1/eshop/media/' . $row['id'] . '/file';
                }
                $products[$id][$field][] = $field === 'tag_ids' ? $row['tag_id'] : $row;
            }
        }
        if (in_array('availability', $fields, true) || in_array('costs', $fields, true)) {
            $this->addLevels($supplierId, $products, $request);
        }
        return $products;
    }

    private function addLevels(int $supplierId, array &$products, array $request): void
    {
        $availability = in_array('availability', $request['fields'], true);
        $costs = in_array('costs', $request['fields'], true);
        foreach ($products as &$product) {
            if ($availability) {
                $product['availability'] = ['qty' => '0.000', 'warehouses' => []];
            }
            if ($costs) {
                $product['costs'] = ['value_total' => '0.00', 'warehouses' => []];
            }
        }
        unset($product);
        $columns = 'stock_item_id, warehouse_id, qty' . ($costs ? ', value_total, avg_unit_cost' : '');
        $params = [$supplierId, ...array_keys($products)];
        $where = '';
        if ($request['warehouse_ids'] !== []) {
            $where = ' AND warehouse_id IN (' . self::placeholders($request['warehouse_ids']) . ')';
            array_push($params, ...$request['warehouse_ids']);
        }
        foreach ($this->query('SELECT ' . $columns . ' FROM stock_levels WHERE supplier_id = ? AND stock_item_id IN ('
            . self::placeholders($products) . ')' . $where . ' ORDER BY stock_item_id, warehouse_id', $params) as $row) {
            $product = &$products[(int) $row['stock_item_id']];
            if ($availability) {
                $product['availability']['qty'] = bcadd($product['availability']['qty'], $row['qty'], 3);
                $product['availability']['warehouses'][] = ['warehouse_id' => (int) $row['warehouse_id'], 'qty' => $row['qty']];
            }
            if ($costs) {
                $product['costs']['value_total'] = bcadd($product['costs']['value_total'], $row['value_total'], 2);
                $product['costs']['warehouses'][] = ['warehouse_id' => (int) $row['warehouse_id'], 'value_total' => $row['value_total'], 'avg_unit_cost' => $row['avg_unit_cost']];
            }
            unset($product);
        }
    }

    private function query(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private static function cast(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (in_array($key, ['is_active', 'is_stocked', 'export_eshop', 'is_primary', 'value_bool', 'vat_included'], true)) {
                $row[$key] = (bool) $value;
            } elseif (in_array($key, ['id', 'row_version', 'manufacturer_id', 'vat_rate_id', 'category_id', 'tag_id', 'attribute_id', 'option_id', 'fee_type_id', 'display_order', 'size_bytes', 'warranty_months', 'delivery_days'], true)) {
                $row[$key] = (int) $value;
            }
        }
        return $row;
    }
}
