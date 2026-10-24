<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TechnicalSupplierAccessRepository;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** Celá HTTP pipeline musí zachovat technické oddělení i s platnou skutečnou session. */
#[Group('integration')]
final class InstanceAdminHttpTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private int $userId = 0;
    /** @var list<int> */
    private array $supplierIds = [];
    /** @var list<int> */
    private array $currencyIds = [];
    private array $session = [];

    protected function setUp(): void
    {
        $this->config = Config::load(Bootstrap::rootDir());
        $this->db = new Connection($this->config);
        $pdo = $this->db->pdo();
        self::assertTrue($this->db->hasTable('user_technical_access'), 'Nejprve aplikujte migraci 2135.');
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'instance_admin'
            AND role_type = 'instance_admin' AND is_active = 1")->fetchColumn();
        self::assertGreaterThan(0, $roleId);
        $pdo->prepare("INSERT INTO users (email, name, password_hash, role, role_id, locale, is_active)
            VALUES (?, 'Syntetický technický správce', ?, 'readonly', ?, 'cs', 1)")
            ->execute(['technical-http-' . bin2hex(random_bytes(6)) . '@example.test',
                password_hash('Synthetic HTTP test password', PASSWORD_DEFAULT), $roleId]);
        $this->userId = (int) $pdo->lastInsertId();
        $clock = new DatabaseSecurityClock();
        $this->session = (new SessionManager($this->db, $this->config, $clock))->create(
            $this->userId, '127.0.0.1', 'Synthetic technical HTTP test',
            SessionAuthContext::strong('totp', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($this->userId > 0) {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM instance_admin_events WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        // Úklid vlastního cyklického fixture musí odstranit firmu i její výchozí měnu.
        if ($this->currencyIds !== []) $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->currencyIds as $id) {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
            }
        } finally {
            if ($this->currencyIds !== []) $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach (array_reverse($this->supplierIds) as $id) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testBootstrapAndTechnicalStatusWorkWithoutAnyCompanyMembership(): void
    {
        $response = $this->request('GET', '/api/auth/me');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $me = $this->json($response);
        self::assertTrue($me['user']['is_instance_admin']);
        self::assertFalse($me['user']['is_superadmin']);
        self::assertFalse($me['user']['can_create_supplier']);
        self::assertSame('instance_admin', $me['user']['role']['type']);
        self::assertSame([], $me['suppliers']);
        self::assertSame(0, $me['current_supplier_id']);
        self::assertSame([], $me['permissions']);
        self::assertNull($me['license']);
        self::assertNull($me['domain_context']);

        $context = $this->json($this->request('GET', '/api/auth/domain-context'));
        self::assertSame('canonical', $context['mode']);
        self::assertFalse($context['locked']);
        self::assertFalse($context['feature_enabled']);
        self::assertNull($context['supplier_id']);

        $status = $this->request('GET', '/api/instance-admin/status');
        self::assertSame(200, $status->getStatusCode(), (string) $status->getBody());
        self::assertSame(['version', 'php_version', 'database', 'extensions', 'storage', 'managed', 'smtp', 'license'],
            array_keys($this->json($status)));
        self::assertSame(200, $this->request('GET', '/api/auth/session/status')->getStatusCode());
        self::assertSame([], $this->json($this->request('GET', '/api/instance-admin/suppliers'))['data']);
    }

    public function testDirectAndEncodedCompanyTokenAndAdministrativeRoutesAreRejected(): void
    {
        foreach ([
            ['GET', '/api/invoices'], ['HEAD', '/api/invoices'], ['GET', '/api/%69nvoices'],
            ['GET', '/api/v1/invoices'], ['GET', '/api/purchase-invoices'],
            ['GET', '/api/payroll/employees'], ['GET', '/api/clients'], ['GET', '/api/bank-statements'],
            ['GET', '/api/documents'], ['GET', '/api/search'], ['GET', '/api/suppliers'],
            ['GET', '/api/settings/supplier'], ['GET', '/api/auth/api-me'], ['GET', '/api/portal'],
            ['GET', '/api/admin/diagnostics'], ['GET', '/api/admin/backups'],
            ['GET', '/api/admin/users'], ['POST', '/api/auth/tokens'],
            ['GET', '/api/mcp/settings'], ['GET', '/oauth/authorize'], ['POST', '/mcp'],
            ['PUT', '/api/auth/default-supplier'], ['POST', '/api/admin/instance-smtp/targets'],
            ['PUT', '/api/admin/users/' . $this->userId . '/technical-suppliers'],
        ] as [$method, $path]) {
            $response = $this->request($method, $path, in_array($method, ['GET', 'HEAD'], true) ? null : []);
            self::assertSame(403, $response->getStatusCode(), "$method $path: " . (string) $response->getBody());
            if ($method !== 'HEAD') {
                self::assertContains($this->json($response)['error']['code'], ['forbidden_permission', 'forbidden_supplier']);
            }
        }
    }

    public function testTechnicalAssignmentCannotBeReplacedByBusinessMembershipAndRevokesLive(): void
    {
        $allowed = $this->supplier('Syntetická přidělená firma');
        $foreign = $this->supplier('Syntetická nepřidělená firma');
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')
            ->execute([$this->userId, $foreign]);
        $access = new TechnicalSupplierAccessRepository($this->db);
        $access->replaceForUser($this->userId, false, [$allowed]);

        $listing = $this->json($this->request('GET', '/api/instance-admin/suppliers', null, $foreign));
        self::assertSame([['supplier_id' => $allowed, 'name' => 'Syntetická přidělená firma']], $listing['data']);
        self::assertSame(200, $this->request('GET', "/api/instance-admin/suppliers/{$allowed}/status", null, $foreign)->getStatusCode());
        self::assertSame(403, $this->request('GET', "/api/instance-admin/suppliers/{$foreign}/status", null, $allowed)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/invoices', null, $foreign)->getStatusCode());
        self::assertSame([], $this->json($this->request('GET', '/api/auth/me', null, $foreign))['suppliers']);

        $access->replaceForUser($this->userId, false, []);
        self::assertSame(403, $this->request('GET', "/api/instance-admin/suppliers/{$allowed}/status")->getStatusCode());
        self::assertSame([], $this->json($this->request('GET', '/api/instance-admin/suppliers'))['data']);
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query('SELECT MIN(id) FROM countries')->fetchColumn();
        $currency = (int) $pdo->query('SELECT MIN(id) FROM currencies')->fetchColumn();
        $vat = (int) $pdo->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
        self::assertGreaterThan(0, min($country, $vat), 'Čisté migrace musí obsahovat základní číselníky.');
        $bootstrap = $currency === 0;
        // První syntetická firma a její měna mají cyklický FK, stejně jako při prvotním setupu.
        if ($currency === 0) $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                default_currency_id, default_vat_rate_id) VALUES (?, 'Testovací 1', 'Testov', '11000', ?,
                'supplier-http@example.test', ?, ?)")->execute([$name, $country, $currency, $vat]);
            $id = (int) $pdo->lastInsertId();
            $this->supplierIds[] = $id;
            if ($currency === 0) {
                $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, name_cs, name_en, symbol)
                    VALUES (?, 'CZK', 'Synthetic CZK', 'Syntetická měna', 'Synthetic currency', 'Kč')")->execute([$id]);
                $currency = (int) $pdo->lastInsertId();
                $this->currencyIds[] = $currency;
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $id]);
            }
            return $id;
        } finally {
            if ($bootstrap) $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function request(string $method, string $path, ?array $body = null, ?int $supplierId = null): ResponseInterface
    {
        $origin = rtrim((string) $this->config->get('app.url'), '/');
        $request = (new ServerRequestFactory())->createServerRequest($method, $origin . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([(string) $this->config->get('session.cookie_name', '__Host-myinvoice_session') => $this->session['token']])
            ->withHeader('Origin', $origin)->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $this->session['csrf_token']);
        if ($supplierId !== null) $request = $request->withHeader('X-Supplier-Id', (string) $supplierId);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        // Každý HTTP request má vlastní kontejner stejně jako PHP runtime; žádná autorizační memoizace mezi requesty.
        return Bootstrap::buildApp()->handle($request);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
