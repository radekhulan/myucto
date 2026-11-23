<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockLevelRepository;
use PDO;

/**
 * Skladové sestavy (Epic SKLAD §6, §8.1): stav zásob k dnešku a ocenění
 * k historickému datu (replay ledgeru per karta). Čtení stock_items/warehouses
 * přímým SQL (vzor {@see StockDocumentService::itemsMeta()}) — repository
 * modulu jsou read-only bez batch-meta metod pro sestavy.
 */
final class StockReportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockLevelRepository $levels,
        private readonly StockLedgerReader $ledger,
        private readonly \MyInvoice\Repository\StockValuationSnapshotRepository $snapshots,
        private readonly StockValuationCategory $categories,
    ) {}

    /** Volitelné sloupce sestavy Stav zásob; bez parametru `columns` jsou vidět první dva. */
    public const STATUS_COLUMNS = ['avg_unit_cost', 'value_total', 'sale_price', 'note'];
    private const STATUS_DEFAULT_COLUMNS = ['avg_unit_cost', 'value_total'];

    /**
     * Filtry sestavy Stav zásob z query stringu. Původní filtry (sklad, typ, pod
     * minimem, aktivní, hledání) zůstávají tolerantní jako dřív, nové jsou přísné:
     * `category_id` a `attribute_id` kladné celé číslo, `columns` čárkami oddělený
     * výběr z {@see STATUS_COLUMNS}. Přítomný `columns` určuje sloupce přesně
     * (prázdný nebo `none` = žádný volitelný), chybějící ponechá výchozí průměrnou
     * cenu a hodnotu.
     *
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    public static function normalizeStatusFilters(array $q): array
    {
        $filters = [];
        if (!empty($q['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $q['warehouse_id'];
        }
        if (!empty($q['item_type'])) {
            $filters['item_type'] = (string) $q['item_type'];
        }
        if (!empty($q['below_min'])) {
            $filters['below_min'] = true;
        }
        if (array_key_exists('active', $q) && $q['active'] !== '') {
            $filters['active'] = (bool) (int) $q['active'];
        }
        if (!empty($q['q'])) {
            $filters['q'] = (string) $q['q'];
        }
        foreach (['category_id', 'attribute_id'] as $key) {
            if (!isset($q[$key]) || $q[$key] === '') {
                continue;
            }
            $id = filter_var($q[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new StockException('validation_failed', 'Neplatný identifikátor filtru.', 422, ['field' => $key]);
            }
            $filters[$key] = $id;
        }
        if (array_key_exists('columns', $q)) {
            if (!is_string($q['columns'])) {
                throw new StockException('validation_failed', 'Neplatný výběr sloupců.', 422, ['field' => 'columns']);
            }
            $requested = array_values(array_filter(array_map('trim', explode(',', $q['columns'])), static fn (string $c): bool => $c !== ''));
            if ($requested === ['none']) {
                $requested = [];
            }
            if (array_diff($requested, self::STATUS_COLUMNS) !== []) {
                throw new StockException('validation_failed', 'Neznámý sloupec sestavy.', 422, ['field' => 'columns']);
            }
            $filters['columns'] = array_values(array_intersect(self::STATUS_COLUMNS, $requested));
        }
        return $filters;
    }

    /**
     * Stav zásob k dnešku (aktuální stock_levels) s filtry sklad/typ/pod minimem/
     * kategorie. Při volbě sloupců (`columns`, `attribute_id`) odpověď navíc nese
     * `columns` (zobrazené volitelné sloupce, parametr jako 'attribute') a `attribute`
     * (popis parametru) a řádky jen vyžádané údaje karty. Bez nich je výstup beze změny.
     *
     * @param array<string,mixed> $filters výstup {@see normalizeStatusFilters()}
     * @return array<string,mixed>
     */
    public function status(int $supplierId, array $filters): array
    {
        $levelFilters = [];
        if (!empty($filters['warehouse_id'])) {
            $levelFilters['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['item_type'])) {
            $levelFilters['item_type'] = (string) $filters['item_type'];
        }
        if (!empty($filters['below_min'])) {
            $levelFilters['below_min'] = true;
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $levelFilters['active'] = $filters['active'];
        }
        if (!empty($filters['q'])) {
            $levelFilters['q'] = (string) $filters['q'];
        }
        if (!empty($filters['category_id'])) {
            $levelFilters['category_id'] = (int) $filters['category_id'];
        }

        $custom = array_key_exists('columns', $filters) || !empty($filters['attribute_id']);
        $columns = (array) ($filters['columns'] ?? self::STATUS_DEFAULT_COLUMNS);
        $attribute = !empty($filters['attribute_id']) ? $this->attributeMeta($supplierId, (int) $filters['attribute_id']) : null;

        $rows = $this->levels->levels($supplierId, $levelFilters, array_values(array_intersect(['sale_price', 'note'], $columns)), $attribute);
        $totalValueC = 0;
        foreach ($rows as $r) {
            $totalValueC += StockValuation::valueToC((string) $r['value_total']);
        }

        $out = [
            'items'  => $rows,
            'totals' => [
                'value_total' => StockValuation::cToDecimal($totalValueC),
                'count'       => count($rows),
            ],
        ];
        if ($custom) {
            $out['columns'] = $attribute !== null ? [...$columns, 'attribute'] : $columns;
            $out['attribute'] = $attribute;
        }
        return $out;
    }

    /** @return array{id:int,code:string,name:string,unit:?string,data_type:string} */
    private function attributeMeta(int $supplierId, int $attributeId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, name, unit, data_type FROM stock_attributes WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $attributeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new StockException('validation_failed', 'Parametr nenalezen.', 422, ['field' => 'attribute_id']);
        }
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'unit' => $row['unit'] !== null && $row['unit'] !== '' ? (string) $row['unit'] : null,
            'data_type' => (string) $row['data_type'],
        ];
    }

    /**
     * Ocenění zásob k historickému datu — replay average-cost sekvence každé
     * dvojice (sklad, karta) od nuly do `$date` (§3.2 stejný algoritmus jako
     * {@see StockRecomputeService::replay()}, ale READ-ONLY — nic se nezapisuje).
     *
     * @param array{warehouse_id?:int,category_id?:int} $filters
     * @return array<string,mixed>
     */
    public function valuation(int $supplierId, string $date, array $filters): array
    {
        if (!self::isDate($date)) {
            throw new StockException('invalid_document', 'Datum sestavy je povinné (YYYY-MM-DD).');
        }

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
        }
        try {
            $warehouseId = !empty($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
            $categoryId = $filters['category_id'] ?? null;
            $category = $this->categories->resolve($supplierId, $categoryId);
            $this->snapshots->versions($supplierId, $warehouseId !== null ? [$warehouseId] : null);
            $itemsMeta = $this->itemsMetaMap($supplierId);
            $warehouses = $this->warehouseMetaMap($supplierId);
            $out = [];
            $totalValueC = 0;
            foreach ($this->ledger->pairs($supplierId, $date, $warehouseId, $categoryId) as $pair) {
                $whId = (int) $pair['warehouse_id'];
                $itemId = (int) $pair['stock_item_id'];
                $snapshot = $this->snapshots->latest($supplierId, $whId, $itemId, $date);
                $qtyT = StockValuation::qtyToT((string) ($snapshot['qty'] ?? '0'));
                $valueC = StockValuation::valueToC((string) ($snapshot['value_total'] ?? '0'));
                foreach ($this->ledger->stream($supplierId, $whId, $itemId, $date, $snapshot['cutoff_date'] ?? null) as $line) {
                    $state = StockLedgerReplay::advance($qtyT, $valueC, $line);
                    $qtyT = $state['qtyT'];
                    $valueC = $state['valueC'];
                }
                if ($qtyT === 0 && $valueC === 0) {
                    continue;
                }
                $out[] = [
                    'warehouse_id' => $whId,
                    'warehouse_code' => $warehouses[$whId]['code'] ?? '',
                    'warehouse_name' => $warehouses[$whId]['name'] ?? '',
                    'stock_item_id' => $itemId,
                    'sku' => $itemsMeta[$itemId]['sku'] ?? '',
                    'name' => $itemsMeta[$itemId]['name'] ?? '',
                    'unit' => $itemsMeta[$itemId]['unit'] ?? '',
                    'qty' => StockValuation::tToDecimal($qtyT),
                    'value_total' => StockValuation::cToDecimal($valueC),
                ];
                $totalValueC += $valueC;
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
            $result = ['date' => $date, 'items' => $out, 'totals' => [
                'value_total' => StockValuation::cToDecimal($totalValueC), 'count' => count($out),
            ]];
            if ($category !== null) {
                $result['category'] = $category;
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int,array{sku:string,name:string,unit:string}> */
    private function itemsMetaMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, sku, name, unit FROM stock_items WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = ['sku' => (string) $r['sku'], 'name' => (string) $r['name'], 'unit' => (string) $r['unit']];
        }
        return $out;
    }

    /** @return array<int,array{code:string,name:string}> */
    private function warehouseMetaMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, name FROM warehouses WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = ['code' => (string) $r['code'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
