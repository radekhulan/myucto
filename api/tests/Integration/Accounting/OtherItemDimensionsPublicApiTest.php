<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DimensionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\OtherDocumentsPostingScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dimenze konceptu ostatní pohledávky nebo závazku, vydané a přijaté faktury přes
 * veřejné API s tokenem (MCP `set_other_item_dimensions`, `set_invoice_dimensions`,
 * `set_purchase_invoice_dimensions`). Token smí jen koncept: u zaúčtovaného dokladu
 * by uložení přerazítkovalo řádky deníku, a to zůstává webovému rozhraní.
 */
#[Group('integration')]
final class OtherItemDimensionsPublicApiTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private OtherDocumentsPostingScenarios $scenarios;
    private DimensionService $dimensions;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->scenarios = new OtherDocumentsPostingScenarios($this->container);
        $this->scenarios->run();
        $this->userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testTokenSetsDimensionsOfDraft(): void
    {
        $draft = $this->draft();
        [$cc, $project] = [$this->scenarios->types()['cost_center'], $this->scenarios->types()['project']];
        $header = [$cc => $this->value($cc, 'REGR-C1'), $project => $this->value($project, 'REGR-P1')];

        $res = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $draft, ['header' => $header]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        $saved = $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $draft)['header'];
        ksort($saved);
        ksort($header);
        self::assertSame($header, $saved);

        $read = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $draft, ['header' => $header], 'read');
        self::assertSame('insufficient_scope', $read['body']['error']['code'] ?? null);
    }

    public function testTokenCannotTagPostedItemOrOtherDocuments(): void
    {
        $cc = $this->scenarios->types()['cost_center'];
        $header = [$cc => $this->value($cc, 'REGR-C1')];
        $posted = $this->scenarios->ids['other_payable'];
        $before = $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $posted)['header'];

        $res = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $posted, ['header' => $header]);
        self::assertSame(403, $res['status']);
        self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        self::assertSame($before, $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $posted)['header']);

        foreach (['cash-documents', 'assets', 'bank-transactions'] as $doc) {
            $other = $this->call('PUT', "/api/v1/accounting/dimensions/documents/$doc/1", ['header' => $header]);
            self::assertSame(403, $other['status'], $doc);
            self::assertSame('token_write_forbidden', $other['body']['error']['code'] ?? null, $doc);
        }
        $preview = $this->call('POST', '/api/v1/accounting/dimensions/documents/other-items/' . $posted . '/preview', ['header' => $header]);
        self::assertSame('token_write_forbidden', $preview['body']['error']['code'] ?? null);

        // Webové rozhraní zaúčtovaný doklad přerazítkovat smí; guard je jen pro token.
        $session = $this->call('PUT', '/api/accounting/dimensions/documents/other-items/' . $posted, ['header' => $header], 'read_write', 'session');
        self::assertSame(200, $session['status'], json_encode($session['body'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testTokenNeedsOtherItemsWritePermission(): void
    {
        $draft = $this->draft();
        $cc = $this->scenarios->types()['cost_center'];
        $res = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $draft,
            ['header' => [$cc => $this->value($cc, 'REGR-C1')]], 'read_write', 'bearer', 'readonly');
        self::assertSame(403, $res['status']);
        self::assertSame([], $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $draft)['header']);
    }

    /** BEZ OPRAVY PADÁ: token dimenze faktur nesměl zapsat vůbec, ani ke konceptu. */
    public function testTokenSetsDimensionsOfInvoiceDrafts(): void
    {
        [$cc, $project] = [$this->scenarios->types()['cost_center'], $this->scenarios->types()['project']];
        $header = [$cc => $this->value($cc, 'REGR-C1')];
        $items = [1 => [$project => $this->value($project, 'REGR-P1')], 2 => [$project => $this->value($project, 'REGR-P2')]];

        foreach ([['invoices', 'invoice', $this->invoiceDraft()], ['purchase-invoices', 'purchase_invoice', $this->purchaseDraft()]] as [$doc, $type, $id]) {
            $res = $this->call('PUT', "/api/v1/accounting/dimensions/documents/$doc/$id", ['header' => $header, 'items' => $items]);
            self::assertSame(200, $res['status'], $doc . ': ' . (json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: ''));
            $saved = $this->dimensions->documentDimensions($this->scenarios->supplierId, $type, $id);
            self::assertSame($header, $saved['header'], $doc);
            self::assertSame($items, $saved['items'], $doc);

            $denied = $this->call('PUT', "/api/v1/accounting/dimensions/documents/$doc/$id", ['header' => $header], 'read_write', 'bearer', 'readonly');
            self::assertSame(403, $denied['status'], $doc);
        }
    }

    public function testTokenCannotTagIssuedOrPostedInvoices(): void
    {
        $cc = $this->scenarios->types()['cost_center'];
        $header = [$cc => $this->value($cc, 'REGR-C1')];
        foreach ([['invoices', 'invoice', $this->scenarios->ids['settled']], ['purchase-invoices', 'purchase_invoice', $this->scenarios->ids['purchase']]] as [$doc, $type, $id]) {
            $before = $this->dimensions->documentDimensions($this->scenarios->supplierId, $type, $id)['header'];
            $res = $this->call('PUT', "/api/v1/accounting/dimensions/documents/$doc/$id", ['header' => $header]);
            self::assertSame(403, $res['status'], $doc);
            self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null, $doc);
            self::assertSame($before, $this->dimensions->documentDimensions($this->scenarios->supplierId, $type, $id)['header'], $doc);
        }
    }

    private function invoiceDraft(): int
    {
        $pdo = $this->db->pdo();
        $src = $pdo->query('SELECT client_id, currency_id FROM invoices WHERE id = ' . (int) $this->scenarios->ids['settled'])->fetch(\PDO::FETCH_ASSOC);
        $date = OtherDocumentsPostingScenarios::YEAR . '-06-10';
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                                   reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, ?, 0, 200, 0, 200, 0, "draft", ?)'
        )->execute([$this->scenarios->supplierId, $src['client_id'], $date, $date, $date, $src['currency_id'], $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->items('invoice_items', 'invoice_id', $id);
        return $id;
    }

    private function purchaseDraft(): int
    {
        $pdo = $this->db->pdo();
        $src = $pdo->query('SELECT vendor_id, currency_id FROM purchase_invoices WHERE id = ' . (int) $this->scenarios->ids['purchase'])->fetch(\PDO::FETCH_ASSOC);
        $date = OtherDocumentsPostingScenarios::YEAR . '-06-11';
        $pdo->prepare(
            'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, due_date,
                                            received_at, currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "{}", 200, "draft", ?)'
        )->execute([$this->scenarios->supplierId, $src['vendor_id'], 'DIM-' . random_int(100000, 999999), $date, $date, $date, $src['currency_id'], $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id);
        return $id;
    }

    private function items(string $table, string $parent, int $id): void
    {
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([0, 1] as $order) {
            $this->db->pdo()->prepare(
                "INSERT INTO {$table} ({$parent}, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                       total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, 'Syntetická položka', 1, 'ks', 100, ?, 0, 100, 0, 100, ?)"
            )->execute([$id, $vatRateId, $order]);
        }
    }

    private function draft(): int
    {
        $item = $this->container->get(OtherItemService::class)->create($this->scenarios->supplierId, [
            'side' => 'receivable', 'kind' => 'loan', 'title' => 'Syntetická zápůjčka',
            'issued_on' => OtherDocumentsPostingScenarios::YEAR . '-05-01',
            'due_on' => OtherDocumentsPostingScenarios::YEAR . '-12-31', 'amount' => 50000,
            'counter_account_code' => '221',
        ], null);
        return (int) $item['id'];
    }

    private function value(int $typeId, string $code): int
    {
        return $this->scenarios->value($typeId, $code);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        string $method,
        string $path,
        array $body,
        string $scope = 'read_write',
        string $auth = 'bearer',
        string $role = 'accountant',
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->scenarios->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope])
            ->withParsedBody($body);

        $action = $this->container->get(DimensionAction::class);
        $router = new class ($action) implements RequestHandlerInterface {
            public function __construct(private readonly DimensionAction $action) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $path = $request->getUri()->getPath();
                if (preg_match('#^/api/accounting/dimensions/documents/([a-z-]+)/([0-9]+)$#', $path, $m) === 1
                    && $request->getMethod() === 'PUT') {
                    return $this->action->saveDocument($request, new Psr7Response(), ['doc' => $m[1], 'id' => $m[2]]);
                }
                return (new ResponseFactory())->createResponse(404);
            }
        };
        $scopeHandler = new class ($router) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ApiScopeMiddleware(new ResponseFactory()))->process($request, $this->next);
            }
        };

        $response = (new ApiVersionRewriteMiddleware())->process($request, $scopeHandler);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
