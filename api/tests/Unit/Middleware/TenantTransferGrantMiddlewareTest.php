<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use DI\Container;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantTransferGrantMiddleware;
use MyInvoice\Routes;
use MyInvoice\Service\Auth\PsrSecurityClock;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Clock\MockClock;

final class TenantTransferGrantMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private TenantTransferGrantService $grants;
    private TenantTransferGrantMiddleware $middleware;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $config = new Config(['tenant_transfer' => ['enabled' => true]]);
        $connection = new Connection($config);
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $this->pdo);
        $this->grants = new TenantTransferGrantService(
            $connection,
            new PsrSecurityClock(new MockClock('2026-08-20T10:00:00Z')),
        );
        $this->middleware = new TenantTransferGrantMiddleware(
            $config,
            $this->grants,
            new IpMatcher($config),
            new ResponseFactory(),
        );
    }

    public function testValidGrantAuthoritativelyAttachesSupplierAndTransferIdentity(): void
    {
        $issued = $this->grants->issue(7, 3, '192.0.2.10');
        $handler = new CapturingTenantTransferHandler();

        $response = $this->middleware->process(
            $this->request()
                ->withHeader(SupplierScopeMiddleware::HEADER_NAME, '999')
                ->withHeader(
                    TenantTransferGrantMiddleware::HEADER_NAME,
                    $issued['plaintext'],
                ),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertNotNull($handler->request);
        self::assertSame(
            3,
            $handler->request->getAttribute(TenantTransferGrantMiddleware::ATTR_SUPPLIER_ID),
        );
        self::assertSame(
            3,
            $handler->request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID),
        );
        self::assertSame(
            TenantTransferGrantMiddleware::AUTH_METHOD,
            $handler->request->getAttribute(AuthMiddleware::ATTR_METHOD),
        );
        $grant = $handler->request->getAttribute(TenantTransferGrantMiddleware::ATTR_GRANT);
        self::assertIsArray($grant);
        self::assertSame($issued['public_id'], $grant['public_id'] ?? null);
    }

    public function testRegisteredInterInstanceRouteInheritsGrantMiddleware(): void
    {
        $container = new Container();
        $container->set(TenantTransferGrantMiddleware::class, $this->middleware);
        $app = AppFactory::createFromContainer($container);
        Routes::register($app);
        $app->addRoutingMiddleware();

        $response = $app->handle($this->request());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_transfer_grant', self::errorCode($response));
    }

    public function testMissingDuplicatedAndQueryGrantAreNeverAccepted(): void
    {
        $issued = $this->grants->issue(7, 3, '192.0.2.10');
        foreach ([
            $this->request(),
            $this->request('?grant=' . rawurlencode($issued['plaintext'])),
            $this->request()
                ->withHeader(TenantTransferGrantMiddleware::HEADER_NAME, $issued['plaintext'])
                ->withAddedHeader(TenantTransferGrantMiddleware::HEADER_NAME, $issued['plaintext']),
        ] as $request) {
            $handler = new CapturingTenantTransferHandler();
            $response = $this->middleware->process($request, $handler);

            self::assertSame(401, $response->getStatusCode());
            self::assertSame('invalid_transfer_grant', self::errorCode($response));
            self::assertNull($handler->request);
        }

        $audit = $this->queryValue(
            "SELECT group_concat(COALESCE(endpoint, '') || COALESCE(reason, ''))
               FROM tenant_transfer_grant_audit",
        );
        self::assertIsString($audit);
        self::assertStringNotContainsString($issued['plaintext'], $audit);
    }

    public function testOrdinaryAuthorizationAndSessionIdentityAreRejected(): void
    {
        $issued = $this->grants->issue(7, 3, '192.0.2.10');
        $requests = [
            $this->request()
                ->withHeader('Authorization', 'Bearer mi_pat_' . str_repeat('A', 43))
                ->withHeader(TenantTransferGrantMiddleware::HEADER_NAME, $issued['plaintext']),
            $this->request()
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withHeader(TenantTransferGrantMiddleware::HEADER_NAME, $issued['plaintext']),
            $this->request()
                ->withCookieParams([
                    '__Host-myinvoice_session' => 'ordinary-browser-session',
                ])
                ->withHeader(TenantTransferGrantMiddleware::HEADER_NAME, $issued['plaintext']),
        ];
        foreach ($requests as $request) {
            $handler = new CapturingTenantTransferHandler();
            $response = $this->middleware->process($request, $handler);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('transfer_authorization_required', self::errorCode($response));
            self::assertNull($handler->request);
        }
    }

    public function testDisabledFeatureReturnsNotFoundBeforeGrantAudit(): void
    {
        $before = $this->queryCount(
            'SELECT COUNT(*) FROM tenant_transfer_grant_audit',
        );
        $config = new Config([]);
        $middleware = new TenantTransferGrantMiddleware(
            $config,
            $this->grants,
            new IpMatcher($config),
            new ResponseFactory(),
        );

        $response = $middleware->process(
            $this->request(),
            new CapturingTenantTransferHandler(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('transfer_api_disabled', self::errorCode($response));
        self::assertSame(
            $before,
            $this->queryCount(
                'SELECT COUNT(*) FROM tenant_transfer_grant_audit',
            ),
        );
    }

    public function testOrdinaryAuthorizationRejectionsShareBoundedIpRateLimit(): void
    {
        $request = $this->request()->withHeader(
            'Authorization',
            'Bearer mi_pat_' . str_repeat('A', 43),
        );
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            self::assertSame(
                403,
                $this->middleware->process(
                    $request,
                    new CapturingTenantTransferHandler(),
                )->getStatusCode(),
            );
        }

        $limited = $this->middleware->process(
            $request,
            new CapturingTenantTransferHandler(),
        );
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('60', $limited->getHeaderLine('Retry-After'));
        self::assertSame('transfer_rate_limited', self::errorCode($limited));
    }

    private function request(string $query = ''): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/tenant-transfer/v1/capabilities' . $query,
            ['REMOTE_ADDR' => '198.51.100.20'],
        );
    }

    private static function errorCode(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $error = $body['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);
        return $code;
    }

    private function queryValue(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }

    private function queryCount(string $sql): int
    {
        $value = $this->queryValue($sql);
        if (is_int($value)) {
            return $value;
        }
        self::assertIsString($value);
        self::assertMatchesRegularExpression('/^[0-9]+$/D', $value);
        return (int) $value;
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE tenant_transfer_grants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            grant_hash BLOB NOT NULL UNIQUE,
            grant_prefix TEXT NOT NULL,
            supplier_id INTEGER NOT NULL,
            created_by_user_id INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            paired_at TEXT NULL,
            target_instance_fingerprint BLOB NULL,
            target_payload_key_fingerprint BLOB NULL,
            consumed_at TEXT NULL,
            revoked_at TEXT NULL,
            revoked_reason TEXT NULL,
            last_used_at TEXT NULL,
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE tenant_transfer_grant_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grant_id INTEGER NULL,
            supplier_id INTEGER NULL,
            actor_user_id INTEGER NULL,
            event TEXT NOT NULL,
            outcome TEXT NOT NULL,
            reason TEXT NOT NULL,
            http_method TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            ip BLOB NULL,
            created_at TEXT NOT NULL
        )');
    }
}

final class CapturingTenantTransferHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        return (new ResponseFactory())->createResponse(204);
    }
}
