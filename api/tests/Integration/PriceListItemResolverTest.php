<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Action\PriceList\PriceListItemAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PriceListItemRepository;
use MyInvoice\Service\Invoice\PriceListItemResolver;
use MyInvoice\Service\Invoice\PriceListResolutionException;
use MyInvoice\Service\Invoice\RecurringPriceListService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class PriceListItemResolverTest extends TestCase
{
    private Connection $db;
    private PriceListItemRepository $items;
    private PriceListItemResolver $resolver;
    private RecurringPriceListService $recurringPrices;
    private PriceListItemAction $action;
    private int $supplierId;
    private int $clientId;
    private int $currencyId;
    private string $currencyCode;
    private int $vatRateId;
    private ?int $createdItemId = null;
    private ?bool $originalStockEnabled = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) $this->markTestSkipped('Container not available');
            $this->db = $container->get(Connection::class);
            $this->items = $container->get(PriceListItemRepository::class);
            $this->resolver = $container->get(PriceListItemResolver::class);
            $this->recurringPrices = $container->get(RecurringPriceListService::class);
            $this->action = $container->get(PriceListItemAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $context = $this->db->pdo()->query(
            "SELECT s.id AS supplier_id, c.id AS client_id, cur.id AS currency_id,
                    cur.code AS currency_code
               FROM supplier s
               JOIN clients c ON c.supplier_id = s.id AND c.is_customer = 1
               JOIN currencies cur ON cur.supplier_id = s.id AND cur.is_active = 1
              ORDER BY s.id, c.id, cur.is_default DESC, cur.id
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$context) $this->markTestSkipped('Missing supplier/client/currency test context');

        $this->supplierId = (int) $context['supplier_id'];
        $this->clientId = (int) $context['client_id'];
        $this->currencyId = (int) $context['currency_id'];
        $this->currencyCode = (string) $context['currency_code'];
        $this->vatRateId = (int) $this->db->pdo()->query(
            'SELECT id FROM vat_rates WHERE is_reverse_charge = 0 ORDER BY is_default DESC, id LIMIT 1'
        )->fetchColumn();
        if ($this->vatRateId <= 0) $this->markTestSkipped('Missing VAT rate');

        // Ceník je dostupný jen firmě BEZ skladového modulu (PriceListItemAction::
        // unavailableForStock → 409 price_list_unavailable). Normalizace tenanta
        // v tests/bootstrap.php ale `stock_enabled` zapíná kvůli skladovým testům,
        // takže se na okolní stav spoléhat nelze — nastavíme si ho explicitně.
        // Původní hodnotu si držíme pro tearDown; snímá se JEN tady, ať ji pozdější
        // zapnutí skladu uvnitř testu nepřepsalo (jinak by tenant zůstal s vypnutým
        // skladem a rozbil skladové testy, které běží po nás).
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $this->originalStockEnabled = (bool) $stmt->fetchColumn();
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->originalStockEnabled !== null && isset($this->supplierId)) {
            $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = ? WHERE id = ?')
                ->execute([$this->originalStockEnabled ? 1 : 0, $this->supplierId]);
        }
        if (isset($this->db) && $this->createdItemId !== null) {
            $this->db->pdo()->prepare('DELETE FROM price_list_items WHERE id = ?')->execute([$this->createdItemId]);
        }
        if (isset($this->db)) $this->db->close();
    }

    public function testCustomerPriceOverridesCatalogPriceInTargetCurrency(): void
    {
        $this->createdItemId = $this->createItem(false, 1250.00);
        self::assertNull($this->items->find($this->createdItemId, 0), 'Item must not leak across supplier scope');
        $netItems = $this->items->listForSupplier(
            $this->supplierId, 'Synthetic price list item', false, 1, 50,
            $this->currencyCode, $this->clientId, false,
        );
        $grossItems = $this->items->listForSupplier(
            $this->supplierId, 'Synthetic price list item', false, 1, 50,
            $this->currencyCode, $this->clientId, true,
        );
        self::assertContains($this->createdItemId, array_column($netItems['data'], 'id'));
        self::assertNotContains($this->createdItemId, array_column($grossItems['data'], 'id'));
        $this->items->upsertCustomerOverride(
            $this->supplierId,
            $this->createdItemId,
            $this->clientId,
            $this->currencyCode,
            1100.00,
        );

        $resolved = $this->resolver->resolveMany(
            [$this->createdItemId],
            $this->supplierId,
            $this->clientId,
            $this->currencyId,
            false,
            new DateTimeImmutable('2026-01-15'),
        )[$this->createdItemId];

        self::assertSame('customer_explicit', $resolved['catalog_price_source']);
        self::assertSame(1100.00, $resolved['unit_price_without_vat']);
        self::assertNull($resolved['catalog_exchange_rate']);
    }

    public function testResolverRejectsPriceModeMismatch(): void
    {
        $this->createdItemId = $this->createItem(true, 1210.00);

        try {
            $this->resolver->resolveMany(
                [$this->createdItemId],
                $this->supplierId,
                $this->clientId,
                $this->currencyId,
                false,
                new DateTimeImmutable('2026-01-15'),
            );
            self::fail('Expected price mode mismatch');
        } catch (PriceListResolutionException $e) {
            self::assertSame('price_mode_mismatch', $e->errorCode);
            self::assertSame($this->createdItemId, $e->priceListItemId);
        }
    }

    public function testListEndpointReturnsResolvedCustomerPriceForRequestedDate(): void
    {
        $this->createdItemId = $this->createItem(false, 1250.00);
        $this->items->upsertCustomerOverride(
            $this->supplierId,
            $this->createdItemId,
            $this->clientId,
            $this->currencyCode,
            1100.00,
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/price-list-items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withQueryParams([
                'q' => 'Synthetic price list item',
                'currency' => $this->currencyCode,
                'client_id' => (string) $this->clientId,
                'prices_include_vat' => '0',
                'rate_date' => '2026-01-15',
            ]);
        $response = $this->action->list($request, new Psr7Response());
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        $row = array_values(array_filter(
            $payload['data'],
            fn (array $item): bool => (int) $item['id'] === $this->createdItemId,
        ))[0] ?? null;
        self::assertNotNull($row);
        self::assertSame('customer_explicit', $row['resolved_price']['catalog_price_source']);
        self::assertSame(1100, $row['resolved_price']['unit_price_without_vat']);
    }

    public function testRecurringPoliciesKeepRefreshOrReviewSnapshot(): void
    {
        $this->createdItemId = $this->createItem(false, 1250.00);
        $baseItem = [
            'description' => 'Template description',
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price_without_vat' => 0,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
            'price_list_item_id' => $this->createdItemId,
            'description_source' => 'catalog',
        ];
        $referenceDate = new DateTimeImmutable('2026-01-15');

        $snapshots = [];
        foreach (['fixed', 'current', 'review_required'] as $policy) {
            $snapshots[$policy] = $this->recurringPrices->prepareForSave(
                [[...$baseItem, 'catalog_policy' => $policy]],
                $this->supplierId,
                $this->clientId,
                $this->currencyId,
                false,
                $referenceDate,
                true,
            )[0];
        }
        $this->items->upsertPrice(
            $this->supplierId,
            $this->createdItemId,
            $this->currencyCode,
            1500.00,
        );
        $priceCount = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM price_list_item_prices
              WHERE supplier_id = ? AND price_list_item_id = ? AND currency_code = ?'
        );
        $priceCount->execute([$this->supplierId, $this->createdItemId, $this->currencyCode]);
        self::assertSame(1, (int) $priceCount->fetchColumn(), 'Repeated save must update, not duplicate, a currency price');

        $fixed = $this->recurringPrices->resolveForGeneration(
            [$snapshots['fixed']], $this->supplierId, $this->clientId,
            $this->currencyId, false, $referenceDate,
        )[0];
        $current = $this->recurringPrices->resolveForGeneration(
            [$snapshots['current']], $this->supplierId, $this->clientId,
            $this->currencyId, false, $referenceDate,
        )[0];

        self::assertSame(1250.00, $fixed['unit_price_without_vat']);
        self::assertSame(1500.00, $current['unit_price_without_vat']);

        try {
            $this->recurringPrices->resolveForGeneration(
                [$snapshots['review_required']], $this->supplierId, $this->clientId,
                $this->currencyId, false, $referenceDate,
            );
            self::fail('Expected recurring price review requirement');
        } catch (PriceListResolutionException $e) {
            self::assertSame('price_list_review_required', $e->errorCode);
        }

        // Druhá polovina scénáře: se ZAPNUTÝM skladem. Původní hodnota už je sejmutá
        // ze setUp(), tady se jen přepíná — opětovné snímání by ji přepsalo nulou
        // a tearDown by tenanta vrátil do špatného stavu.
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);

        $stockSnapshot = $this->recurringPrices->resolveForGeneration(
            [$snapshots['current']], $this->supplierId, $this->clientId,
            $this->currencyId, false, $referenceDate,
        )[0];
        self::assertSame(1250.00, $stockSnapshot['unit_price_without_vat']);
        self::assertSame('fixed', $stockSnapshot['catalog_policy']);

        $savedWithStock = $this->recurringPrices->prepareForSave(
            [$snapshots['current']], $this->supplierId, $this->clientId,
            $this->currencyId, false, $referenceDate, false,
        )[0];
        self::assertNull($savedWithStock['price_list_item_id']);
        self::assertSame('fixed', $savedWithStock['catalog_policy']);
        self::assertSame(1250.00, $savedWithStock['unit_price_without_vat']);
    }

    public function testInvoicePriceReadsAcceptVendorWithoutPromotingAndKeepTenantScope(): void
    {
        $pdo = $this->db->pdo();
        $this->createdItemId = $this->createItem(false, 1250.00);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE clients SET is_customer = 0, is_vendor = 1 WHERE id = ?')->execute([$this->clientId]);
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/price-list-items')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withQueryParams(['client_id' => (string) $this->clientId, 'currency' => $this->currencyCode, 'currency_id' => $this->currencyId]);
            self::assertSame(200, $this->action->list($request, new Psr7Response())->getStatusCode());
            $resolved = $this->action->resolve($request, new Psr7Response(), ['id' => $this->createdItemId]);
            self::assertSame(200, $resolved->getStatusCode(), (string) $resolved->getBody());
            self::assertSame(0, (int) $pdo->query('SELECT is_customer FROM clients WHERE id = ' . $this->clientId)->fetchColumn());
            self::assertFalse($this->items->clientExists($this->supplierId, $this->clientId));
            self::assertFalse($this->items->clientExists(0, $this->clientId, false));
        } finally {
            $pdo->rollBack();
        }
    }

    private function createItem(bool $pricesIncludeVat, float $price): int
    {
        return $this->items->create($this->supplierId, [
            'code' => 'PHPUNIT-' . bin2hex(random_bytes(4)),
            'name' => 'Synthetic price list item',
            'description' => 'Synthetic recurring service',
            'unit' => 'ks',
            'vat_rate_id' => $this->vatRateId,
            'prices_include_vat' => $pricesIncludeVat,
            'base_currency_code' => $this->currencyCode,
            'allow_exchange_rate_conversion' => false,
            'archived' => false,
        ], [[
            'currency_code' => $this->currencyCode,
            'unit_price' => $price,
            'archived' => false,
        ]]);
    }
}
