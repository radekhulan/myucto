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
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Schopnost tokenu upravit vystavenou fakturu (#355) jde zapnout jen tokenu vázanému
 * na firmu, se zápisem, a jen tomu, kdo vystavenou fakturu ve firmě smí upravit sám.
 * Odmítnutí přijde před step-up ověřením, takže nespotřebuje jednorázový proof.
 */
#[Group('integration')]
final class CreateTokenIssuedInvoiceEditTest extends TestCase
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

    public function testUnboundTokenCannotGetCapability(): void
    {
        $res = $this->create(['supplier_id' => null, 'scope' => 'read_write'], $this->admin());
        self::assertSame([422, 'issued_invoice_edit_not_allowed'], $res);
    }

    public function testReadOnlyTokenCannotGetCapability(): void
    {
        $res = $this->create(['scope' => 'read'], $this->admin());
        self::assertSame([422, 'issued_invoice_edit_not_allowed'], $res);
    }

    public function testRoleWithoutEditIssuedCannotGrantCapability(): void
    {
        $clerk = new EffectiveRole(92, 'Fakturantka', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);
        self::assertSame([422, 'issued_invoice_edit_not_allowed'], $this->create([], $clerk));
        self::assertSame([422, 'issued_invoice_edit_not_allowed'], $this->create([], null));
    }

    public function testAdminOrEditIssuedRolePassesToStepUp(): void
    {
        $editor = new EffectiveRole(93, 'Úprava vystavených', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ]);
        foreach ([$this->admin(), $editor] as $role) {
            self::assertSame([401, 'step_up_required'], $this->create([], $role));
        }
    }

    private function admin(): EffectiveRole
    {
        return new EffectiveRole(94, 'Správce', 'staff', true, [], 'admin');
    }

    /**
     * @param array<string,mixed> $override
     * @return array{0:int,1:?string}
     */
    private function create(array $override, ?EffectiveRole $scopedRole): array
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
            new \MyInvoice\Service\Auth\SystemWriteTokenFeature(new \MyInvoice\Infrastructure\Config\Config([])),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/tokens', ['REMOTE_ADDR' => '203.0.113.92'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'is_superadmin' => true])
            ->withParsedBody($override + [
                'name' => 'Portál (PHPUnit)',
                'supplier_id' => $this->supplierId,
                'scope' => 'read_write',
                'allow_issued_invoice_edit' => true,
            ]);
        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($body) ? ($body['error']['code'] ?? null) : null];
    }
}
