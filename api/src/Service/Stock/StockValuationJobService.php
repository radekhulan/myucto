<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockTakeRepository;
use MyInvoice\Repository\StockValuationSnapshotRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use PDO;

final class StockValuationJobService
{
    private const BATCH_SIZE = 2000;
    private const BATCH_CARDS = 500;
    private const BATCH_SECONDS = 1.0;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly StockLedgerReader $ledger,
        private readonly StockValuationSnapshotRepository $snapshots,
        private readonly WarehouseRepository $warehouses,
        private readonly StockTakeRepository $takes,
        private readonly \MyInvoice\Repository\StockDocumentRepository $documents,
    ) {}

    public function enqueue(int $supplierId, string $date, array $filters = []): array
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new StockException('invalid_document', 'Datum ocenění je povinné (YYYY-MM-DD).');
        }
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $warehouseId = !empty($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
            $versions = $this->snapshots->versions($supplierId, $warehouseId !== null ? [$warehouseId] : null);
            $this->warehouses->lockForStockOperation($supplierId, array_keys($versions));
            $versions = $this->snapshots->versions($supplierId, array_keys($versions));
            $takeId = (int) ($filters['stock_take_id'] ?? 0);
            $jobId = $this->jobs->enqueue($supplierId, 'stock_valuation', [
                'date' => $date, 'warehouse_id' => $warehouseId, 'source_versions' => $versions,
                'stock_take_id' => $takeId > 0 ? $takeId : null,
            ]);
            $sql = 'INSERT INTO stock_valuation_rows (job_id, supplier_id, warehouse_id, stock_item_id)
                SELECT ?, supplier_id, warehouse_id, stock_item_id FROM stock_levels WHERE supplier_id = ?';
            $params = [$jobId, $supplierId];
            if ($warehouseId !== null) {
                $sql .= ' AND warehouse_id = ?';
                $params[] = $warehouseId;
            }
            $pdo->prepare($sql)->execute($params);
            if ($takeId > 0 && $warehouseId !== null) {
                $pdo->prepare('INSERT IGNORE INTO stock_valuation_rows (job_id, supplier_id, warehouse_id, stock_item_id)
                    SELECT ?, supplier_id, ?, id FROM stock_items WHERE supplier_id = ? AND is_active = 1')
                    ->execute([$jobId, $warehouseId, $supplierId]);
            }
            $pdo->prepare('UPDATE catalog_jobs SET total = (SELECT COUNT(*) FROM stock_valuation_rows WHERE job_id = ? AND supplier_id = ?) WHERE id = ? AND supplier_id = ?')
                ->execute([$jobId, $supplierId, $jobId, $supplierId]);
            $result = $this->jobs->find($supplierId, $jobId);
            if ($own) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $job = $this->jobs->claim($supplierId, 'stock_valuation');
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($i = 0; $i < max(1, min(100, $maxBatches)); $i++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token, fn (array $current): array => $this->processBatch($supplierId, $current));
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $code = $e instanceof StockException ? $e->errorCode : 'stock_valuation_failed';
            $this->jobs->fail($supplierId, $job['id'], $token, $code);
            throw $e;
        }
    }

    private function processBatch(int $supplierId, array $job): array
    {
        if ($job['input_version'] !== 1) {
            throw new StockException('unsupported_input_version', 'Nepodporovaná verze úlohy.', 409);
        }
        $input = $job['input'];
        $warehouseIds = array_map('intval', array_keys($input['source_versions']));
        $this->warehouses->lockForStockOperation($supplierId, $warehouseIds);
        if ($this->snapshots->versions($supplierId, $warehouseIds) != $input['source_versions']) {
            throw new StockException('stock_valuation_stale', 'Sklad se během výpočtu změnil. Spusťte nové ocenění.', 409);
        }
        $pdo = $this->db->pdo();
        $select = $pdo->prepare('SELECT * FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND completed = 0 ORDER BY stock_item_id, warehouse_id LIMIT ' . self::BATCH_CARDS . ' FOR UPDATE');
        $select->execute([$supplierId, $job['id']]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $checkpoint = $job['checkpoint'];
        $movementCount = (int) ($job['report']['movements'] ?? 0);
        $remainingMovements = self::BATCH_SIZE;
        $deadline = hrtime(true) + (int) (self::BATCH_SECONDS * 1_000_000_000);
        $update = $pdo->prepare('UPDATE stock_valuation_rows SET started = 1, completed = ?, base_date = ?, cursor_json = ?, qty = ?, value_total = ?, movements = movements + ?
            WHERE supplier_id = ? AND job_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        foreach ($rows as $index => $row) {
            if ($remainingMovements === 0 || ($index > 0 && hrtime(true) >= $deadline)) {
                break;
            }
            $warehouseId = (int) $row['warehouse_id'];
            $itemId = (int) $row['stock_item_id'];
            if (!$row['started']) {
                $snapshot = $this->snapshots->latest($supplierId, $warehouseId, $itemId, $input['date']);
                $row['qty'] = $snapshot['qty'] ?? '0';
                $row['value_total'] = $snapshot['value_total'] ?? '0';
                $row['base_date'] = $snapshot['cutoff_date'] ?? null;
            }
            $qtyT = StockValuation::qtyToT((string) $row['qty']);
            $valueC = StockValuation::valueToC((string) $row['value_total']);
            $cursor = $row['cursor_json'] !== null ? json_decode($row['cursor_json'], true, 512, JSON_THROW_ON_ERROR) : null;
            $lines = $this->ledger->page($supplierId, $warehouseId, $itemId, $input['date'], $row['base_date'], $cursor, $remainingMovements);
            foreach ($lines as $line) {
                $state = StockLedgerReplay::advance($qtyT, $valueC, $line);
                $qtyT = $state['qtyT'];
                $valueC = $state['valueC'];
                $cursor = StockLedgerReader::cursor($line);
            }
            $complete = count($lines) < $remainingMovements;
            $remainingMovements -= count($lines);
            $movementCount += count($lines);
            $update->execute([(int) $complete, $row['base_date'], $cursor !== null ? json_encode($cursor, JSON_THROW_ON_ERROR) : null,
                    StockValuation::tToDecimal($qtyT), StockValuation::cToDecimal($valueC), count($lines), $supplierId, $job['id'], $warehouseId, $itemId]);
            if ($complete) {
                $this->snapshots->save($supplierId, $warehouseId, $itemId, $input['date'], $qtyT, $valueC, (int) $input['source_versions'][$warehouseId]);
                $checkpoint++;
            }
        }
        $done = $checkpoint === $job['total'];
        if ($done && !empty($input['stock_take_id'])) {
            $this->finishPreparation($supplierId, (int) $input['stock_take_id'], $job['id'], $input['date']);
        }
        return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => [
            'processed' => $checkpoint, 'movements' => $movementCount, 'date' => $input['date'],
        ]];
    }

    private function finishPreparation(int $supplierId, int $takeId, int $jobId, string $date): void
    {
        $take = $this->takes->find($supplierId, $takeId);
        if ($take === null || $take['status'] !== 'preparing' || (int) $take['preparation_job_id'] !== $jobId) {
            throw new StockException('stock_take_preparation_conflict', 'Příprava inventury byla změněna.', 409);
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM stock_take_lines WHERE supplier_id = ? AND stock_take_id = ?')->execute([$supplierId, $takeId]);
        $pdo->prepare('INSERT INTO stock_take_lines (stock_take_id, supplier_id, stock_item_id, expected_qty, expected_value, counted_qty, surplus_unit_cost)
            SELECT ?, r.supplier_id, r.stock_item_id, r.qty, r.value_total, NULL,
                CASE WHEN r.qty > 0 THEN r.value_total / r.qty ELSE NULL END
            FROM stock_valuation_rows r JOIN stock_items i ON i.id = r.stock_item_id AND i.supplier_id = r.supplier_id
            WHERE r.supplier_id = ? AND r.job_id = ? AND r.completed = 1 AND (i.is_active = 1 OR r.qty <> 0)')
            ->execute([$takeId, $supplierId, $jobId]);
        $costUpdate = $pdo->prepare('UPDATE stock_take_lines SET surplus_unit_cost = ?
            WHERE supplier_id = ? AND stock_take_id = ? AND stock_item_id = ? AND expected_qty = 0');
        $emptyLines = $pdo->prepare('SELECT stock_item_id FROM stock_take_lines WHERE supplier_id = ? AND stock_take_id = ? AND expected_qty = 0');
        $emptyLines->execute([$supplierId, $takeId]);
        foreach ($emptyLines->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            $cost = $this->documents->lastKnownUnitCost($supplierId, (int) $take['warehouse_id'], (int) $itemId, $date);
            $costUpdate->execute([$cost, $supplierId, $takeId, $itemId]);
        }
        $this->takes->updateStatus($supplierId, $takeId, 'counting', ['started_at' => date('Y-m-d H:i:s')]);
    }

    public function result(int $supplierId, int $jobId, int $page = 1, int $limit = 100): array
    {
        $job = $this->jobs->find($supplierId, $jobId);
        if ($job === null || $job['kind'] !== 'stock_valuation') {
            throw new StockException('not_found', 'Ocenění nenalezeno.', 404);
        }
        if ($job['status'] !== 'completed') {
            throw new StockException('stock_valuation_not_ready', 'Ocenění dosud není dokončeno.', 409);
        }
        $versions = $job['input']['source_versions'];
        if ($this->snapshots->versions($supplierId, array_map('intval', array_keys($versions))) != $versions) {
            throw new StockException('stock_valuation_stale', 'Ocenění vychází ze staršího stavu skladu.', 409);
        }
        $page = max(1, $page);
        $limit = max(1, min(500, $limit));
        $where = 'r.supplier_id = ? AND r.job_id = ? AND r.completed = 1 AND (r.qty <> 0 OR r.value_total <> 0)';
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) AS count, COALESCE(SUM(r.value_total), 0) AS value_total FROM stock_valuation_rows r WHERE ' . $where);
        $stmt->execute([$supplierId, $jobId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $this->db->pdo()->prepare('SELECT r.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
            r.stock_item_id, i.sku, i.name, i.unit, r.qty, r.value_total FROM stock_valuation_rows r
            JOIN warehouses w ON w.id = r.warehouse_id AND w.supplier_id = r.supplier_id
            JOIN stock_items i ON i.id = r.stock_item_id AND i.supplier_id = r.supplier_id
            WHERE ' . $where . ' ORDER BY r.stock_item_id, r.warehouse_id LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
        $stmt->execute([$supplierId, $jobId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['warehouse_id'] = (int) $item['warehouse_id'];
            $item['stock_item_id'] = (int) $item['stock_item_id'];
        }
        return ['job_id' => $jobId, 'date' => $job['input']['date'], 'items' => $items,
            'source_versions' => $versions, 'totals' => ['count' => (int) $totals['count'], 'value_total' => (string) $totals['value_total']],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $totals['count'], 'pages' => (int) ceil((int) $totals['count'] / $limit)]];
    }
}
