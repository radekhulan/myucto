<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Catalog;

use MyInvoice\Action\Property\Catalog\PropertyLegalRoleCatalogAction;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

final class PropertyLegalRolePublicContractTest extends TestCase
{
    public function testRegisteredCatalogRoutesAllowOnlyPropertyRead(): void
    {
        $app = \Slim\Factory\AppFactory::create();
        \MyInvoice\Routes::register($app);
        $policy = new ApiScopeMiddleware(new ResponseFactory());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };

        foreach (['/api/property/catalog/unit-legal-roles' => 'list', '/api/property/catalog/unit-legal-roles/7' => 'detail'] as $path => $method) {
            $routing = $app->getRouteResolver()->computeRoutingResults($path, 'GET');
            self::assertSame(RoutingResults::FOUND, $routing->getRouteStatus());
            $route = $app->getRouteResolver()->resolveRoute((string) $routing->getRouteIdentifier());
            self::assertSame([PropertyLegalRoleCatalogAction::class, $method], $route->getCallable());
            $permission = (new RoutePermissionMap())->match('GET', $path);
            self::assertSame('property', $permission->key);
            self::assertSame(AccessLevel::READ, $permission->minimum);

            foreach (['read', 'read_write'] as $scope) {
                $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
                    ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
                    ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope]);
                self::assertSame(204, $policy->process($request, $handler)->getStatusCode());
                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $writeMethod) {
                    self::assertSame(403, $policy->process($request->withMethod($writeMethod), $handler)->getStatusCode());
                }
            }
        }
    }
}
