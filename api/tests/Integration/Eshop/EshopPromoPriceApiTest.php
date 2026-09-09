<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\ProductPromoPriceAction;
use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * REST vrstva akčních cen (migrace 1328) — bulk replace, validace, dopočtený
 * stav a promítnutí platné ceny do našeptávače skladových karet (= to, co
 * předvyplní cenu na řádku faktury).
 *
 * Vzor AssetsApiTest: Action třídy volané přímo s ATTR_USER/ATTR_CURRENT_ID.
 */
#[Group('integration')]
final class EshopPromoPriceApiTest extends StockTestCase
{
    private ProductPromoPriceAction $action;
    private StockItemAction $itemAction;
    private StockItemPromoPriceRepository $promos;
    private StockItemPriceRepository $prices;
    private PriceCalculationService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action     = $this->container->get(ProductPromoPriceAction::class);
        $this->itemAction = $this->container->get(StockItemAction::class);
        $this->promos     = $this->container->get(StockItemPromoPriceRepository::class);
        $this->prices     = $this->container->get(StockItemPriceRepository::class);
        $this->calc       = $this->container->get(PriceCalculationService::class);
    }

    /** @param array<string,mixed> $body */
    private function request(int $supplierId, string $method, string $path, array $body = [], array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }
        return $req;
    }

    /** @return array{0:int, 1:mixed} [status, dekódované tělo] */
    private function call(callable $handler, \Psr\Http\Message\ServerRequestInterface $req, array $args): array
    {
        $res = $handler($req, new Psr7Response(), $args);
        $res->getBody()->rewind();
        $payload = json_decode((string) $res->getBody(), true);
        return [$res->getStatusCode(), $payload];
    }

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

    public function testPutCreatesUpdatesAndDeletesInOneCall(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-1');

        // 1) založení dvou akcí
        [$status, $body] = $this->call([$this->action, 'put'], $this->request($sid, 'PUT', '/', [
            'promo_prices' => [
                ['currency_code' => 'CZK', 'promo_price' => '790', 'label' => 'A', 'qty_mode' => 'unlimited'],
                ['currency_code' => 'CZK', 'promo_price' => '690', 'label' => 'B', 'qty_mode' => 'limited', 'qty_limit' => '25'],
            ],
        ]), ['id' => $item]);

        self::assertSame(200, $status);
        self::assertCount(2, $body);
        $ids = array_column($body, 'id', 'label');

        // 2) jednu upravím, druhou vypustím z payloadu → má zmizet
        [$status, $body] = $this->call([$this->action, 'put'], $this->request($sid, 'PUT', '/', [
            'promo_prices' => [
                ['id' => $ids['A'], 'currency_code' => 'CZK', 'promo_price' => '750', 'label' => 'A2', 'qty_mode' => 'unlimited'],
            ],
        ]), ['id' => $item]);

        self::assertSame(200, $status);
        self::assertCount(1, $body);
        self::assertSame('A2', $body[0]['label']);
        self::assertSame('750.00', $body[0]['promo_price']);
        self::assertSame($ids['A'], $body[0]['id'], 'Úprava nesmí založit nový řádek.');
    }

    public function testGetReturnsComputedStateAndRemainingQty(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-2');
        $this->promos->insert($sid, $item, [
            'currency_code' => 'CZK', 'promo_price' => '500.00', 'label' => 'L',
            'valid_from' => null, 'valid_to' => null,
            'qty_mode' => 'limited', 'qty_limit' => '7.000', 'is_active' => true, 'note' => null,
        ]);

        [$status, $body] = $this->call([$this->action, 'get'], $this->request($sid, 'GET', '/'), ['id' => $item]);

        self::assertSame(200, $status);
        self::assertSame('7.000', $body[0]['qty_remaining']);
        self::assertSame('active', $body[0]['state']);
    }

    public function testEffectivePriceEndpointRespectsQuantityAndDate(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'API-PROMO-3');
        $this->receiveStock($sid, $wh, $item, '3.000', 400.0);
        $this->promos->insert($sid, $item, [
            'currency_code' => 'CZK', 'promo_price' => '600.00', 'label' => null,
            'valid_from' => '2099-01-01', 'valid_to' => '2099-12-31',
            'qty_mode' => 'stock', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);

        [, $inWindow] = $this->call([$this->action, 'effective'],
            $this->request($sid, 'GET', '/', [], ['qty' => '2', 'on_date' => '2099-05-05']), ['id' => $item]);
        self::assertSame('600.00', $inWindow['unit_price']);

        [, $tooMany] = $this->call([$this->action, 'effective'],
            $this->request($sid, 'GET', '/', [], ['qty' => '9', 'on_date' => '2099-05-05']), ['id' => $item]);
        self::assertSame('1000.00', $tooMany['unit_price']);
        self::assertSame('qty_exceeds_remaining', $tooMany['promo_reason']);

        [, $outOfWindow] = $this->call([$this->action, 'effective'],
            $this->request($sid, 'GET', '/', [], ['qty' => '1', 'on_date' => '2100-01-01']), ['id' => $item]);
        self::assertSame('1000.00', $outOfWindow['unit_price']);
    }

    public function testLimitedModeWithoutLimitIsRejected(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-4');

        [$status, $body] = $this->call([$this->action, 'put'], $this->request($sid, 'PUT', '/', [
            'promo_prices' => [['currency_code' => 'CZK', 'promo_price' => '100', 'qty_mode' => 'limited']],
        ]), ['id' => $item]);

        self::assertSame(400, $status);
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame([], $this->promos->listForItem($sid, $item), 'Neplatný payload nesmí nic uložit.');
    }

    public function testMalformedRowDoesNotDeleteExistingPromoOrAdvanceVersion(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-MALFORMED');
        $promoId = $this->promos->insert($sid, $item, [
            'currency_code' => 'CZK', 'promo_price' => '500.00', 'label' => 'Původní',
            'valid_from' => null, 'valid_to' => null,
            'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);
        $versionBefore = $this->itemsRepo->find($sid, $item)['row_version'];

        [$status, $body] = $this->call([$this->action, 'put'], $this->request($sid, 'PUT', '/', [
            'promo_prices' => ['neplatný řádek'],
        ]), ['id' => $item]);

        self::assertSame(400, $status);
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame('500.00', $this->promos->find($sid, $promoId)['promo_price']);
        self::assertSame($versionBefore, $this->itemsRepo->find($sid, $item)['row_version']);
    }

    public function testStandalonePromoWriteAdvancesParentVersion(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-VERSION');
        $versionBefore = $this->itemsRepo->find($sid, $item)['row_version'];

        [$status] = $this->call([$this->action, 'put'], $this->request($sid, 'PUT', '/', [
            'promo_prices' => [[
                'currency_code' => 'CZK',
                'promo_price' => '450.00',
                'qty_mode' => 'unlimited',
            ]],
        ]), ['id' => $item]);

        self::assertSame(200, $status);
        self::assertGreaterThan($versionBefore, $this->itemsRepo->find($sid, $item)['row_version']);
    }

    public function testInvertedWindowIsRejected(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-5');

        [$status] = $this->call([$this->action, 'put'], $this->request($sid, 'PUT', '/', [
            'promo_prices' => [[
                'currency_code' => 'CZK', 'promo_price' => '100',
                'valid_from' => '2099-12-31', 'valid_to' => '2099-01-01',
            ]],
        ]), ['id' => $item]);

        self::assertSame(400, $status);
    }

    public function testPromoOfAnotherTenantCannotBeHijackedById(): void
    {
        $sidA = $this->createSupplier();
        $sidB = $this->createSupplier();
        $itemA = $this->pricedItem($sidA, 'API-PROMO-A');
        $itemB = $this->pricedItem($sidB, 'API-PROMO-B');
        $foreign = $this->promos->insert($sidA, $itemA, [
            'currency_code' => 'CZK', 'promo_price' => '111.00', 'label' => 'cizi',
            'valid_from' => null, 'valid_to' => null,
            'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);

        [$status] = $this->call([$this->action, 'put'], $this->request($sidB, 'PUT', '/', [
            'promo_prices' => [['id' => $foreign, 'currency_code' => 'CZK', 'promo_price' => '1.00']],
        ]), ['id' => $itemB]);

        self::assertSame(404, $status);
        self::assertSame('111.00', $this->promos->find($sidA, $foreign)['promo_price'], 'Cizí akce zůstala nedotčená.');
    }

    public function testStockItemSearchQuotesThePromoPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-SEARCH');
        $this->promos->insert($sid, $item, [
            'currency_code' => 'CZK', 'promo_price' => '333.00', 'label' => 'Vyprodej',
            'valid_from' => null, 'valid_to' => null,
            'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);

        [$status, $body] = $this->call([$this->itemAction, 'search'],
            $this->request($sid, 'GET', '/', [], ['q' => 'API-PROMO-SEARCH']), []);

        self::assertSame(200, $status);
        $row = $body[0];
        // Řádek faktury bere effective_price — akční cena se musí propsat až sem.
        self::assertSame('333.00', $row['effective_price']);
        self::assertSame('333.00', $row['promo_price']);
        self::assertSame('Vyprodej', $row['promo_label']);
        self::assertSame('1000.00', $row['sale_price_without_vat'], 'Standardní hladina zůstává k dispozici pro UI.');
    }

    public function testStockItemDetailCarriesEffectivePrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'API-PROMO-DETAIL');
        $this->promos->insert($sid, $item, [
            'currency_code' => 'CZK', 'promo_price' => '444.00', 'label' => null,
            'valid_from' => null, 'valid_to' => null,
            'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);

        [$status, $body] = $this->call([$this->itemAction, 'get'], $this->request($sid, 'GET', '/'), ['id' => $item]);

        self::assertSame(200, $status);
        self::assertSame('444.00', $body['effective_price']);
    }

    public function testStockDisabledCompanyGets403(): void
    {
        $sid = $this->createSupplier('tax_evidence', false, false);
        [$status, $body] = $this->call([$this->action, 'get'], $this->request($sid, 'GET', '/'), ['id' => 1]);
        self::assertSame(403, $status);
        self::assertSame('stock_disabled', $body['error']['code']);
    }
}
