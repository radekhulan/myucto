<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Eshop\ProductPromoPriceWriteService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemCustomerPriceService;
use MyInvoice\Service\Stock\StockPriceLevelService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ceny karty pod haléř (issue #295): levný materiál za 0,004 EUR/ks se nesmí uložit
 * jako nula. Cena karty, cenotvorba, zákaznická, akční a hladinová cena drží šest
 * desetinných míst; haléřová cena se čte beze změny („12.50").
 */
#[Group('integration')]
final class SubCentUnitPriceTest extends StockTestCase
{
    private const TODAY = '2099-06-15';

    public function testCardSalePriceKeepsSixDecimalsAndCentPriceReadsUnchanged(): void
    {
        $sid = $this->createSupplier();
        $cheap = $this->itemsRepo->insert($sid, ['sku' => 'SC-CAP', 'name' => 'Víčko', 'item_type' => 'goods', 'unit' => 'ks',
            'sale_price_without_vat' => '0.004', 'is_active' => true]);
        $precise = $this->itemsRepo->insert($sid, ['sku' => 'SC-SCREW', 'name' => 'Šroubek', 'item_type' => 'goods', 'unit' => 'ks',
            'sale_price_without_vat' => '0.0108', 'is_active' => true]);
        $cent = $this->itemsRepo->insert($sid, ['sku' => 'SC-CENT', 'name' => 'Krabice', 'item_type' => 'goods', 'unit' => 'ks',
            'sale_price_without_vat' => '12.5', 'is_active' => true]);

        self::assertSame('0.004', $this->itemsRepo->find($sid, $cheap)['sale_price_without_vat']);
        self::assertSame('0.0108', $this->itemsRepo->find($sid, $precise)['sale_price_without_vat']);
        self::assertSame('12.50', $this->itemsRepo->find($sid, $cent)['sale_price_without_vat']);
        $found = array_column($this->itemsRepo->search($sid, 'SC-', 10), 'sale_price_without_vat', 'sku');
        self::assertSame(['SC-CAP' => '0.004', 'SC-CENT' => '12.50', 'SC-SCREW' => '0.0108'], self::sorted($found));

        $resolver = $this->container->get(EffectivePriceResolver::class);
        self::assertSame('0.004', $resolver->resolve($sid, $cheap, 'CZK', '250000', self::TODAY)['unit_price']);
        self::assertSame('12.50', $resolver->resolve($sid, $cent, 'CZK', '1', self::TODAY)['unit_price']);
    }

    public function testFixedPricingKeepsSixDecimalsAndRejectsMorePrecision(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'SC-FIXED');
        $writer = $this->container->get(PriceWriteService::class);

        $writer->save($sid, $item, [['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '0.004']]);
        $row = $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'EUR');
        self::assertSame(['0.004', '0.004'], [$row['fixed_price'], $row['computed_price']]);
        self::assertSame('0.004', $this->container->get(EffectivePriceResolver::class)->resolve($sid, $item, 'EUR', '1', self::TODAY)['unit_price']);

        $writer->save($sid, $item, [['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '100']]);
        self::assertSame('100.00', $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'CZK')['computed_price']);

        $this->expectException(\InvalidArgumentException::class);
        $writer->save($sid, $item, [['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '0.0000001']]);
    }

    public function testCustomerPriceKeepsSixDecimalsAndRejectsMorePrecision(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'SC-CUSTOMER');
        $client = $this->client($sid);
        $service = $this->container->get(StockItemCustomerPriceService::class);

        $saved = $service->save($sid, $item, [['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '0.0108']]);
        self::assertSame('0.0108', $saved[0]['fixed_price']);
        self::assertSame('0.0108', $this->container->get(EffectivePriceResolver::class)->resolve($sid, $item, 'CZK', '1', self::TODAY, $client)['unit_price']);

        foreach (['0.0000001', 1.0E-7, '99999999999'] as $tooPrecise) {
            try {
                $service->save($sid, $item, [['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => $tooPrecise]]);
                self::fail('Cena ' . var_export($tooPrecise, true) . ' nesmí projít.');
            } catch (StockException $e) {
                self::assertSame(['validation_failed', 422, 'fixed_price'], [$e->errorCode, $e->httpStatus, $e->details['field']]);
            }
        }
        self::assertSame('0.0108', $service->list($sid, $item)[0]['fixed_price'], 'Neplatné uložení nesmí cenu změnit.');
    }

    public function testPromoPriceKeepsSixDecimalsAndRejectsMorePrecision(): void
    {
        $sid = $this->createSupplier();
        $item = $this->itemsRepo->insert($sid, ['sku' => 'SC-PROMO', 'name' => 'Víčko', 'item_type' => 'goods', 'unit' => 'ks',
            'sale_price_without_vat' => '0.0108', 'is_active' => true]);
        $writer = $this->container->get(ProductPromoPriceWriteService::class);

        $saved = $writer->save($sid, $item, [['currency_code' => 'CZK', 'promo_price' => '0.004', 'qty_mode' => 'unlimited']]);
        self::assertSame('0.004', $saved[0]['promo_price']);
        $resolved = $this->container->get(EffectivePriceResolver::class)->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame(['0.004', true], [$resolved['unit_price'], $resolved['promo_applied']]);

        try {
            $writer->save($sid, $item, [['currency_code' => 'CZK', 'promo_price' => '0.0000001', 'qty_mode' => 'unlimited']]);
            self::fail('Akční cena se sedmi desetinnými místy nesmí projít.');
        } catch (EshopException $e) {
            self::assertSame(['validation_failed', 400], [$e->errorCode, $e->httpStatus]);
        }
    }

    public function testPriceLevelFixedPriceKeepsSixDecimalsAndRejectsMorePrecision(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'SC-LEVEL');
        $level = $this->container->get(\MyInvoice\Repository\StockPriceLevelRepository::class)->insert($sid, [
            'code' => 'VO', 'name' => 'Velkoobchod', 'default_discount_pct' => '0', 'is_active' => true, 'display_order' => 0,
        ]);
        $service = $this->container->get(StockPriceLevelService::class);

        $saved = $service->replaceRules($sid, $level, [['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'fixed', 'fixed_price' => '0.0108', 'currency_code' => 'CZK']]);
        self::assertSame('0.0108', $saved[0]['fixed_price']);

        try {
            $service->replaceRules($sid, $level, [['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'fixed', 'fixed_price' => '0.0000001', 'currency_code' => 'CZK']]);
            self::fail('Pevná cena se sedmi desetinnými místy nesmí projít.');
        } catch (StockException $e) {
            self::assertSame(['validation_failed', 'fixed_price'], [$e->errorCode, $e->details['field']]);
        }
        self::assertSame('0.0108', $service->rules($sid, $level)[0]['fixed_price']);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private static function sorted(array $values): array
    {
        ksort($values);
        return $values;
    }
}
