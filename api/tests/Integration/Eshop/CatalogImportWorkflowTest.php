<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Eshop\Import\CatalogImportService;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Eshop\Import\CatalogImportWorker;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\UploadedFile;

final class CatalogImportWorkflowTest extends StockTestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function preview(int $supplierId, string $csv, array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-flow-');
        $this->files[] = $path;
        file_put_contents($path, $csv);
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'fixture.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        $this->container->get(CatalogImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
        return $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::STAGE_KIND);
    }

    public function testCreateApplyAndIdenticalReimportMakesNoChanges(): void
    {
        $sid = $this->createSupplier();
        $config = ['mapping' => ['sku' => 'sku', 'name' => 'name', 'min_qty' => 'min']];
        $preview = $this->preview($sid, "sku;name;min\n00001;Fixture;100.00\n", $config);
        self::assertSame('completed', $preview['status']);
        self::assertSame(1, $preview['report']['counts']['ready']);
        self::assertNull($this->itemsRepo->findBySku($sid, '00001'));
        $service = $this->container->get(CatalogImportService::class);
        $service->apply($sid, $preview['id'], $this->userId);
        $applied = $this->container->get(CatalogImportWorker::class)->tickKind($sid, CatalogImportService::APPLY_KIND);
        self::assertSame(1, $applied['report']['counts']['applied']);
        $item = $this->itemsRepo->findBySku($sid, '00001');
        self::assertNotNull($item);
        self::assertSame('100.000', $item['min_qty']);
        $version = $item['row_version'];
        $repeat = $this->preview($sid, "sku;name;min\n00001;Fixture;100\n", $config);
        self::assertSame(1, $repeat['report']['counts']['unchanged']);
        self::assertSame($version, $this->itemsRepo->findBySku($sid, '00001')['row_version']);
    }

    public function testDuplicateIdentitiesInvalidateEveryOccurrence(): void
    {
        $sid = $this->createSupplier();
        $preview = $this->preview($sid, "sku;name\nDUP;First\ndup;Second\n", ['mapping' => ['sku' => 'sku', 'name' => 'name']]);
        self::assertSame(2, $preview['report']['counts']['failed']);
        $rows = $this->container->get(CatalogJobItemRepository::class)->page($sid, $preview['id']);
        self::assertSame(['import_duplicate_identity', 'import_duplicate_identity'], array_column($rows['items'], 'error_code'));
        self::assertNull($this->itemsRepo->findBySku($sid, 'DUP'));
    }

    public function testExplicitClearRemovesLegacyBasePriceWithoutCurrencyRow(): void
    {
        $sid = $this->createSupplier();
        $id = $this->itemsRepo->insert($sid, ['sku' => 'LEGACY-CLEAR', 'name' => 'Fixture', 'sale_price_without_vat' => '75.00']);
        $preview = $this->preview($sid, "sku;price\nLEGACY-CLEAR;\n", [
            'mapping' => ['sku' => 'sku', 'price' => 'price'], 'blank' => 'clear',
        ]);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $this->container->get(CatalogImportService::class)->apply($sid, $preview['id'], $this->userId);
        $applied = $this->container->get(CatalogImportWorker::class)->tickKind($sid, CatalogImportService::APPLY_KIND);
        self::assertSame(1, $applied['report']['counts']['applied']);
        self::assertNull($this->itemsRepo->find($sid, $id)['sale_price_without_vat']);
    }

    public function testFixedPriceComparisonIgnoresCalculationDayButKeepsAmountChanges(): void
    {
        $sid = $this->createSupplier();
        $this->container->get(\MyInvoice\Repository\StockCurrencyRepository::class)->insert($sid, ['code' => 'CZK', 'name' => 'CZK', 'is_default' => true]);
        $id = $this->itemsRepo->insert($sid, ['sku' => 'DATED', 'name' => 'Fixture']);
        $this->container->get(\MyInvoice\Service\Eshop\Pricing\PriceWriteService::class)->save($sid, $id, [
            ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '100.00'],
        ]);
        $this->db->pdo()->prepare("UPDATE stock_item_prices SET computed_context = JSON_SET(computed_context, '$.output.calculation_date', '2000-01-01') WHERE supplier_id = ? AND stock_item_id = ?")->execute([$sid, $id]);
        $csv = "sku;prices\nDATED;\"[{\"\"currency_code\"\":\"\"CZK\"\",\"\"price_mode\"\":\"\"fixed\"\",\"\"fixed_price\"\":\"\"100\"\"}]\"\n";
        $config = ['mapping' => ['sku' => 'sku', 'prices' => 'prices']];
        $unchanged = $this->preview($sid, $csv, $config);
        self::assertSame(1, $unchanged['report']['counts']['unchanged']);
        $changed = $this->preview($sid, str_replace('100', '120', $csv), $config);
        self::assertSame(1, $changed['report']['counts']['ready']);
        $this->db->pdo()->prepare("UPDATE catalog_job_items SET after_json = JSON_SET(after_json, '$.prices[0].computed_context.output.calculation_date', '2000-01-01') WHERE supplier_id = ? AND job_id = ?")->execute([$sid, $changed['id']]);
        $this->container->get(CatalogImportService::class)->apply($sid, $changed['id'], $this->userId);
        $applied = $this->container->get(CatalogImportWorker::class)->tickKind($sid, CatalogImportService::APPLY_KIND);
        self::assertSame(1, $applied['report']['counts']['applied']);
        self::assertSame('120.00', $this->itemsRepo->find($sid, $id)['sale_price_without_vat']);
    }

    public function testSimpleCzkPriceImportPreservesOtherCurrenciesAndNormalizesNoOp(): void
    {
        $sid = $this->createSupplier();
        $id = $this->itemsRepo->insert($sid, ['sku' => 'PRICE', 'name' => 'Fixture']);
        $this->container->get(\MyInvoice\Service\Eshop\Pricing\PriceWriteService::class)->save($sid, $id, [
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '9.99'],
        ]);
        $config = ['mapping' => ['sku' => 'sku', 'price' => 'cena']];
        $preview = $this->preview($sid, "sku;cena\nPRICE;100,00\n", $config);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $this->container->get(CatalogImportService::class)->apply($sid, $preview['id'], $this->userId);
        $result = $this->container->get(CatalogImportWorker::class)->tickKind($sid, CatalogImportService::APPLY_KIND);
        self::assertSame(1, $result['report']['counts']['applied']);
        $prices = $this->container->get(\MyInvoice\Repository\StockItemPriceRepository::class);
        self::assertSame('9.99', $prices->findByCurrency($sid, $id, 'EUR')['computed_price']);
        self::assertSame('100.00', $this->itemsRepo->find($sid, $id)['sale_price_without_vat']);
        $repeat = $this->preview($sid, "sku;cena\nPRICE;100\n", $config);
        self::assertSame(1, $repeat['report']['counts']['unchanged']);
    }

    public function testTenThousandRowsResumeWithoutDuplicateItems(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;name\n";
        for ($i = 1; $i <= 10000; $i++) {
            $csv .= 'RESUME-' . $i . ";Fixture\n";
        }
        $preview = $this->preview($sid, $csv, ['mapping' => ['sku' => 'sku', 'name' => 'name']]);
        self::assertSame('queued', $preview['status']);
        self::assertSame(1000, $preview['checkpoint']);
        $worker = $this->container->get(CatalogImportWorker::class);
        do {
            $preview = $worker->tickKind($sid, CatalogImportService::STAGE_KIND);
        } while ($preview['status'] === 'queued');
        self::assertSame('completed', $preview['status']);
        self::assertSame(10000, $preview['report']['counts']['ready']);
        $this->container->get(CatalogImportService::class)->apply($sid, $preview['id'], $this->userId);
        $first = $worker->tickKind($sid, CatalogImportService::APPLY_KIND, 1);
        self::assertSame(100, $first['checkpoint']);
        do {
            $result = $worker->tickKind($sid, CatalogImportService::APPLY_KIND);
        } while ($result['status'] === 'queued');
        self::assertSame(10000, $result['report']['counts']['applied']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_items WHERE supplier_id = ?');
        $stmt->execute([$sid]);
        self::assertSame(10000, (int) $stmt->fetchColumn());
    }

    public function testExternalIdentityDoesNotFallBackToMatchingSku(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $id = $this->itemsRepo->insert($sid, ['sku' => 'EXISTING', 'name' => 'Original']);
        $config = ['identity' => 'external_id', 'source_key' => 'fixture', 'mapping' => ['external_id' => 'id', 'sku' => 'sku', 'name' => 'name']];
        $collision = $this->preview($sid, "id;sku;name\n000123;EXISTING;Wrong\n", $config);
        self::assertSame(1, $collision['report']['counts']['failed']);
        self::assertSame('Original', $this->itemsRepo->find($sid, $id)['name']);
        $preview = $this->preview($other, "id;sku;name\n000123;EXISTING;Other tenant\n", $config);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $this->container->get(CatalogImportService::class)->apply($other, $preview['id'], $this->userId);
        $result = $this->container->get(CatalogImportWorker::class)->tickKind($other, CatalogImportService::APPLY_KIND);
        self::assertSame(1, $result['report']['counts']['applied']);
        $repeat = $this->preview($other, "id;sku;name\n000123;EXISTING;Other tenant\n", $config);
        self::assertSame(1, $repeat['report']['counts']['unchanged']);
    }

    public function testNewerUpdateConflictsWithoutOverwriting(): void
    {
        $sid = $this->createSupplier();
        $id = $this->itemsRepo->insert($sid, ['sku' => 'CAS', 'name' => 'Before']);
        $preview = $this->preview($sid, "sku;name\nCAS;Imported\n", ['mapping' => ['sku' => 'sku', 'name' => 'name']]);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $this->itemsRepo->update($sid, $id, ['sku' => 'CAS', 'name' => 'Newer']);
        $this->container->get(CatalogImportService::class)->apply($sid, $preview['id'], $this->userId);
        $applied = $this->container->get(CatalogImportWorker::class)->tickKind($sid, CatalogImportService::APPLY_KIND);
        self::assertSame(1, $applied['report']['counts']['conflict']);
        self::assertSame('Newer', $this->itemsRepo->find($sid, $id)['name']);
    }
}
