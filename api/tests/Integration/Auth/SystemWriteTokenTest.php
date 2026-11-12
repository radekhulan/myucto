<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiRequestLogMiddleware;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Routes;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Token s plným zápisem (`system_write`, #371) skrz skutečný řetězec middleware
 * (ověření tokenu, log volání, firma, oprávnění, scope tokenu) a skutečné routy,
 * `/api/...` i `/api/v1/...`.
 *
 * Instance se zapíná proměnnou prostředí `MYINVOICE_API_ALLOW_SYSTEM_WRITE_TOKENS`
 * před sestavením kontejneru; vše běží v jedné transakci nad jeho připojením a na
 * konci se vrací. Data jsou syntetická.
 */
#[Group('integration')]
final class SystemWriteTokenTest extends TestCase
{
    use ChargeIssuanceFixture;

    private const AMOUNT = 1000.0;
    private const CLOSED_YEAR = 2094;

    private ?ContainerInterface $container = null;
    private ?Connection $db = null;
    private ?App $app = null;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private int $czkId = 0;
    private int $vendorId = 0;
    private string $date = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        $this->db = null;
        $this->container = null;
        $this->app = null;
    }

    public function testWithoutFlagAccountingStaysReadOnly(): void
    {
        $this->boot(true);
        $token = $this->token(false);
        $purchase = $this->purchase('received');
        $other = $this->otherItemDraft();

        foreach ([
            ['POST', '/api/v1/purchase-invoices/' . $purchase . '/transition', ['target' => 'booked']],
            ['POST', '/api/v1/accounting/other-items/' . $other . '/post', []],
            ['POST', '/api/invoices/' . $purchase . '/book', []],
            ['POST', '/api/bank-transactions/' . $purchase . '/post', []],
        ] as [$method, $path, $body]) {
            $res = $this->call($token, $method, $path, $body);
            self::assertSame(403, $res['status'], $path . ' ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null, $path);
        }
        self::assertSame('received', $this->purchaseStatus($purchase));
    }

    public function testFlagWithoutInstanceSettingBehavesAsPlainToken(): void
    {
        $this->boot(false);
        $token = $this->token(true);
        $purchase = $this->purchase('received');

        $res = $this->call($token, 'POST', '/api/v1/purchase-invoices/' . $purchase . '/transition', ['target' => 'booked']);
        self::assertSame(403, $res['status']);
        self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        self::assertSame('received', $this->purchaseStatus($purchase));

        $me = $this->call($token, 'GET', '/api/v1/auth/api-me');
        self::assertSame(200, $me['status']);
        self::assertFalse($me['body']['token']['system_write']);
    }

    public function testSystemWriteTokenPostsLikeSession(): void
    {
        $this->boot(true);
        $token = $this->token(true);

        $me = $this->call($token, 'GET', '/api/auth/api-me');
        self::assertTrue($me['body']['token']['system_write']);

        $purchase = $this->purchase('received');
        $res = $this->call($token, 'POST', '/api/v1/purchase-invoices/' . $purchase . '/transition', ['target' => 'booked']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('booked', $this->purchaseStatus($purchase));

        $other = $this->otherItemDraft();
        $res = $this->call($token, 'POST', '/api/v1/accounting/other-items/' . $other . '/post', []);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('posted', $this->otherItemStatus($other));
        $res = $this->call($token, 'POST', '/api/accounting/other-items/' . $other . '/reverse', ['reason' => 'Syntetické storno']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNotSame('posted', $this->otherItemStatus($other));

        foreach ([
            ['POST', '/api/v1/invoices/999999999/book'],
            ['POST', '/api/v1/bank-transactions/999999999/post'],
        ] as [$method, $path]) {
            $res = $this->call($token, $method, $path, []);
            self::assertNotContains($res['body']['error']['code'] ?? null,
                ['token_write_forbidden', 'token_endpoint_forbidden', 'session_required', 'forbidden_permission'], $path);
        }

        $audit = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE action = 'api_token.system_write_call' AND entity_id = ? ORDER BY id"
        );
        $audit->execute([$token['id']]);
        $payloads = array_map(static fn ($p): array => json_decode((string) $p, true), $audit->fetchAll(\PDO::FETCH_COLUMN));
        self::assertGreaterThanOrEqual(3, count($payloads));
        self::assertTrue($payloads[0]['via_system_write_token']);
        self::assertSame(['id' => $token['id'], 'name' => $token['name'], 'prefix' => $token['prefix']], $payloads[0]['api_token']);
        self::assertStringNotContainsString(hash('sha256', $token['plaintext']), json_encode($payloads));

        $log = $this->db->pdo()->prepare('SELECT DISTINCT scope_used FROM api_request_log WHERE token_id = ?');
        $log->execute([$token['id']]);
        self::assertSame(['system_write'], $log->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testSystemWriteTokenWritesPropertyAgenda(): void
    {
        $this->boot(true);
        $f = $this->issuanceFixture($this->db);
        $this->supplierId = (int) $f['supplier'];
        $plain = $this->token(false);
        $token = $this->token(true);

        $registry = ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'reason' => 'Syntetický dům',
            'title_ref' => 'Syntetické prohlášení', 'idempotency_key' => 'system-write-house', 'code' => 'SW1',
            'state' => ['name' => 'Syntetický dům', 'active' => true,
                'address' => ['street' => 'Syntetická 1', 'city' => 'Syntetické', 'postal_code' => '10000', 'country' => 'CZ'],
                'declaration_ref' => 'Syntetické prohlášení', 'share_scope' => 'SW1', 'registry_complete' => false]];
        $denied = $this->call($plain, 'POST', '/api/property/houses', $registry);
        self::assertSame(403, $denied['status']);
        $house = $this->call($token, 'POST', '/api/property/houses', $registry);
        self::assertNotContains($house['body']['error']['code'] ?? null, ['token_write_forbidden', 'session_required', 'forbidden'],
            json_encode($house['body'], JSON_UNESCAPED_UNICODE));

        $request = $f['issue_request'];
        $denied = $this->call($plain, 'POST', '/api/v1/property/charges/issues/preview', $request);
        self::assertSame(403, $denied['status']);
        self::assertContains($denied['body']['error']['code'] ?? null, ['token_write_forbidden', 'token_endpoint_forbidden']);
        $preview = $this->call($token, 'POST', '/api/v1/property/charges/issues/preview', $request);
        self::assertSame(200, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));

        $issued = $this->call($token, 'POST', '/api/property/charges/issues', [
            'request' => $request, 'preview_hash' => $preview['body']['preview_hash'], 'idempotency_key' => 'system-write-issue',
        ]);
        self::assertSame(200, $issued['status'], json_encode($issued['body'], JSON_UNESCAPED_UNICODE));
        $batchId = (int) ($issued['body']['batch']['id'] ?? $issued['body']['id'] ?? 0);
        self::assertGreaterThan(0, $batchId, json_encode(array_keys($issued['body'])));

        $post = $this->call($token, 'POST', '/api/property/charges/issues/batches/' . $batchId . '/post', []);
        self::assertNotContains($post['body']['error']['code'] ?? null, ['token_write_forbidden', 'session_required', 'forbidden'],
            json_encode($post['body'], JSON_UNESCAPED_UNICODE));
    }

    /** Uzavřené období token nepřebije ani jako správce a ani s plným zápisem. */
    public function testClosedPeriodCannotBeForcedNorReopened(): void
    {
        $this->boot(true);
        $token = $this->token(true);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, 'closed')"
        )->execute([$this->supplierId, self::CLOSED_YEAR, self::CLOSED_YEAR . '-01-01', self::CLOSED_YEAR . '-12-31']);
        $periodId = (int) $pdo->lastInsertId();
        $date = self::CLOSED_YEAR . '-06-15';
        $invoice = $this->invoice('draft', $date);
        $body = $this->invoiceBody($date) + ['note_below_items' => 'Uzavřené období (PHPUnit)'];

        $res = $this->call($token, 'PUT', '/api/v1/invoices/' . $invoice . '?force=1', $body);
        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_closed', $res['body']['error']['code'] ?? null);
        $note = $pdo->query("SELECT note_below_items FROM invoices WHERE id = $invoice")->fetchColumn();
        self::assertNotSame('Uzavřené období (PHPUnit)', $note);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'document_lock.force_override' AND entity_id = ?");
        $cnt->execute([$invoice]);
        self::assertSame(0, (int) $cnt->fetchColumn());

        $version = (int) $pdo->query("SELECT row_version FROM accounting_periods WHERE id = $periodId")->fetchColumn();
        $res = $this->call($token, 'POST', '/api/accounting/periods/' . $periodId . '/status',
            ['status' => 'open', 'reason' => 'Znovuotevření přes token (PHPUnit)', 'row_version' => max(1, $version)]);
        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_closed', $res['body']['error']['code'] ?? null);
        self::assertSame('closed', $pdo->query("SELECT status FROM accounting_periods WHERE id = $periodId")->fetchColumn());
    }

    /** Uzamčené datum (`locked_until`) token vynucenou úpravou nepřebije ani jako správce. */
    public function testDateLockedDocumentCannotBeForced(): void
    {
        $this->boot(true);
        $token = $this->token(true);
        $pdo = $this->db->pdo();
        $this->lockUntil($this->date);
        $purchase = $this->purchase('received');

        $res = $this->call($token, 'PUT', '/api/v1/purchase-invoices/' . $purchase . '?force=1', $this->purchaseBody($purchase, 2 * self::AMOUNT));
        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_closed', $res['body']['error']['code'] ?? null);
        self::assertEqualsWithDelta(self::AMOUNT, (float) $pdo->query(
            "SELECT total_without_vat FROM purchase_invoices WHERE id = $purchase"
        )->fetchColumn(), 0.001);
    }

    /** Podané DPH token nepřebije ani jako správce a ani s plným zápisem. */
    public function testFiledPeriodCannotBeOverridden(): void
    {
        $this->boot(true);
        $token = $this->token(true);
        $pdo = $this->db->pdo();
        $purchase = $this->purchase('received');
        $amount = fn (): float => (float) $pdo->query("SELECT total_without_vat FROM purchase_invoices WHERE id = $purchase")->fetchColumn();

        $year = (int) substr($this->date, 0, 4);
        $month = (int) substr($this->date, 5, 2);
        $pdo->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at,
                 summary_json, generated_by)
             VALUES (?, 'dphdp3', ?, ?, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', ?, '{}', ?)"
        )->execute([$this->supplierId, $year, $month, hash('sha256', 'system-write'), $this->date . ' 09:00:00', $this->userId]);

        $res = $this->call($token, 'PUT', '/api/v1/purchase-invoices/' . $purchase . '?force=1', $this->purchaseBody($purchase, 2 * self::AMOUNT));
        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_filed', $res['body']['error']['code'] ?? null);
        self::assertEqualsWithDelta(self::AMOUNT, $amount(), 0.001);

        $this->lockUntil($this->date);
        $res = $this->call($token, 'PUT', '/api/accounting/period-lock', ['locked_until' => null, 'reason' => 'Posun zámku přes token (PHPUnit)']);
        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertContains($res['body']['error']['code'] ?? null, ['period_lock_filed_vat', 'period_lock_closed_period']);
        self::assertSame($this->date, (string) $pdo->query(
            "SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = {$this->supplierId}"
        )->fetchColumn());
    }

    /** Token se nedostane k přihlášení, MFA, heslu ani správě tokenů. */
    public function testSecuritySurfacesStayClosed(): void
    {
        $this->boot(true);
        $token = $this->token(true);
        foreach ([
            ['GET', '/api/auth/tokens'],
            ['POST', '/api/auth/tokens', ['name' => 'Další token', 'scope' => 'read_write', 'allow_system_write' => true]],
            ['POST', '/api/auth/change-password', ['current_password' => 'x', 'new_password' => 'y']],
            ['POST', '/api/auth/totp/setup'],
            ['GET', '/api/auth/webauthn/credentials'],
            ['GET', '/api/v1/admin/users'],
            ['GET', '/api/license/status'],
        ] as $call) {
            $res = $this->call($token, $call[0], $call[1], $call[2] ?? null);
            self::assertSame(403, $res['status'], $call[1]);
            self::assertSame('token_endpoint_forbidden', $res['body']['error']['code'] ?? null, $call[1]);
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND name LIKE ?');
        $count->execute([$this->userId, 'Další token%']);
        self::assertSame(0, (int) $count->fetchColumn(), 'Token nesmí založit další token.');
    }

    /** Platí jen práva uživatele tokenu: role bez práva zaúčtovat zůstane u 403. */
    public function testUserPermissionsStillApply(): void
    {
        $this->boot(true);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, "staff", 1)')
            ->execute(['Účetní bez zaúčtování (PHPUnit) ' . bin2hex(random_bytes(3))]);
        $roleId = (int) $pdo->lastInsertId();
        foreach (['invoices' => 2, 'purchase_invoices' => 2, 'accounting' => 1] as $key => $level) {
            $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)')
                ->execute([$roleId, $key, $level]);
        }
        $pdo->prepare('INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, "accountant", ?, "cs", 1)')
            ->execute(['system-write-' . bin2hex(random_bytes(4)) . '@example.test',
                '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'Účetní (PHPUnit)', $roleId]);
        $this->userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$this->userId, $this->supplierId]);
        $token = $this->token(true);

        $invoice = $this->invoice('issued', $this->date, 'SW' . random_int(10000000, 99999999));
        $res = $this->call($token, 'POST', '/api/v1/invoices/' . $invoice . '/book', []);
        self::assertSame(403, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('forbidden_permission', $res['body']['error']['code'] ?? null);

        $other = $this->otherItemDraft();
        $res = $this->call($token, 'POST', '/api/accounting/other-items/' . $other . '/post', []);
        self::assertSame(403, $res['status']);
        self::assertSame('draft', $this->otherItemStatus($other));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function boot(bool $instanceAllows): void
    {
        putenv('MYINVOICE_API_ALLOW_SYSTEM_WRITE_TOKENS=' . ($instanceAllows ? '1' : '0'));
        try {
            $this->container = Bootstrap::buildContainer();
        } finally {
            putenv('MYINVOICE_API_ALLOW_SYSTEM_WRITE_TOKENS');
        }
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());

        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.system_key = 'superadmin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->czkId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $this->markTestSkipped('Dodavatel nemá měnu CZK.');
        }
        $year = (int) date('Y');
        $this->date = $year . '-03-10';

        $pdo->beginTransaction();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $this->container->get(AccountingPeriodRepository::class);
        if ($periods->findByYear($this->supplierId, $year) === null) {
            $periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        }
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "Plný zápis partner s.r.o. (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "system-write@example.test", "cs", ?, 1, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->vendorId = (int) $pdo->lastInsertId();

        AppFactory::setContainer($this->container);
        $app = AppFactory::create();
        Routes::register($app);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(ApiScopeMiddleware::class);
        $app->add(PermissionMiddleware::class);
        $app->add(SupplierScopeMiddleware::class);
        $app->add(ApiRequestLogMiddleware::class);
        $app->add(AuthMiddleware::class);
        $app->add(new ApiVersionRewriteMiddleware());
        $this->app = $app;
    }

    /** @return array{plaintext:string, prefix:string, id:int, name:string} */
    private function token(bool $systemWrite): array
    {
        $name = 'Plný zápis (PHPUnit) ' . bin2hex(random_bytes(3));
        $out = $this->container->get(ApiTokenService::class)->generateInTransaction(
            $this->db->pdo(),
            $this->userId,
            $this->supplierId,
            $name,
            'read_write',
            new \DateTimeImmutable('+1 day'),
            seatGate: false,
            allowSystemWrite: $systemWrite,
        );

        return $out + ['name' => $name];
    }

    /**
     * @param array{plaintext:string} $token
     * @param array<string,mixed>|null $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(array $token, string $method, string $path, ?array $body = null): array
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '203.0.113.71'])
            ->withQueryParams($params)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $token['plaintext']);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return self::decode($this->app->handle($request));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function purchase(string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, booked_at,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, 1, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, NULL, "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'SYSWRITE-' . bin2hex(random_bytes(3)),
            $this->date, $this->date, $this->date, $this->date, $this->czkId,
            self::AMOUNT, self::AMOUNT, $status, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, "Služba (PHPUnit)", 1, "ks", ?, ?, 0, ?, 0, ?, 0, "40")'
        )->execute([$id, self::AMOUNT, $this->vatRateId, self::AMOUNT, self::AMOUNT]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function purchaseBody(int $id, float $price): array
    {
        $row = $this->db->pdo()->query("SELECT * FROM purchase_invoices WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);

        return [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => $row['vendor_invoice_number'],
            'document_kind' => 'invoice',
            'issue_date' => $row['issue_date'],
            'tax_date' => $row['tax_date'],
            'due_date' => $row['due_date'],
            'received_at' => $row['received_at'],
            'currency_id' => $this->czkId,
            'vat_classification_code' => '40',
            'items' => [[
                'description' => 'Služba (PHPUnit)', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => $price, 'vat_rate_id' => $this->vatRateId,
                'vat_classification_code' => '40',
            ]],
        ];
    }

    private function purchaseStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = $id")->fetchColumn();
    }

    private function invoice(string $status, string $date, ?string $varsymbol = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date,
                                   due_date, currency_id, status, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, ?, 0, ?)'
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, $date, $date, $date, $this->czkId, $status, $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function invoiceBody(string $date): array
    {
        return [
            'client_id' => $this->vendorId,
            'invoice_type' => 'invoice',
            'issue_date' => $date,
            'tax_date' => $date,
            'due_date' => $date,
            'currency_id' => $this->czkId,
            'items' => [],
        ];
    }

    private function otherItemDraft(): int
    {
        $item = $this->container->get(OtherItemService::class)->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'loan', 'title' => 'Syntetická zápůjčka (PHPUnit)',
            'issued_on' => $this->date, 'due_on' => substr($this->date, 0, 4) . '-12-31', 'amount' => 50000,
            'counter_account_code' => '221',
        ], null);

        return (int) $item['id'];
    }

    private function lockUntil(string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, $date]);
    }

    private function otherItemStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM other_items WHERE id = $id")->fetchColumn();
    }
}
