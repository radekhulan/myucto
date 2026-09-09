<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Eshop\Pricing\CatalogExchangeRateStore;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;

final class CatalogPriceJobTest extends StockTestCase
{
    public function testStaleJobPreservesNewerPriceAndQueuesFreshCalculation(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-STALE');
        $this->receiveStock($sid, $this->warehouse($sid), $item, '1.000', 100.0);
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'XTS', 'price_mode' => 'markup', 'markup_pct' => '0'],
        ]);
        $store = $this->container->get(CatalogExchangeRateStore::class);
        $store->save(date('Y-m-d'), ['XTS' => '25']);
        $store->save(date('Y-m-d'), ['XTS' => '20']);
        $this->container->get(PriceCalculationService::class)->recompute($sid, $item);
        $worker = $this->container->get(CatalogPriceJobService::class);
        $job = $worker->tick($sid);
        self::assertSame($item, $job['report']['stale_items'][0]['item_id']);
        self::assertNotEmpty($job['report']['replacement_job_ids']);
        for ($i = 0; $i < 10; $i++) {
            self::assertSame('5.00', $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'XTS')['computed_price']);
            if ($worker->tick($sid) === null) {
                break;
            }
        }
        self::assertLessThan(10, $i, 'Následné přepočty musí doběhnout bez nekonečného requeue.');
    }

    public function testSupplierCostUsesTheSameSnapshotAsSellingCurrency(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-VENDOR');
        $this->itemsRepo->update($sid, $item, array_replace($this->itemsRepo->find($sid, $item), ['pricing_base' => 'manual']));
        $this->container->get(StockItemVendorRepository::class)->add($sid, $item, [
            'client_id' => $this->client($sid), 'purchase_price' => '10', 'currency_code' => 'XTS', 'is_preferred' => true,
        ]);
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'CZK', 'price_mode' => 'markup', 'markup_pct' => '0'],
            ['currency_code' => 'XTS', 'price_mode' => 'markup', 'markup_pct' => '0'],
        ]);
        $this->container->get(CatalogExchangeRateStore::class)->save(date('Y-m-d'), ['XTS' => '25']);
        $this->db->pdo()->prepare("UPDATE exchange_rates SET rate = 20 WHERE currency_code = 'XTS' AND rate_date = ?")->execute([date('Y-m-d')]);
        $this->container->get(CatalogPriceJobService::class)->tick($sid);
        $prices = $this->container->get(StockItemPriceRepository::class);
        self::assertSame('250.00', $prices->findByCurrency($sid, $item, 'CZK')['computed_price']);
        self::assertSame('10.00', $prices->findByCurrency($sid, $item, 'XTS')['computed_price']);
        self::assertSame('250.000000', $prices->findByCurrency($sid, $item, 'XTS')['computed_base']);
    }

    public function testFailuresRemainInReportAcrossBatchesAndDoNotBlockOtherItems(): void
    {
        $sid = $this->createSupplier();
        $warehouse = $this->warehouse($sid);
        $missing = $this->item($sid, 'JOB-BATCH-MISSING');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($sid, $missing, [['currency_code' => 'CZK', 'price_mode' => 'markup', 'markup_pct' => '0']]);
        for ($i = 0; $i < 99; $i++) {
            $last = $this->item($sid, 'JOB-BATCH-' . $i);
            $writer->save($sid, $last, [['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '10']]);
        }
        $last = $this->item($sid, 'JOB-BATCH-LAST');
        $this->receiveStock($sid, $warehouse, $last, '1.000', 100.0);
        $writer->save($sid, $last, [['currency_code' => 'XTS', 'price_mode' => 'markup', 'markup_pct' => '0']]);
        $worker = $this->container->get(CatalogPriceJobService::class);
        $this->container->get(CatalogExchangeRateStore::class)->save(date('Y-m-d'), ['XTS' => '25']);
        $first = $worker->tick($sid, 1);
        self::assertSame(100, $first['checkpoint']);
        self::assertCount(1, $first['report']['failed_items']);
        $this->db->pdo()->prepare("UPDATE exchange_rates SET rate = 20 WHERE currency_code = 'XTS' AND rate_date = ?")->execute([date('Y-m-d')]);
        $second = $worker->tick($sid, 1);
        self::assertSame('completed', $second['status']);
        self::assertSame(101, $second['report']['processed']);
        self::assertSame(100, $second['report']['succeeded']);
        self::assertSame($first['report']['failed_items'], $second['report']['failed_items']);
        self::assertSame('4.00', $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $last, 'XTS')['computed_price']);
    }

    public function testRunUsesPersistedRateAndInputVersions(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-SNAPSHOT');
        $this->receiveStock($sid, $this->warehouse($sid), $item, '1.000', 100.0);
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'XTS', 'price_mode' => 'markup', 'markup_pct' => '0'],
        ]);
        $worker = $this->container->get(CatalogPriceJobService::class);
        $this->container->get(CatalogExchangeRateStore::class)->save(date('Y-m-d'), ['XTS' => '25']);
        $this->db->pdo()->prepare("UPDATE exchange_rates SET rate = 20 WHERE currency_code = 'XTS' AND rate_date = ?")->execute([date('Y-m-d')]);
        $job = $worker->tick($sid);
        self::assertSame('4.00', $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'XTS')['computed_price']);
        self::assertSame(3, $job['input_version']);
        self::assertArrayHasKey($item, $job['input']['item_versions']);
        self::assertSame('25.000000', $job['input']['rates']['XTS']['rate']);
    }

    public function testCurrencyOnlyRecomputeAdvancesVersion(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-VERSION');
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '10'],
        ]);
        $before = $this->itemsRepo->find($sid, $item)['row_version'];
        $this->container->get(PriceCalculationService::class)->recompute($sid, $item);
        self::assertGreaterThan($before, $this->itemsRepo->find($sid, $item)['row_version']);
    }

    public function testMissingRateIsDurableItemFailure(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-MISSING');
        $this->receiveStock($sid, $this->warehouse($sid), $item, '1.000', 100.0);
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '12'],
            ['currency_code' => 'XTS', 'price_mode' => 'markup', 'markup_pct' => '0'],
        ]);
        $this->db->pdo()->prepare("UPDATE stock_item_prices SET fixed_price = 14 WHERE supplier_id = ? AND stock_item_id = ? AND currency_code = 'CZK'")->execute([$sid, $item]);
        $worker = $this->container->get(CatalogPriceJobService::class);
        $id = $worker->enqueue($sid, [$item]);
        $worker->tick($sid);
        $job = $this->container->get(CatalogJobService::class)->find($sid, $id);
        self::assertSame($item, $job['report']['failed_items'][0]['item_id']);
        self::assertSame('missing_exchange_rate', $job['report']['failed_items'][0]['error_code']);
        self::assertSame('XTS', $job['report']['failed_items'][0]['currency_code']);
        self::assertSame('12.00', $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'CZK')['computed_price']);
    }

    public function testReceiptQueuesActualCostRecomputation(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-RECEIPT');
        $warehouse = $this->warehouse($sid);
        $this->receiveStock($sid, $warehouse, $item, '1.000', 100.0);
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'CZK', 'price_mode' => 'markup', 'markup_pct' => '25'],
        ]);
        $this->receiveStock($sid, $warehouse, $item, '1.000', 200.0);
        $result = $this->container->get(CatalogPriceJobService::class)->tick($sid);
        self::assertSame('completed', $result['status']);
        $price = $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'CZK');
        self::assertSame('187.50', $price['computed_price']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->prepare("DELETE FROM exchange_rates WHERE currency_code = 'XTS' AND rate_date = ?")->execute([date('Y-m-d')]);
        }
        parent::tearDown();
    }

    public function testRatePersistenceQueuesAndRecomputesPrices(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'JOB-FX');
        $warehouse = $this->warehouse($sid);
        $this->receiveStock($sid, $warehouse, $item, '10.000', 100.0);
        $this->container->get(PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'XTS', 'price_mode' => 'markup', 'markup_pct' => '0'],
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '49.90'],
        ]);
        $store = $this->container->get(CatalogExchangeRateStore::class);
        $worker = $this->container->get(CatalogPriceJobService::class);
        $prices = $this->container->get(StockItemPriceRepository::class);
        $store->save(date('Y-m-d'), ['XTS' => '25']);
        self::assertSame('completed', $worker->tick($sid)['status']);
        self::assertSame('4.00', $prices->findByCurrency($sid, $item, 'XTS')['computed_price']);
        $store->save(date('Y-m-d'), ['XTS' => '20']);
        $worker->tick($sid);
        self::assertSame('5.00', $prices->findByCurrency($sid, $item, 'XTS')['computed_price']);
        self::assertSame('49.90', $prices->findByCurrency($sid, $item, 'EUR')['computed_price']);
        $store->save(date('Y-m-d'), ['XTS' => '20']);
        self::assertNull($worker->tick($sid));
    }
}
