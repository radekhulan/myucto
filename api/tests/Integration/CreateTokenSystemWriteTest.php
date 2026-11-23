<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SystemWriteTokenFeature;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Plný zápis tokenu (`system_write`, #371) jde zapnout jen na povolené instanci, jen
 * tokenu se zápisem a jen tím, kdo za firmu rozhoduje: správce firmy u tokenu vázaného
 * na firmu, superadmin u tokenu bez vazby. Odmítnutí přijde před step-up ověřením,
 * takže nespotřebuje jednorázový proof.
 */
#[Group('integration')]
final class CreateTokenSystemWriteTest extends TestCase
{
    private ContainerInterface $container;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $pdo = $this->container->get(Connection::class)->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    public function testDisabledInstanceRejectsFlag(): void
    {
        self::assertSame([422, 'system_write_disabled'], $this->create([], $this->admin(), enabled: false));
    }

    public function testReadOnlyTokenCannotGetFlag(): void
    {
        self::assertSame([422, 'system_write_not_allowed'], $this->create(['scope' => 'read'], $this->admin()));
    }

    public function testOnlyCompanyAdminGrantsBoundToken(): void
    {
        $accountant = new EffectiveRole(95, 'Účetní', 'staff', true, [
            'accounting' => AccessLevel::WRITE->value,
            'accounting.journal.post' => AccessLevel::WRITE->value,
        ]);
        self::assertSame([422, 'system_write_not_allowed'], $this->create([], $accountant));
        self::assertSame([422, 'system_write_not_allowed'], $this->create([], null));
        self::assertSame([401, 'step_up_required'], $this->create([], $this->admin()));
    }

    public function testUnboundTokenNeedsSuperadmin(): void
    {
        self::assertSame([401, 'step_up_required'], $this->create(['supplier_id' => null], null, superadmin: true));
        self::assertSame([422, 'system_write_not_allowed'], $this->create(['supplier_id' => null], null, superadmin: false));
    }

    private function admin(): EffectiveRole
    {
        return new EffectiveRole(94, 'Správce', 'staff', true, [], 'admin');
    }

    /**
     * @param array<string,mixed> $override
     * @return array{0:int,1:?string}
     */
    private function create(array $override, ?EffectiveRole $scopedRole, bool $enabled = true, bool $superadmin = true): array
    {
        $cross = $this->createStub(CrossSupplierAuthorization::class);
        $cross->method('scopedRequest')->willReturnCallback(
            static fn (ServerRequestInterface $request): ?ServerRequestInterface => $scopedRole === null
                ? null
                : $request->withAttribute('auth.effective_role', $scopedRole),
        );
        $policy = $this->createStub(LoginPolicyService::class);
        $policy->method('isEnforced')->willReturn(true);
        $protected = $this->createMock(MfaProtectedOperationService::class);
        $protected->expects(self::never())->method('createApiToken');

        $c = $this->container;
        $action = new CreateTokenAction(
            $c->get(Connection::class),
            $c->get(ApiTokenService::class),
            $c->get(TotpService::class),
            $c->get(SecretEncryption::class),
            $c->get(ActivityLogger::class),
            $c->get(IpMatcher::class),
            $c->get(UserSupplierRepository::class),
            $c->get(PasswordHasher::class),
            $c->get(PasskeyCredentialRepository::class),
            $c->get(MfaPolicyService::class),
            $c->get(BruteForceGuard::class),
            $protected,
            $policy,
            $cross,
            new SystemWriteTokenFeature(new Config(['api' => ['allow_system_write_tokens' => $enabled]])),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/tokens', ['REMOTE_ADDR' => '203.0.113.93'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'is_superadmin' => $superadmin])
            ->withAttribute('auth.effective_role', $superadmin
                ? new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin')
                : new EffectiveRole(96, 'Účetní', 'staff', true, ['accounting' => AccessLevel::WRITE->value]))
            ->withParsedBody($override + [
                'name' => 'Agent (PHPUnit)',
                'supplier_id' => $this->supplierId,
                'scope' => 'read_write',
                'allow_system_write' => true,
            ]);
        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($body) ? ($body['error']['code'] ?? null) : null];
    }
}
