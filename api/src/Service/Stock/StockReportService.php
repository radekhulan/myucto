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
    ) {}

    /**
     * Stav zásob k dnešku (aktuální stock_levels) s filtry sklad/typ/pod minimem.
     *
     * @param array<string,mixed> $filters
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

        $rows = $this->levels->levels($supplierId, $levelFilters);
        $totalValueC = 0;
        foreach ($rows as $r) {
            $totalValueC += StockValuation::valueToC((string) $r['value_total']);
        }

        return [
            'items'  => $rows,
            'totals' => [
                'value_total' => StockValuation::cToDecimal($totalValueC),
                'count'       => count($rows),
            ],
        ];
    }

    /**
     * Ocenění zásob k historickému datu — replay average-cost sekvence každé
     * dvojice (sklad, karta) od nuly do `$date` (§3.2 stejný algoritmus jako
     * {@see StockRecomputeService::replay()}, ale READ-ONLY — nic se nezapisuje).
     *
     * @param array<string,mixed> $filters {warehouse_id?:int}
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
            $this->snapshots->versions($supplierId, $warehouseId !== null ? [$warehouseId] : null);
            $itemsMeta = $this->itemsMetaMap($supplierId);
            $warehouses = $this->warehouseMetaMap($supplierId);
            $out = [];
            $totalValueC = 0;
            foreach ($this->ledger->pairs($supplierId, $date, $warehouseId) as $pair) {
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
            return ['date' => $date, 'items' => $out, 'totals' => [
                'value_total' => StockValuation::cToDecimal($totalValueC), 'count' => count($out),
            ]];
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
