<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * PUT /api/invoices/{id} a klíč `items`.
 *
 * `replaceItems()` se volal NEPODMÍNĚNĚ z `(array) ($body['items'] ?? [])`, takže
 * částečný payload bez klíče `items` — oprava DUZP, poznámky — smazal dokladu všechny
 * řádky a nechal ho na nule. Editor to nevyvolá (položky posílá vždycky), takže se na to
 * běžným používáním nepřijde; dopadá to na integrace a skripty.
 *
 * Pravidlo je v {@see \MyInvoice\Service\Invoice\DocumentItemsPayload}.
 *
 * Bez obalové transakce (akce končí přepočtem revenue cache s vlastní transakcí),
 * úklid v tearDown přes vlastního klienta. Data jsou syntetická.
 */
#[Group('integration')]
final class PartialUpdateItemsTest extends TestCase
{
    private const ISSUE_DATE = '2096-04-10';
    private const TAX_DATE   = '2096-04-10';
    private const DUE_DATE   = '2096-05-10';

    private Connection $db;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;

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
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreateInvoiceAction::class);
            $this->update = $c->get(UpdateInvoiceAction::class);
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

    /** Doklad, který žádné řádky nemá, nemá co ztratit — prázdné pole je tam no-op. */
    public function testEmptyItemsOnDocumentWithoutItemsIsNoOp(): void
    {
        $body = $this->payload();
        $body['items'] = [];
        $created = ($this->create)($this->request('POST', $body), new Psr7Response());
        $created = self::decode($created);
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];

        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->itemCount($id));
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
        $stmt = $this->db->pdo()->prepare('SELECT description FROM invoice_items WHERE invoice_id = ?');
        $stmt->execute([$id]);
        self::assertSame('Jediný nový řádek', (string) $stmt->fetchColumn());
    }

    /**
     * Desetinná místa podle jednotky (2036) se kontrolují jen u nových a změněných řádků.
     * Doklad s řádkem uloženým dřív, než jednotka dostala nastavení, musí jít dál ukládat:
     * bez klíče `items` i s řádky poslanými beze změny (editor posílá vždy všechny).
     * Změněné množství už kontrolou neprojde.
     *
     * BEZ OPRAVY PADÁ: PUT s řádky beze změny vracel 400 a doklad nešel uložit vůbec.
     */
    public function testUnitDecimalsCheckSkipsUnchangedStoredRows(): void
    {
        $code = 'pu' . substr(bin2hex(random_bytes(4)), 0, 6);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO units (code, label_cs, label_en) VALUES (?, ?, ?)')->execute([$code, $code, $code]);
        try {
            $body = $this->payload();
            $body['items'][0]['unit'] = $code;
            $body['items'][0]['quantity'] = 1.5;
            $created = self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
            self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
            $id = (int) $created['body']['id'];

            $pdo->prepare('UPDATE units SET quantity_decimals = 0 WHERE code = ?')->execute([$code]);

            $partial = $body;
            unset($partial['items']);
            $partial['note_above_items'] = 'Jen poznámka.';
            $res = $this->put($id, $partial);
            self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

            // Starý řádek beze změny (jiná jednotka zápisu, jiné pořadí) projde.
            $unchanged = $body;
            $unchanged['note_above_items'] = 'Změna hlavičky.';
            $unchanged['items'][0]['unit'] = strtoupper($code);
            $unchanged['items'] = array_reverse($unchanged['items']);
            $res = $this->put($id, $unchanged);
            self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

            // Změněné množství ne, a nic se nesmaže.
            $changed = $body;
            $changed['items'][0]['quantity'] = 2.5;
            $res = $this->put($id, $changed);
            self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
            self::assertSame([['index' => 0, 'unit' => $code, 'decimals' => 0]], $res['body']['error']['items'] ?? null);
            self::assertSame(2, $this->itemCount($id), 'Odmítnutý požadavek nesmí nic smazat.');

            // Nový řádek se stejným množstvím jako starý (druhá kopie) se kontroluje.
            $copy = $body;
            $copy['items'][] = $body['items'][0];
            $res = $this->put($id, $copy);
            self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            self::assertSame([['index' => 2, 'unit' => $code, 'decimals' => 0]], $res['body']['error']['items'] ?? null);
        } finally {
            $pdo->prepare('DELETE FROM units WHERE code = ?')->execute([$code]);
        }
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
            'items'              => [
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

    private function itemCount(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);

        return (int) $stmt->fetchColumn();
    }

    private function totalWithVat(int $invoiceId): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_with_vat FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);

        return (float) $stmt->fetchColumn();
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
             VALUES (?, "TEST partial items (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "partial-items@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
