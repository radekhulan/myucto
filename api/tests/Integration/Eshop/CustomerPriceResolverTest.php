<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemCustomerPriceService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Individuální ceny zákazníků (issue #17) — {@see EffectivePriceResolver} s `$clientId`.
 *
 * Zákaznická cena platná k datu a v měně dokladu nahrazuje standardní cenu jako
 * základ; akce vyhraje jen tehdy, když je levnější než tento základ. Bez klienta
 * se nic nemění.
 */
#[Group('integration')]
final class CustomerPriceResolverTest extends StockTestCase
{
    private const TODAY = '2099-06-15';

    private EffectivePriceResolver $resolver;
    private StockItemPriceRepository $prices;
    private PriceCalculationService $calc;
    private StockItemPromoPriceRepository $promos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->container->get(EffectivePriceResolver::class);
        $this->prices = $this->container->get(StockItemPriceRepository::class);
        $this->calc = $this->container->get(PriceCalculationService::class);
        $this->promos = $this->container->get(StockItemPromoPriceRepository::class);
    }

    public function testWithoutClientCustomerPriceIsIgnored(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-NOCLIENT');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, ['fixed_price' => '850.00']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame('1000.00', $r['unit_price']);
        self::assertArrayNotHasKey('price_source', $r, 'Bez klienta je výsledek beze změny — žádné nové klíče.');
        self::assertArrayNotHasKey('customer_price_id', $r);
    }

    public function testFixedCustomerPriceReplacesStandardPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-FIXED');
        $client = $this->client($sid);
        $id = $this->customerPrice($sid, $item, $client, ['fixed_price' => '850.00']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('850.00', $r['unit_price']);
        self::assertSame('850.00', $r['base_price']);
        self::assertSame('1000.00', $r['standard_price']);
        self::assertSame('customer_fixed', $r['price_source']);
        self::assertSame($id, $r['customer_price_id']);

        $other = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $this->client($sid, 'Jiný odběratel'));
        self::assertSame('1000.00', $other['unit_price'], 'Cena jednoho odběratele nesmí platit pro jiného.');
    }

    public function testDiscountIsComputedFromStandardPriceOnSixDecimals(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-DISC');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, ['price_type' => 'discount_pct', 'fixed_price' => null, 'discount_pct' => '12.500']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('875.00', $r['unit_price']);
        self::assertSame('customer_discount', $r['price_source']);

        self::assertSame('666.663333', EffectivePriceResolver::customerBaseline('999.99', ['price_type' => 'discount_pct', 'discount_pct' => '33.333', 'fixed_price' => null]));
        self::assertSame('0.009', EffectivePriceResolver::customerBaseline('0.01', ['price_type' => 'discount_pct', 'discount_pct' => '10', 'fixed_price' => null]));
        self::assertSame('850.00', EffectivePriceResolver::customerBaseline('1000.00', ['price_type' => 'fixed', 'discount_pct' => null, 'fixed_price' => '850.000000']));
        self::assertNull(EffectivePriceResolver::customerBaseline(null, ['price_type' => 'discount_pct', 'discount_pct' => '10.000', 'fixed_price' => null]));
    }

    public function testValidityWindowIsInclusive(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-WINDOW');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, ['fixed_price' => '850.00', 'valid_from' => self::TODAY, 'valid_to' => '2099-06-20']);

        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2099-06-14', $client)['unit_price']);
        self::assertSame('850.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client)['unit_price']);
        self::assertSame('850.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2099-06-20', $client)['unit_price']);
        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2099-06-21', $client)['unit_price']);
    }

    public function testCustomerPriceInOtherCurrencyDoesNotApply(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-CURRENCY');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, ['currency_code' => 'EUR', 'fixed_price' => '30.00']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('1000.00', $r['unit_price']);
        self::assertArrayNotHasKey('price_source', $r, 'Cena v jiné měně se nepoužila — výsledek je standardní.');
    }

    public function testCheaperPromoBeatsCustomerPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-PROMO-WIN');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, ['fixed_price' => '850.00']);
        $this->promo($sid, $item, '790.00');

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('790.00', $r['unit_price']);
        self::assertSame('promo', $r['price_source']);
        self::assertSame('850.00', $r['base_price']);
    }

    public function testPromoNotCheaperThanCustomerPriceIsIgnored(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-PROMO-LOSE');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, ['fixed_price' => '700.00']);
        $this->promo($sid, $item, '790.00');

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('700.00', $r['unit_price']);
        self::assertSame('customer_fixed', $r['price_source']);
        self::assertSame('not_cheaper', $r['promo_reason']);

        // Bez klienta akce platí dál.
        self::assertSame('790.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['unit_price']);
    }

    // ── editor zákaznických cen ─────────────────────────────────────────────

    public function testEditorValidatesAndReportsResultingPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'CP-EDITOR');
        $client = $this->client($sid);
        $service = $this->container->get(StockItemCustomerPriceService::class);

        $saved = $service->save($sid, $item, [
            ['client_id' => $client, 'currency_code' => 'czk', 'price_type' => 'discount_pct', 'discount_pct' => 10, 'valid_from' => '2099-01-01', 'valid_to' => null, 'note' => 'Rámcová smlouva'],
        ]);
        self::assertCount(1, $saved);
        self::assertSame('CZK', $saved[0]['currency_code']);
        self::assertSame('900.00', $saved[0]['resulting_price']);
        self::assertSame('Testovací klient', $saved[0]['client_name']);

        $cases = [
            'customer_price_duplicate' => [
                ['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '1'],
                ['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '2'],
            ],
            'invalid_client' => [
                ['client_id' => $this->client($this->createSupplier()), 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '1'],
            ],
            'validation_failed' => [
                ['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'discount_pct', 'discount_pct' => '120'],
            ],
        ];
        foreach ($cases as $expected => $body) {
            try {
                $service->save($sid, $item, $body);
                self::fail("Očekávám $expected.");
            } catch (StockException $e) {
                self::assertSame($expected, $e->errorCode);
            }
        }
        try {
            $service->save($sid, $item, [['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '1', 'valid_from' => '2099-02-01', 'valid_to' => '2099-01-01']]);
            self::fail('Platnost od po platnosti do nesmí projít.');
        } catch (StockException $e) {
            self::assertSame('valid_to', $e->details['field']);
        }
        self::assertCount(1, $service->list($sid, $item), 'Neplatné uložení nesmí sadu změnit.');
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function pricedItem(int $supplierId, string $sku, string $price = '1000.00'): int
    {
        $item = $this->item($supplierId, $sku);
        $this->prices->upsert($supplierId, $item, 'CZK', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => $price,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);
        $this->calc->recompute($supplierId, $item);
        return $item;
    }

    /** @param array<string,mixed> $over */
    private function customerPrice(int $supplierId, int $itemId, int $clientId, array $over = []): int
    {
        $row = array_merge([
            'currency_code' => 'CZK', 'price_type' => 'fixed', 'fixed_price' => '850.00', 'discount_pct' => null,
            'valid_from' => null, 'valid_to' => null,
        ], $over);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_customer_prices
                (supplier_id, stock_item_id, client_id, currency_code, price_type, fixed_price, discount_pct, valid_from, valid_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $itemId, $clientId, $row['currency_code'], $row['price_type'], $row['fixed_price'], $row['discount_pct'], $row['valid_from'], $row['valid_to']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function promo(int $supplierId, int $itemId, string $price): void
    {
        $this->promos->insert($supplierId, $itemId, [
            'currency_code' => 'CZK', 'promo_price' => $price, 'label' => 'Akce', 'valid_from' => null,
            'valid_to' => null, 'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);
    }
}
