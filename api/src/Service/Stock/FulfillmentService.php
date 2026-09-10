<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FulfillmentRepository;
use MyInvoice\Repository\WarehouseRepository;

final class FulfillmentService
{
    private const TASK_OPEN = ['picking', 'packing', 'partially_shipped'];
    private const DISPOSITIONS = ['sellable', 'quarantine', 'scrap'];

    public function __construct(
        private readonly Connection $db,
        private readonly FulfillmentRepository $repo,
        private readonly FulfillmentSourceResolver $sources,
        private readonly StockLevelService $levels,
        private readonly WarehouseRepository $warehouses,
        private readonly StockDocumentService $documents,
        private readonly TrackingAllocationService $tracking,
        private readonly StockCommitmentService $commitments,
    ) {}

    /** @return list<array<string,mixed>> */
    public function history(int $supplierId, int $limit = 100): array
    {
        return $this->repo->history($supplierId, $limit);
    }

    public function find(int $supplierId, int $id): ?array
    {
        return $this->repo->findTask($supplierId, $id);
    }

    /** @return array<string,mixed> */
    public function createTask(int $supplierId, string $sourceType, string $sourceId, ?int $userId): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '' || mb_strlen($sourceId) > 191) {
            throw new StockException('fulfillment_source_invalid', 'Zdroj vychystání je povinný.', 422);
        }
        $provider = $this->sources->resolve($sourceType);

        $taskId = $this->transaction(function () use ($supplierId, $sourceType, $sourceId, $userId, $provider): int {
            $source = $provider->loadForClaim($supplierId, $sourceId);
            $pairs = [];
            $requested = [];
            foreach ($source['lines'] as $line) {
                $key = (int) $line['warehouse_id'] . ':' . (int) $line['stock_item_id'];
                $pairs[$key] = ['warehouse_id' => (int) $line['warehouse_id'], 'stock_item_id' => (int) $line['stock_item_id']];
                $requested[$key] = ($requested[$key] ?? 0) + StockValuation::qtyToT((string) $line['expected_qty']);
            }
            $pairs = array_values($pairs);
            usort($pairs, static fn (array $a, array $b): int => [$a['warehouse_id'], $a['stock_item_id']] <=> [$b['warehouse_id'], $b['stock_item_id']]);
            foreach (array_unique(array_column($pairs, 'warehouse_id')) as $warehouseId) {
                $warehouse = $this->warehouses->find($supplierId, (int) $warehouseId);
                if ($warehouse === null || empty($warehouse['is_active']) || empty($warehouse['is_sellable'])) {
                    throw new StockException('fulfillment_source_warehouse', 'Vychystávat lze jen z aktivního prodejného skladu.', 422);
                }
            }
            $this->warehouses->lockForStockOperation($supplierId, array_column($pairs, 'warehouse_id'));
            $this->levels->lockLevels($supplierId, $pairs);
            $allocated = $this->commitments->committedForPairs($supplierId, $pairs, $sourceType === 'sales_order' ? $sourceId : null);
            $shortages = [];
            foreach ($pairs as $pair) {
                $key = $pair['warehouse_id'] . ':' . $pair['stock_item_id'];
                $current = $this->levels->currentForUpdate($supplierId, $pair['warehouse_id'], $pair['stock_item_id']);
                $availableT = $current['qtyT'] - ($allocated[$key] ?? 0);
                if ($requested[$key] > $availableT) {
                    $shortages[] = [
                        'stock_item_id' => $pair['stock_item_id'],
                        'warehouse_id' => $pair['warehouse_id'],
                        'requested' => StockValuation::tToDecimal($requested[$key]),
                        'available_after_allocations' => StockValuation::tToDecimal($availableT),
                    ];
                }
            }
            if ($shortages !== []) {
                throw new StockException('fulfillment_insufficient_available', 'Pro vychystání není po odečtení alokací dostatek zásob.', 409, $shortages);
            }

            try {
                $id = $this->repo->createTask(
                    $supplierId,
                    $sourceType,
                    $sourceId,
                    $source['claimed_stock_document_id'],
                    $source['source_snapshot'],
                    $userId,
                );
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new StockException('fulfillment_source_claimed', 'Zdroj už převzala jiná vychystávací úloha.', 409);
                }
                throw $e;
            }
            foreach ($source['lines'] as $line) {
                $this->repo->addTaskLine($supplierId, $id, $line);
            }
            return $id;
        });

        return $this->requireTask($supplierId, $taskId);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function scan(int $supplierId, int $taskId, array $body, ?int $userId, bool $canOverride): array
    {
        $operationId = trim((string) ($body['client_operation_id'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $qtyT = StockValuation::qtyToT((string) ($body['quantity'] ?? '1'));
        $override = !empty($body['override']);
        $reason = trim((string) ($body['override_reason'] ?? ''));
        $targetLineId = (int) ($body['task_line_id'] ?? 0);
        if ($operationId === '' || strlen($operationId) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $operationId)) {
            throw new StockException('fulfillment_operation_invalid', 'client_operation_id je povinné a musí být stabilní.', 422);
        }
        if ($code === '' || $qtyT <= 0) {
            throw new StockException('fulfillment_scan_invalid', 'SKU/EAN a kladné množství jsou povinné.', 422);
        }
        if ($override && (!$canOverride || $reason === '')) {
            throw new StockException('fulfillment_override_forbidden', 'Odchylka vyžaduje oprávnění a uvedení důvodu.', 403);
        }
        $canonical = [
            'code' => mb_strtoupper($code),
            'quantity' => StockValuation::tToDecimal($qtyT),
            'override' => $override,
            'override_reason' => $reason,
            'task_line_id' => $targetLineId,
        ];
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $this->transaction(function () use ($supplierId, $taskId, $operationId, $canonical, $hash, $qtyT, $override, $targetLineId, $userId): array {
            $task = $this->requireOpenTask($supplierId, $taskId);
            $existing = $this->repo->scanOperation($supplierId, $taskId, $operationId);
            if ($existing !== null) {
                if (!hash_equals($existing['payload_hash'], $hash)) {
                    throw new StockException('fulfillment_operation_conflict', 'client_operation_id už bylo použito s jiným obsahem.', 409);
                }
                $result = $existing['result'];
                $result['replayed'] = true;
                return $result;
            }

            $lines = $this->repo->taskLines($supplierId, $taskId, true);
            $matches = array_values(array_filter($lines, static function (array $line) use ($canonical): bool {
                $snapshot = $line['component_snapshot'];
                $code = $canonical['code'];
                return mb_strtoupper((string) ($snapshot['sku'] ?? '')) === $code
                    || (($snapshot['ean'] ?? null) !== null && mb_strtoupper((string) $snapshot['ean']) === $code);
            }));
            $line = null;
            foreach ($matches as $candidate) {
                if (StockValuation::qtyToT($candidate['picked_qty']) < StockValuation::qtyToT($candidate['expected_qty'])) {
                    $line = $candidate;
                    break;
                }
            }
            if ($line === null && $override && $targetLineId > 0) {
                foreach ($lines as $candidate) {
                    if ((int) $candidate['id'] === $targetLineId) {
                        $line = $candidate;
                        break;
                    }
                }
            }
            if ($line === null) {
                throw new StockException('fulfillment_scan_unknown', 'Kód do této úlohy nepatří nebo už je plně vychystaný.', 409);
            }

            $newPickedT = StockValuation::qtyToT($line['picked_qty']) + $qtyT;
            $expectedT = StockValuation::qtyToT($line['expected_qty']);
            if ($newPickedT > $expectedT && !$override) {
                throw new StockException('fulfillment_scan_excess', 'Sken by překročil požadované množství.', 409, [
                    'task_line_id' => (int) $line['id'],
                    'remaining' => StockValuation::tToDecimal(max(0, $expectedT - StockValuation::qtyToT($line['picked_qty']))),
                ]);
            }
            $this->repo->setPickedQty($supplierId, (int) $line['id'], StockValuation::tToDecimal($newPickedT));
            $line['picked_qty'] = StockValuation::tToDecimal($newPickedT);
            $allPicked = true;
            foreach ($lines as $candidate) {
                $picked = (int) $candidate['id'] === (int) $line['id'] ? $newPickedT : StockValuation::qtyToT($candidate['picked_qty']);
                if ($picked < StockValuation::qtyToT($candidate['expected_qty'])) {
                    $allPicked = false;
                    break;
                }
            }
            if ($allPicked && (string) $task['status'] === 'picking') {
                $this->repo->setTaskStatus($supplierId, $taskId, 'packing');
            }
            $result = [
                'task_id' => $taskId,
                'task_line_id' => (int) $line['id'],
                'picked_qty' => $line['picked_qty'],
                'expected_qty' => (string) $line['expected_qty'],
                'status' => $allPicked && (string) $task['status'] === 'picking' ? 'packing' : (string) $task['status'],
                'override' => $override,
                'replayed' => false,
            ];
            $this->repo->addScanOperation($supplierId, $taskId, $operationId, $hash, $canonical, $result, $userId);
            return $result;
        });
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createShipment(int $supplierId, int $taskId, array $body, ?int $userId): array
    {
        $carrier = self::nullable($body['carrier'] ?? null, 100);
        $tracking = self::nullable($body['tracking_number'] ?? null, 150);
        if ($carrier === null || $tracking === null) {
            throw new StockException('fulfillment_tracking_required', 'Dopravce a tracking jsou povinné už při zabalení zásilky.', 422);
        }
        $items = array_values((array) ($body['items'] ?? []));
        if ($items === []) {
            throw new StockException('fulfillment_shipment_empty', 'Zásilka musí obsahovat alespoň jednu položku.', 422);
        }

        $shipmentId = $this->transaction(function () use ($supplierId, $taskId, $carrier, $tracking, $items, $userId): int {
            $this->requireOpenTask($supplierId, $taskId);
            $lines = $this->repo->taskLines($supplierId, $taskId, true);
            $byId = [];
            foreach ($lines as $line) {
                $byId[(int) $line['id']] = $line;
            }
            $packing = $this->repo->packingQtyByTaskLine($supplierId, $taskId);
            $normalized = [];
            $seen = [];
            $warehouseId = null;
            foreach ($items as $raw) {
                $lineId = (int) ($raw['task_line_id'] ?? 0);
                $line = $byId[$lineId] ?? null;
                if ($line === null) {
                    throw new StockException('fulfillment_line_not_found', 'Řádek vychystání nebyl nalezen.', 404);
                }
                if (isset($seen[$lineId])) {
                    throw new StockException('fulfillment_line_duplicate', 'Řádek vychystání může být v zásilce jen jednou.', 422);
                }
                $seen[$lineId] = true;
                $qtyT = StockValuation::qtyToT((string) ($raw['quantity'] ?? '0'));
                $freeT = StockValuation::qtyToT($line['picked_qty'])
                    - StockValuation::qtyToT($line['shipped_qty'])
                    - StockValuation::qtyToT($packing[$lineId] ?? '0');
                if ($qtyT <= 0 || $qtyT > $freeT) {
                    throw new StockException('fulfillment_shipment_excess', 'Do zásilky lze vložit jen dosud neexpedované vychystané množství.', 409, [
                        'task_line_id' => $lineId,
                        'available' => StockValuation::tToDecimal(max(0, $freeT)),
                    ]);
                }
                if ($warehouseId !== null && $warehouseId !== (int) $line['warehouse_id']) {
                    throw new StockException('fulfillment_shipment_warehouses', 'Jedna zásilka může vydávat jen z jednoho skladu.', 422);
                }
                $warehouseId = (int) $line['warehouse_id'];
                $trackingAllocations = self::trackingAllocations((array) ($raw['tracking_allocations'] ?? []), $qtyT);
                $normalized[] = [$lineId, StockValuation::tToDecimal($qtyT), $trackingAllocations];
            }
            $id = $this->repo->createShipment($supplierId, $taskId, $carrier, $tracking, $userId);
            foreach ($normalized as [$lineId, $qty, $allocations]) {
                $this->repo->addShipmentItem($supplierId, $id, $lineId, $qty, $allocations);
            }
            return $id;
        });

        return $this->requireShipment($supplierId, $shipmentId);
    }

    /** @return array<string,mixed> */
    public function dispatch(int $supplierId, int $shipmentId, ?int $userId): array
    {
        $taskId = $this->transaction(function () use ($supplierId, $shipmentId, $userId): int {
            $shipment = $this->repo->lockShipment($supplierId, $shipmentId);
            if ($shipment === null) {
                throw new StockException('fulfillment_shipment_not_found', 'Zásilka nebyla nalezena.', 404);
            }
            if ((string) $shipment['status'] === 'shipped') {
                return (int) $shipment['task_id'];
            }
            if ((string) $shipment['status'] !== 'packing') {
                throw new StockException('fulfillment_shipment_state', 'Expedovat lze jen rozpracovanou zásilku.', 409);
            }
            if (self::nullable($shipment['carrier'] ?? null, 100) === null || self::nullable($shipment['tracking_number'] ?? null, 150) === null) {
                throw new StockException('fulfillment_tracking_required', 'Před expedicí vyplňte dopravce i tracking.', 422);
            }
            $task = $this->requireOpenTask($supplierId, (int) $shipment['task_id']);
            $items = $this->repo->shipmentItems($supplierId, $shipmentId, true);
            if ($items === []) {
                throw new StockException('fulfillment_shipment_empty', 'Zásilka nemá žádné položky.', 422);
            }
            $warehouseId = (int) $items[0]['warehouse_id'];
            $docLines = [];
            foreach ($items as $index => $item) {
                if ((int) $item['warehouse_id'] !== $warehouseId) {
                    throw new StockException('fulfillment_shipment_warehouses', 'Jedna zásilka může vydávat jen z jednoho skladu.', 422);
                }
                $docLines[] = [
                    'stock_item_id' => (int) $item['stock_item_id'],
                    'qty' => (string) $item['qty'],
                    'line_no' => $index,
                    'note' => 'Fulfillment shipment #' . $shipmentId,
                    'invoice_item_id' => $item['component_snapshot']['invoice_item_id'] ?? null,
                    'tracking_allocations' => $item['tracking_snapshot'],
                ];
            }
            $draft = $this->documents->create($supplierId, [
                'doc_type' => 'issue',
                'origin' => 'manual',
                'warehouse_id' => $warehouseId,
                'doc_date' => date('Y-m-d'),
                'description' => 'Expedice zásilky #' . $shipmentId,
                'invoice_id' => $task['source_snapshot']['invoice_id'] ?? null,
                'lines' => $docLines,
            ], $userId, false);
            $posted = $this->documents->postFulfillmentShipment($supplierId, (int) $draft['id'], $shipmentId, $userId);
            $postedLines = array_values((array) ($posted['lines'] ?? []));
            foreach ($items as $index => $item) {
                $postedLine = $postedLines[$index] ?? null;
                if ($postedLine === null) {
                    throw new \RuntimeException('Zaúčtovaná výdejka nemá očekávaný řádek.');
                }
                $canonicalTracking = $this->tracking->canonicalAllocationsForLine($supplierId, (int) $postedLine['id'], 'out');
                $this->repo->updateShipmentTrackingSnapshot($supplierId, (int) $item['id'], $canonicalTracking);
                $this->repo->completeShipmentItem($supplierId, (int) $item['id'], (int) $postedLine['id'], (string) $postedLine['unit_cost']);
                $this->repo->addTaskLineShipped($supplierId, (int) $item['task_line_id'], (string) $item['qty']);
            }
            $this->repo->markShipmentShipped($supplierId, $shipmentId, (int) $posted['id']);

            $this->sources->consumeShipment(
                $supplierId,
                (string) $task['source_type'],
                (string) $task['source_id'],
                $shipmentId,
                array_map(static fn (array $item): array => [
                    'source_line_id' => (string) $item['component_snapshot']['source_line_id'],
                    'quantity' => (string) $item['qty'],
                ], $items),
            );

            $allShipped = true;
            $taskLines = $this->repo->taskLines($supplierId, (int) $task['id'], true);
            foreach ($taskLines as $line) {
                if (StockValuation::qtyToT($line['shipped_qty']) < StockValuation::qtyToT($line['expected_qty'])) {
                    $allShipped = false;
                }
            }
            $this->repo->setTaskStatus($supplierId, (int) $task['id'], $allShipped ? 'shipped' : 'partially_shipped');
            return (int) $task['id'];
        });
        return $this->requireTask($supplierId, $taskId);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function receiveReturn(int $supplierId, int $shipmentId, array $body, ?int $userId): array
    {
        $disposition = (string) ($body['disposition'] ?? '');
        if (!in_array($disposition, self::DISPOSITIONS, true)) {
            throw new StockException('fulfillment_return_invalid', 'Neplatná dispozice vratky.', 422);
        }
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $note = self::nullable($body['note'] ?? null, 500);
        $rawItems = array_values((array) ($body['items'] ?? []));
        if ($rawItems === []) {
            throw new StockException('fulfillment_return_empty', 'Vratka musí obsahovat alespoň jednu položku.', 422);
        }

        $returnId = $this->transaction(function () use ($supplierId, $shipmentId, $disposition, $warehouseId, $note, $rawItems, $userId): int {
            $shipment = $this->repo->lockShipment($supplierId, $shipmentId);
            if ($shipment === null || (string) $shipment['status'] !== 'shipped') {
                throw new StockException('fulfillment_shipment_not_shipped', 'Vratku lze přijmout jen k expedované zásilce.', 409);
            }
            $warehouse = null;
            if ($disposition !== 'scrap') {
                if ($warehouseId <= 0 || ($warehouse = $this->warehouses->find($supplierId, $warehouseId)) === null || empty($warehouse['is_active'])) {
                    throw new StockException('fulfillment_return_warehouse', 'Pro vratku zvolte aktivní sklad této firmy.', 422);
                }
                $shouldSell = $disposition === 'sellable';
                if ((bool) $warehouse['is_sellable'] !== $shouldSell) {
                    throw new StockException('fulfillment_return_warehouse_kind', $shouldSell
                        ? 'Prodejnou vratku lze přijmout jen do prodejného skladu.'
                        : 'Karanténní vratku lze přijmout jen do neprodejného skladu.', 422);
                }
            } elseif ($warehouseId > 0) {
                throw new StockException('fulfillment_return_warehouse', 'Vyřazení nevytváří skladový příjem a sklad se u něj nevyplňuje.', 422);
            }

            $shipmentItems = $this->repo->shipmentItems($supplierId, $shipmentId, true);
            $byId = [];
            foreach ($shipmentItems as $item) {
                $byId[(int) $item['id']] = $item;
            }
            $normalized = [];
            $seen = [];
            foreach ($rawItems as $raw) {
                $shipmentItemId = (int) ($raw['shipment_item_id'] ?? 0);
                $item = $byId[$shipmentItemId] ?? null;
                if ($item === null) {
                    throw new StockException('fulfillment_return_item_not_found', 'Položka nepatří do zásilky.', 404);
                }
                if (isset($seen[$shipmentItemId])) {
                    throw new StockException('fulfillment_return_item_duplicate', 'Položka zásilky může být ve vratce jen jednou.', 422);
                }
                $seen[$shipmentItemId] = true;
                $qtyT = StockValuation::qtyToT((string) ($raw['quantity'] ?? '0'));
                $remainingT = StockValuation::qtyToT($item['qty']) - StockValuation::qtyToT($item['returned_qty']);
                if ($qtyT <= 0 || $qtyT > $remainingT) {
                    throw new StockException('fulfillment_return_excess', 'Vrácené množství překračuje historicky expedované množství.', 409, [
                        'shipment_item_id' => $shipmentItemId,
                        'remaining' => StockValuation::tToDecimal(max(0, $remainingT)),
                    ]);
                }
                $tracking = $this->trackingForReturn($supplierId, $item, $raw, $qtyT);
                $snapshot = [
                    'component' => $item['component_snapshot'],
                    'tracking_allocations' => $tracking,
                    'issue_document_line_id' => $item['issue_document_line_id'],
                    'unit_cost' => $item['unit_cost_snapshot'],
                    'disposition' => $disposition,
                ];
                $normalized[] = [$item, StockValuation::tToDecimal($qtyT), $tracking, $snapshot];
            }
            $id = $this->repo->createReturn($supplierId, $shipmentId, $disposition, $disposition === 'scrap' ? null : $warehouseId, $note, $userId);
            foreach ($normalized as [$item, $qty, $tracking, $snapshot]) {
                $this->repo->addReturnItem($supplierId, $id, (int) $item['id'], $qty, $snapshot);
            }

            if ($disposition !== 'scrap') {
                $task = $this->requireTask($supplierId, (int) $shipment['task_id']);
                $docLines = [];
                foreach ($normalized as $index => [$item, $qty, $tracking]) {
                    $docLines[] = [
                        'stock_item_id' => (int) $item['stock_item_id'],
                        'qty' => $qty,
                        'unit_cost' => (string) ($item['unit_cost_snapshot'] ?? '0'),
                        'line_no' => $index,
                        'note' => 'Fulfillment return #' . $id . ' (' . $disposition . ')',
                        'invoice_item_id' => $item['component_snapshot']['invoice_item_id'] ?? null,
                        'tracking_allocations' => $tracking,
                    ];
                }
                $draft = $this->documents->create($supplierId, [
                    'doc_type' => 'receipt',
                    'origin' => 'manual',
                    'warehouse_id' => $warehouseId,
                    'doc_date' => date('Y-m-d'),
                    'description' => 'Příjem vratky #' . $id,
                    'invoice_id' => $task['source_snapshot']['invoice_id'] ?? null,
                    'lines' => $docLines,
                ], $userId, false);
                $posted = $this->documents->post($supplierId, (int) $draft['id'], $userId);
                $this->repo->attachReturnDocument($supplierId, $id, (int) $posted['id']);
            }
            foreach ($normalized as [$item, $qty]) {
                $this->repo->addReturnedQty($supplierId, (int) $item['id'], (int) $item['task_line_id'], $qty);
            }
            return $id;
        });

        $task = $this->requireTask($supplierId, (int) $this->requireShipment($supplierId, $shipmentId)['task_id']);
        foreach ($task['shipments'] as $shipment) {
            if ((int) $shipment['id'] === $shipmentId) {
                foreach ($shipment['returns'] as $return) {
                    if ((int) $return['id'] === $returnId) {
                        return $return;
                    }
                }
            }
        }
        throw new \RuntimeException('Uložená vratka nebyla načtena.');
    }

    private function requireTask(int $supplierId, int $id): array
    {
        return $this->repo->findTask($supplierId, $id)
            ?? throw new StockException('fulfillment_task_not_found', 'Vychystávací úloha nebyla nalezena.', 404);
    }

    private function requireOpenTask(int $supplierId, int $id): array
    {
        $task = $this->repo->lockTask($supplierId, $id);
        if ($task === null) {
            throw new StockException('fulfillment_task_not_found', 'Vychystávací úloha nebyla nalezena.', 404);
        }
        if (!in_array((string) $task['status'], self::TASK_OPEN, true)) {
            throw new StockException('fulfillment_task_closed', 'Vychystávací úloha už není otevřená.', 409);
        }
        return $task;
    }

    private function requireShipment(int $supplierId, int $id): array
    {
        $shipment = $this->repo->findShipment($supplierId, $id);
        if ($shipment === null) {
            throw new StockException('fulfillment_shipment_not_found', 'Zásilka nebyla nalezena.', 404);
        }
        $shipment['items'] = $this->repo->shipmentItems($supplierId, $id);
        return $shipment;
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $raw @return list<array<string,mixed>> */
    private function trackingForReturn(int $supplierId, array $item, array $raw, int $qtyT): array
    {
        $origin = array_values((array) $item['tracking_snapshot']);
        $hasInput = array_key_exists('tracking_allocations', $raw);
        $input = $hasInput && is_array($raw['tracking_allocations'])
            ? array_values($raw['tracking_allocations'])
            : [];
        if ($origin === []) {
            if ($input !== []) {
                throw new StockException('fulfillment_tracking_invalid', 'Sledovaná jednotka nepatří do původní zásilky.', 409);
            }
            return [];
        }
        if (!$hasInput || $input === []) {
            throw new StockException('fulfillment_tracking_required', 'U sledované položky vratky vyberte konkrétní sériová čísla nebo šarže.', 422);
        }

        $requested = self::trackingAllocations($input, $qtyT);
        [$originByKey, $aliases] = self::trackingOriginIndex($origin);
        $originT = array_sum(array_column($originByKey, 'quantityT'));
        if ($originT !== StockValuation::qtyToT((string) $item['qty'])) {
            throw new StockException('fulfillment_tracking_invalid', 'Historický tracking zásilky neodpovídá expedovanému množství.', 409);
        }
        $used = array_fill_keys(array_keys($originByKey), 0);
        foreach ($this->repo->returnedTrackingSnapshots($supplierId, (int) $item['id']) as $previousReturn) {
            $previousT = 0;
            foreach ($previousReturn['tracking_allocations'] as $previous) {
                $key = self::resolveTrackingIdentity($previous, $aliases);
                $allocationT = StockValuation::qtyToT((string) ($previous['quantity'] ?? '0'));
                $used[$key] += $allocationT;
                $previousT += $allocationT;
            }
            if ($previousT !== StockValuation::qtyToT($previousReturn['quantity'])) {
                throw new StockException('fulfillment_tracking_invalid', 'Historická vratka nemá úplnou identitu sledovaných jednotek.', 409);
            }
        }

        $requestedByKey = array_fill_keys(array_keys($originByKey), 0);
        $canonical = [];
        foreach ($requested as $allocation) {
            $key = self::resolveTrackingIdentity($allocation, $aliases);
            $requestedByKey[$key] += StockValuation::qtyToT((string) $allocation['quantity']);
            $identity = $originByKey[$key]['identity'];
            $canonical[] = [
                'stock_tracking_unit_id' => $identity['stock_tracking_unit_id'],
                'quantity' => (string) $allocation['quantity'],
                'serial_number' => $identity['serial_number'],
                'lot_code' => $identity['lot_code'],
                'expires_on' => $identity['expires_on'],
                'location_id' => $allocation['location_id'] ?? null,
            ];
        }
        foreach ($originByKey as $key => $entry) {
            if ($requestedByKey[$key] > $entry['quantityT'] - $used[$key]) {
                throw new StockException('fulfillment_tracking_excess', 'Vrácené množství sledované jednotky překračuje množství z původní zásilky.', 409, [
                    'identity' => $key,
                    'available' => StockValuation::tToDecimal(max(0, $entry['quantityT'] - $used[$key])),
                ]);
            }
        }
        return $canonical;
    }

    /** @return array{0:array<string,array{identity:array<string,mixed>,quantityT:int}>,1:array<string,list<string>>} */
    private static function trackingOriginIndex(array $rows): array
    {
        $byKey = [];
        $aliases = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new StockException('fulfillment_tracking_invalid', 'Historický tracking zásilky je neplatný.', 409);
            }
            $id = isset($row['stock_tracking_unit_id']) && (int) $row['stock_tracking_unit_id'] > 0
                ? (int) $row['stock_tracking_unit_id'] : null;
            $serial = self::nullable($row['serial_number'] ?? null, 184);
            $lot = self::nullable($row['lot_code'] ?? null, 100);
            $expires = self::nullable($row['expires_on'] ?? null, 10);
            $key = $id !== null ? 'id:' . $id
                : ($serial !== null ? 'serial:' . $serial
                    : ($lot !== null ? 'lot:' . $lot . '|' . ($expires ?? '') : ''));
            $quantityT = StockValuation::qtyToT((string) ($row['quantity'] ?? '0'));
            if ($key === '' || $quantityT <= 0) {
                throw new StockException('fulfillment_tracking_invalid', 'Historický tracking zásilky je neplatný.', 409);
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'identity' => [
                        'stock_tracking_unit_id' => $id,
                        'serial_number' => $serial,
                        'lot_code' => $lot,
                        'expires_on' => $expires,
                    ],
                    'quantityT' => 0,
                ];
            }
            $byKey[$key]['quantityT'] += $quantityT;
            foreach (array_filter([
                $id !== null ? 'id:' . $id : null,
                $serial !== null ? 'serial:' . $serial : null,
                $lot !== null ? 'lot:' . $lot . '|' . ($expires ?? '') : null,
            ]) as $alias) {
                $aliases[$alias] ??= [];
                if (!in_array($key, $aliases[$alias], true)) {
                    $aliases[$alias][] = $key;
                }
            }
        }
        return [$byKey, $aliases];
    }

    /** @param array<string,mixed> $row @param array<string,list<string>> $aliases */
    private static function resolveTrackingIdentity(array $row, array $aliases): string
    {
        $provided = [];
        if (isset($row['stock_tracking_unit_id']) && (int) $row['stock_tracking_unit_id'] > 0) {
            $provided[] = 'id:' . (int) $row['stock_tracking_unit_id'];
        }
        $serial = self::nullable($row['serial_number'] ?? null, 184);
        if ($serial !== null) {
            $provided[] = 'serial:' . $serial;
        }
        $lot = self::nullable($row['lot_code'] ?? null, 100);
        if ($lot !== null) {
            $expires = self::nullable($row['expires_on'] ?? null, 10);
            $provided[] = 'lot:' . $lot . '|' . ($expires ?? '');
        }
        if ($provided === []) {
            throw new StockException('fulfillment_tracking_invalid', 'Sledovaná jednotka vratky nemá identitu.', 422);
        }

        $matches = null;
        foreach ($provided as $alias) {
            $keys = $aliases[$alias] ?? [];
            $matches = $matches === null ? $keys : array_values(array_intersect($matches, $keys));
        }
        if ($matches === null || count($matches) !== 1) {
            throw new StockException('fulfillment_tracking_invalid', 'Sledovaná jednotka nepatří do původní zásilky nebo její aliasy nesouhlasí.', 409);
        }
        return $matches[0];
    }

    /** @return list<array<string,mixed>> */
    private static function trackingAllocations(array $rows, int $qtyT): array
    {
        if ($rows === []) {
            return [];
        }
        $out = [];
        $sumT = 0;
        foreach ($rows as $row) {
            $allocationQtyT = StockValuation::qtyToT((string) ($row['quantity'] ?? '0'));
            if ($allocationQtyT <= 0) {
                throw new StockException('fulfillment_tracking_invalid', 'Množství sledované jednotky musí být kladné.', 422);
            }
            $normalized = ['quantity' => StockValuation::tToDecimal($allocationQtyT)];
            foreach (['stock_tracking_unit_id', 'location_id'] as $key) {
                if (isset($row[$key]) && (int) $row[$key] > 0) {
                    $normalized[$key] = (int) $row[$key];
                }
            }
            foreach (['serial_number', 'lot_code', 'expires_on'] as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $normalized[$key] = trim((string) $row[$key]);
                }
            }
            $sumT += $allocationQtyT;
            $out[] = $normalized;
        }
        if ($sumT !== $qtyT) {
            throw new StockException('fulfillment_tracking_invalid', 'Součet sledovaných jednotek musí odpovídat množství řádku.', 422);
        }
        return $out;
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @template T @param callable():T $fn @return T */
    private function transaction(callable $fn)
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
