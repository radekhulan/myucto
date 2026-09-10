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
        $internalManufacturer = in_array('effective', $fields, true) && !in_array('manufacturer_id', $fields, true);
        $columns = ['id', 'row_version', ...array_intersect(self::BASE_FIELDS, $fields)];
        if ($internalManufacturer) {
            $columns[] = 'manufacturer_id';
        }
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
        if (array_intersect(['master', 'variant', 'effective'], $fields) !== []) {
            $this->addMasterProjection($supplierId, $products, $request);
        }
        if (in_array('relations', $fields, true)) {
            $this->addRelations($supplierId, $products);
        }
        if (in_array('availability', $fields, true) || in_array('costs', $fields, true)) {
            $this->addLevels($supplierId, $products, $request);
        }
        if ($internalManufacturer) {
            foreach ($products as &$product) {
                unset($product['manufacturer_id']);
            }
            unset($product);
        }
        return $products;
    }

    private function addMasterProjection(int $supplierId, array &$products, array $request): void
    {
        $fields = $request['fields'];
        foreach ($products as &$product) {
            if (in_array('master', $fields, true)) {
                $product['master'] = null;
            }
            if (in_array('variant', $fields, true)) {
                $product['variant'] = null;
            }
            if (in_array('effective', $fields, true)) {
                $product['effective'] = ['manufacturer_id' => $product['manufacturer_id'] ?? null, 'i18n' => []];
            }
        }
        unset($product);
        $ids = array_keys($products);
        $ph = self::placeholders($ids);
        $rows = $this->query('SELECT v.stock_item_id, v.master_id, v.row_version AS link_row_version,
            v.inherit_manufacturer, HEX(v.option_signature) AS option_signature,
            m.name AS master_name, m.status AS master_status, m.row_version AS master_row_version, m.manufacturer_id AS master_manufacturer_id
            FROM product_variants v JOIN product_masters m ON m.id = v.master_id AND m.supplier_id = v.supplier_id
            WHERE v.supplier_id = ? AND v.stock_item_id IN (' . $ph . ')', [$supplierId, ...$ids]);
        $masterIds = [];
        $linked = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['stock_item_id'];
            $linked[$itemId] = true;
            $masterId = (int) $row['master_id'];
            $masterIds[$masterId] = $masterId;
            if (in_array('master', $fields, true)) {
                $products[$itemId]['master'] = ['id' => $masterId, 'name' => $row['master_name'],
                    'status' => $row['master_status'], 'row_version' => (int) $row['master_row_version']];
            }
            if (in_array('variant', $fields, true)) {
                $products[$itemId]['variant'] = ['master_id' => $masterId, 'link_row_version' => (int) $row['link_row_version'],
                    'option_signature' => strtolower((string) $row['option_signature']), 'options' => [],
                    'inheritance' => ['manufacturer' => (bool) $row['inherit_manufacturer'], 'i18n' => []]];
            }
            if (in_array('effective', $fields, true) && (bool) $row['inherit_manufacturer']) {
                $products[$itemId]['effective']['manufacturer_id'] = $row['master_manufacturer_id'] === null ? null : (int) $row['master_manufacturer_id'];
            }
        }
        if (in_array('variant', $fields, true)) {
            foreach ($this->query('SELECT stock_item_id, attribute_id, option_id FROM product_variant_options
                WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ') ORDER BY stock_item_id, display_order, attribute_id', [$supplierId, ...$ids]) as $row) {
                if ($products[(int) $row['stock_item_id']]['variant'] !== null) {
                    $products[(int) $row['stock_item_id']]['variant']['options'][] = [
                        'attribute_id' => (int) $row['attribute_id'], 'option_id' => (int) $row['option_id'],
                    ];
                }
            }
        }
        if (!in_array('effective', $fields, true) && !in_array('variant', $fields, true)) {
            return;
        }
        $inheritance = [];
        foreach ($this->query('SELECT stock_item_id, locale, inherit_name, inherit_short_desc, inherit_description,
            inherit_seo_title, inherit_seo_description FROM product_variant_i18n_inheritance
            WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ')', [$supplierId, ...$ids]) as $row) {
            $flags = [];
            foreach (['name', 'short_desc', 'description', 'seo_title', 'seo_description'] as $field) {
                $flags[$field] = (bool) $row['inherit_' . $field];
            }
            $inheritance[(int) $row['stock_item_id']][$row['locale']] = $flags;
            if (in_array('variant', $fields, true) && $products[(int) $row['stock_item_id']]['variant'] !== null) {
                $products[(int) $row['stock_item_id']]['variant']['inheritance']['i18n'][$row['locale']] = $flags;
            }
        }
        if (!in_array('effective', $fields, true)) {
            return;
        }
        $own = [];
        foreach ($this->query('SELECT stock_item_id, locale, name, short_desc, description, seo_title, seo_description
            FROM stock_item_i18n WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ')
            AND locale IN (' . self::placeholders($request['locales']) . ')', [$supplierId, ...$ids, ...$request['locales']]) as $row) {
            $own[(int) $row['stock_item_id']][$row['locale']] = $row;
        }
        $masterI18n = [];
        if ($masterIds !== []) {
            foreach ($this->query('SELECT master_id, locale, name, short_desc, description, seo_title, seo_description
                FROM product_master_i18n WHERE supplier_id = ? AND master_id IN (' . self::placeholders($masterIds) . ')
                AND locale IN (' . self::placeholders($request['locales']) . ')', [$supplierId, ...array_values($masterIds), ...$request['locales']]) as $row) {
                $masterI18n[(int) $row['master_id']][$row['locale']] = $row;
            }
        }
        foreach ($rows as $variant) {
            $itemId = (int) $variant['stock_item_id'];
            $masterId = (int) $variant['master_id'];
            foreach ($request['locales'] as $locale) {
                $flags = $inheritance[$itemId][$locale] ?? array_fill_keys(['name', 'short_desc', 'description', 'seo_title', 'seo_description'], true);
                $effective = ['locale' => $locale];
                foreach (['name', 'short_desc', 'description', 'seo_title', 'seo_description'] as $field) {
                    $effective[$field] = ($flags[$field] ?? true)
                        ? ($masterI18n[$masterId][$locale][$field] ?? null)
                        : ($own[$itemId][$locale][$field] ?? null);
                }
                $products[$itemId]['effective']['i18n'][] = $effective;
            }
        }
        foreach ($products as $itemId => &$product) {
            if (isset($linked[$itemId]) || !isset($own[$itemId])) {
                continue;
            }
            foreach ($request['locales'] as $locale) {
                if (isset($own[$itemId][$locale])) {
                    $row = $own[$itemId][$locale];
                    unset($row['stock_item_id']);
                    $product['effective']['i18n'][] = $row;
                }
            }
        }
        unset($product);
    }

    private function addRelations(int $supplierId, array &$products): void
    {
        foreach ($products as &$product) {
            $product['relations'] = [];
        }
        unset($product);
        $ids = array_keys($products);
        foreach ($this->query('SELECT r.source_stock_item_id, r.target_stock_item_id, r.relation_type AS type,
            r.display_order, target.sku AS target_sku, target.name AS target_name
            FROM stock_item_relations r JOIN stock_items target ON target.id = r.target_stock_item_id AND target.supplier_id = r.supplier_id
            WHERE r.supplier_id = ? AND r.source_stock_item_id IN (' . self::placeholders($ids) . ')
            ORDER BY r.source_stock_item_id, FIELD(r.relation_type, \'accessory\', \'replacement\', \'related\'), r.display_order, r.id', [$supplierId, ...$ids]) as $row) {
            $sourceId = (int) $row['source_stock_item_id'];
            unset($row['source_stock_item_id']);
            $row['target_stock_item_id'] = (int) $row['target_stock_item_id'];
            $row['display_order'] = (int) $row['display_order'];
            $products[$sourceId]['relations'][] = $row;
        }
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
        $columns = 'l.stock_item_id, l.warehouse_id, l.qty, w.is_active, w.is_sellable' . ($costs ? ', l.value_total, l.avg_unit_cost' : '');
        $params = [$supplierId, ...array_keys($products)];
        $where = '';
        if ($request['warehouse_ids'] !== []) {
            $where = ' AND warehouse_id IN (' . self::placeholders($request['warehouse_ids']) . ')';
            array_push($params, ...$request['warehouse_ids']);
        }
        foreach ($this->query('SELECT ' . $columns . ' FROM stock_levels l JOIN warehouses w ON w.id = l.warehouse_id AND w.supplier_id = l.supplier_id WHERE l.supplier_id = ? AND stock_item_id IN ('
            . self::placeholders($products) . ')' . $where . ' ORDER BY stock_item_id, warehouse_id', $params) as $row) {
            $product = &$products[(int) $row['stock_item_id']];
            if ($availability && (bool) $row['is_active'] && (bool) $row['is_sellable']) {
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
