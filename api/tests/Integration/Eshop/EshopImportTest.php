<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\ManufacturerRepository;
use MyInvoice\Service\Eshop\ProductImportService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic ESHOP F3 — import zboží z CSV: dry-run vs ostrý zápis, update, výrobce
 * resolution, all-or-nothing (chybný řádek zruší celý zápis).
 */
#[Group('integration')]
final class EshopImportTest extends StockTestCase
{
    private ProductImportService $import;
    private ManufacturerRepository $manufacturers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->import = $this->container->get(ProductImportService::class);
        $this->manufacturers = $this->container->get(ManufacturerRepository::class);
    }

    private function imp(int $sid, string $csv, bool $dryRun): array
    {
        return $this->import->import($sid, $this->userId, $csv, 'zbozi.csv', $dryRun);
    }

    public function testIdenticalNormalizedPriceDoesNotChangeProduct(): void
    {
        $sid = $this->createSupplier();
        $this->imp($sid, "sku;nazev;cena\nIMP-NORMAL;Test;100\n", false);
        $result = $this->imp($sid, "sku;nazev;cena\nIMP-NORMAL;Test;100\n", false);
        self::assertSame(0, $result['updated']);
    }

    public function testBlankPricePreservesExistingPrice(): void
    {
        $sid = $this->createSupplier();
        $this->imp($sid, "sku;nazev;cena\nIMP-BLANK;Test;100\n", false);
        $this->imp($sid, "sku;cena\nIMP-BLANK;\n", false);
        self::assertSame('100.00', $this->itemsRepo->findBySku($sid, 'IMP-BLANK')['sale_price_without_vat']);
    }

    public function testEqualImportedPriceReplacesMarkupRule(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'IMP-RULE');
        $wh = $this->warehouse($sid);
        $this->receiveStock($sid, $wh, $item, '1.000', 100.0);
        $this->container->get(\MyInvoice\Service\Eshop\Pricing\PriceWriteService::class)->save($sid, $item, [
            ['currency_code' => 'CZK', 'price_mode' => 'markup', 'markup_pct' => '0'],
        ]);
        $this->imp($sid, "sku;cena\nIMP-RULE;100\n", false);
        $row = $this->container->get(\MyInvoice\Repository\StockItemPriceRepository::class)->findByCurrency($sid, $item, 'CZK');
        self::assertSame('fixed', $row['price_mode']);
    }

    public function testImportedPriceRoundsHalfUp(): void
    {
        $sid = $this->createSupplier();
        $this->imp($sid, "sku;nazev;cena\nIMP-ROUND;Test;19.999\n", false);
        self::assertSame('20.00', $this->itemsRepo->findBySku($sid, 'IMP-ROUND')['sale_price_without_vat']);
    }

    public function testImportedPriceChangesEffectiveCzkWithoutRemovingEur(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'IMP-EFFECTIVE');
        $writer = $this->container->get(\MyInvoice\Service\Eshop\Pricing\PriceWriteService::class);
        $writer->save($sid, $item, [
            ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '100'],
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '20'],
        ]);
        $result = $this->imp($sid, "sku;cena\nIMP-EFFECTIVE;150\n", false);
        self::assertTrue($result['ok']);
        $prices = $this->container->get(\MyInvoice\Repository\StockItemPriceRepository::class);
        self::assertSame('150.00', $prices->findByCurrency($sid, $item, 'CZK')['computed_price']);
        self::assertSame('20.00', $prices->findByCurrency($sid, $item, 'EUR')['computed_price']);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;nazev;cena;skladem\nIMP-1;Zboží 1;199,90;ano\n";

        $report = $this->imp($sid, $csv, true);
        self::assertTrue($report['ok']);
        self::assertTrue($report['dry_run']);
        self::assertSame(1, $report['created']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'IMP-1'), 'dry-run nesmí zapisovat');
    }

    public function testRealRunCreatesGoods(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;nazev;cena;skladem;export_eshop\nIMP-2;Zboží 2;1 234,50;ne;ano\n";

        $report = $this->imp($sid, $csv, false);
        self::assertTrue($report['ok']);
        self::assertSame(1, $report['created']);

        $item = $this->itemsRepo->findBySku($sid, 'IMP-2');
        self::assertNotNull($item);
        self::assertSame('goods', $item['item_type']);
        self::assertSame('1234.50', $item['sale_price_without_vat']);
        self::assertFalse($item['is_stocked']);
        self::assertTrue($item['export_eshop']);
    }

    public function testUpdateExistingBySku(): void
    {
        $sid = $this->createSupplier();
        $this->imp($sid, "sku;nazev;cena\nIMP-3;Původní;100\n", false);

        $report = $this->imp($sid, "sku;nazev;cena\nIMP-3;Nový název;150\n", false);
        self::assertSame(1, $report['updated']);
        $item = $this->itemsRepo->findBySku($sid, 'IMP-3');
        self::assertSame('Nový název', $item['name']);
        self::assertSame('150.00', $item['sale_price_without_vat']);
    }

    public function testManufacturerResolutionAndUnknownError(): void
    {
        $sid = $this->createSupplier();
        $this->manufacturers->insert($sid, ['code' => 'ACME', 'name' => 'Acme s.r.o.']);

        $ok = $this->imp($sid, "sku;nazev;vyrobce\nIMP-4;S výrobcem;ACME\n", false);
        self::assertSame(1, $ok['created']);
        $item = $this->itemsRepo->findBySku($sid, 'IMP-4');
        self::assertNotNull($item['manufacturer_id']);

        $bad = $this->imp($sid, "sku;nazev;vyrobce\nIMP-5;Neznámý výrobce;NOPE\n", false);
        self::assertFalse($bad['ok']);
        self::assertSame(1, $bad['failed']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'IMP-5'));
    }

    public function testCaseInsensitiveDuplicateSkuRejected(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;nazev;cena\nDUP;První;100\ndup;Druhý;200\n";

        $report = $this->imp($sid, $csv, false);
        self::assertFalse($report['ok'], 'ABC/abc je v DB (CI collation) duplicita');
        self::assertSame(1, $report['failed']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'DUP'));
    }

    public function testNegativePriceRejected(): void
    {
        $sid = $this->createSupplier();
        $report = $this->imp($sid, "sku;nazev;cena\nNEG-1;Záporná;-50\n", false);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['failed']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'NEG-1'));
    }

    public function testAllOrNothingRollbackOnBadRow(): void
    {
        $sid = $this->createSupplier();
        // Řádek 1 validní (create), řádek 2 chybný (nové zboží bez názvu).
        $csv = "sku;nazev;cena\nIMP-OK;Dobrý;100\nIMP-BAD;;200\n";

        $report = $this->imp($sid, $csv, false);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['failed']);
        // All-or-nothing: ani validní řádek se nezapsal.
        self::assertNull($this->itemsRepo->findBySku($sid, 'IMP-OK'), 'chybný řádek ruší celý import');
    }
}
