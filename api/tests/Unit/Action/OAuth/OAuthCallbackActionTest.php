<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\OAuth;

use MyInvoice\Action\OAuth\OAuthCallbackAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class OAuthCallbackActionTest extends TestCase
{
    public function testApiTokenCannotCompleteLoginBeforeDispatch(): void
    {
        $action = (new \ReflectionClass(OAuthCallbackAction::class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback?state=x&code=y')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');

        $response = $action($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testCallbackRouteNeedsLoginButNoPermissionOfTheCurrentSupplier(): void
    {
        $route = (new RoutePermissionMap())->match('GET', '/api/oauth/callback');

        self::assertSame(RoutePermissionMap::SELF_SERVICE, $route?->kind);
        self::assertNull((new RoutePermissionMap())->match('POST', '/api/oauth/callback/other'));
    }
}
