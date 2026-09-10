<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\IntegrationEventPublisher;
use PDO;

final class SalesOrderService
{
    private const COMMERCIAL = ['draft', 'confirmed', 'cancelled', 'completed'];
    private const PAYMENT = ['unpaid', 'authorized', 'partially_paid', 'paid', 'refunded', 'partially_refunded'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockLevelService $levels,
        private readonly SalesOrderLineExpander $lineExpander,
        private readonly StockCommitmentService $commitments,
        private readonly IntegrationEventPublisher $events,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int} */
    public function list(int $supplierId, array $filters, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $where = ['o.supplier_id = ?'];
        $args = [$supplierId];
        foreach (['commercial_status', 'payment_status', 'fulfillment_status'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where[] = 'o.' . $field . ' = ?';
                $args[] = (string) $filters[$field];
            }
        }
        if (!empty($filters['shortage'])) {
            $where[] = "o.fulfillment_status IN ('partially_reserved', 'partially_fulfilled')
                AND EXISTS (
                    SELECT 1
                      FROM sales_order_lines shortage_line
                      JOIN JSON_TABLE(shortage_line.component_snapshot, '$[*]' COLUMNS(
                               component_no FOR ORDINALITY,
                               required_qty DECIMAL(14,3) PATH '$.quantity'
                           )) shortage_component
                 LEFT JOIN sales_order_reservations shortage_reservation
                            ON shortage_reservation.order_line_id = shortage_line.id
                           AND shortage_reservation.supplier_id = shortage_line.supplier_id
                           AND shortage_reservation.component_no = shortage_component.component_no - 1
                     WHERE shortage_line.supplier_id = o.supplier_id
                       AND shortage_line.order_id = o.id
                  GROUP BY shortage_line.id, shortage_component.component_no, shortage_component.required_qty
                    HAVING COALESCE(SUM(shortage_reservation.qty_reserved), 0) < shortage_component.required_qty
                )";
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(o.order_number LIKE ? OR c.company_name LIKE ? OR o.external_id LIKE ?)';
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
            array_push($args, $needle, $needle, $needle);
        }
        $predicate = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM sales_orders o JOIN clients c ON c.id = o.client_id AND c.supplier_id = o.supplier_id WHERE ' . $predicate
        );
        $count->execute($args);
        $stmt = $this->db->pdo()->prepare(
            'SELECT o.*, c.company_name AS client_name, i.invoice_id,
                    COALESCE(r.reserved_qty, 0) AS reserved_qty,
                    COALESCE(r.consumed_qty, 0) AS consumed_qty,
                    COALESCE(r.remaining_qty, 0) AS reservation_remaining_qty
               FROM sales_orders o
               JOIN clients c ON c.id = o.client_id AND c.supplier_id = o.supplier_id
          LEFT JOIN sales_order_invoice_links i ON i.order_id = o.id AND i.supplier_id = o.supplier_id
          LEFT JOIN (
                SELECT order_id, SUM(qty_reserved) reserved_qty, SUM(qty_consumed) consumed_qty,
                       SUM(GREATEST(qty_reserved - qty_consumed - qty_released, 0)) remaining_qty
                  FROM sales_order_reservations WHERE supplier_id = ? GROUP BY order_id
          ) r ON r.order_id = o.id
              WHERE ' . $predicate . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute([$supplierId, ...$args]);

        return [
            'items' => array_map($this->presentHeader(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => (int) $count->fetchColumn(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function detail(int $supplierId, int|string $id): ?array
    {
        $where = is_int($id) || ctype_digit((string) $id) ? 'o.id = ?' : 'o.order_uuid = ?';
        $stmt = $this->db->pdo()->prepare(
            'SELECT o.*, c.company_name AS client_name, l.invoice_id, ft.id AS fulfillment_task_id
               FROM sales_orders o
               JOIN clients c ON c.id = o.client_id AND c.supplier_id = o.supplier_id
          LEFT JOIN sales_order_invoice_links l ON l.order_id = o.id AND l.supplier_id = o.supplier_id
          LEFT JOIN fulfillment_tasks ft ON ft.supplier_id = o.supplier_id AND ft.source_type = "sales_order" AND ft.source_id = o.order_uuid
              WHERE o.supplier_id = ? AND ' . $where
        );
        $stmt->execute([$supplierId, $id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) return null;

        $lines = $this->db->pdo()->prepare('SELECT * FROM sales_order_lines WHERE supplier_id = ? AND order_id = ? ORDER BY line_no, id');
        $lines->execute([$supplierId, $order['id']]);
        $reservations = $this->db->pdo()->prepare(
            'SELECT r.*, l.line_uuid FROM sales_order_reservations r
              JOIN sales_order_lines l ON l.id = r.order_line_id AND l.supplier_id = r.supplier_id
             WHERE r.supplier_id = ? AND r.order_id = ? ORDER BY l.line_no, r.component_no'
        );
        $reservations->execute([$supplierId, $order['id']]);
        $returns = $this->db->pdo()->prepare('SELECT * FROM sales_order_returns WHERE supplier_id = ? AND order_id = ? ORDER BY id');
        $returns->execute([$supplierId, $order['id']]);

        return $this->presentHeader($order) + [
            'lines' => array_map($this->presentLine(...), $lines->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'reservations' => array_map($this->presentReservation(...), $reservations->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'returns' => array_map($this->presentReturn(...), $returns->fetchAll(PDO::FETCH_ASSOC) ?: []),
        ];
    }

    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $normalized = $this->normalizeDraft($supplierId, $data);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($normalized['external_source'] !== null && $normalized['external_id'] !== null) {
                $existing = $pdo->prepare('SELECT id FROM sales_orders WHERE supplier_id = ? AND external_source = ? AND external_id = ? FOR UPDATE');
                $existing->execute([$supplierId, $normalized['external_source'], $normalized['external_id']]);
                $existingId = $existing->fetchColumn();
                if ($existingId !== false) {
                    $pdo->commit();
                    return $this->detail($supplierId, (int) $existingId) ?? throw new \LogicException('Objednávka zmizela.');
                }
            }

            $uuid = self::uuid();
            $number = trim((string) ($data['order_number'] ?? ''));
            if ($number === '') $number = 'SO-' . date('Ymd') . '-' . strtoupper(substr(str_replace('-', '', $uuid), 0, 8));
            $stmt = $pdo->prepare(
                'INSERT INTO sales_orders
                    (order_uuid, supplier_id, client_id, order_number, external_source, external_id,
                     allocation_policy, currency_id, currency_code, exchange_rate, prices_include_vat,
                     customer_snapshot, shipping_snapshot, discount_snapshot, total_without_vat, total_vat,
                     total_with_vat, reservation_expires_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $uuid, $supplierId, $normalized['client_id'], $number,
                $normalized['external_source'], $normalized['external_id'], $normalized['allocation_policy'],
                $normalized['currency_id'], $normalized['currency_code'], $normalized['exchange_rate'],
                (int) $normalized['prices_include_vat'], self::json($normalized['customer_snapshot']),
                self::json($normalized['shipping_snapshot']), self::json($normalized['discount_snapshot']),
                $normalized['total_without_vat'], $normalized['total_vat'], $normalized['total_with_vat'],
                $normalized['reservation_expires_at'], $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->insertLines($supplierId, $id, $normalized['lines']);
            $this->publishOrderEvent($supplierId, $uuid, $id, 1, 'sales_order.created', [
                'commercial_status' => 'draft',
                'payment_status' => 'unpaid',
                'fulfillment_status' => 'unfulfilled',
            ]);
            $pdo->commit();
            return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka nevznikla.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $supplierId, int $id, int $expectedVersion, array $data): array
    {
        $normalized = $this->normalizeDraft($supplierId, $data);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $locked = $this->lockOrder($supplierId, $id);
            if ($locked['commercial_status'] !== 'draft') {
                throw new SalesOrderException('state_conflict', 'Potvrzenou objednávku už nelze přepsat.', 409);
            }
            $stmt = $pdo->prepare(
                'UPDATE sales_orders SET client_id = ?, allocation_policy = ?, currency_id = ?, currency_code = ?,
                    exchange_rate = ?, prices_include_vat = ?, customer_snapshot = ?, shipping_snapshot = ?,
                    discount_snapshot = ?, total_without_vat = ?, total_vat = ?, total_with_vat = ?,
                    reservation_expires_at = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ? AND commercial_status = "draft"'
            );
            $stmt->execute([
                $normalized['client_id'], $normalized['allocation_policy'], $normalized['currency_id'],
                $normalized['currency_code'], $normalized['exchange_rate'], (int) $normalized['prices_include_vat'],
                self::json($normalized['customer_snapshot']), self::json($normalized['shipping_snapshot']),
                self::json($normalized['discount_snapshot']), $normalized['total_without_vat'],
                $normalized['total_vat'], $normalized['total_with_vat'], $normalized['reservation_expires_at'],
                $supplierId, $id, $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) throw new SalesOrderException('version_conflict', 'Objednávku mezitím změnil jiný uživatel.', 409);
            $pdo->prepare('DELETE FROM sales_order_lines WHERE supplier_id = ? AND order_id = ?')->execute([$supplierId, $id]);
            $this->insertLines($supplierId, $id, $normalized['lines']);
            $this->publishOrderEvent($supplierId, (string) $locked['order_uuid'], $id, $expectedVersion + 1, 'sales_order.updated', [
                'commercial_status' => 'draft',
                'currency_code' => $normalized['currency_code'],
                'total_with_vat' => $normalized['total_with_vat'],
            ]);
            $pdo->commit();
            return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka zmizela.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function confirm(int $supplierId, int $id, string $idempotencyKey): array
    {
        $idempotencyKey = self::idempotencyKey($idempotencyKey);
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            if ($this->operationExists($supplierId, $id, 'confirm', $idempotencyKey)) {
                $pdo->commit();
                return $this->detail($supplierId, $id) ?? throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
            }
            $order = $this->lockOrder($supplierId, $id);
            if (!in_array($order['commercial_status'], ['draft', 'confirmed'], true)) {
                throw new SalesOrderException('state_conflict', 'Potvrdit lze jen rozpracovanou nebo částečně rezervovanou objednávku.', 409);
            }
            if ($order['commercial_status'] === 'confirmed'
                && !in_array($order['fulfillment_status'], ['partially_reserved', 'partially_fulfilled'], true)) {
                $this->rememberOperation($supplierId, $id, 'confirm', $idempotencyKey);
                $pdo->commit();
                return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka zmizela.');
            }

            $existingStmt = $pdo->prepare(
                'SELECT * FROM sales_order_reservations WHERE supplier_id = ? AND order_id = ? ORDER BY order_line_id, component_no FOR UPDATE'
            );
            $existingStmt->execute([$supplierId, $id]);
            $existing = [];
            $anyConsumed = false;
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reservation) {
                $existing[(int) $reservation['order_line_id'] . ':' . (int) $reservation['component_no']] = $reservation;
                $anyConsumed = $anyConsumed || StockValuation::qtyToT((string) $reservation['qty_consumed']) > 0;
            }

            $lines = $this->rawLines($supplierId, $id);
            $requests = [];
            foreach ($lines as $line) {
                foreach ($line['component_snapshot'] as $componentNo => $component) {
                    $warehouseId = (int) ($component['warehouse_id'] ?? $line['warehouse_id'] ?? 0);
                    $itemId = (int) ($component['stock_item_id'] ?? 0);
                    $requiredT = StockValuation::qtyToT(self::quantity((string) ($component['quantity'] ?? '0')));
                    if ($warehouseId < 1 || $itemId < 1) {
                        throw new SalesOrderException('reservation_target_missing', 'Skladová komponenta nemá určený sklad.', 422);
                    }
                    $reservation = $existing[(int) $line['id'] . ':' . $componentNo] ?? null;
                    $reservedT = $reservation !== null ? StockValuation::qtyToT((string) $reservation['qty_reserved']) : 0;
                    $missingT = max(0, $requiredT - $reservedT);
                    if ($missingT === 0) continue;
                    $requests[] = [
                        'line' => $line,
                        'component_no' => $componentNo,
                        'warehouse_id' => $warehouseId,
                        'stock_item_id' => $itemId,
                        'qty' => StockValuation::tToDecimal($missingT),
                        'snapshot' => $component,
                    ];
                }
            }
            $pairsByKey = [];
            foreach ($requests as $request) {
                $key = $request['warehouse_id'] . ':' . $request['stock_item_id'];
                $pairsByKey[$key] = ['warehouse_id' => $request['warehouse_id'], 'stock_item_id' => $request['stock_item_id']];
            }
            $pairs = array_values($pairsByKey);
            $levels = $this->levels->lockLevels($supplierId, $pairs);
            $reserved = $this->commitments->committedForPairs($supplierId, $pairs);
            $available = [];
            foreach ($levels as $key => $level) {
                $available[$key] = $level['qtyT'] - ($reserved[$key] ?? 0);
            }
            $shortages = [];
            $allocations = [];
            foreach ($requests as $request) {
                $key = $request['warehouse_id'] . ':' . $request['stock_item_id'];
                $requestedT = StockValuation::qtyToT($request['qty']);
                $allocatedT = min($requestedT, max(0, $available[$key] ?? 0));
                if ($allocatedT < $requestedT) {
                    $shortages[] = [
                        'line_uuid' => $request['line']['line_uuid'],
                        'stock_item_id' => $request['stock_item_id'],
                        'warehouse_id' => $request['warehouse_id'],
                        'requested' => $request['qty'],
                        'available' => StockValuation::tToDecimal(max(0, $available[$key] ?? 0)),
                    ];
                }
                if ($order['allocation_policy'] === 'all_or_nothing' && $allocatedT < $requestedT) continue;
                if ($allocatedT > 0) {
                    $allocations[] = $request + ['allocatedT' => $allocatedT];
                    $available[$key] -= $allocatedT;
                }
            }
            if ($shortages !== [] && $order['allocation_policy'] === 'all_or_nothing') {
                throw new SalesOrderException('insufficient_stock', 'Objednávku nelze celou rezervovat.', 409, $shortages);
            }

            $insert = $pdo->prepare(
                'INSERT INTO sales_order_reservations
                    (supplier_id, order_id, order_line_id, component_no, warehouse_id, stock_item_id, qty_reserved, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE qty_reserved = qty_reserved + VALUES(qty_reserved), status = "active", expires_at = VALUES(expires_at)'
            );
            foreach ($allocations as $allocation) {
                $insert->execute([
                    $supplierId, $id, $allocation['line']['id'], $allocation['component_no'],
                    $allocation['warehouse_id'], $allocation['stock_item_id'],
                    StockValuation::tToDecimal($allocation['allocatedT']), $order['reservation_expires_at'],
                ]);
            }

            $isDraft = $order['commercial_status'] === 'draft';
            if (!$isDraft && $allocations === []) {
                $this->rememberOperation($supplierId, $id, 'confirm', $idempotencyKey);
                $pdo->commit();
                return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka zmizela.');
            }
            $fulfillment = $anyConsumed
                ? 'partially_fulfilled'
                : ($shortages === [] ? 'reserved' : 'partially_reserved');
            $update = $pdo->prepare(
                'UPDATE sales_orders SET commercial_status = "confirmed", fulfillment_status = ?,
                    confirmed_at = COALESCE(confirmed_at, NOW()), row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND commercial_status = ? AND row_version = ?'
            );
            $update->execute([$fulfillment, $supplierId, $id, $order['commercial_status'], $order['row_version']]);
            if ($update->rowCount() !== 1) throw new SalesOrderException('version_conflict', 'Objednávku mezitím změnil jiný proces.', 409);
            $this->syncFulfillmentTask($supplierId, (string) $order['order_uuid'], $allocations);
            $this->rememberOperation($supplierId, $id, 'confirm', $idempotencyKey);
            $reservations = array_map(static fn (array $allocation): array => [
                'source_line_id' => (string) $allocation['line']['line_uuid'] . '#' . $allocation['component_no'],
                'stock_item_id' => $allocation['stock_item_id'],
                'warehouse_id' => $allocation['warehouse_id'],
                'quantity' => StockValuation::tToDecimal($allocation['allocatedT']),
            ], $allocations);
            $this->catalogReservationsChanged($supplierId, $allocations);
            $this->publishOrderEvent(
                $supplierId,
                (string) $order['order_uuid'],
                $id,
                (int) $order['row_version'] + 1,
                $isDraft ? 'sales_order.confirmed' : 'sales_order.reservation_extended',
                ['fulfillment_status' => $fulfillment, 'reservations' => $reservations],
                self::integrationOperationKey((string) $order['order_uuid'], 'confirm', $idempotencyKey),
            );
            $pdo->commit();
            return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka zmizela.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function cancel(int $supplierId, int $id, string $idempotencyKey): array
    {
        return $this->releaseAndClose($supplierId, $id, $idempotencyKey, false);
    }

    public function expire(int $supplierId, int $id): array
    {
        return $this->releaseAndClose($supplierId, $id, 'expiry:' . $id, true);
    }

    public function setPaymentStatus(int $supplierId, int $id, string $status, int $expectedVersion): array
    {
        if (!in_array($status, self::PAYMENT, true)) throw new SalesOrderException('payment_status_invalid', 'Neplatný platební stav.');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE sales_orders SET payment_status = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([$status, $supplierId, $id, $expectedVersion]);
            if ($stmt->rowCount() !== 1) throw new SalesOrderException('version_conflict', 'Objednávku mezitím změnil jiný uživatel.', 409);
            $order = $this->lockOrder($supplierId, $id);
            $this->publishOrderEvent($supplierId, (string) $order['order_uuid'], $id, (int) $order['row_version'], 'sales_order.payment_status_changed', [
                'payment_status' => $status,
            ]);
            $pdo->commit();
            return $this->detail($supplierId, $id) ?? throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function createReturn(int $supplierId, int $id, array $data, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $header = $this->lockOrder($supplierId, $id);
            $order = $this->detail($supplierId, $id) ?? throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
            $commercial = (string) ($data['commercial_resolution'] ?? 'none');
            $physical = (string) ($data['physical_resolution'] ?? 'none');
            if (!in_array($commercial, ['none', 'credit_note', 'refund'], true)
                || !in_array($physical, ['none', 'return_to_stock'], true)) {
                throw new SalesOrderException('return_resolution_invalid', 'Neplatný způsob vyřízení vratky.');
            }
            $lines = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
            if ($lines === []) throw new SalesOrderException('return_lines_required', 'Vratka musí obsahovat alespoň jeden řádek.');
            $known = array_column($order['lines'], null, 'line_uuid');
            foreach ($lines as &$line) {
                $uuid = (string) ($line['line_uuid'] ?? '');
                if (!isset($known[$uuid])) throw new SalesOrderException('return_line_invalid', 'Řádek vratky nepatří objednávce.');
                $line = ['line_uuid' => $uuid, 'quantity' => self::quantity((string) ($line['quantity'] ?? '0'))];
            }
            unset($line);
            $uuid = self::uuid();
            $stmt = $pdo->prepare(
                'INSERT INTO sales_order_returns
                    (return_uuid, supplier_id, order_id, commercial_resolution, physical_resolution, lines_json, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uuid, $supplierId, $id, $commercial, $physical, self::json($lines), $userId]);
            $pdo->prepare('UPDATE sales_orders SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $id]);
            $version = (int) $header['row_version'] + 1;
            $this->publishOrderEvent($supplierId, (string) $header['order_uuid'], $id, $version, 'sales_order.return_requested', [
                'return_uuid' => $uuid,
                'commercial_resolution' => $commercial,
                'physical_resolution' => $physical,
                'lines' => $lines,
            ]);
            $pdo->commit();
            return ['return_uuid' => $uuid, 'order_id' => $id, 'commercial_resolution' => $commercial, 'physical_resolution' => $physical, 'lines' => $lines, 'credit_note_id' => null, 'stock_document_id' => null];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function shortageQueue(int $supplierId, int $limit = 200): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.id, o.order_uuid, o.order_number, o.reservation_expires_at, l.line_uuid,
                    l.description, component.component_no - 1 AS component_no,
                    component.stock_item_id, component.required_qty AS quantity,
                    COALESCE(SUM(r.qty_reserved), 0) AS reserved_qty
               FROM sales_orders o
               JOIN sales_order_lines l ON l.order_id = o.id AND l.supplier_id = o.supplier_id
               JOIN JSON_TABLE(l.component_snapshot, '$[*]' COLUMNS(
                        component_no FOR ORDINALITY,
                        stock_item_id BIGINT PATH '$.stock_item_id',
                        required_qty DECIMAL(14,3) PATH '$.quantity'
                    )) component
          LEFT JOIN sales_order_reservations r ON r.order_line_id = l.id
                    AND r.supplier_id = l.supplier_id
                    AND r.component_no = component.component_no - 1
              WHERE o.supplier_id = ? AND o.commercial_status = 'confirmed'
                AND o.fulfillment_status IN ('partially_reserved', 'partially_fulfilled')
              GROUP BY o.id, l.id, component.component_no, component.stock_item_id, component.required_qty
             HAVING reserved_qty < component.required_qty
              ORDER BY o.created_at, o.id, l.line_no, component.component_no LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<int> */
    public function expiredIds(int $supplierId, int $afterId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM sales_orders WHERE supplier_id = ? AND id > ?
               AND commercial_status = 'confirmed' AND reservation_expires_at IS NOT NULL
               AND reservation_expires_at <= NOW() ORDER BY id LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([$supplierId, $afterId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function releaseAndClose(int $supplierId, int $id, string $idempotencyKey, bool $expiry): array
    {
        $operation = $expiry ? 'expire' : 'cancel';
        $idempotencyKey = self::idempotencyKey($idempotencyKey);
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            if ($this->operationExists($supplierId, $id, $operation, $idempotencyKey)) {
                if ($ownTransaction) $pdo->commit();
                return $this->detail($supplierId, $id) ?? throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
            }
            $order = $this->lockOrder($supplierId, $id);
            if ($order['commercial_status'] === 'cancelled') {
                $this->rememberOperation($supplierId, $id, $operation, $idempotencyKey);
                if ($ownTransaction) $pdo->commit();
                return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka zmizela.');
            }
            if ($expiry && ($order['reservation_expires_at'] === null || strtotime((string) $order['reservation_expires_at']) > time())) {
                throw new SalesOrderException('reservation_not_expired', 'Rezervace dosud nevypršela.', 409);
            }
            if (!in_array($order['commercial_status'], ['draft', 'confirmed'], true)) {
                throw new SalesOrderException('state_conflict', 'Objednávku v tomto stavu nelze zrušit.', 409);
            }
            $reservations = $pdo->prepare(
                "SELECT r.*, l.line_uuid FROM sales_order_reservations r
                   JOIN sales_order_lines l ON l.id = r.order_line_id AND l.supplier_id = r.supplier_id
                  WHERE r.supplier_id = ? AND r.order_id = ? AND r.status = 'active'
                  ORDER BY r.warehouse_id, r.stock_item_id, r.id FOR UPDATE"
            );
            $reservations->execute([$supplierId, $id]);
            $rows = $reservations->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $this->levels->lockLevels($supplierId, array_map(static fn (array $r): array => ['warehouse_id' => (int) $r['warehouse_id'], 'stock_item_id' => (int) $r['stock_item_id']], $rows));
            $release = $pdo->prepare(
                "UPDATE sales_order_reservations
                    SET qty_released = qty_reserved - qty_consumed, status = IF(qty_consumed > 0, 'consumed', 'released')
                  WHERE supplier_id = ? AND id = ? AND status = 'active'"
            );
            $released = [];
            foreach ($rows as $row) {
                $remaining = bcsub(bcsub((string) $row['qty_reserved'], (string) $row['qty_consumed'], 3), (string) $row['qty_released'], 3);
                $release->execute([$supplierId, $row['id']]);
                if (bccomp($remaining, '0', 3) > 0) {
                    $released[] = [
                        'source_line_id' => (string) $row['line_uuid'] . '#' . $row['component_no'],
                        'stock_item_id' => (int) $row['stock_item_id'],
                        'warehouse_id' => (int) $row['warehouse_id'],
                        'quantity' => $remaining,
                    ];
                }
            }
            $pdo->prepare(
                'UPDATE sales_orders SET commercial_status = "cancelled", fulfillment_status = "cancelled", cancelled_at = NOW(), row_version = row_version + 1 WHERE supplier_id = ? AND id = ?'
            )->execute([$supplierId, $id]);
            $this->rememberOperation($supplierId, $id, $operation, $idempotencyKey);
            $this->catalogReservationsChanged($supplierId, $released);
            $this->publishOrderEvent(
                $supplierId,
                (string) $order['order_uuid'],
                $id,
                (int) $order['row_version'] + 1,
                $expiry ? 'sales_order.expired' : 'sales_order.cancelled',
                ['released_reservations' => $released],
                self::integrationOperationKey((string) $order['order_uuid'], $operation, $idempotencyKey),
            );
            if ($ownTransaction) $pdo->commit();
            return $this->detail($supplierId, $id) ?? throw new \LogicException('Objednávka zmizela.');
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function normalizeDraft(int $supplierId, array $data): array
    {
        $clientId = (int) ($data['client_id'] ?? 0);
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $client = $this->db->pdo()->prepare(
            'SELECT c.id, c.company_name, c.first_name, c.last_name, c.ic, c.dic, c.street, c.city, c.zip,
                    c.main_email, c.phone, c.language, co.iso2 AS country
               FROM clients c JOIN countries co ON co.id = c.country_id
              WHERE c.supplier_id = ? AND c.id = ? AND c.archived_at IS NULL'
        );
        $client->execute([$supplierId, $clientId]);
        $customer = $client->fetch(PDO::FETCH_ASSOC);
        if ($customer === false) throw new SalesOrderException('client_not_found', 'Odběratel neexistuje.', 404);
        $currency = $this->db->pdo()->prepare('SELECT id, code FROM currencies WHERE supplier_id = ? AND id = ? AND is_active = 1');
        $currency->execute([$supplierId, $currencyId]);
        $currencyRow = $currency->fetch(PDO::FETCH_ASSOC);
        if ($currencyRow === false) throw new SalesOrderException('currency_not_found', 'Měna neexistuje nebo není aktivní.', 404);
        $policy = (string) ($data['allocation_policy'] ?? 'all_or_nothing');
        if (!in_array($policy, ['all_or_nothing', 'partial'], true)) throw new SalesOrderException('allocation_policy_invalid', 'Neplatná politika rezervace.');
        $pricesIncludeVat = (bool) ($data['prices_include_vat'] ?? false);
        $rawLines = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
        if ($rawLines === []) throw new SalesOrderException('lines_required', 'Objednávka musí obsahovat alespoň jeden řádek.');
        if (count($rawLines) > 1000) throw new SalesOrderException('lines_limit', 'Objednávka má příliš mnoho řádků.');
        $lines = [];
        $totalNet = $totalVat = $totalGross = 0.0;
        foreach ($rawLines as $index => $raw) {
            if (!is_array($raw)) throw new SalesOrderException('line_invalid', 'Neplatný řádek objednávky.');
            $qty = self::quantity((string) ($raw['quantity'] ?? '0'));
            $unitPrice = self::money((string) ($raw['unit_price'] ?? '0'), 6);
            $discount = self::percent((string) ($raw['discount_percent'] ?? '0'));
            $warehouseId = isset($raw['warehouse_id']) ? (int) $raw['warehouse_id'] : 0;
            if ($warehouseId > 0) {
                $sellable = $this->db->hasColumn('warehouses', 'is_sellable') ? ' AND is_sellable = 1' : '';
                $warehouse = $this->db->pdo()->prepare('SELECT 1 FROM warehouses WHERE supplier_id = ? AND id = ? AND is_active = 1' . $sellable);
                $warehouse->execute([$supplierId, $warehouseId]);
                if ($warehouse->fetchColumn() === false) throw new SalesOrderException('warehouse_not_found', 'Sklad neexistuje nebo není aktivní.', 404);
            }
            $expanded = $this->lineExpander->expand($supplierId, $raw + ['quantity' => $qty], (string) $currencyRow['code']);
            if ($expanded['component_snapshot'] !== [] && $warehouseId < 1) throw new SalesOrderException('warehouse_required', 'U skladového řádku je povinný sklad.');
            $vatRateId = isset($raw['vat_rate_id']) ? (int) $raw['vat_rate_id'] : (int) ($expanded['product_snapshot']['vat_rate_id'] ?? 0);
            $vat = $this->db->pdo()->prepare('SELECT rate_percent FROM vat_rates WHERE id = ?');
            $vat->execute([$vatRateId]);
            $vatRate = $vat->fetchColumn();
            if ($vatRate === false) throw new SalesOrderException('vat_rate_not_found', 'Sazba DPH neexistuje.', 404);
            $base = round((float) $qty * (float) $unitPrice * (1 - (float) $discount / 100), 2);
            if ($pricesIncludeVat) {
                $gross = $base;
                $net = round($gross / (1 + (float) $vatRate / 100), 2);
                $tax = round($gross - $net, 2);
            } else {
                $net = $base;
                $tax = round($net * (float) $vatRate / 100, 2);
                $gross = round($net + $tax, 2);
            }
            $totalNet += $net; $totalVat += $tax; $totalGross += $gross;
            $lines[] = [
                'line_uuid' => isset($raw['line_uuid']) && self::validUuid((string) $raw['line_uuid']) ? (string) $raw['line_uuid'] : self::uuid(),
                'line_no' => $index + 1,
                'stock_item_id' => (int) ($raw['stock_item_id'] ?? 0) ?: null,
                'warehouse_id' => $warehouseId ?: null,
                'description' => trim((string) ($raw['description'] ?? $expanded['product_snapshot']['name'] ?? '')),
                'sku_snapshot' => (string) ($expanded['product_snapshot']['sku'] ?? ''),
                'unit' => trim((string) ($raw['unit'] ?? $expanded['product_snapshot']['unit'] ?? 'ks')) ?: 'ks',
                'quantity' => $qty, 'unit_price' => $unitPrice, 'discount_percent' => $discount,
                'vat_rate_id' => $vatRateId, 'vat_rate_snapshot' => number_format((float) $vatRate, 2, '.', ''),
                'total_without_vat' => number_format($net, 2, '.', ''), 'total_vat' => number_format($tax, 2, '.', ''),
                'total_with_vat' => number_format($gross, 2, '.', ''),
                'product_snapshot' => $expanded['product_snapshot'], 'component_snapshot' => $expanded['component_snapshot'],
            ];
            if ($lines[array_key_last($lines)]['description'] === '') throw new SalesOrderException('description_required', 'Popis řádku je povinný.');
        }
        $expires = trim((string) ($data['reservation_expires_at'] ?? ''));
        if ($expires !== '' && strtotime($expires) === false) throw new SalesOrderException('expiry_invalid', 'Neplatná expirace rezervace.');
        return [
            'client_id' => $clientId, 'currency_id' => $currencyId, 'currency_code' => (string) $currencyRow['code'],
            'exchange_rate' => isset($data['exchange_rate']) ? self::money((string) $data['exchange_rate'], 8) : null,
            'prices_include_vat' => $pricesIncludeVat, 'allocation_policy' => $policy,
            'external_source' => self::nullableText($data['external_source'] ?? null, 50),
            'external_id' => self::nullableText($data['external_id'] ?? null, 191),
            'customer_snapshot' => $customer,
            'shipping_snapshot' => is_array($data['shipping_snapshot'] ?? null) ? $data['shipping_snapshot'] : [],
            'discount_snapshot' => is_array($data['discount_snapshot'] ?? null) ? $data['discount_snapshot'] : [],
            'total_without_vat' => number_format($totalNet, 2, '.', ''), 'total_vat' => number_format($totalVat, 2, '.', ''),
            'total_with_vat' => number_format($totalGross, 2, '.', ''),
            'reservation_expires_at' => $expires !== '' ? date('Y-m-d H:i:s', strtotime($expires)) : null,
            'lines' => $lines,
        ];
    }

    private function insertLines(int $supplierId, int $orderId, array $lines): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO sales_order_lines
                (line_uuid, order_id, supplier_id, line_no, stock_item_id, warehouse_id, description,
                 sku_snapshot, unit, quantity, unit_price, discount_percent, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, product_snapshot, component_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $stmt->execute([
                $line['line_uuid'], $orderId, $supplierId, $line['line_no'], $line['stock_item_id'], $line['warehouse_id'],
                $line['description'], $line['sku_snapshot'] ?: null, $line['unit'], $line['quantity'], $line['unit_price'],
                $line['discount_percent'], $line['vat_rate_id'], $line['vat_rate_snapshot'], $line['total_without_vat'],
                $line['total_vat'], $line['total_with_vat'], self::json($line['product_snapshot']), self::json($line['component_snapshot']),
            ]);
        }
    }

    private function syncFulfillmentTask(int $supplierId, string $orderUuid, array $allocations): void
    {
        if ($allocations === []) return;
        $pdo = $this->db->pdo();
        $taskStmt = $pdo->prepare(
            'SELECT id, status FROM fulfillment_tasks WHERE supplier_id = ? AND source_type = "sales_order" AND source_id = ? FOR UPDATE'
        );
        $taskStmt->execute([$supplierId, $orderUuid]);
        $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
        if ($task === false || $task['status'] === 'cancelled') return;

        $reservationStmt = $pdo->prepare(
            'SELECT qty_reserved, qty_released FROM sales_order_reservations
              WHERE supplier_id = ? AND order_id = ? AND order_line_id = ? AND component_no = ?'
        );
        $upsert = $pdo->prepare(
            'INSERT INTO fulfillment_task_lines
                (task_id, supplier_id, source_line_id, stock_item_id, warehouse_id, component_snapshot, expected_qty)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE expected_qty = GREATEST(expected_qty, VALUES(expected_qty))'
        );
        foreach ($allocations as $allocation) {
            $reservationStmt->execute([
                $supplierId,
                $allocation['line']['order_id'],
                $allocation['line']['id'],
                $allocation['component_no'],
            ]);
            $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);
            if ($reservation === false) continue;
            $expectedT = max(
                0,
                StockValuation::qtyToT((string) $reservation['qty_reserved'])
                    - StockValuation::qtyToT((string) $reservation['qty_released']),
            );
            $sourceLineId = (string) $allocation['line']['line_uuid'] . '#' . $allocation['component_no'];
            $componentSnapshot = (array) $allocation['snapshot'] + [
                'source_line_id' => $sourceLineId,
                'sales_order_line_uuid' => (string) $allocation['line']['line_uuid'],
                'description' => (string) $allocation['line']['description'],
                'sku' => (string) ($allocation['line']['sku_snapshot'] ?? ''),
            ];
            $upsert->execute([
                $task['id'],
                $supplierId,
                $sourceLineId,
                $allocation['stock_item_id'],
                $allocation['warehouse_id'],
                self::json($componentSnapshot),
                StockValuation::tToDecimal($expectedT),
            ]);
        }
        if ($task['status'] === 'shipped') {
            $pdo->prepare('UPDATE fulfillment_tasks SET status = "picking" WHERE supplier_id = ? AND id = ? AND status = "shipped"')
                ->execute([$supplierId, $task['id']]);
        }
    }

    private function lockOrder(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM sales_orders WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
        return $row;
    }

    private function rawLines(int $supplierId, int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM sales_order_lines WHERE supplier_id = ? AND order_id = ? ORDER BY line_no, id');
        $stmt->execute([$supplierId, $orderId]);
        return array_map($this->presentLine(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function operationExists(int $supplierId, int $orderId, string $operation, string $key): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT order_id FROM sales_order_operation_keys WHERE supplier_id = ? AND operation = ? AND idempotency_key = ?');
        $stmt->execute([$supplierId, $operation, $key]);
        $found = $stmt->fetchColumn();
        if ($found === false) return false;
        if ((int) $found !== $orderId) throw new SalesOrderException('idempotency_conflict', 'Idempotency key už patří jiné objednávce.', 409);
        return true;
    }

    private function rememberOperation(int $supplierId, int $orderId, string $operation, string $key): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO sales_order_operation_keys (supplier_id, order_id, operation, idempotency_key)
             VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE idempotency_key = VALUES(idempotency_key)'
        )->execute([$supplierId, $orderId, $operation, $key]);
        $owner = $pdo->prepare(
            'SELECT order_id FROM sales_order_operation_keys
              WHERE supplier_id = ? AND operation = ? AND idempotency_key = ? FOR UPDATE'
        );
        $owner->execute([$supplierId, $operation, $key]);
        if ((int) $owner->fetchColumn() !== $orderId) {
            throw new SalesOrderException('idempotency_conflict', 'Idempotency key už patří jiné objednávce.', 409);
        }
    }

    private function publishOrderEvent(
        int $supplierId,
        string $orderUuid,
        int $orderId,
        int $rowVersion,
        string $eventType,
        array $payload,
        ?string $idempotencyKey = null,
    ): void {
        $this->events->publish($supplierId, 'sales_order', $orderUuid, $eventType, $rowVersion, [
            'order_uuid' => $orderUuid,
            'order_id' => $orderId,
            'row_version' => $rowVersion,
        ] + $payload, $idempotencyKey);
    }

    private function catalogReservationsChanged(int $supplierId, array $reservations): void
    {
        $itemIds = [];
        foreach ($reservations as $reservation) {
            $itemId = (int) ($reservation['stock_item_id'] ?? 0);
            if ($itemId > 0) $itemIds[$itemId] = true;
        }
        foreach (array_keys($itemIds) as $itemId) {
            $this->events->catalogChanged($supplierId, $itemId, 'reservation');
        }
    }

    private static function integrationOperationKey(string $orderUuid, string $operation, string $key): string
    {
        return 'sales_order:' . $orderUuid . ':' . $operation . ':' . hash('sha256', $key);
    }

    private function presentHeader(array $row): array
    {
        foreach (['id', 'supplier_id', 'client_id', 'currency_id', 'row_version', 'invoice_id', 'fulfillment_task_id'] as $key) {
            if (array_key_exists($key, $row)) $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        $row['prices_include_vat'] = (bool) $row['prices_include_vat'];
        foreach (['customer_snapshot', 'shipping_snapshot', 'discount_snapshot'] as $key) {
            $row[$key] = json_decode((string) $row[$key], true, 512, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    private function presentLine(array $row): array
    {
        foreach (['id', 'order_id', 'supplier_id', 'line_no', 'stock_item_id', 'warehouse_id', 'vat_rate_id'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        $row['product_snapshot'] = json_decode((string) $row['product_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $row['component_snapshot'] = json_decode((string) $row['component_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function presentReservation(array $row): array
    {
        foreach (['id', 'supplier_id', 'order_id', 'order_line_id', 'component_no', 'warehouse_id', 'stock_item_id'] as $key) $row[$key] = (int) $row[$key];
        $row['remaining_qty'] = number_format(max(0, (float) $row['qty_reserved'] - (float) $row['qty_consumed'] - (float) $row['qty_released']), 3, '.', '');
        $row['source_line_id'] = $row['line_uuid'] . '#' . $row['component_no'];
        return $row;
    }

    private function presentReturn(array $row): array
    {
        foreach (['id', 'supplier_id', 'order_id', 'credit_note_id', 'stock_document_id', 'created_by'] as $key) $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        $row['lines'] = json_decode((string) $row['lines_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['lines_json']);
        return $row;
    }

    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    private static function validUuid(string $value): bool { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1; }
    private static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
    private static function idempotencyKey(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191) throw new SalesOrderException('idempotency_key_required', 'Je vyžadován platný idempotency key.');
        return $value;
    }
    private static function quantity(string $value): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/D', $value) || bccomp($value, '0', 3) <= 0) throw new SalesOrderException('quantity_invalid', 'Neplatné množství.');
        return number_format((float) $value, 3, '.', '');
    }
    private static function money(string $value, int $scale): string
    {
        if (!is_numeric($value) || bccomp($value, '0', $scale) < 0 || bccomp($value, '999999999999.99999999', $scale) > 0) throw new SalesOrderException('money_invalid', 'Neplatná peněžní částka.');
        return number_format((float) $value, $scale, '.', '');
    }
    private static function percent(string $value): string
    {
        if (!is_numeric($value) || bccomp($value, '0', 4) < 0 || bccomp($value, '100', 4) > 0) throw new SalesOrderException('discount_invalid', 'Neplatná sleva.');
        return number_format((float) $value, 4, '.', '');
    }
    private static function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;
        if (mb_strlen($value) > $limit) throw new SalesOrderException('text_too_long', 'Text je příliš dlouhý.');
        return $value;
    }
}
