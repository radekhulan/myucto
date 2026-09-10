<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ProductMasterRepository
{
    public const I18N_FIELDS = ['name', 'short_desc', 'description', 'seo_title', 'seo_description'];

    public function __construct(private readonly Connection $db) {}

    public function list(int $supplierId, string $status, string $query, int $page, int $limit): array
    {
        $where = 'm.supplier_id = ?';
        $params = [$supplierId];
        if ($status !== 'all') {
            $where .= ' AND m.status = ?';
            $params[] = $status;
        }
        if ($query !== '') {
            $where .= ' AND m.name LIKE ?';
            $params[] = '%' . addcslashes($query, '%_\\') . '%';
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM product_masters m WHERE ' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT m.id, m.name, m.manufacturer_id, m.status, m.row_version,
            m.created_at, m.updated_at, COUNT(v.stock_item_id) AS variant_count
            FROM product_masters m LEFT JOIN product_variants v ON v.master_id = m.id AND v.supplier_id = m.supplier_id
            WHERE ' . $where . ' GROUP BY m.id ORDER BY m.name, m.id LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
        $stmt->execute($params);
        return [
            'items' => array_map(self::castMaster(...), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
        ];
    }

    public function find(int $supplierId, int $masterId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, name, manufacturer_id, status, row_version, created_at, updated_at
            FROM product_masters WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $masterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castMaster($row);
    }

    public function detail(int $supplierId, int $masterId): ?array
    {
        $master = $this->find($supplierId, $masterId);
        if ($master === null) {
            return null;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT locale, name, short_desc, description, seo_title, seo_description
            FROM product_master_i18n WHERE supplier_id = ? AND master_id = ? ORDER BY locale');
        $stmt->execute([$supplierId, $masterId]);
        $master['i18n'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT a.id AS attribute_id, a.code, a.name, x.display_order
            FROM product_master_axes x JOIN stock_attributes a ON a.id = x.attribute_id AND a.supplier_id = x.supplier_id
            WHERE x.supplier_id = ? AND x.master_id = ? ORDER BY x.display_order, a.id');
        $stmt->execute([$supplierId, $masterId]);
        $master['axes'] = array_map(static function (array $row): array {
            $row['attribute_id'] = (int) $row['attribute_id'];
            $row['display_order'] = (int) $row['display_order'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $pdo->prepare('SELECT v.stock_item_id, v.row_version AS link_row_version, v.inherit_manufacturer,
            HEX(v.option_signature) AS option_signature, s.sku, s.name AS own_name, s.manufacturer_id AS own_manufacturer_id, s.row_version
            FROM product_variants v JOIN stock_items s ON s.id = v.stock_item_id AND s.supplier_id = v.supplier_id
            WHERE v.supplier_id = ? AND v.master_id = ? ORDER BY s.sku, s.id');
        $stmt->execute([$supplierId, $masterId]);
        $variants = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int) $row['stock_item_id'];
            $variants[$itemId] = [
                'stock_item_id' => $itemId,
                'sku' => (string) $row['sku'],
                'own_name' => (string) $row['own_name'],
                'effective_name' => (string) $row['own_name'],
                'row_version' => (int) $row['row_version'],
                'link_row_version' => (int) $row['link_row_version'],
                'option_signature' => $row['option_signature'] === null ? null : strtolower((string) $row['option_signature']),
                'options' => [],
                'inheritance' => ['manufacturer' => (bool) $row['inherit_manufacturer'], 'i18n' => []],
                'effective' => ['manufacturer_id' => (bool) $row['inherit_manufacturer']
                    ? $master['manufacturer_id'] : ($row['own_manufacturer_id'] === null ? null : (int) $row['own_manufacturer_id']), 'i18n' => []],
            ];
        }
        if ($variants !== []) {
            $ids = array_keys($variants);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare('SELECT stock_item_id, attribute_id, option_id, display_order FROM product_variant_options
                WHERE supplier_id = ? AND master_id = ? AND stock_item_id IN (' . $ph . ') ORDER BY stock_item_id, display_order, attribute_id');
            $stmt->execute([$supplierId, $masterId, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $variants[(int) $row['stock_item_id']]['options'][] = [
                    'attribute_id' => (int) $row['attribute_id'], 'option_id' => (int) $row['option_id'],
                ];
            }
            $stmt = $pdo->prepare('SELECT stock_item_id, locale, inherit_name, inherit_short_desc, inherit_description,
                inherit_seo_title, inherit_seo_description FROM product_variant_i18n_inheritance
                WHERE supplier_id = ? AND master_id = ? AND stock_item_id IN (' . $ph . ') ORDER BY stock_item_id, locale');
            $stmt->execute([$supplierId, $masterId, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $flags = [];
                foreach (self::I18N_FIELDS as $field) {
                    $flags[$field] = (bool) $row['inherit_' . $field];
                }
                $variants[(int) $row['stock_item_id']]['inheritance']['i18n'][(string) $row['locale']] = $flags;
            }
            $masterI18n = [];
            foreach ($master['i18n'] as $row) {
                $masterI18n[$row['locale']] = $row;
            }
            $stmt = $pdo->prepare('SELECT stock_item_id, locale, name, short_desc, description, seo_title, seo_description
                FROM stock_item_i18n WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ') ORDER BY stock_item_id, locale');
            $stmt->execute([$supplierId, ...$ids]);
            $ownI18n = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ownI18n[(int) $row['stock_item_id']][$row['locale']] = $row;
            }
            foreach ($variants as $itemId => &$variant) {
                $locales = array_unique([...array_keys($masterI18n), ...array_keys($ownI18n[$itemId] ?? []), ...array_keys($variant['inheritance']['i18n'])]);
                foreach ($locales as $locale) {
                    $flags = $variant['inheritance']['i18n'][$locale] ?? array_fill_keys(self::I18N_FIELDS, true);
                    $effective = ['locale' => $locale];
                    foreach (self::I18N_FIELDS as $field) {
                        $effective[$field] = ($flags[$field] ?? true)
                            ? ($masterI18n[$locale][$field] ?? null)
                            : ($ownI18n[$itemId][$locale][$field] ?? null);
                    }
                    $variant['effective']['i18n'][] = $effective;
                    if ($locale === 'cs' && ($flags['name'] ?? true) && ($effective['name'] ?? '') !== '') {
                        $variant['effective_name'] = $effective['name'];
                    }
                }
            }
            unset($variant);
        }
        $master['variants'] = array_values($variants);
        return $master;
    }

    public function variantContext(int $supplierId, int $stockItemId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT v.master_id, v.stock_item_id, v.inherit_manufacturer,
            v.row_version AS link_row_version, m.manufacturer_id AS master_manufacturer_id, m.status AS master_status,
            m.row_version AS master_row_version
            FROM product_variants v JOIN product_masters m ON m.id = v.master_id AND m.supplier_id = v.supplier_id
            WHERE v.supplier_id = ? AND v.stock_item_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $stockItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['master_id', 'stock_item_id', 'link_row_version', 'master_row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['inherit_manufacturer'] = (bool) $row['inherit_manufacturer'];
        $row['master_manufacturer_id'] = $row['master_manufacturer_id'] === null ? null : (int) $row['master_manufacturer_id'];
        return $row;
    }

    public function effectiveManufacturerId(int $supplierId, int $stockItemId, ?int $ownManufacturerId): ?int
    {
        $context = $this->variantContext($supplierId, $stockItemId);
        return $context !== null && $context['inherit_manufacturer']
            ? $context['master_manufacturer_id'] : $ownManufacturerId;
    }

    private static function castMaster(array $row): array
    {
        foreach (['id', 'supplier_id', 'row_version', 'variant_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['manufacturer_id'] = $row['manufacturer_id'] === null ? null : (int) $row['manufacturer_id'];
        return $row;
    }
}
