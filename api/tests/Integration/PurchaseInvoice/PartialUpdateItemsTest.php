<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * PUT /api/purchase-invoices/{id} a klíč `items` — přijatá strana téhož nálezu jako
 * {@see \MyInvoice\Tests\Integration\Invoice\PartialUpdateItemsTest}.
 *
 * `replaceItems()` se volal NEPODMÍNĚNĚ z `(array) ($body['items'] ?? [])`, takže
 * částečný payload (jen DUZP, jen poznámka) smazal dokladu všechny řádky a nechal ho
 * na nule. Pravidlo je v {@see \MyInvoice\Service\Invoice\DocumentItemsPayload}.
 *
 * Vše v transakci s rollbackem; data jsou syntetická.
 */
#[Group('integration')]
final class PartialUpdateItemsTest extends TestCase
{
    private const ISSUE_DATE = '2096-04-10';
    private const DUE_DATE   = '2096-05-10';

    private Connection $db;
    private CreatePurchaseInvoiceAction $create;
    private UpdatePurchaseInvoiceAction $update;
    private SetPurchaseInvoiceItemsAction $setItems;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->create   = $c->get(CreatePurchaseInvoiceAction::class);
            $this->update   = $c->get(UpdatePurchaseInvoiceAction::class);
            $this->setItems = $c->get(SetPurchaseInvoiceItemsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Dodavatel BEZ DIČ — CreatePurchaseInvoiceAction jinak sahá na CRPDPH (síť).
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "TEST partial items dodavatel (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "partial-items-vendor@example.test", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * BEZ OPRAVY PADÁ: payload bez klíče `items` doklad vyprázdnil a součty spadly na nulu.
     */
    public function testUpdateWithoutItemsKeyLeavesItemsUntouched(): void
    {
        $id = $this->createInvoice();
        $before = $this->totalWithVat($id);
        self::assertGreaterThan(0.0, $before, 'Doklad musí mít nenulový součet, jinak test netestuje nic.');

        $body = $this->payload();
        unset($body['items']);
        $body['note_above_items'] = 'Jen poznámka, položky neposíláme.';

        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame(2, $this->itemCount($id), 'Chybějící klíč `items` znamená „neměň", ne „smaž".');
        self::assertEqualsWithDelta($before, $this->totalWithVat($id), 0.005,
            'Součty se počítají z řádků — po částečném PUT musí zůstat stejné.');
    }

    /** `items: null` je artefakt serializace částečného těla, ne pokyn k mazání. */
    public function testUpdateWithNullItemsLeavesItemsUntouched(): void
    {
        $id = $this->createInvoice();

        $body = $this->payload();
        $body['items'] = null;

        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(2, $this->itemCount($id));
    }

    /**
     * BEZ OPRAVY PADÁ: prázdné pole doklad tiše vyprázdnilo se stavem 200.
     */
    public function testUpdateWithExplicitEmptyItemsIsRejected(): void
    {
        $id = $this->createInvoice();

        $body = $this->payload();
        $body['items'] = [];

        $res = $this->put($id, $body);
        self::assertSame(422, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('no_items', $res['body']['error']['code'] ?? null);
        self::assertSame(2, $this->itemCount($id), 'Odmítnutý požadavek nesmí nic smazat.');
    }

    /** Regrese: poslané neprázdné `items` řádky dál nahrazují. */
    public function testUpdateWithItemsStillReplacesThem(): void
    {
        $id = $this->createInvoice();

        $body = $this->payload();
        $body['items'] = [$this->item('Jediný nový řádek', 500.0)];

        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame(1, $this->itemCount($id));
        $stmt = $this->db->pdo()->prepare('SELECT description FROM purchase_invoice_items WHERE purchase_invoice_id = ?');
        $stmt->execute([$id]);
        self::assertSame('Jediný nový řádek', (string) $stmt->fetchColumn());
    }

    /**
     * Dedikovaný endpoint na položky se chová OPAČNĚ: `items` je tam celý obsah požadavku,
     * takže chybějící klíč je vada, ne „neměň". Bez toho by `{}` tiše smazalo všechny řádky.
     */
    public function testItemsEndpointRequiresTheKey(): void
    {
        $id = $this->createInvoice();

        $res = $this->putItems($id, []);
        self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(2, $this->itemCount($id), 'Odmítnutý požadavek nesmí nic smazat.');
    }

    /** Explicitní prázdné pole je na dedikovaném endpointu jednoznačný pokyn — projde. */
    public function testItemsEndpointAcceptsExplicitEmptyArray(): void
    {
        $id = $this->createInvoice();

        $res = $this->putItems($id, ['items' => []]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->itemCount($id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createInvoice(): int
    {
        $created = self::decode(($this->create)($this->request('POST', $this->payload()), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        return (int) $created['body']['id'];
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'PARTIAL-' . bin2hex(random_bytes(3)),
            'document_kind'         => 'invoice',
            'issue_date'            => self::ISSUE_DATE,
            'tax_date'              => self::ISSUE_DATE,
            'due_date'              => self::DUE_DATE,
            'received_at'           => self::ISSUE_DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => [
                $this->item('Konzultace (PHPUnit)', 1000.0),
                $this->item('Licence (PHPUnit)', 250.0),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function item(string $description, float $price): array
    {
        return [
            'description'            => $description,
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => $price,
            'vat_rate_id'            => $this->vatRateId,
        ];
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body): array
    {
        return self::decode(
            ($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id])
        );
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function putItems(int $id, array $body): array
    {
        return self::decode(
            ($this->setItems)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id])
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function itemCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoice_items WHERE purchase_invoice_id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function totalWithVat(int $id): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_with_vat FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);

        return (float) $stmt->fetchColumn();
    }
}
