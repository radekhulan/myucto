<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #38 — generický klíč `note` v těle faktury.
 *
 * OpenAPI ho dokumentovalo jako zkratku pro `note_below_items`, ale create i update
 * cesta předávaly tělo rovnou do repository, která čte jen konkrétní klíče. Doklad
 * tak vznikl s oběma poznámkami NULL a volající nedostal ani varování.
 *
 * Překlad dělá {@see \MyInvoice\Service\Invoice\InvoiceNoteAlias} nad syrovým tělem,
 * ještě než se ho dotkne cokoli dalšího — `updateDraft` váže `note_below_items = ?`
 * nepodmíněně, takže později už není co zachránit.
 *
 * Bez obalové transakce (akce si přepočítávají cache ve vlastní transakci), úklid
 * v tearDown přes vlastního klienta. Data jsou syntetická.
 */
#[Group('integration')]
final class GenericNoteAliasTest extends TestCase
{
    private const ISSUE_DATE = '2096-06-10';
    private const TAX_DATE   = '2096-06-10';
    private const DUE_DATE   = '2096-07-10';

    private Connection $db;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;
    private GetInvoiceAction $get;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreateInvoiceAction::class);
            $this->update = $c->get(UpdateInvoiceAction::class);
            $this->get    = $c->get(GetInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / vat_rates).');
        }

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    /**
     * BEZ OPRAVY PADÁ: reprodukce z issue #38 — POST jen s `note` uložil obě poznámky NULL.
     */
    public function testCreateWithGenericNoteStoresItBelowItems(): void
    {
        $body = $this->payload();
        $body['note'] = 'TEST NOTE';

        $created = self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        $id = (int) $created['body']['id'];
        self::assertSame('TEST NOTE', $this->noteBelow($id));
        self::assertNull($this->noteAbove($id), '`note` patří pod položky, nad ně nikdy.');
    }

    /** BEZ OPRAVY PADÁ: PUT jen s `note` pole tiše zahodil. */
    public function testUpdateWithGenericNoteStoresItBelowItems(): void
    {
        $id = $this->createInvoice();

        $body = $this->payload();
        $body['note'] = 'POZNÁMKA Z PUT';

        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('POZNÁMKA Z PUT', $this->noteBelow($id));
    }

    /** Konkrétní klíč má přednost — dvě pravdy o jednom sloupci nesmí soupeřit. */
    public function testExplicitNoteBelowItemsWinsOverAlias(): void
    {
        $body = $this->payload();
        $body['note']             = 'alias';
        $body['note_below_items'] = 'konkrétní';

        $created = self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame('konkrétní', $this->noteBelow((int) $created['body']['id']));
    }

    /**
     * `note` je JEN vstupní alias — odpověď GETu ho vracet nesmí (proto zmizel
     * i z výstupního schématu `Invoice` v openapi.yaml).
     */
    public function testGetDoesNotEchoGenericNoteKey(): void
    {
        $body = $this->payload();
        $body['note'] = 'TEST NOTE';
        $created = self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
        $id = (int) $created['body']['id'];

        $res = self::decode(
            ($this->get)($this->request('GET', []), new Psr7Response(), ['id' => (string) $id])
        );
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertArrayNotHasKey('note', $res['body']);
        self::assertSame('TEST NOTE', $res['body']['note_below_items'] ?? null);
    }

    public function testSupplierOrderNumberWorksWithoutProjectOnCreateAndUpdate(): void
    {
        $body = $this->payload();
        $body['project_id'] = null;
        $body['supplier_order_number'] = 'MYU000023';
        $created = self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        $id = (int) $created['body']['id'];
        self::assertSame('MYU000023', $created['body']['supplier_order_number'] ?? null);

        $body['supplier_order_number'] = 'OBJ-2026-24';
        $updated = $this->put($id, $body);
        self::assertSame(200, $updated['status'], json_encode($updated['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('OBJ-2026-24', $updated['body']['supplier_order_number'] ?? null);
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
            'invoice_type'       => 'invoice',
            'client_id'          => $this->clientId,
            'issue_date'         => self::ISSUE_DATE,
            'tax_date'           => self::TAX_DATE,
            'due_date'           => self::DUE_DATE,
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'payment_method'     => PaymentMethods::DEFAULT,
            'language'           => 'cs',
            'items'              => [$this->item('Konzultace (PHPUnit)', 1000.0)],
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

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices')
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

    private function noteBelow(int $invoiceId): ?string
    {
        return $this->noteColumn($invoiceId, 'note_below_items');
    }

    private function noteAbove(int $invoiceId): ?string
    {
        return $this->noteColumn($invoiceId, 'note_above_items');
    }

    private function noteColumn(int $invoiceId, string $column): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        return $id;
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "TEST note alias (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "note-alias@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
