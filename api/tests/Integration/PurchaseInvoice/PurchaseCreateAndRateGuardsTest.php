<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExchangeRateAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Veřejné API přijatých faktur (audit MCP 2026-11):
 *  - POST /purchase-invoices neurčuje stav dokladu: `status: received` z těla by obešel
 *    přechod (schvalování, automatické zaúčtování, pokladní doklad);
 *  - zaokrouhlení zadané při založení se uloží stejně jako při úpravě;
 *  - POST /purchase-invoices/{id}/exchange-rate odmítne zaúčtovaný a stornovaný doklad,
 *    protože deník nepřeúčtuje;
 *  - API token zaúčtovanou fakturu nestornuje (storno zápisu je ruční účetní úkon).
 *
 * Data jsou syntetická (rok 2096), vše v transakci s rollbackem.
 */
#[Group('integration')]
final class PurchaseCreateAndRateGuardsTest extends TestCase
{
    private Connection $db;
    private CreatePurchaseInvoiceAction $create;
    private SetPurchaseInvoiceExchangeRateAction $rate;

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
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreatePurchaseInvoiceAction::class);
            $this->rate   = $c->get(SetPurchaseInvoiceExchangeRateAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent > 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
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
             VALUES (?, "TEST create guards dodavatel (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "create-guards-vendor@example.test", "cs", ?, 1, 1)'
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

    /** BEZ OPRAVY PADÁ: doklad vznikl rovnou jako přijatý s interním číslem, bez přechodu stavu. */
    public function testCreateIgnoresStatusFromBody(): void
    {
        $id = $this->createInvoice(['status' => 'received']);

        self::assertSame('draft', $this->column($id, 'status'), 'Stav určuje jen přechod, ne tělo POST.');
    }

    /** BEZ OPRAVY PADÁ: zaokrouhlení zadané při založení se ztratilo (ukládal ho jen PUT). */
    public function testCreateKeepsRounding(): void
    {
        $id = $this->createInvoice(['rounding' => -0.4]);

        self::assertEqualsWithDelta(-0.4, (float) $this->column($id, 'rounding'), 0.0001);
    }

    /** BEZ OPRAVY PADÁ: kurz zaúčtovaného dokladu se změnil bez přeúčtování deníku. */
    public function testExchangeRateRefusedForPostedDocument(): void
    {
        $id = $this->createInvoice();
        $this->markPosted($id);
        $before = $this->column($id, 'exchange_rate');

        $res = $this->setRate($id, 26.5);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('posted_document', $res['body']['error']['code'] ?? null);
        self::assertSame($before, $this->column($id, 'exchange_rate'));
    }

    /** BEZ OPRAVY PADÁ: storno je neměnné, endpoint ho přesto přepsal. */
    public function testExchangeRateRefusedForCancelledDocument(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$id]);

        $res = $this->setRate($id, 26.5);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('not_editable', $res['body']['error']['code'] ?? null);
    }

    /** BEZ OPRAVY PADÁ: token stornoval zaúčtovanou přijatou fakturu i s jejím zápisem v deníku. */
    public function testTokenCannotCancelPostedDocument(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);
        $this->markPosted($id);

        $res = $this->transition($id, 'cancelled', 'bearer');

        self::assertSame(403, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        self::assertSame('received', $this->column($id, 'status'));
    }

    public function testTokenMayCancelUnpostedDocument(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);

        $res = $this->transition($id, 'cancelled', 'bearer');

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('cancelled', $this->column($id, 'status'));
    }

    public function testExchangeRateAllowedForDraft(): void
    {
        $id = $this->createInvoice();

        $res = $this->setRate($id, 26.5);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(26.5, (float) $this->column($id, 'exchange_rate'), 0.0001);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $extra */
    private function createInvoice(array $extra = []): int
    {
        $body = $extra + [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'GUARD-' . bin2hex(random_bytes(3)),
            'document_kind'         => 'invoice',
            'issue_date'            => '2096-07-02',
            'tax_date'              => '2096-07-02',
            'due_date'              => '2096-07-16',
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => [[
                'description'            => 'Dodávka (PHPUnit)',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ];
        $created = self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        return (int) $created['body']['id'];
    }

    private function markPosted(int $id): void
    {
        $pdo = $this->db->pdo();
        $periodId = (int) $pdo->query(
            "SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($periodId === 0) {
            self::markTestSkipped('Bez účetního období nelze založit zápis.');
        }
        $pdo->prepare(
            "INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at)
             VALUES (?, ?, '2096-07-02', 'Test zaúčtování', 'purchase_invoice', ?, NOW())"
        )->execute([$this->supplierId, $periodId, $id]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function transition(int $id, string $target, string $auth): array
    {
        $request = $this->request('POST', ['target' => $target])->withAttribute(AuthMiddleware::ATTR_METHOD, $auth);
        if ($auth === 'bearer') {
            $request = $request->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write']);
        }
        $action = Bootstrap::buildContainer()->get(TransitionPurchaseInvoiceStatusAction::class);

        return self::decode($action($request, new Psr7Response(), ['id' => (string) $id]));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function setRate(int $id, float $rate): array
    {
        return self::decode(($this->rate)(
            $this->request('POST', ['rate' => $rate, 'rate_date' => '2096-07-02', 'source' => 'manual']),
            new Psr7Response(),
            ['id' => (string) $id],
        ));
    }

    private function column(int $id, string $column): mixed
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM purchase_invoices WHERE id = ?");
        $stmt->execute([$id]);

        return $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
