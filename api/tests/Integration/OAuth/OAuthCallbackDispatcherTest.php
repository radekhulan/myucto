<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\OAuth;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\OAuthSessionRepository;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthCallbackDispatcher;
use MyInvoice\Service\OAuth\OAuthCallbackHandler;
use MyInvoice\Service\OAuth\OAuthCallbackRegistry;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Společný callback: rozeslání podle hashe state na handler domény, firma z přihlášení
 * (ne z aktuálně zvolené firmy), cizí a použitý state.
 */
#[Group('integration')]
final class OAuthCallbackDispatcherTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OAuthClientFlow $flow;
    private OAuthCallbackDispatcher $dispatcher;
    private object $handler;
    /** @var array<string,mixed> */
    private array $user;
    private int $supplierA = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $suppliers = $container->get(CrossSupplierAuthorization::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $source = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $userId = (int) ($this->pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active = 1 AND r.system_key = 'superadmin' ORDER BY u.id LIMIT 1")->fetchColumn() ?: 0);
        $user = $userId > 0 ? $suppliers->activeUser($userId) : null;
        if ($source === 0 || $user === null) {
            $this->markTestSkipped('Testovací DB nemá firmu nebo aktivního superadmina.');
        }
        $this->user = $user;
        $this->pdo->beginTransaction();
        $this->supplierA = $this->createIsolatedSupplier($this->pdo, $source);
        $this->flow = new OAuthClientFlow(new OAuthSessionRepository($this->db), new SecretEncryption(new Config(['app' => [
            'pepper' => 'synthetic-pepper', 'secret_encryption_key' => base64_encode(str_repeat('k', 32)),
        ]])), $this->db, new Config(['app' => ['url' => 'https://example.test']]));
        $this->handler = new class ($this->flow) implements OAuthCallbackHandler {
            public bool $allowed = true;
            /** @var list<array{supplier:int, code:string, secret:?array, error:?string}> */
            public array $completed = [];
            /** @var list<string> */
            public array $failures = [];

            public function __construct(private readonly OAuthClientFlow $flow) {}

            public function domain(): string
            {
                return 'test_domain';
            }

            public function allows(Request $request): bool
            {
                return $this->allowed && $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID) > 0;
            }

            public function complete(OAuthCallback $callback): string
            {
                $session = $this->flow->claim($callback->state, $callback->supplierId(), $callback->userId(), 'test_domain', 'test_provider');
                $this->completed[] = ['supplier' => (int) $callback->request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID),
                    'code' => $callback->code, 'secret' => $session?->secret, 'error' => $callback->error];
                if ($session !== null) $this->flow->finish($session, true);
                return '/done';
            }

            public function failureLocation(array $session, string $errorCode): string
            {
                $this->failures[] = $errorCode;
                return '/failed?code=' . $errorCode;
            }
        };
        $this->dispatcher = new OAuthCallbackDispatcher($this->flow, new OAuthCallbackRegistry([$this->handler]), $suppliers, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testCallbackIsHandedToDomainHandlerInTheSupplierOfTheLogin(): void
    {
        $state = $this->login(['client_secret' => 'synthetic-secret']);

        self::assertSame('/done', $this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'synthetic-code'])));
        self::assertSame([['supplier' => $this->supplierA, 'code' => 'synthetic-code', 'secret' => ['client_secret' => 'synthetic-secret'],
            'error' => null]], $this->handler->completed);
    }

    public function testReplayedStateEndsAsFailureWithoutSecondCompletion(): void
    {
        $state = $this->login(['k' => 'v']);
        $this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'synthetic-code']));

        self::assertSame('/failed?code=' . OAuthException::INVALID_STATE,
            $this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'synthetic-code'])));
        self::assertCount(1, $this->handler->completed);
    }

    public function testUnknownOrForeignStateIsNotDispatched(): void
    {
        $state = $this->login(['k' => 'v']);

        self::assertNull($this->dispatcher->dispatch($this->request(['state' => bin2hex(random_bytes(32)), 'code' => 'c'])));
        self::assertNull($this->dispatcher->dispatch($this->request(['state' => 'garbage', 'code' => 'c'])));
        self::assertNull($this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'c'], (int) $this->user['id'] + 100000)));
        self::assertNull($this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'c']), 'bank'));
        self::assertNull($this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'c']), 'test_domain', 'csas'));
        self::assertSame([], $this->handler->completed);
        self::assertSame('pending', $this->flow->find($state)['status']);
    }

    public function testHandlerPermissionIsCheckedBeforeTheLoginIsUsed(): void
    {
        $state = $this->login(['k' => 'v']);
        $this->handler->allowed = false;

        self::assertSame('/failed?code=forbidden', $this->dispatcher->dispatch($this->request(['state' => $state, 'code' => 'c'])));
        self::assertSame('pending', $this->flow->find($state)['status']);
    }

    public function testProviderErrorIsPassedOnlyAsKnownCode(): void
    {
        $state = $this->login(['k' => 'v']);
        $this->dispatcher->dispatch($this->request(['state' => $state, 'error' => '<script>']));

        self::assertSame('unspecified', $this->handler->completed[0]['error']);
    }

    private function login(array $secret): string
    {
        $auth = $this->flow->authorize();
        $this->flow->store($auth, $this->supplierA, (int) $this->user['id'], 'test_domain', 'test_provider', 1,
            OAuthClientFlow::FLOW_REDIRECT, $secret, 600);
        return $auth->state;
    }

    private function request(array $query, ?int $userId = null): Request
    {
        $user = $userId === null ? $this->user : ['id' => $userId] + $this->user;
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback')
            ->withQueryParams($query)
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
