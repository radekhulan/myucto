<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET profilu DPPO 504 je čtení bez zámků; sestavení podkladů pod zámky jde jen přes
 * výslovný požadavek readiness. Akceptaci výpočtu mění jen správce firmy.
 */
final class NonprofitDppoProfileHttpTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private \Slim\App $app;
    private int $supplier;
    private int $period;
    private int $actor;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry',taxpayer_type='po' WHERE id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504')")
            ->execute([$this->supplier]);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->app = \Slim\Factory\AppFactory::create(new ResponseFactory(), $this->container);
        \MyInvoice\Routes::register($this->app);
        $this->app->add($this->container->get(CsrfMiddleware::class));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testProfileGetTakesNoRowLocksAndReadinessIsExplicit(): void
    {
        $before = $this->rowsLocked();
        [$status, $profile] = $this->http('GET', '/periods/' . $this->period . '/nonprofit-legal-profile');
        self::assertSame(200, $status, json_encode($profile));
        self::assertSame($before, $this->rowsLocked());
        self::assertFalse($profile['capabilities']['readiness_checked']);
        self::assertNull($profile['capabilities']['calculation_ready']);
        self::assertFalse($profile['capabilities']['calculation_accepted']);

        [$status, $ready] = $this->http('POST', '/periods/' . $this->period . '/nonprofit-legal-profile/readiness');
        self::assertSame(200, $status, json_encode($ready));
        self::assertTrue($ready['capabilities']['readiness_checked']);
        self::assertFalse($ready['capabilities']['calculation_ready']);
        self::assertContains('nonprofit_dppo_legal_profile_required', array_column($ready['capabilities']['preparation_findings'], 'code'));
    }

    public function testOnlyCompanyAdminAcceptsCalculationWithExplicitConfirmationAndReference(): void
    {
        [$status, $state] = $this->http('GET', '/nonprofit-dppo/acceptance');
        self::assertSame(200, $status, json_encode($state));
        self::assertFalse($state['accepted']);
        self::assertFalse($state['can_change']);
        $body = ['confirmed' => true, 'verification_reference' => 'Syntetický protokol ověření DPPO 504',
            'reason' => 'Syntetická akceptace', 'idempotency_key' => 'synthetic-accept-' . $this->supplier, 'expected_head_id' => null];
        [$status, $denied] = $this->http('POST', '/nonprofit-dppo/acceptance/accept', $body);
        self::assertSame(403, $status, json_encode($denied));
        [$status] = $this->http('POST', '/nonprofit-dppo/acceptance/accept', array_replace($body, ['confirmed' => false]), admin: true);
        self::assertSame(422, $status);
        [$status] = $this->http('POST', '/nonprofit-dppo/acceptance/accept', array_replace($body, ['verification_reference' => ' ']), admin: true);
        self::assertSame(422, $status);
        [$status, $accepted] = $this->http('POST', '/nonprofit-dppo/acceptance/accept', $body, admin: true);
        self::assertSame(200, $status, json_encode($accepted));
        self::assertTrue($accepted['accepted']);
        [$status, $retry] = $this->http('POST', '/nonprofit-dppo/acceptance/accept', $body, admin: true);
        self::assertSame(200, $status);
        self::assertSame($accepted['head'], $retry['head']);
        [$status, $profile] = $this->http('GET', '/periods/' . $this->period . '/nonprofit-legal-profile');
        self::assertSame(200, $status);
        self::assertTrue($profile['capabilities']['calculation_accepted']);
        self::assertTrue($profile['capabilities']['operations']['finalize']['allowed']);
        [$status] = $this->http('POST', '/nonprofit-dppo/acceptance/accept', $body, auth: 'bearer', admin: true);
        self::assertSame(403, $status);
    }

    private function http(string $method, string $path, array $body = [], string $auth = 'session', bool $admin = false): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/accounting' . $path)
            ->withParsedBody($body)->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => $admin ? 'admin' : 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'synthetic-dppo-http-csrf'])
            ->withAttribute('auth.effective_role', new EffectiveRole(124, 'Synthetic DPPO', 'staff', true,
                ['accounting' => 2, 'accounting.periods.close' => 2, 'reports' => 2], $admin ? 'admin' : null))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext('canonical', 'example.test', 'https://example.test'))
            ->withHeader('Origin', 'https://example.test')
            ->withHeader('X-CSRF-Token', 'synthetic-dppo-http-csrf');
        $response = $this->app->handle($request);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function rowsLocked(): int
    {
        $value = $this->pdo->query('SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=CONNECTION_ID()')
            ->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }
}
