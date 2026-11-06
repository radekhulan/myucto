<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\OAuth;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthCallbackDispatcher;
use MyInvoice\Service\OAuth\OAuthCallbackHandler;
use MyInvoice\Service\OAuth\OAuthCallbackRegistry;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OAuthCallbackDispatcherTest extends TestCase
{
    /** Klientský portál napojení nedokončí, ani když by mu role dala právo, které handler chce. */
    public function testClientTypeRoleIsRejectedEvenWithHandlerPermission(): void
    {
        $handler = $this->handler();
        $location = $this->dispatcher($handler, new EffectiveRole(10, 'Klient', 'client', true,
            ['settings.bank_accounts' => AccessLevel::WRITE->value]))->dispatch($this->request());

        self::assertSame('/failed?forbidden', $location);
        self::assertSame(0, $handler->completed);
    }

    public function testStaffRoleWithPermissionIsDispatched(): void
    {
        $handler = $this->handler();
        $location = $this->dispatcher($handler, new EffectiveRole(10, 'Účetní', 'staff', true,
            ['settings.bank_accounts' => AccessLevel::WRITE->value]))->dispatch($this->request());

        self::assertSame('/done', $location);
        self::assertSame(1, $handler->completed);
    }

    private function dispatcher(OAuthCallbackHandler $handler, EffectiveRole $role): OAuthCallbackDispatcher
    {
        $flow = $this->createStub(OAuthClientFlow::class);
        $flow->method('find')->willReturn(['supplier_id' => 7, 'user_id' => 5, 'domain' => 'test_domain', 'provider' => 'p',
            'subject_id' => 1, 'status' => 'pending', 'expired' => false]);
        $suppliers = $this->createStub(CrossSupplierAuthorization::class);
        $suppliers->method('scopedRequest')->willReturnCallback(static fn (Request $r): Request =>
            $r->withAttribute('auth.effective_role', $role));
        return new OAuthCallbackDispatcher($flow, new OAuthCallbackRegistry([$handler]), $suppliers, new NullLogger());
    }

    private function request(): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback')
            ->withQueryParams(['state' => str_repeat('a', 64), 'code' => 'synthetic-code'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function handler(): OAuthCallbackHandler
    {
        return new class implements OAuthCallbackHandler {
            public int $completed = 0;

            public function domain(): string
            {
                return 'test_domain';
            }

            public function allows(Request $request): bool
            {
                return \MyInvoice\Security\RequestAuthorization::allows($request, 'settings.bank_accounts', AccessLevel::WRITE);
            }

            public function complete(OAuthCallback $callback): string
            {
                $this->completed++;
                return '/done';
            }

            public function failureLocation(array $session, string $errorCode): string
            {
                return '/failed?' . $errorCode;
            }
        };
    }
}
