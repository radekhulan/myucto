<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use PDO;

final class CycleCountService
{
    private const BATCH = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly WarehouseRepository $warehouses,
        private readonly StockTrackingRepository $tracking,
        private readonly StockDocumentService $documents,
    ) {}

    public function create(int $supplierId, array $body, ?int $userId): array
    {
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $warehouse = $this->warehouses->find($supplierId, $warehouseId);
        if ($warehouse === null || empty($warehouse['is_active'])) throw new StockException('invalid_document', 'Sklad nenalezen nebo není aktivní.', 422);
        $locationId = isset($body['location_id']) && (int) $body['location_id'] > 0 ? (int) $body['location_id'] : null;
        if ($locationId !== null) {
            $location = $this->tracking->location($supplierId, $locationId);
            if ($location === null || (int) $location['warehouse_id'] !== $warehouseId || !$location['is_active']) throw new StockException('invalid_location', 'Lokace nepatří zvolenému skladu.', 422);
        }
        $date = trim((string) ($body['take_date'] ?? date('Y-m-d')));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) throw new StockException('invalid_document', 'Neplatné datum cyklické inventury.', 422);
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $this->warehouses->lockForStockOperation($supplierId, [$warehouseId]);
            $open = $pdo->prepare("SELECT id FROM stock_cycle_counts WHERE supplier_id = ? AND warehouse_id = ? AND (location_id <=> ?) AND status IN ('draft','queued','running','counting') LIMIT 1 FOR UPDATE");
            $open->execute([$supplierId, $warehouseId, $locationId]);
            if ($open->fetchColumn() !== false) throw new StockException('cycle_count_in_progress', 'Pro sklad a lokaci už probíhá cyklická inventura.', 409);
            $itemIds = $this->normalizeItemIds($supplierId, $body['item_ids'] ?? null);
            $cutoffLine = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM stock_document_lines')->fetchColumn();
            $cutoffAllocation = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM stock_tracking_allocations')->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO stock_cycle_counts (supplier_id, warehouse_id, location_id, status, cutoff_document_line_id, cutoff_allocation_id, take_date, note, created_by) VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?)");
            $stmt->execute([$supplierId, $warehouseId, $locationId, $cutoffLine, $cutoffAllocation, $date, self::nullable($body['note'] ?? null), $userId]);
            $id = (int) $pdo->lastInsertId();
            $membership = $pdo->prepare("INSERT INTO stock_cycle_count_documents (supplier_id, cycle_count_id, stock_document_id) SELECT ?, ?, d.id FROM stock_documents d WHERE d.supplier_id = ? AND d.status IN ('posted','reversed') AND (d.warehouse_id = ? OR (d.doc_type = 'transfer' AND d.warehouse_to_id = ?))");
            $membership->execute([$supplierId, $id, $supplierId, $warehouseId, $warehouseId]);
            $jobId = $this->jobs->enqueue($supplierId, 'stock_cycle_prepare', [
                'cycle_count_id' => $id, 'warehouse_id' => $warehouseId, 'location_id' => $locationId,
                'item_ids' => $itemIds, 'cutoff_document_line_id' => $cutoffLine, 'cutoff_allocation_id' => $cutoffAllocation,
            ], count($itemIds), createdBy: $userId);
            $pdo->prepare('UPDATE stock_cycle_counts SET preparation_job_id = ? WHERE supplier_id = ? AND id = ?')->execute([$jobId, $supplierId, $id]);
            if ($own) $pdo->commit();
            return $this->get($supplierId, $id);
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $job = $this->jobs->claim($supplierId, 'stock_cycle_prepare');
        if ($job === null) return null;
        $token = $job['lease_token'];
        try {
            for ($i = 0; $i < max(1, min(100, $maxBatches)); $i++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token, fn (array $current): array => $this->prepareBatch($supplierId, $current));
                if ($job['status'] !== 'running') return $job;
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, $e instanceof StockException ? $e->errorCode : 'stock_cycle_prepare_failed');
            $this->db->pdo()->prepare("UPDATE stock_cycle_counts SET status = 'failed' WHERE supplier_id = ? AND preparation_job_id = ? AND status IN ('queued','running')")->execute([$supplierId, $job['id']]);
            throw $e;
        }
    }

    private function prepareBatch(int $supplierId, array $job): array
    {
        $input = $job['input'];
        $id = (int) $input['cycle_count_id'];
        $cycle = $this->lock($supplierId, $id);
        if ($cycle === null || !in_array($cycle['status'], ['queued', 'running'], true) || (int) $cycle['preparation_job_id'] !== $job['id']) throw new StockException('cycle_count_conflict', 'Cyklická inventura byla mezitím změněna.', 409);
        if ($cycle['status'] === 'queued') $this->db->pdo()->prepare("UPDATE stock_cycle_counts SET status = 'running' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $id]);
        $itemIds = array_map('intval', array_slice($input['item_ids'], (int) $job['checkpoint'], self::BATCH));
        foreach ($itemIds as $itemId) $this->snapshotItem($supplierId, $cycle, $itemId);
        $checkpoint = (int) $job['checkpoint'] + count($itemIds);
        $done = $checkpoint === (int) $job['total'];
        if ($done) $this->db->pdo()->prepare("UPDATE stock_cycle_counts SET status = 'counting' WHERE supplier_id = ? AND id = ? AND status = 'running'")->execute([$supplierId, $id]);
        return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => ['processed' => $checkpoint, 'cycle_count_id' => $id]];
    }

    private function snapshotItem(int $supplierId, array $cycle, int $itemId): void
    {
        $item = $this->tracking->item($supplierId, $itemId);
        if ($item === null) return;
        $pdo = $this->db->pdo();
        if ($item['tracking_mode'] !== 'none') {
            $sql = 'SELECT a.stock_tracking_unit_id, SUM(CASE WHEN a.direction = \'in\' THEN a.quantity ELSE -a.quantity END) qty FROM stock_tracking_allocations a JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_cycle_count_documents ccd ON ccd.supplier_id = l.supplier_id AND ccd.cycle_count_id = ? AND ccd.stock_document_id = l.document_id WHERE a.supplier_id = ? AND l.stock_item_id = ? AND a.warehouse_id = ?';
            $params = [$cycle['id'], $supplierId, $itemId, $cycle['warehouse_id']];
            if ($cycle['location_id'] !== null) { $sql .= ' AND a.location_id = ?'; $params[] = $cycle['location_id']; }
            $sql .= ' GROUP BY a.stock_tracking_unit_id HAVING qty <> 0';
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $insert = $pdo->prepare('INSERT INTO stock_cycle_count_lines (supplier_id, cycle_count_id, stock_item_id, stock_tracking_unit_id, expected_qty) VALUES (?, ?, ?, ?, ?)');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $insert->execute([$supplierId, $cycle['id'], $itemId, $row['stock_tracking_unit_id'], $row['qty']]);
            return;
        }
        if ($cycle['location_id'] !== null) return;
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN d.doc_type = 'receipt' AND d.warehouse_id = ? THEN l.qty WHEN d.doc_type = 'issue' AND d.warehouse_id = ? THEN -l.qty WHEN d.doc_type = 'transfer' AND d.warehouse_id = ? THEN -l.qty WHEN d.doc_type = 'transfer' AND d.warehouse_to_id = ? THEN l.qty ELSE 0 END), 0) FROM stock_document_lines l JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id JOIN stock_cycle_count_documents ccd ON ccd.supplier_id = l.supplier_id AND ccd.cycle_count_id = ? AND ccd.stock_document_id = l.document_id WHERE l.supplier_id = ? AND l.stock_item_id = ?");
        $stmt->execute([$cycle['warehouse_id'], $cycle['warehouse_id'], $cycle['warehouse_id'], $cycle['warehouse_id'], $cycle['id'], $supplierId, $itemId]);
        $pdo->prepare('INSERT INTO stock_cycle_count_lines (supplier_id, cycle_count_id, stock_item_id, stock_tracking_unit_id, expected_qty) VALUES (?, ?, ?, NULL, ?)')->execute([$supplierId, $cycle['id'], $itemId, (string) $stmt->fetchColumn()]);
    }

    public function updateCounts(int $supplierId, int $id, array $lines): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) throw new \LogicException('Uložení cyklické inventury vlastní transakci.');
        $before = $this->get($supplierId, $id);
        $pdo->beginTransaction();
        try {
            $this->warehouses->lockForStockOperation($supplierId, [(int) $before['warehouse_id']]);
            $cycle = $this->lock($supplierId, $id);
            if ($cycle === null || $cycle['status'] !== 'counting') throw new StockException('cycle_count_conflict', 'Počty lze zadávat jen v otevřené cyklické inventuře.', 409);
            $find = $pdo->prepare('SELECT * FROM stock_cycle_count_lines WHERE supplier_id = ? AND cycle_count_id = ? AND id = ? FOR UPDATE');
            $update = $pdo->prepare('UPDATE stock_cycle_count_lines SET counted_qty = ?, count_reference_qty = ?, counted_at = CASE WHEN ? = 1 THEN counted_at WHEN ? IS NULL THEN NULL ELSE NOW(6) END, surplus_unit_cost = ? WHERE supplier_id = ? AND cycle_count_id = ? AND id = ?');
            foreach ($lines as $input) {
                if (!is_array($input) || (int) ($input['id'] ?? 0) <= 0) continue;
                $find->execute([$supplierId, $id, (int) $input['id']]);
                $line = $find->fetch(PDO::FETCH_ASSOC);
                if ($line === false) continue;
                $counted = $input['counted_qty'] ?? null;
                $counted = $counted === null || $counted === '' ? null : StockValuation::tToDecimal(ExactUnitConversion::toBaseT((string) $counted, 1, 1));
                $cost = isset($input['surplus_unit_cost']) && $input['surplus_unit_cost'] !== '' ? (string) $input['surplus_unit_cost'] : null;
                if ($cost !== null && (!is_numeric($cost) || (float) $cost < 0)) throw new StockException('invalid_document', 'Cena přebytku není platná.', 422);
                $sameCount = $counted === null
                    ? $line['counted_qty'] === null
                    : $line['counted_qty'] !== null && StockValuation::qtyToT((string) $line['counted_qty']) === StockValuation::qtyToT($counted);
                $preserveReference = $sameCount && ($counted === null || $line['count_reference_qty'] !== null);
                $reference = $preserveReference
                    ? $line['count_reference_qty']
                    : ($counted === null ? null : StockValuation::tToDecimal($this->currentT($supplierId, $cycle, $line)));
                $update->execute([$counted, $reference, $preserveReference ? 1 : 0, $counted, $cost, $supplierId, $id, (int) $line['id']]);
            }
            $pdo->commit();
            return $this->get($supplierId, $id);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function close(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) throw new \LogicException('Uzavření cyklické inventury vlastní transakci.');
        $pdo->beginTransaction();
        try {
            $before = $this->get($supplierId, $id);
            $this->warehouses->lockForStockOperation($supplierId, [(int) $before['warehouse_id']]);
            $cycle = $this->lock($supplierId, $id);
            if ($cycle === null || $cycle['status'] !== 'counting') throw new StockException('cycle_count_conflict', 'Uzavřít lze jen otevřenou cyklickou inventuru.', 409);
            $lines = $this->lines($supplierId, $id, true);
            $receipt = []; $issue = [];
            foreach ($lines as $line) {
                if ($line['counted_qty'] === null) continue;
                if ($line['count_reference_qty'] === null) throw new StockException('cycle_count_conflict', 'Před uzavřením znovu uložte fyzické počty.', 409, ['line_id' => $line['id']]);
                $countedT = StockValuation::qtyToT((string) $line['counted_qty']);
                $diffT = $countedT - StockValuation::qtyToT((string) $line['count_reference_qty']);
                if ($diffT === 0) continue;
                $payload = ['stock_item_id' => (int) $line['stock_item_id'], 'qty' => StockValuation::tToDecimal(abs($diffT))];
                if ($line['stock_tracking_unit_id'] !== null) {
                    $unit = $this->tracking->findUnit($supplierId, (int) $line['stock_item_id'], (int) $line['stock_tracking_unit_id']);
                    $payload['tracking_allocations'] = $diffT < 0 && $cycle['location_id'] === null
                        ? $this->issueAllocationsAcrossLocations($supplierId, $cycle, $line, -$diffT, $unit)
                        : [[
                            'stock_tracking_unit_id' => (int) $line['stock_tracking_unit_id'], 'quantity' => $payload['qty'],
                            'serial_number' => $unit['serial_number'], 'lot_code' => $unit['lot_code'], 'expires_on' => $unit['expires_on'],
                            'location_id' => $cycle['location_id'],
                        ]];
                }
                if ($diffT > 0) {
                    if ($line['surplus_unit_cost'] === null || (float) $line['surplus_unit_cost'] <= 0) throw new StockException('missing_surplus_unit_cost', 'Inventurní přebytek vyžaduje pořizovací cenu.', 422, ['line_id' => $line['id']]);
                    $payload['unit_cost'] = $line['surplus_unit_cost']; $receipt[] = $payload;
                } else $issue[] = $payload;
            }
            $pdo->prepare("UPDATE stock_cycle_counts SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE supplier_id = ? AND id = ? AND status = 'counting'")->execute([$userId, $supplierId, $id]);
            $docs = [];
            foreach ([['receipt', $receipt], ['issue', $issue]] as [$type, $docLines]) {
                if ($docLines === []) continue;
                $draft = $this->documents->create($supplierId, ['doc_type' => $type, 'origin' => 'inventory', 'warehouse_id' => $cycle['warehouse_id'], 'doc_date' => $cycle['take_date'], 'description' => 'Cyklická inventura #' . $id, 'lines' => $docLines], $userId);
                $docs[$type] = $this->documents->post($supplierId, (int) $draft['id'], $userId);
            }
            $pdo->commit();
            return $this->get($supplierId, $id) + ['documents' => $docs];
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private function currentT(int $supplierId, array $cycle, array $line): int
    {
        if ($line['stock_tracking_unit_id'] !== null) return $this->tracking->balance($supplierId, (int) $line['stock_tracking_unit_id'], (int) $cycle['warehouse_id'], $cycle['location_id'], $cycle['location_id'] === null);
        $stmt = $this->db->pdo()->prepare('SELECT qty FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $cycle['warehouse_id'], $line['stock_item_id']]);
        return StockValuation::qtyToT((string) ($stmt->fetchColumn() ?: '0'));
    }

    private function issueAllocationsAcrossLocations(int $supplierId, array $cycle, array $line, int $neededT, array $unit): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT a.location_id, SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END) qty FROM stock_tracking_allocations a JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE a.supplier_id = ? AND a.stock_tracking_unit_id = ? AND a.warehouse_id = ? AND d.status IN ('posted','reversed') GROUP BY a.location_id HAVING qty > 0 ORDER BY a.location_id IS NULL, a.location_id");
        $stmt->execute([$supplierId, $line['stock_tracking_unit_id'], $cycle['warehouse_id']]);
        $allocations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $balance) {
            if ($neededT === 0) break;
            $quantityT = min($neededT, StockValuation::qtyToT((string) $balance['qty']));
            if ($quantityT <= 0) continue;
            $allocations[] = [
                'stock_tracking_unit_id' => (int) $line['stock_tracking_unit_id'],
                'quantity' => StockValuation::tToDecimal($quantityT),
                'serial_number' => $unit['serial_number'], 'lot_code' => $unit['lot_code'], 'expires_on' => $unit['expires_on'],
                'location_id' => $balance['location_id'] === null ? null : (int) $balance['location_id'],
            ];
            $neededT -= $quantityT;
        }
        if ($neededT !== 0) throw new StockException('insufficient_stock', 'Sledovaná zásoba v lokacích nestačí k zaúčtování inventurního rozdílu.', 409, ['line_id' => $line['id']]);
        return $allocations;
    }

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_cycle_counts WHERE supplier_id = ? ORDER BY id DESC LIMIT 100'); $stmt->execute([$supplierId]);
        return array_map($this->castCycle(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_cycle_counts WHERE supplier_id = ? AND id = ?'); $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); if ($row === false) throw new StockException('not_found', 'Cyklická inventura nenalezena.', 404);
        $cycle = $this->castCycle($row);
        $cycle['lines'] = $this->lines($supplierId, $id);
        $cycle['preparation_job'] = $cycle['preparation_job_id'] === null
            ? null
            : $this->jobs->find($supplierId, $cycle['preparation_job_id']);
        return $cycle;
    }

    private function lock(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_cycle_counts WHERE supplier_id = ? AND id = ? FOR UPDATE'); $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $this->castCycle($row);
    }

    private function lines(int $supplierId, int $id, bool $withReferences = false): array
    {
        $references = $withReferences ? ', l.count_reference_qty, l.counted_at' : '';
        $stmt = $this->db->pdo()->prepare('SELECT l.id, l.supplier_id, l.cycle_count_id, l.stock_item_id, l.stock_tracking_unit_id, l.expected_qty, l.counted_qty, l.surplus_unit_cost' . $references . ', i.sku, i.name, i.unit, i.tracking_mode, u.lot_code, u.serial_number, u.expires_on FROM stock_cycle_count_lines l JOIN stock_items i ON i.id = l.stock_item_id AND i.supplier_id = l.supplier_id LEFT JOIN stock_tracking_units u ON u.id = l.stock_tracking_unit_id AND u.supplier_id = l.supplier_id WHERE l.supplier_id = ? AND l.cycle_count_id = ? ORDER BY i.sku, u.id'); $stmt->execute([$supplierId, $id]);
        return array_map(static function (array $r): array { foreach (['id','supplier_id','cycle_count_id','stock_item_id'] as $k) $r[$k]=(int)$r[$k]; $r['stock_tracking_unit_id']=$r['stock_tracking_unit_id']===null?null:(int)$r['stock_tracking_unit_id']; return $r; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function normalizeItemIds(int $supplierId, mixed $raw): array
    {
        if ($raw !== null && (!is_array($raw) || !array_is_list($raw) || count($raw) > 30000)) throw new StockException('invalid_document', 'item_ids musí být seznam nejvýše 30 000 ID.', 422);
        $params = [$supplierId]; $sql = 'SELECT id FROM stock_items WHERE supplier_id = ? AND is_active = 1';
        if ($raw !== null) { $ids=array_values(array_unique(array_map('intval',$raw))); if ($ids===[]) return []; $sql.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=[...$params,...$ids]; }
        $stmt=$this->db->pdo()->prepare($sql.' ORDER BY id'); $stmt->execute($params); return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function castCycle(array $r): array { foreach(['id','supplier_id','warehouse_id','cutoff_document_line_id','cutoff_allocation_id'] as $k)$r[$k]=(int)$r[$k]; foreach(['location_id','preparation_job_id','created_by','closed_by'] as $k)$r[$k]=$r[$k]===null?null:(int)$r[$k]; return $r; }
    private static function nullable(mixed $v): ?string { $v=trim((string)$v); return $v===''?null:$v; }
}
