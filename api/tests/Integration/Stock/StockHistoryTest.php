<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\StockValuationSnapshotRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockValuationJobService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class StockHistoryTest extends StockTestCase
{
    use \MyInvoice\Tests\Support\StockHistoryFixtureTrait;

    public function testOtherWarehouseHistoryCannotBlockValuation(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid, 'SMALL');
        $noise = $this->warehouse($sid, 'NOISE');
        $item = $this->item($sid, 'HISTORY-FILTER');
        $this->receiveStock($sid, $wh, $item, '2', 10, '2099-01-01');
        $this->syntheticHistory($sid, $noise, $item, 50001);
        $report = $this->container->get(StockReportService::class)->valuation($sid, '2099-01-31', ['warehouse_id' => $wh]);
        self::assertSame('20.00', $report['totals']['value_total']);
        self::assertCount(1, $report['items']);
    }

    public function testCheckpointResumesWithinOneLongCardWithoutDuplicates(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-RESUME');
        $this->syntheticHistory($sid, $wh, $item, 2001);
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        $first = $jobs->tick($sid, 1);
        self::assertSame('queued', $first['status']);
        self::assertSame(0, $first['checkpoint']);
        self::assertSame(2000, $first['report']['movements']);
        $second = $jobs->tick($sid, 1);
        self::assertSame('completed', $second['status']);
        self::assertSame(2001, $second['report']['movements']);
        self::assertSame('2001.00', $jobs->result($sid, $job['id'])['totals']['value_total']);
    }

    public function testBatchProcessesManyShortCardsAndSnapshotCards(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $this->syntheticEmptyCards($sid, $wh, 1001);
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        $first = $jobs->tick($sid, 1);
        self::assertGreaterThan(1, $first['checkpoint']);
        self::assertLessThanOrEqual(500, $first['checkpoint']);
        $done = $jobs->tick($sid);
        self::assertSame('completed', $done['status']);
        self::assertSame(1001, $done['checkpoint']);
        self::assertSame(0, $done['report']['movements']);
        self::assertSame('0.00', $jobs->result($sid, $job['id'])['totals']['value_total']);
        $jobs->enqueue($sid, '2099-02-01', ['warehouse_id' => $wh]);
        $snapshot = $jobs->tick($sid);
        self::assertSame('completed', $snapshot['status']);
        self::assertSame(1001, $snapshot['checkpoint']);
        self::assertSame(0, $snapshot['report']['movements']);
    }

    public function testMovementBudgetResumesLongCardThenContinuesShortCards(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $short = $this->item($sid, 'HISTORY-MIXED-SHORT');
        $this->syntheticHistory($sid, $wh, $short, 501);
        $item = $this->item($sid, 'HISTORY-MIXED-LONG');
        $this->syntheticHistory($sid, $wh, $item, 4501);
        $this->syntheticEmptyCards($sid, $wh, 301);
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        foreach ([2000, 4000] as $movements) {
            $partial = $jobs->tick($sid, 1);
            self::assertSame('queued', $partial['status']);
            self::assertSame(1, $partial['checkpoint']);
            self::assertSame($movements, $partial['report']['movements']);
        }
        $done = $jobs->tick($sid);
        self::assertSame('completed', $done['status']);
        self::assertSame(303, $done['checkpoint']);
        self::assertSame(5002, $done['report']['movements']);
        self::assertSame('5002.00', $jobs->result($sid, $job['id'])['totals']['value_total']);
    }

    public function testJobPublishesPagedResultAndReusesSnapshot(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-A');
        $this->receiveStock($sid, $wh, $item, '10', 10, '2099-01-01');
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        $finished = $jobs->tick($sid);
        self::assertSame('completed', $finished['status']);
        $result = $jobs->result($sid, $job['id']);
        self::assertSame('100.00', $result['totals']['value_total']);
        self::assertSame('10.000', $result['items'][0]['qty']);
        $next = $jobs->enqueue($sid, '2099-02-01', ['warehouse_id' => $wh]);
        $finished = $jobs->tick($sid);
        self::assertSame(0, $finished['report']['movements']);
        self::assertSame($result['items'], $jobs->result($sid, $next['id'])['items']);
    }

    public function testBackdatedReceiptInvalidatesOnlyAffectedSnapshotsAndReplayUsesEarlierOne(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-B');
        $this->receiveStock($sid, $wh, $item, '10', 10, '2099-01-01');
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobs->enqueue($sid, '2099-01-05', ['warehouse_id' => $wh]);
        $jobs->tick($sid);
        $draft = $this->documents->create($sid, ['doc_type' => 'issue', 'warehouse_id' => $wh,
            'doc_date' => '2099-01-20', 'description' => 'Synthetic issue',
            'lines' => [['stock_item_id' => $item, 'qty' => '2']]], $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);
        $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        $jobs->tick($sid);
        $this->db->pdo()->prepare("INSERT INTO stock_item_prices (supplier_id, stock_item_id, currency_code, price_mode, fixed_price) VALUES (?, ?, 'CZK', 'fixed', 100)")
            ->execute([$sid, $item]);
        $this->receiveStock($sid, $wh, $item, '10', 30, '2099-01-10');
        $priceJobs = $this->db->pdo()->prepare("SELECT input_json FROM catalog_jobs WHERE supplier_id = ? AND kind = 'price_recompute'");
        $priceJobs->execute([$sid]);
        $queued = $priceJobs->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $queued);
        self::assertSame([$item], json_decode($queued[0], true, 512, JSON_THROW_ON_ERROR)['item_ids']);
        $snapshots = $this->container->get(StockValuationSnapshotRepository::class);
        self::assertSame('2099-01-05', $snapshots->latest($sid, $wh, $item, '2099-01-31')['cutoff_date']);
        self::assertSame(36000, $this->level($sid, $wh, $item)['valueC']);
        $report = $this->container->get(StockReportService::class)->valuation($sid, '2099-01-31', ['warehouse_id' => $wh]);
        self::assertSame('360.00', $report['totals']['value_total']);
    }

    public function testChangedSourceFailsJobAndHidesIncompleteResult(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $a = $this->item($sid, 'HISTORY-C1');
        $b = $this->item($sid, 'HISTORY-C2');
        $this->syntheticHistory($sid, $wh, $a, 2001);
        $this->receiveStock($sid, $wh, $b, '1', 10);
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        self::assertSame(0, $jobs->tick($sid, 1)['checkpoint']);
        $this->receiveStock($sid, $wh, $a, '1', 10);
        try {
            $jobs->tick($sid);
            self::fail('Changed input must invalidate the unfinished valuation.');
        } catch (StockException $e) {
            self::assertSame('stock_valuation_stale', $e->errorCode);
        }
        self::assertSame('failed', $this->container->get(CatalogJobService::class)->find($sid, $job['id'])['status']);
        $this->expectException(StockException::class);
        $jobs->result($sid, $job['id']);
    }

    public function testPreparationBlocksStockAndPublishesOnlyCompleteInventory(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-D');
        $this->receiveStock($sid, $wh, $item, '5', 10);
        $take = $this->newTake($sid, $wh);
        $prepared = $this->takes->prepare($sid, (int) $take['id'], $this->userId);
        self::assertSame('preparing', $prepared['status']);
        self::assertSame([], $prepared['lines']);
        self::assertSame('stock_valuation', $prepared['preparation_job']['kind']);
        try {
            $this->receiveStock($sid, $wh, $item, '1', 10);
            self::fail('Preparing inventory must block stock movements.');
        } catch (StockException $e) {
            self::assertSame('stock_take_in_progress', $e->errorCode);
        }
        $this->container->get(StockValuationJobService::class)->tick($sid);
        $result = $this->takes->get($sid, (int) $take['id']);
        self::assertSame('counting', $result['status']);
        self::assertSame('5.000', $result['lines'][0]['expected_qty']);
        self::assertSame('50.00', $result['lines'][0]['expected_value']);
    }

    public function testPreparationKeepsLastKnownCostForEmptyCard(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-EMPTY');
        $this->receiveStock($sid, $wh, $item, '5', 10, '2099-01-01');
        $issue = $this->documents->create($sid, ['doc_type' => 'issue', 'warehouse_id' => $wh,
            'doc_date' => '2099-01-02', 'description' => 'Synthetic empty stock',
            'lines' => [['stock_item_id' => $item, 'qty' => '5']]], $this->userId);
        $this->documents->post($sid, (int) $issue['id'], $this->userId);
        $take = $this->newTake($sid, $wh);
        $this->takes->prepare($sid, (int) $take['id'], $this->userId);
        $this->container->get(StockValuationJobService::class)->tick($sid);
        $result = $this->takes->get($sid, (int) $take['id']);
        self::assertSame('0.000', $result['lines'][0]['expected_qty']);
        self::assertSame('10.000000', $result['lines'][0]['surplus_unit_cost']);
    }

    public function testCancelledPreparationUnblocksWarehouse(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-E');
        $take = $this->newTake($sid, $wh);
        $prepared = $this->takes->prepare($sid, (int) $take['id'], $this->userId);
        $cancelled = $this->takes->cancelPreparation($sid, (int) $take['id']);
        self::assertSame('draft', $cancelled['status']);
        self::assertSame('cancelled', $this->container->get(CatalogJobService::class)->find($sid, $prepared['preparation_job_id'])['status']);
        $this->receiveStock($sid, $wh, $item, '1', 10);
        self::assertSame(1000, $this->level($sid, $wh, $item)['qtyT']);
    }

    public function testForeignTenantCannotReadResult(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31');
        $jobs->tick($sid);
        try {
            $jobs->result($other, $job['id']);
            self::fail('Foreign result must not be visible.');
        } catch (StockException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
    }

    public function testTransferSnapshotsContainBothLegsAndReversalInvalidatesBoth(): void
    {
        $sid = $this->createSupplier();
        $source = $this->warehouse($sid, 'SOURCE');
        $target = $this->warehouse($sid, 'TARGET');
        $item = $this->item($sid, 'HISTORY-TRANSFER');
        $this->receiveStock($sid, $source, $item, '10', 10, '2099-01-01');
        $draft = $this->documents->create($sid, ['doc_type' => 'transfer', 'warehouse_id' => $source,
            'warehouse_to_id' => $target, 'doc_date' => '2099-01-10', 'description' => 'Synthetic transfer',
            'lines' => [['stock_item_id' => $item, 'qty' => '4']]], $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31');
        $jobs->tick($sid);
        $result = $jobs->result($sid, $job['id']);
        self::assertSame('100.00', $result['totals']['value_total']);
        self::assertSame(['60.00', '40.00'], array_column($result['items'], 'value_total'));
        $snapshots = $this->container->get(StockValuationSnapshotRepository::class);
        $before = $snapshots->versions($sid, [$source, $target]);
        $this->documents->reverse($sid, (int) $draft['id'], ['reason' => 'Synthetic reversal'], $this->userId);
        $after = $snapshots->versions($sid, [$source, $target]);
        self::assertGreaterThan($before[$source], $after[$source]);
        self::assertGreaterThan($before[$target], $after[$target]);
        self::assertNull($snapshots->latest($sid, $source, $item, '2099-01-31'));
        self::assertNull($snapshots->latest($sid, $target, $item, '2099-01-31'));
        $report = $this->container->get(StockReportService::class)->valuation($sid, '2099-01-31', []);
        self::assertSame('100.00', $report['totals']['value_total']);
    }

    private function newTake(int $sid, int $warehouseId): array
    {
        return $this->takes->create($sid, ['warehouse_id' => $warehouseId, 'take_date' => '2099-01-31',
            'counting_method' => 'physical_count', 'responsible_count_name' => 'Synthetic counter',
            'responsible_inventory_name' => 'Synthetic supervisor'], $this->userId);
    }
}
