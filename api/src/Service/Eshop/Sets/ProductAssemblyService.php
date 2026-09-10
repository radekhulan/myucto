<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Sets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockLevelService;
use PDO;

final class ProductAssemblyService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProductSetService $sets,
        private readonly ProductSetGraph $graph,
        private readonly StockDocumentService $documents,
        private readonly StockLevelService $levels,
    ) {}

    public function get(int $supplierId, int $id): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM product_assemblies WHERE supplier_id = ? AND id = ?');
        $query->execute([$supplierId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->present($row);
    }

    public function list(int $supplierId, int $page = 1, int $limit = 30): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $query = $this->db->pdo()->prepare('SELECT COUNT(*) FROM product_assemblies WHERE supplier_id = ?');
        $query->execute([$supplierId]);
        $total = (int) $query->fetchColumn();
        $offset = ($page - 1) * $limit;
        $query = $this->db->pdo()->prepare("SELECT * FROM product_assemblies WHERE supplier_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute([$supplierId]);
        return ['items' => array_map($this->present(...), $query->fetchAll(PDO::FETCH_ASSOC)), 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
    }

    public function create(int $supplierId, array $body, ?int $userId): array
    {
        $operation = $body['operation_key'] ?? null;
        if (!is_string($operation) || preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $operation) !== 1) throw new EshopException('operation_key_required', 'Kompletace vyžaduje identifikátor operace.', 422);
        $itemId = $body['stock_item_id'] ?? null;
        $warehouseId = $body['warehouse_id'] ?? null;
        if (!is_int($itemId) || $itemId < 1 || !is_int($warehouseId) || $warehouseId < 1 || !is_array($body['definition'] ?? null) || !is_string($body['doc_date'] ?? null)) throw new EshopException('assembly_invalid', 'Neplatné zadání kompletace.', 422);
        $quantity = ProductSetDefinition::quantity($body['quantity'] ?? null);
        $definition = ProductSetDefinition::normalize($body['definition']);
        $selections = $body['selections'] ?? [];
        if (!is_array($selections)) throw new EshopException('set_selection_invalid', 'Neplatná konfigurace.', 422);
        $componentTracking = $this->trackingAllocationMap($body['component_tracking_allocations'] ?? []);
        $productTracking = $this->trackingAllocationList($body['product_tracking_allocations'] ?? []);
        $hash = hash('sha256', json_encode([
            $itemId,
            $warehouseId,
            $quantity,
            $body['doc_date'],
            $definition,
            $selections,
            $componentTracking,
            $productTracking,
        ], JSON_THROW_ON_ERROR));
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $tenant = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
            $tenant->execute([$supplierId]);
            if (!$tenant->fetchColumn()) throw new EshopException('not_found', 'Firma nenalezena.', 404);
            $query = $pdo->prepare('SELECT * FROM product_assemblies WHERE supplier_id = ? AND operation_key = ? FOR UPDATE');
            $query->execute([$supplierId, $operation]);
            $existing = $query->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new EshopException('operation_conflict', 'Identifikátor operace už patří jinému zadání.', 409);
                if ($own) $pdo->commit();
                return $this->present($existing);
            }
            $query = $pdo->prepare('SELECT is_stocked, item_type, is_active FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $query->execute([$supplierId, $itemId]);
            $product = $query->fetch(PDO::FETCH_ASSOC);
            if ($product === false || !(bool) $product['is_stocked'] || !(bool) $product['is_active'] || $product['item_type'] !== 'product') throw new EshopException('assembly_product_required', 'Kompletace vyžaduje aktivní skladovanou kartu výrobku.', 422);
            $definitions = $this->sets->definitions($supplierId);
            if (isset($definitions[$itemId])) throw new EshopException('assembly_virtual_set', 'Virtuální set nemůže přijímat vlastní skladovou zásobu.', 422);
            $definitions[$itemId] = $definition;
            $expanded = $this->graph->expand($itemId, $quantity, $definitions, $selections);
            $unknownTrackedComponents = array_diff(array_keys($componentTracking), array_map('intval', array_keys($expanded['components'])));
            if ($unknownTrackedComponents !== []) throw new EshopException('assembly_tracking_component_invalid', 'Alokace sledování odkazuje na komponentu, která není součástí kompletace.', 422);
            $query = $pdo->prepare('INSERT INTO product_assemblies (supplier_id, operation_key, request_hash, stock_item_id, warehouse_id, quantity, definition_json, components_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([$supplierId, $operation, $hash, $itemId, $warehouseId, $quantity, json_encode($definition, JSON_THROW_ON_ERROR), json_encode($expanded, JSON_THROW_ON_ERROR)]);
            $id = (int) $pdo->lastInsertId();
            $lines = [];
            $pairs = [['warehouse_id' => $warehouseId, 'stock_item_id' => $itemId]];
            foreach ($expanded['components'] as $componentId => $qty) {
                $lines[] = [
                    'stock_item_id' => $componentId,
                    'qty' => $qty,
                    'tracking_allocations' => $componentTracking[(int) $componentId] ?? [],
                ];
                $pairs[] = ['warehouse_id' => $warehouseId, 'stock_item_id' => $componentId];
            }
            $header = ['origin' => 'manual', 'warehouse_id' => $warehouseId, 'doc_date' => $body['doc_date'], 'description' => 'Kompletace výrobku #' . $id];
            $issue = $this->documents->create($supplierId, $header + ['doc_type' => 'issue', 'lines' => $lines], $userId);
            $this->levels->lockLevels($supplierId, $pairs);
            $issued = $this->documents->post($supplierId, (int) $issue['id'], $userId);
            $value = '0.00';
            foreach ($issued['lines'] as $line) $value = bcadd($value, ltrim((string) $line['value_total'], '-'), 2);
            $unitCost = bcdiv($value, $quantity, 6);
            $baseValue = bcdiv(bcadd(bcmul($unitCost, $quantity, 9), '0.005', 9), '1', 2);
            $extraCost = bcsub($value, $baseValue, 2);
            $receipt = $this->documents->create($supplierId, $header + ['doc_type' => 'receipt', 'lines' => [[
                'stock_item_id' => $itemId,
                'qty' => $quantity,
                'unit_cost' => $unitCost,
                'extra_cost' => $extraCost,
                'tracking_allocations' => $productTracking,
            ]]], $userId);
            $received = $this->documents->post($supplierId, (int) $receipt['id'], $userId);
            if (bccomp((string) $received['lines'][0]['value_total'], $value, 2) !== 0) throw new EshopException('assembly_valuation_mismatch', 'Ocenění výrobku neodpovídá vydaným komponentám.', 409);
            $pdo->prepare('UPDATE product_assemblies SET issue_document_id = ?, receipt_document_id = ?, value_total = ? WHERE supplier_id = ? AND id = ?')->execute([$issue['id'], $receipt['id'], $value, $supplierId, $id]);
            $result = $this->get($supplierId, $id);
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function reverse(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $query = $pdo->prepare('SELECT * FROM product_assemblies WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $query->execute([$supplierId, $id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row === false) throw new EshopException('not_found', 'Kompletace nenalezena.', 404);
            if ($row['status'] !== 'reversed') {
                $this->documents->reverse($supplierId, (int) $row['receipt_document_id'], [], $userId, true);
                $this->documents->reverse($supplierId, (int) $row['issue_document_id'], [], $userId, true);
                $pdo->prepare("UPDATE product_assemblies SET status = 'reversed', reversed_at = CURRENT_TIMESTAMP(6) WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $id]);
            }
            $result = $this->get($supplierId, $id);
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function present(array $row): array
    {
        foreach (['id', 'supplier_id', 'stock_item_id', 'warehouse_id', 'issue_document_id', 'receipt_document_id'] as $key) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        $row['definition'] = json_decode($row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['components'] = json_decode($row['components_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['definition_json'], $row['components_json'], $row['request_hash']);
        return $row;
    }

    private function trackingAllocationMap(mixed $raw): array
    {
        if (!is_array($raw)) throw new EshopException('assembly_tracking_invalid', 'Neplatné alokace sledování komponent.', 422);
        $result = [];
        foreach ($raw as $itemId => $allocations) {
            if ((!is_int($itemId) && (!is_string($itemId) || preg_match('/^[1-9][0-9]*$/D', $itemId) !== 1)) || (int) $itemId < 1) {
                throw new EshopException('assembly_tracking_invalid', 'Neplatná komponenta v alokacích sledování.', 422);
            }
            $result[(int) $itemId] = $this->trackingAllocationList($allocations);
        }
        ksort($result);
        return $result;
    }

    private function trackingAllocationList(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) throw new EshopException('assembly_tracking_invalid', 'Alokace sledování musí být seznam.', 422);
        foreach ($raw as $allocation) {
            if (!is_array($allocation)) throw new EshopException('assembly_tracking_invalid', 'Neplatná alokace sledování.', 422);
        }
        return $raw;
    }
}
