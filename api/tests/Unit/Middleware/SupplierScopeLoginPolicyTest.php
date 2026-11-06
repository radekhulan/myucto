<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Firma s vynuceným přihlášením přes poskytovatele identity: rozhoduje metoda
 * přihlášení sezení, a u API tokenu metoda sezení, ze kterého byl vydaný.
 * Token z heslového sezení tak politiku neobejde.
 */
final class SupplierScopeLoginPolicyTest extends TestCase
{
    private const SUPPLIER = 5;

    public function testPasswordSessionIsRejectedAndEntraSessionPasses(): void
    {
        self::assertSame(403, $this->process($this->session('password'))->getStatusCode());
        self::assertSame(204, $this->process($this->session('oidc:entra'))->getStatusCode());
    }

    public function testBearerTokenIsJudgedByTheSessionItWasIssuedFrom(): void
    {
        self::assertSame(403, $this->process($this->bearer('password'))->getStatusCode());
        self::assertSame(403, $this->process($this->bearer(null))->getStatusCode());
        self::assertSame(204, $this->process($this->bearer('oidc:entra'))->getStatusCode());
    }

    public function testRejectionCarriesProvider(): void
    {
        $body = (string) $this->process($this->bearer('password'))->getBody();
        self::assertStringContainsString('"sso_required"', $body);
        self::assertStringContainsString('"entra"', $body);
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        $resolver = $this->createStub(SupplierAccessResolver::class);
        $resolver->method('resolve')->willReturn(new SupplierAccess(self::SUPPLIER, false, null));
        $policy = $this->createStub(LoginPolicyService::class);
        $policy->method('requestAllowedForSupplier')->willReturnCallback(static function (ServerRequestInterface $request): bool {
            $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
            $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
            return ($session['auth_method'] ?? $token['issued_auth_method'] ?? null) === 'oidc:entra';
        });
        $policy->method('requiredProviderForSupplier')->willReturn('entra');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
        return (new SupplierScopeMiddleware($resolver, new ResponseFactory(), $policy))->process($request, $handler);
    }

    private function session(string $authMethod): ServerRequestInterface
    {
        return $this->base()
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['auth_method' => $authMethod]);
    }

    private function bearer(?string $issuedAuthMethod): ServerRequestInterface
    {
        return $this->base()
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['id' => 9, 'issued_auth_method' => $issuedAuthMethod]);
    }

    private function base(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role_summary' => ['type' => 'staff', 'system_key' => 'accountant'],
            ]);
    }
}
