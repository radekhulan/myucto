<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\SalesOrderService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SalesOrderConfirmConcurrencyTest extends StockTestCase
{
    private SalesOrderService $orders;
    private ?int $supplierId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = $this->container->get(SalesOrderService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId !== null) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$this->supplierId]);
        }
        parent::tearDown();
    }

    public function testConfirmSeesReservationCommittedWhileWaitingForStockLock(): void
    {
        $this->supplierId = $this->createSupplier();
        $clientId = $this->client($this->supplierId);
        $warehouseId = $this->warehouse($this->supplierId);
        $itemId = $this->item($this->supplierId, 'SO-RR-SNAPSHOT');
        $this->receiveStock($this->supplierId, $warehouseId, $itemId, '1.000', 20.0, date('Y-m-d', strtotime('-1 day')));

        $blocker = $this->orders->create(
            $this->supplierId,
            $this->draft($clientId, $warehouseId, $itemId, 'TEST-SO-RR-BLOCKER'),
            $this->userId,
        );
        $waiter = $this->orders->create(
            $this->supplierId,
            $this->draft($clientId, $warehouseId, $itemId, 'TEST-SO-RR-WAITER'),
            $this->userId,
        );

        $readyFile = tempnam(sys_get_temp_dir(), 'sales-order-ready-');
        self::assertIsString($readyFile);
        $resultFile = tempnam(sys_get_temp_dir(), 'sales-order-confirm-');
        self::assertIsString($resultFile);
        $process = null;
        $pipes = [];
        $pdo = $this->db->pdo();

        try {
            $pdo->beginTransaction();
            $this->levels->lockLevels($this->supplierId, [[
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $itemId,
            ]]);

            $worker = dirname(__DIR__, 2) . '/Support/Stock/SalesOrderConfirmWorker.php';
            $process = proc_open(
                [PHP_BINARY, $worker, (string) $this->supplierId, (string) $waiter['id'], 'rr-waiter-confirm', $readyFile, $resultFile],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 3),
                null,
                ['bypass_shell' => true],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);

            $connectionId = $this->waitForWorkerConnectionId($readyFile, $process);
            $this->waitUntilWorkerBlocksOnStockLock($pdo, $process, $connectionId, $resultFile);

            $lineId = (int) $blocker['lines'][0]['id'];
            $pdo->prepare(
                'INSERT INTO sales_order_reservations
                    (supplier_id, order_id, order_line_id, component_no, warehouse_id, stock_item_id, qty_reserved)
                 VALUES (?, ?, ?, 0, ?, ?, "1.000")'
            )->execute([$this->supplierId, $blocker['id'], $lineId, $warehouseId, $itemId]);
            $pdo->commit();

            $this->waitForProcess($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $process = null;

            self::assertSame(0, $exitCode, trim($stdout . "\n" . $stderr));
            $result = json_decode((string) file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(
                ['status' => 'error', 'error_code' => 'insufficient_stock'],
                $result,
                'Confirm po čekání na stock lock musí číst rezervaci commitnutou blokující transakcí.',
            );

            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM sales_order_reservations
                  WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ? AND status = "active"'
            );
            $count->execute([$this->supplierId, $warehouseId, $itemId]);
            self::assertSame(1, (int) $count->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @unlink($resultFile);
            @unlink($readyFile);
        }
    }

    public function testConcurrentReuseOfKeyByDifferentOrdersKeepsOriginalOwner(): void
    {
        $this->supplierId = $this->createSupplier();
        $clientId = $this->client($this->supplierId);
        $warehouseId = $this->warehouse($this->supplierId);
        $itemIds = [
            $this->item($this->supplierId, 'SO-IDEMPOTENCY-RACE-A'),
            $this->item($this->supplierId, 'SO-IDEMPOTENCY-RACE-B'),
        ];
        foreach ($itemIds as $itemId) {
            $this->receiveStock($this->supplierId, $warehouseId, $itemId, '1.000', 20.0, date('Y-m-d', strtotime('-1 day')));
        }
        $orders = [
            $this->orders->create(
                $this->supplierId,
                $this->draft($clientId, $warehouseId, $itemIds[0], 'TEST-SO-IDEMPOTENCY-A'),
                $this->userId,
            ),
            $this->orders->create(
                $this->supplierId,
                $this->draft($clientId, $warehouseId, $itemIds[1], 'TEST-SO-IDEMPOTENCY-B'),
                $this->userId,
            ),
        ];
        $workers = [];
        $pdo = $this->db->pdo();

        try {
            $pdo->beginTransaction();
            $this->levels->lockLevels($this->supplierId, [[
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $itemIds[0],
            ], [
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $itemIds[1],
            ]]);

            foreach ($orders as $index => $order) {
                $readyFile = tempnam(sys_get_temp_dir(), 'sales-order-key-ready-');
                $resultFile = tempnam(sys_get_temp_dir(), 'sales-order-key-result-');
                self::assertIsString($readyFile);
                self::assertIsString($resultFile);
                $worker = dirname(__DIR__, 2) . '/Support/Stock/SalesOrderConfirmWorker.php';
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $worker, (string) $this->supplierId, (string) $order['id'], 'shared-confirm-key', $readyFile, $resultFile],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 3),
                    null,
                    ['bypass_shell' => true],
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[$index] = compact('process', 'pipes', 'readyFile', 'resultFile');
            }

            foreach ($workers as $worker) {
                $connectionId = $this->waitForWorkerConnectionId($worker['readyFile'], $worker['process']);
                $this->waitUntilWorkerBlocksOnStockLock($pdo, $worker['process'], $connectionId, $worker['resultFile']);
            }
            $pdo->commit();

            $results = [];
            foreach ($workers as $index => &$worker) {
                $this->waitForProcess($worker['process']);
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exitCode = proc_close($worker['process']);
                $worker['process'] = null;
                self::assertSame(0, $exitCode, trim($stdout . "\n" . $stderr));
                $results[$index] = json_decode((string) file_get_contents($worker['resultFile']), true, flags: JSON_THROW_ON_ERROR);
            }
            unset($worker);

            $successful = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'ok'));
            $conflicts = array_values(array_filter($results, static fn (array $result): bool => $result === [
                'status' => 'error',
                'error_code' => 'idempotency_conflict',
            ]));
            self::assertCount(1, $successful, json_encode($results, JSON_THROW_ON_ERROR));
            self::assertCount(1, $conflicts, json_encode($results, JSON_THROW_ON_ERROR));

            $keyOwner = $pdo->prepare(
                'SELECT order_id FROM sales_order_operation_keys
                  WHERE supplier_id = ? AND operation = "confirm" AND idempotency_key = "shared-confirm-key"'
            );
            $keyOwner->execute([$this->supplierId]);
            $ownerId = (int) $keyOwner->fetchColumn();
            self::assertContains($ownerId, array_map(static fn (array $order): int => (int) $order['id'], $orders));

            $confirmed = $pdo->prepare(
                'SELECT COUNT(*) FROM sales_orders WHERE supplier_id = ? AND commercial_status = "confirmed"'
            );
            $confirmed->execute([$this->supplierId]);
            self::assertSame(1, (int) $confirmed->fetchColumn());
            self::assertSame(1, (int) $pdo->query(
                'SELECT COUNT(*) FROM sales_order_reservations WHERE supplier_id = ' . $this->supplierId
            )->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    proc_close($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                @unlink($worker['resultFile']);
                @unlink($worker['readyFile']);
            }
        }
    }

    /** @param resource $process */
    private function waitForWorkerConnectionId(string $readyFile, $process): int
    {
        $deadline = microtime(true) + 10.0;
        do {
            $connectionId = (int) trim((string) file_get_contents($readyFile));
            if ($connectionId > 0) {
                return $connectionId;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                self::fail('Confirm worker skončil před zveřejněním ID databázové session.');
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Confirm worker nezveřejnil ID databázové session do 10 sekund.');
    }

    /** @param resource $process */
    private function waitUntilWorkerBlocksOnStockLock(\PDO $pdo, $process, int $connectionId, string $resultFile): void
    {
        $query = $pdo->prepare(
            'SELECT
                EXISTS(
                    SELECT 1
                      FROM information_schema.INNODB_LOCK_WAITS w
                      JOIN information_schema.INNODB_TRX waiter ON waiter.trx_id = w.requesting_trx_id
                     WHERE waiter.trx_mysql_thread_id = ?
                ) AS has_lock_wait,
                COALESCE((SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?), "") AS current_statement'
        );
        $deadline = microtime(true) + 10.0;
        do {
            $query->execute([$connectionId, $connectionId]);
            $barrier = $query->fetch(\PDO::FETCH_ASSOC);
            if (
                (int) ($barrier['has_lock_wait'] ?? 0) > 0
                || preg_match('/(?:INSERT IGNORE INTO|FROM)\s+stock_levels\b/i', (string) ($barrier['current_statement'] ?? '')) === 1
            ) {
                return;
            }
            $earlyResult = trim((string) file_get_contents($resultFile));
            if ($earlyResult !== '') {
                self::fail('Confirm worker skončil před čekáním na stock lock: ' . $earlyResult);
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                self::fail('Confirm worker skončil dříve, než začal čekat na stock lock.');
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $state = $pdo->prepare(
            'SELECT COMMAND, TIME, STATE, LEFT(INFO, 160) AS INFO FROM information_schema.PROCESSLIST WHERE ID = ?'
        );
        $state->execute([$connectionId]);
        self::fail(
            'Confirm worker nezačal do 10 sekund čekat na stock lock. Session: '
            . json_encode($state->fetch(\PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR)
        );
    }

    /** @param resource $process */
    private function waitForProcess($process): void
    {
        $deadline = microtime(true) + 10.0;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Confirm worker neskončil do 10 sekund po uvolnění stock locku.');
    }

    /** @return array<string,mixed> */
    private function draft(int $clientId, int $warehouseId, int $itemId, string $number): array
    {
        return [
            'client_id' => $clientId,
            'currency_id' => $this->currencyIdFor($this->supplierId ?? 0),
            'order_number' => $number,
            'allocation_policy' => 'all_or_nothing',
            'prices_include_vat' => false,
            'lines' => [[
                'stock_item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'quantity' => '1.000',
                'unit_price' => '100.000000',
                'vat_rate_id' => $this->vatRateId,
            ]],
        ];
    }
}
