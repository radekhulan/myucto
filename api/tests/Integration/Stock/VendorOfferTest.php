<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\VendorOfferAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Stock\VendorOfferImportService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic SKLAD fáze 3 — „u dodavatele": CRUD nabídek, import ceníku, návaznost
 * na cenotvorbu a karta bez jediného pohybu (rozhodnutí #12 plánu).
 */
#[Group('integration')]
final class VendorOfferTest extends StockTestCase
{
    private VendorOfferAction $action;
    private StockItemVendorRepository $vendors;
    private StockItemPriceRepository $prices;
    private VendorOfferImportService $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action  = $this->container->get(VendorOfferAction::class);
        $this->vendors = $this->container->get(StockItemVendorRepository::class);
        $this->prices  = $this->container->get(StockItemPriceRepository::class);
        $this->import  = $this->container->get(VendorOfferImportService::class);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        string $method,
        string $httpMethod,
        int $supplierId,
        array $args = [],
        array $body = [],
        array $query = [],
    ): array {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/stock/vendor-offers')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    // ── #12: karta existuje dřív, než se cokoli objedná ───────────────────────

    public function testBrandNewCardWithOnlyVendorOffersIsFullyUsable(): void
    {
        $sid = $this->createSupplier();
        // ŽÁDNÝ sklad, ŽÁDNÁ příjemka, ŽÁDNÝ řádek ve stock_levels — jen karta.
        $item = $this->item($sid, 'VO-NEW');
        $vendor = $this->client($sid, 'Dodavatel Nový');

        $create = $this->call('create', 'POST', $sid, [], [
            'stock_item_id'      => $item,
            'client_id'          => $vendor,
            'purchase_price'     => '120.50',
            'stock_qty'          => '150',
            'availability_state' => 'in_stock',
        ]);
        self::assertSame(201, $create['status'], 'Nabídku lze navázat hned po založení karty.');

        $list = $this->call('list', 'GET', $sid, [], [], ['stock_item_id' => (string) $item]);
        self::assertSame(200, $list['status']);
        self::assertSame(1, $list['body']['total'], 'Karta bez pohybu se ze seznamu nesmí vytratit.');

        $row = $list['body']['items'][0];
        self::assertSame('0', rtrim(rtrim((string) $row['on_hand'], '0'), '.') ?: '0',
            'Bez řádku ve stock_levels je skladem 0, ne prázdno.');
        self::assertSame('150.000', $row['stock_qty']);
        self::assertSame('in_stock', $row['availability_state']);
        self::assertSame('VO-NEW', $row['sku']);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function testPatchUpdatesOnlyGivenFields(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-PATCH');
        $vendor = $this->client($sid, 'Dodavatel Patch');

        $id = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id'  => $item,
            'client_id'      => $vendor,
            'purchase_price' => '100.00',
            'vendor_sku'     => 'DOD-1',
            'delivery_days'  => 7,
            'note'           => 'Původní poznámka',
        ])['body']['id'];

        $patched = $this->call('patch', 'PATCH', $sid, ['id' => (string) $id], ['purchase_price' => '90.00']);
        self::assertSame(200, $patched['status']);
        self::assertSame('90.00', $patched['body']['purchase_price']);
        self::assertSame('DOD-1', $patched['body']['vendor_sku'], 'PATCH nesmí nulovat nepředaná pole.');
        self::assertSame(7, $patched['body']['delivery_days']);
        self::assertSame('Původní poznámka', $patched['body']['note']);

        // null u nullable sloupce hodnotu skutečně maže.
        $cleared = $this->call('patch', 'PATCH', $sid, ['id' => (string) $id], ['note' => null]);
        self::assertNull($cleared['body']['note']);
    }

    public function testStockQtyChangeStampsUpdatedAt(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-STAMP');
        $vendor = $this->client($sid, 'Dodavatel Razítko');

        $created = $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $vendor,
        ]);
        $id = (int) $created['body']['id'];
        self::assertNull($created['body']['stock_qty_updated_at'], 'Bez množství není co razítkovat.');

        $patched = $this->call('patch', 'PATCH', $sid, ['id' => (string) $id], ['stock_qty' => '42']);
        self::assertNotNull($patched['body']['stock_qty_updated_at'], 'Nové množství = nové razítko.');
    }

    public function testPreferredVendorIsExclusivePerItem(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-PREF');
        $a = $this->client($sid, 'Dodavatel A');
        $b = $this->client($sid, 'Dodavatel B');

        $first = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $a, 'is_preferred' => true,
        ])['body']['id'];
        $second = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $b, 'is_preferred' => true,
        ])['body']['id'];

        self::assertFalse($this->vendors->findOffer($sid, $first)['is_preferred']);
        self::assertTrue($this->vendors->findOffer($sid, $second)['is_preferred']);
    }

    public function testDuplicateVendorForItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-DUP');
        $vendor = $this->client($sid, 'Dodavatel Dup');

        $this->call('create', 'POST', $sid, [], ['stock_item_id' => $item, 'client_id' => $vendor]);
        $again = $this->call('create', 'POST', $sid, [], ['stock_item_id' => $item, 'client_id' => $vendor]);
        self::assertSame(409, $again['status']);
        self::assertSame('vendor_offer_exists', $again['body']['error']['code']);
    }

    public function testValidationRejectsBadValues(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-VAL');
        $vendor = $this->client($sid, 'Dodavatel Val');
        $id = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $vendor,
        ])['body']['id'];

        foreach ([
            ['availability_state' => 'nekde'],
            ['currency_code' => 'CZKK'],
            ['purchase_price' => '-1'],
            ['min_order_qty' => '0'],
            ['price_valid_to' => '31.12.2026'],
            ['data_source' => 'magie'],
        ] as $body) {
            $resp = $this->call('patch', 'PATCH', $sid, ['id' => (string) $id], $body);
            self::assertSame(422, $resp['status'], (string) array_key_first($body));
        }
    }

    public function testForeignTenantSeesNothing(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $item = $this->item($sid, 'VO-TEN');
        $vendor = $this->client($sid, 'Dodavatel Tenant');
        $id = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $vendor,
        ])['body']['id'];

        self::assertSame(404, $this->call('get', 'GET', $other, ['id' => (string) $id])['status']);
        self::assertSame(404, $this->call('patch', 'PATCH', $other, ['id' => (string) $id], ['note' => 'X'])['status']);
        self::assertSame(404, $this->call('delete', 'DELETE', $other, ['id' => (string) $id])['status']);
        self::assertSame(0, $this->call('list', 'GET', $other)['body']['total']);
    }

    public function testVendorMustBeMarkedAsVendorAndOwned(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $item = $this->item($sid, 'VO-GUARD');
        $foreignVendor = $this->client($other, 'Cizí dodavatel');

        $resp = $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $foreignVendor,
        ]);
        self::assertSame(422, $resp['status']);
        self::assertSame('vendor_invalid', $resp['body']['error']['code']);
    }

    // ── Cenotvorba: PATCH musí přepočítat jako ProductVendorAction::put() ─────

    public function testPatchRecomputesSellingPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-PRICE');
        $this->db->pdo()->prepare('UPDATE stock_items SET pricing_base = ? WHERE id = ?')
            ->execute(['manual', $item]);
        $vendor = $this->client($sid, 'Dodavatel Cena');

        $id = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $vendor,
            'purchase_price' => '200.00', 'is_preferred' => true,
        ])['body']['id'];

        $this->prices->upsert($sid, $item, 'CZK', [
            'price_mode' => 'markup', 'markup_pct' => '25', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);
        // Zápis ceníkového řádku sám o sobě nepřepočítává — vynuť výchozí stav.
        $this->call('patch', 'PATCH', $sid, ['id' => (string) $id], ['purchase_price' => '200.00']);

        $this->call('patch', 'PATCH', $sid, ['id' => (string) $id], ['purchase_price' => '400.00']);
        self::assertSame('500.00', $this->prices->findByCurrency($sid, $item, 'CZK')['computed_price'],
            'PATCH musí volat PriceRecomputeDispatcher::recomputeItem() — jinak se cenotvorba rozejde podle cesty zápisu.');
    }

    // ── Import ceníku ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function imp(int $sid, string $csv, bool $dryRun): array
    {
        return $this->import->import($sid, $this->userId, $csv, 'cenik.csv', $dryRun);
    }

    public function testImportCreatesAndUpdatesOffers(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-IMP');
        $this->client($sid, 'Alfa Dodavatel');

        $csv = "sku;dodavatel;kod_dodavatele;nakupni_cena;skladem_u_dodavatele;dostupnost;baleni\n"
            . "VO-IMP;Alfa Dodavatel;A-1;99,50;25;skladem;5\n";

        $dry = $this->imp($sid, $csv, true);
        self::assertTrue($dry['ok']);
        self::assertSame(1, $dry['created']);
        self::assertSame(0, $this->vendors->listOffers($sid)['total'], 'dry-run nesmí zapisovat');

        $real = $this->imp($sid, $csv, false);
        self::assertTrue($real['ok']);
        self::assertSame(1, $real['created']);

        $offers = $this->vendors->listOffers($sid, ['stock_item_id' => $item]);
        self::assertSame(1, $offers['total']);
        $offer = $offers['items'][0];
        self::assertSame('99.50', $offer['purchase_price']);
        self::assertSame('25.000', $offer['stock_qty']);
        self::assertSame('in_stock', $offer['availability_state']);
        self::assertSame('5.000', $offer['package_qty']);
        self::assertSame('import', $offer['data_source']);
        self::assertNotNull($offer['stock_qty_updated_at']);

        // Druhý běh se stejným souborem nic nemění.
        $again = $this->imp($sid, $csv, false);
        self::assertSame(0, $again['created']);
        self::assertSame(1, $again['skipped']);

        // Změna ceny = update, ne duplicita.
        $csv2 = "sku;dodavatel;nakupni_cena\nVO-IMP;Alfa Dodavatel;88,00\n";
        $updated = $this->imp($sid, $csv2, false);
        self::assertSame(1, $updated['updated']);
        self::assertSame(1, $this->vendors->listOffers($sid, ['stock_item_id' => $item])['total']);
        self::assertSame('88.00', $this->vendors->listOffers($sid, ['stock_item_id' => $item])['items'][0]['purchase_price']);
    }

    public function testImportNeverCreatesMasterData(): void
    {
        $sid = $this->createSupplier();
        $this->item($sid, 'VO-IMP-OK');
        $this->client($sid, 'Beta Dodavatel');

        $csv = "sku;dodavatel;nakupni_cena\n"
            . "NEEXISTUJE;Beta Dodavatel;10\n"
            . "VO-IMP-OK;Neznámý Dodavatel;10\n";

        $report = $this->imp($sid, $csv, false);
        self::assertFalse($report['ok']);
        self::assertSame(2, $report['failed'], 'Neznámé SKU ani neznámý dodavatel se nezakládají.');
        self::assertSame(0, $this->vendors->listOffers($sid)['total']);
    }

    public function testImportIsAllOrNothing(): void
    {
        $sid = $this->createSupplier();
        $this->item($sid, 'VO-AON');
        $this->client($sid, 'Gama Dodavatel');

        $csv = "sku;dodavatel;nakupni_cena\n"
            . "VO-AON;Gama Dodavatel;10\n"
            . "VO-AON;Neexistující;20\n";

        $report = $this->imp($sid, $csv, false);
        self::assertFalse($report['ok']);
        self::assertSame(0, $this->vendors->listOffers($sid)['total'], 'Jeden chybný řádek zruší celý zápis.');
    }

    public function testImportRequiresVendorColumn(): void
    {
        $sid = $this->createSupplier();
        $this->item($sid, 'VO-NOVEND');

        $report = $this->imp($sid, "sku;nakupni_cena\nVO-NOVEND;10\n", true);
        self::assertFalse($report['ok']);
        self::assertStringContainsString('dodavatel', (string) $report['rows'][0]['message']);
    }

    // ── Karta zboží: pole nabídky přežijí replace-all na kartě ───────────────

    public function testProductVendorReplaceKeepsOfferFields(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-KEEP');
        $vendor = $this->client($sid, 'Dodavatel Keep');

        $id = (int) $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item, 'client_id' => $vendor,
            'availability_state' => 'on_order', 'min_order_qty' => '10', 'package_qty' => '5',
            'price_valid_to' => '2099-12-31', 'data_source' => 'feed', 'is_active' => false,
        ])['body']['id'];
        self::assertGreaterThan(0, $id);
        $versionBefore = $this->itemsRepo->find($sid, $item)['row_version'];

        /** @var \MyInvoice\Action\Eshop\ProductVendorAction $productVendors */
        $productVendors = $this->container->get(\MyInvoice\Action\Eshop\ProductVendorAction::class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/eshop/products/' . $item . '/vendors')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody(['vendors' => [['client_id' => $vendor, 'purchase_price' => '55.00']]]);
        $resp = $productVendors->put($req, new Psr7Response(), ['id' => (string) $item]);
        self::assertSame(200, $resp->getStatusCode());

        $offer = $this->vendors->listOffers($sid, ['stock_item_id' => $item])['items'][0];
        self::assertSame('on_order', $offer['availability_state'], 'Replace-all na kartě nesmí nulovat pole nabídky.');
        self::assertSame('10.000', $offer['min_order_qty']);
        self::assertSame('5.000', $offer['package_qty']);
        self::assertSame('2099-12-31', $offer['price_valid_to']);
        self::assertSame('feed', $offer['data_source']);
        self::assertFalse($offer['is_active']);
        self::assertSame('55.00', $offer['purchase_price']);
        self::assertGreaterThan($versionBefore, $this->itemsRepo->find($sid, $item)['row_version']);
    }

    public function testMalformedProductVendorRowDoesNotDeleteExistingOffer(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'VO-MALFORMED');
        $vendor = $this->client($sid, 'Dodavatel Malformed');
        $this->call('create', 'POST', $sid, [], [
            'stock_item_id' => $item,
            'client_id' => $vendor,
            'purchase_price' => '80.00',
        ]);
        $versionBefore = $this->itemsRepo->find($sid, $item)['row_version'];

        $action = $this->container->get(\MyInvoice\Action\Eshop\ProductVendorAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/eshop/products/' . $item . '/vendors')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody(['vendors' => ['neplatný řádek']]);
        $response = $action->put($request, new Psr7Response(), ['id' => (string) $item]);

        self::assertSame(400, $response->getStatusCode());
        $offers = $this->vendors->listForItem($sid, $item);
        self::assertCount(1, $offers);
        self::assertSame('80.00', $offers[0]['purchase_price']);
        self::assertSame($versionBefore, $this->itemsRepo->find($sid, $item)['row_version']);
    }
}
