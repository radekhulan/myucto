<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\PurchaseOrderReceiptService;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class StockIntegrityTest extends StockTestCase
{
    use IsolatedSupplierTrait;

    public static function payerCases(): array
    {
        return [[true, true], [false, false], [true, false], [false, true]];
    }

    #[DataProvider('payerCases')]
    public function testBothReceiptPathsUseHistoricVatAndInvoiceExchangeRate(bool $live, bool $historic): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture();
        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $sid, '1900-01-01', $live);
        $this->setVatPayerAt($pdo, $sid, '2099-01-01', $historic);
        $pi = $this->purchaseInvoice($sid, (int) $order['vendor_id']);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, 'EUR', 'Euro', 'EUR', 'Euro', 'Euro', 2, 1, 0)")->execute([$sid]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE purchase_invoices SET currency_id = ?, exchange_rate = 25 WHERE id = ?')->execute([$currency, $pi]);
        $line = $this->purchaseInvoiceItem($pi, $item, '1', 100);
        $pdo->prepare('UPDATE purchase_invoice_items SET purchase_order_line_id = ? WHERE id = ?')->execute([$order['lines'][0]['id'], $line]);
        $direct = $this->receipts->proposeForPurchaseInvoice($sid, $pi);
        $viaOrder = $this->container->get(PurchaseOrderReceiptService::class)->propose($sid, (int) $order['id']);
        $expected = 2500 * ($historic ? 1 : 1 + (float) $this->vatRatePercent / 100);
        self::assertEqualsWithDelta($expected, (float) $direct['lines'][0]['unit_cost'], 0.000001);
        self::assertEqualsWithDelta($expected, (float) $viaOrder['lines'][0]['unit_cost'], 0.000001);

        $costs = [['amount' => '250.00', 'allocation' => 'by_value', 'description' => 'Synthetic freight']];
        $receiptA = $this->receipts->createReceipt($sid, $pi, [
            'warehouse_id' => $wh, 'doc_date' => '2099-06-02', 'landed_costs' => $costs,
            'lines' => [['purchase_invoice_item_id' => $line, 'quantity' => '1']],
        ], $this->userId);
        $receiptB = $this->container->get(PurchaseOrderReceiptService::class)->createReceipt($sid, (int) $order['id'], [
            'doc_date' => '2099-06-02', 'landed_costs' => $costs,
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'qty' => '1']],
        ], $this->userId);
        self::assertSame($receiptA['lines'][0]['value_total'], $receiptB['lines'][0]['value_total']);
        self::assertSame('250.00', $receiptB['lines'][0]['extra_cost']);
    }

    public function testOverDeliveryCannotHideAnotherUnreceivedLine(): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture(true);
        $draft = $this->container->get(PurchaseOrderReceiptService::class)->createReceipt($sid, (int) $order['id'], [
            'doc_date' => '2099-06-02', 'allow_over_delivery' => true,
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'qty' => '20']],
        ], $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);
        $result = $this->container->get(PurchaseOrderService::class)->detail($sid, (int) $order['id']);
        self::assertSame('partially_received', $result['state']);
        self::assertSame('10.000', $result['lines'][1]['qty_remaining']);
    }

    public function testPostingRechecksReceiptQuotaAndDoesNotInheritAnotherDraftPermission(): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture();
        $receipts = $this->container->get(PurchaseOrderReceiptService::class);
        $body = ['doc_date' => '2099-06-02', 'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'qty' => '10']]];
        $first = $receipts->createReceipt($sid, (int) $order['id'], $body, $this->userId);
        $second = $receipts->createReceipt($sid, (int) $order['id'], $body, $this->userId);
        $receipts->createReceipt($sid, (int) $order['id'], array_replace($body, ['allow_over_delivery' => true, 'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'qty' => '20']]]), $this->userId);
        $this->documents->post($sid, (int) $first['id'], $this->userId);
        try {
            $this->documents->post($sid, (int) $second['id'], $this->userId);
            self::fail('Second receipt must reject exhausted order quota.');
        } catch (StockException $e) {
            self::assertSame('over_receipt', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame('draft', $this->docsRepo->find($sid, (int) $second['id'])['status']);
        self::assertSame(10000, $this->level($sid, $wh, $item)['qtyT']);
    }

    public function testOrderDraftUpdatePreservesLineIdentity(): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture(true, false);
        $body = $order;
        $body['note'] = 'Updated synthetic note';
        $body['lines'] = array_reverse($body['lines']);
        $result = $this->container->get(PurchaseOrderService::class)->update($sid, (int) $order['id'], $body, $this->userId);
        self::assertSame(array_reverse(array_column($order['lines'], 'id')), array_column($result['lines'], 'id'));
    }

    public function testLinkedOrderLineCannotBeDeletedAndUpdateRollsBack(): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture(true, false);
        $pi = $this->purchaseInvoice($sid, (int) $order['vendor_id']);
        $piLine = $this->purchaseInvoiceItem($pi, $item);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET purchase_order_line_id = ? WHERE id = ?')
            ->execute([$order['lines'][0]['id'], $piLine]);
        $body = $order;
        $body['note'] = 'Must roll back';
        $body['lines'] = [$order['lines'][1]];
        $orders = $this->container->get(PurchaseOrderService::class);
        try {
            $orders->update($sid, (int) $order['id'], $body, $this->userId);
            self::fail('Linked line deletion must be rejected.');
        } catch (StockException $e) {
            self::assertSame('order_line_linked', $e->errorCode);
        }
        $result = $orders->detail($sid, (int) $order['id']);
        self::assertSame($order['note'], $result['note']);
        self::assertSame(array_column($order['lines'], 'id'), array_column($result['lines'], 'id'));
    }

    public function testDuplicateReceiptLinesShareOneOrderQuota(): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture();
        $line = ['purchase_order_line_id' => $order['lines'][0]['id'], 'qty' => '6'];
        $draft = $this->container->get(PurchaseOrderReceiptService::class)->createReceipt($sid, (int) $order['id'], [
            'doc_date' => '2099-06-02', 'lines' => [$line, $line],
        ], $this->userId);
        try {
            $this->documents->post($sid, (int) $draft['id'], $this->userId);
            self::fail('Duplicate references must consume the same quota.');
        } catch (StockException $e) {
            self::assertSame('over_receipt', $e->errorCode);
        }
        self::assertSame(0, $this->level($sid, $wh, $item)['qtyT']);
    }

    public function testUnchangedDraftHeaderAllowsLineOnlyUpdate(): void
    {
        $sid = $this->createSupplier();
        $body = ['doc_type' => 'receipt', 'warehouse_id' => $this->warehouse($sid), 'doc_date' => '2099-06-02',
            'description' => 'Synthetic unchanged header',
            'lines' => [['stock_item_id' => $this->item($sid, 'INTEGRITY-NOOP'), 'qty' => '1', 'unit_cost' => '100']]];
        $draft = $this->documents->create($sid, $body, $this->userId);
        $body['lines'][0]['qty'] = '2';
        $result = $this->documents->updateDraft($sid, (int) $draft['id'], $body, $this->userId);
        self::assertSame('2.000', $result['lines'][0]['qty']);
    }

    public function testConcurrentEditorCannotReplacePostedLines(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'INTEGRITY-RACE');
        $body = ['doc_type' => 'receipt', 'warehouse_id' => $wh, 'doc_date' => '2099-06-02',
            'description' => 'Synthetic race', 'lines' => [['stock_item_id' => $item, 'qty' => '1', 'unit_cost' => '100']]];
        $draft = $this->documents->create($sid, $body, $this->userId);
        $body['lines'][0]['qty'] = '7';
        $result = $this->competingWrite(
            ['sid' => $sid, 'id' => $draft['id'], 'body' => $body, 'user' => $this->userId],
            fn () => $this->docsRepo->lockForPost($sid, (int) $draft['id']),
            fn () => $this->documents->post($sid, (int) $draft['id'], $this->userId),
        );
        self::assertSame('not_draft', $result);
        $posted = $this->docsRepo->findWithLines($sid, (int) $draft['id']);
        self::assertSame('posted', $posted['status']);
        self::assertSame('1.000', $posted['lines'][0]['qty']);
        self::assertSame(1000, $this->level($sid, $wh, $item)['qtyT']);
    }

    public function testConcurrentEditorUsesActualDatabaseDespiteInheritedEnvironment(): void
    {
        $previous = getenv('MYINVOICE_DB_NAME');
        putenv('MYINVOICE_DB_NAME=stock_race_unused_test');
        try {
            $this->testConcurrentEditorCannotReplacePostedLines();
        } finally {
            putenv($previous === false ? 'MYINVOICE_DB_NAME' : 'MYINVOICE_DB_NAME=' . $previous);
        }
    }

    public function testConcurrentReceiptsCannotExceedOrderQuantity(): void
    {
        [$sid, $wh, $item, $order] = $this->orderFixture();
        $receipts = $this->container->get(PurchaseOrderReceiptService::class);
        $body = ['doc_date' => '2099-06-02', 'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'qty' => '10']]];
        $first = $receipts->createReceipt($sid, (int) $order['id'], $body, $this->userId);
        $second = $receipts->createReceipt($sid, (int) $order['id'], $body, $this->userId);
        $repo = $this->container->get(\MyInvoice\Repository\PurchaseOrderRepository::class);
        $result = $this->competingWrite(
            ['sid' => $sid, 'id' => $second['id'], 'operation' => 'post', 'user' => $this->userId],
            fn () => $repo->lockForUpdate($sid, (int) $order['id']),
            fn () => $this->documents->post($sid, (int) $first['id'], $this->userId),
        );
        self::assertSame('over_receipt', $result);
        self::assertSame(10000, $this->level($sid, $wh, $item)['qtyT']);
        self::assertSame('draft', $this->docsRepo->find($sid, (int) $second['id'])['status']);
        self::assertSame('received', $repo->find($sid, (int) $order['id'])['state']);
    }

    private function competingWrite(array $input, callable $lock, callable $firstWrite): string
    {
        $file = tempnam(sys_get_temp_dir(), 'stock-race-');
        $pdo = $this->db->pdo();
        $input['database'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        file_put_contents($file, json_encode($input, JSON_THROW_ON_ERROR));
        $pdo->beginTransaction();
        $lock();
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/stock-integrity-worker.php', $file],
            [0 => ['pipe', 'r'], 1 => ['file', $file . '.out', 'w'], 2 => ['file', $file . '.err', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $waiting = false;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                clearstatcache(true, $file . '.ready');
                if (is_file($file . '.ready')) {
                    $ready = json_decode(file_get_contents($file . '.ready'), true, 512, JSON_THROW_ON_ERROR);
                    if ($ready['database'] !== $input['database']) {
                        self::fail('The competing writer connected to a different database.');
                    }
                    $connectionId = (int) $ready['connection_id'];
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id WHERE t.trx_mysql_thread_id = ?');
                    $stmt->execute([$connectionId]);
                    if ((int) $stmt->fetchColumn() > 0) {
                        $waiting = true;
                        break;
                    }
                }
                clearstatcache(true, $file . '.blocked');
                if (is_file($file . '.blocked')) {
                    self::assertSame('1205', file_get_contents($file . '.blocked'));
                    $waiting = true;
                    break;
                }
                if (!proc_get_status($process)['running']) {
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($waiting, 'Editor must actually wait for the document lock. ' . file_get_contents($file . '.out') . file_get_contents($file . '.err') . (is_file($file . '.result') ? file_get_contents($file . '.result') : 'No result'));
            $firstWrite();
            $pdo->commit();
            $deadline = microtime(true) + 10;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(20000);
            }
            self::assertFileExists($file . '.result');
            return (string) file_get_contents($file . '.result');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            foreach (['', '.ready', '.ready.tmp', '.blocked', '.result', '.out', '.err'] as $suffix) {
                if (is_file($file . $suffix)) {
                    unlink($file . $suffix);
                }
            }
        }
    }

    private function orderFixture(bool $twoLines = false, bool $send = true): array
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'INTEGRITY-A');
        $lines = [['stock_item_id' => $item, 'qty_ordered' => '10', 'unit_price' => '100']];
        if ($twoLines) {
            $lines[] = ['stock_item_id' => $this->item($sid, 'INTEGRITY-B'), 'qty_ordered' => '10', 'unit_price' => '100'];
        }
        $orders = $this->container->get(PurchaseOrderService::class);
        $order = $orders->create($sid, [
            'vendor_id' => $this->client($sid, 'Synthetic vendor'), 'order_date' => '2099-06-01',
            'warehouse_id' => $wh, 'currency_id' => $this->currencyIdFor($sid), 'lines' => $lines,
        ], $this->userId);
        if ($send) {
            $order = $orders->send($sid, (int) $order['id'], $this->userId);
        }
        return [$sid, $wh, $item, $order];
    }
}
