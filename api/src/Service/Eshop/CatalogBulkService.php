<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\StockItemCategoryRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemTagRepository;
use PDO;

final class CatalogBulkService
{
    public const PREVIEW_KIND = 'catalog_bulk_preview';
    public const APPLY_KIND = 'catalog_bulk_apply';
    public const RESTORE_KIND = 'catalog_bulk_restore';

    private const CHANGE_FIELDS = [
        'manufacturer_id', 'category_ids', 'tag_ids', 'is_active', 'export_eshop', 'min_qty',
    ];

    private const COMPARED_FIELDS = [
        'manufacturer_id', 'categories', 'tag_ids', 'is_active', 'export_eshop', 'min_qty',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogSelectionService $selection,
        private readonly CatalogJobItemRepository $jobItems,
        private readonly StockItemRepository $stockItems,
        private readonly StockItemCategoryRepository $itemCategories,
        private readonly StockItemTagRepository $itemTags,
        private readonly ProductCardService $cards,
        private readonly \MyInvoice\Service\Eshop\Pricing\PriceCalculationService $pricing,
    ) {}

    public function preview(int $supplierId, array $selection, array $changes, ?int $createdBy = null): array
    {
        $changes = $this->normalizeChanges($supplierId, $changes);
        $id = $this->selection->enqueue($supplierId, self::PREVIEW_KIND, ['changes' => $changes], $selection, $createdBy);
        return $this->jobs->find($supplierId, $id) ?? [];
    }

    public function apply(int $supplierId, int $previewId, ?int $createdBy = null): array
    {
        return $this->derive($supplierId, $previewId, self::PREVIEW_KIND, self::APPLY_KIND, 'ready', $createdBy);
    }

    public function restore(int $supplierId, int $applyId, ?int $createdBy = null): array
    {
        return $this->derive($supplierId, $applyId, self::APPLY_KIND, self::RESTORE_KIND, 'applied', $createdBy);
    }

    public function normalizeChanges(int $supplierId, array $changes): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::CHANGE_FIELDS) !== []) {
            throw new \InvalidArgumentException('Změny obsahují neznámé pole nebo jsou prázdné.');
        }
        $result = [];
        if (array_key_exists('manufacturer_id', $changes)) {
            $id = $changes['manufacturer_id'];
            if ($id !== null && (!is_int($id) || $id < 1)) {
                throw new \InvalidArgumentException('manufacturer_id musí být kladné celé číslo nebo null.');
            }
            if ($id !== null && !$this->stockItems->manufacturerOwned($supplierId, $id)) {
                throw new EshopException('manufacturer_invalid', 'Zvolený výrobce neexistuje.', 422);
            }
            $result['manufacturer_id'] = $id;
        }
        foreach (['category_ids', 'tag_ids'] as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $ids = $this->positiveIds($changes[$field], $field);
            $owned = $field === 'category_ids'
                ? $this->itemCategories->filterOwned($supplierId, $ids)
                : $this->itemTags->filterOwned($supplierId, $ids);
            sort($owned, SORT_NUMERIC);
            $expected = $ids;
            sort($expected, SORT_NUMERIC);
            if ($owned !== $expected) {
                throw new EshopException($field === 'category_ids' ? 'category_invalid' : 'tag_invalid',
                    $field === 'category_ids' ? 'Zvolená kategorie neexistuje.' : 'Zvolený štítek neexistuje.', 422);
            }
            $result[$field] = $field === 'tag_ids' ? $expected : $ids;
        }
        foreach (['is_active', 'export_eshop'] as $field) {
            if (array_key_exists($field, $changes)) {
                if (!is_bool($changes[$field])) {
                    throw new \InvalidArgumentException($field . ' musí být boolean.');
                }
                $result[$field] = $changes[$field];
            }
        }
        if (array_key_exists('min_qty', $changes)) {
            if (!is_string($changes['min_qty']) || !preg_match('/^(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?$/D', $changes['min_qty'])) {
                throw new \InvalidArgumentException('min_qty musí být nezáporné desetinné číslo s nejvýše třemi desetinnými místy.');
            }
            [$whole, $fraction] = array_pad(explode('.', $changes['min_qty'], 2), 2, '');
            $result['min_qty'] = $whole . '.' . str_pad($fraction, 3, '0');
        }
        return $result;
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    public function states(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, sku, name, row_version, manufacturer_id, is_active, export_eshop, min_qty
            FROM stock_items WHERE supplier_id = ? AND id IN (' . $ph . ')' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids]);
        $states = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $states[$id] = [
                'id' => $id,
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'row_version' => (int) $row['row_version'],
                'manufacturer_id' => $row['manufacturer_id'] === null ? null : (int) $row['manufacturer_id'],
                'category_ids' => [],
                'categories' => [],
                'tag_ids' => [],
                'is_active' => (bool) $row['is_active'],
                'export_eshop' => (bool) $row['export_eshop'],
                'min_qty' => $row['min_qty'] === null ? null : (string) $row['min_qty'],
            ];
        }
        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, category_id, is_primary, display_order FROM stock_item_categories
            WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ')
            ORDER BY stock_item_id, is_primary DESC, display_order, category_id');
        $stmt->execute([$supplierId, ...$ids]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int) $row['stock_item_id'];
            $categoryId = (int) $row['category_id'];
            $states[$itemId]['category_ids'][] = $categoryId;
            $states[$itemId]['categories'][] = [
                'category_id' => $categoryId,
                'is_primary' => (bool) $row['is_primary'],
                'display_order' => (int) $row['display_order'],
            ];
        }
        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, tag_id FROM stock_item_tags
            WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ') ORDER BY stock_item_id, tag_id');
        $stmt->execute([$supplierId, ...$ids]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(int) $row['stock_item_id']]['tag_ids'][] = (int) $row['tag_id'];
        }
        return $states;
    }

    public function changedState(array $before, array $changes): array
    {
        $after = $before;
        foreach ($changes as $field => $value) {
            $after[$field] = $value;
        }
        if (array_key_exists('category_ids', $changes)) {
            $after['categories'] = array_map(static fn (int $id, int $index): array => [
                'category_id' => $id,
                'is_primary' => $index === 0,
                'display_order' => $index,
            ], $changes['category_ids'], array_keys($changes['category_ids']));
        }
        if (!$this->sameValues($before, $after)) {
            $after['row_version'] = (int) $before['row_version'] + 1 + (int) $this->hasProductChanges($changes);
        }
        return $after;
    }

    public function sameValues(array $left, array $right): bool
    {
        foreach (self::COMPARED_FIELDS as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    public function writeState(int $supplierId, int $itemId, int $expectedVersion, array $desired, array $changes): void
    {
        $base = $this->stockItems->find($supplierId, $itemId);
        if ($base === null) {
            throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
        }
        if (!$this->stockItems->updateVersioned($supplierId, $itemId, $expectedVersion, array_replace($base, [
            'is_active' => $desired['is_active'],
            'min_qty' => $desired['min_qty'],
        ]))) {
            throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409);
        }
        $product = [];
        if (array_key_exists('manufacturer_id', $changes)) {
            $product['manufacturer_id'] = $desired['manufacturer_id'];
        }
        if (array_key_exists('export_eshop', $changes)) {
            $product['export_eshop'] = $desired['export_eshop'];
        }
        if (array_key_exists('category_ids', $changes)) {
            $product['categories'] = $desired['categories'];
        }
        if (array_key_exists('tag_ids', $changes)) {
            $product['tag_ids'] = $desired['tag_ids'];
        }
        if ($product !== []) {
            $this->cards->updateForEditor($supplierId, $itemId, $base, $product);
        }
        if (array_key_exists('category_ids', $changes)) {
            foreach ($desired['categories'] as $category) {
                $this->itemCategories->add(
                    $supplierId,
                    $itemId,
                    (int) $category['category_id'],
                    (bool) $category['is_primary'],
                    (int) $category['display_order'],
                );
            }
        }
        if (array_key_exists('manufacturer_id', $changes) || array_key_exists('category_ids', $changes)) {
            $prices = $this->db->pdo()->prepare('SELECT EXISTS (SELECT 1 FROM stock_item_prices
                WHERE supplier_id = ? AND stock_item_id = ? AND use_pricing_rules = 1)');
            $prices->execute([$supplierId, $itemId]);
            if ($prices->fetchColumn()) {
                $this->pricing->recompute($supplierId, $itemId);
            }
        }
    }

    private function derive(int $supplierId, int $sourceId, string $sourceKind, string $kind, string $status, ?int $createdBy): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Odvození hromadné úlohy vyžaduje samostatnou transakci.');
        }
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $sourceId]);
            $source = $lock->fetchColumn() === false ? null : $this->jobs->find($supplierId, $sourceId);
            if ($source === null || $source['kind'] !== $sourceKind) {
                throw new EshopException('not_found', 'Hromadná úloha nenalezena.', 404);
            }
            if ($source['status'] !== 'completed') {
                throw new EshopException('job_state_conflict', 'Hromadná úloha ještě není dokončena.', 409);
            }
            $duplicate = $pdo->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.source_job_id')) = ?
                AND status IN ('queued','running','completed') ORDER BY id DESC LIMIT 1");
            $duplicate->execute([$supplierId, $kind, (string) $sourceId]);
            if ($duplicate->fetchColumn() !== false) {
                throw new EshopException('job_state_conflict', 'Navazující hromadná úloha již existuje.', 409);
            }
            $changes = (array) ($source['input']['changes'] ?? []);
            $id = $this->jobs->enqueue($supplierId, $kind, ['source_job_id' => $sourceId, 'changes' => $changes], createdBy: $createdBy);
            $copy = $pdo->prepare('INSERT INTO catalog_job_items
                (supplier_id, job_id, ordinal, stock_item_id, source_row, expected_version, input_json)
                SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY source.ordinal), source.stock_item_id, source.ordinal,
                    CASE WHEN ? = 1
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(source.after_json, "$.row_version")) AS UNSIGNED)
                        ELSE source.expected_version END,
                    JSON_OBJECT(
                        "before", JSON_EXTRACT(source.before_json, "$"),
                        "after", JSON_EXTRACT(source.after_json, "$")
                    )
                FROM catalog_job_items source
                WHERE source.supplier_id = ? AND source.job_id = ? AND source.status = ?
                ORDER BY source.ordinal');
            $copy->execute([$supplierId, $id, (int) ($kind === self::RESTORE_KIND), $supplierId, $sourceId, $status]);
            $total = $copy->rowCount();
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$total, $supplierId, $id]);
            $pdo->commit();
            return $this->jobs->find($supplierId, $id) ?? [];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function positiveIds(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 1000) {
            throw new \InvalidArgumentException($field . ' musí být seznam nejvýše 1000 ID.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException($field . ' obsahuje neplatné ID.');
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private function hasProductChanges(array $changes): bool
    {
        return array_intersect(array_keys($changes), ['manufacturer_id', 'category_ids', 'tag_ids', 'export_eshop']) !== [];
    }
}
