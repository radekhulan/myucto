<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Čtecí GET (stažení výkazu, náhled) smí mimochodem zapsat jen v aplikaci. API token
 * je pro účetní a daňovou vrstvu jen ke čtení, i když jeho uživatel právo zápisu má.
 */
final class RequestAuthorizationReadSideEffectsTest extends TestCase
{
    public function testSessionWithWriteMayCauseSideEffects(): void
    {
        self::assertTrue(RequestAuthorization::allowsReadSideEffects($this->request('session', 'admin'), 'reports.export'));
    }

    public function testBearerNeverCausesSideEffectsEvenForSuperadmin(): void
    {
        self::assertFalse(RequestAuthorization::allowsReadSideEffects($this->request('bearer', 'admin'), 'reports.export'));
    }

    public function testSessionWithoutWriteMayNot(): void
    {
        self::assertFalse(RequestAuthorization::allowsReadSideEffects($this->request('session', 'readonly'), 'reports.export'));
    }

    public function testRequestWithoutAuthMethodMayNot(): void
    {
        self::assertFalse(RequestAuthorization::allowsReadSideEffects($this->request(null, 'admin'), 'reports.export'));
    }

    private function request(?string $method, string $role): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/dphdp3')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => $role]);

        return $method === null ? $request : $request->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
    }
}
