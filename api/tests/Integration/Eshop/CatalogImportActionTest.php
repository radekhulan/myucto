<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\CatalogImportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CatalogImportActionTest extends StockTestCase
{
    public function testProfileWriteRequiresSessionAndBothWritePermissions(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(CatalogImportAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/eshop/imports/profiles')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(123, 'Fixture', 'staff', true, ['eshop.write' => 2, 'stock.items.write' => 2]))
            ->withParsedBody(['name' => 'Fixture', 'config' => ['mapping' => ['sku' => 'sku']]]);
        self::assertSame(403, $action->saveProfile($request, new Response())->getStatusCode());
        self::assertSame(403, $action->saveProfile($request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response())->getStatusCode());
        $session = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        self::assertSame(403, $action->saveProfile($session->withAttribute('auth.effective_role', new EffectiveRole(123, 'Fixture', 'staff', true, ['eshop.write' => 2])), new Response())->getStatusCode());
        $created = $action->saveProfile($session, new Response());
        self::assertSame(201, $created->getStatusCode());
        self::assertSame(409, $action->saveProfile($session, new Response())->getStatusCode());
        $other = $this->createSupplier();
        $profile = json_decode((string) $created->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(404, $action->saveProfile($session->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other)
            ->withParsedBody(['name' => 'Wrong tenant', 'version' => 1, 'config' => ['mapping' => ['sku' => 'sku']]]), new Response(), ['id' => $profile['id']])->getStatusCode());
    }
}
