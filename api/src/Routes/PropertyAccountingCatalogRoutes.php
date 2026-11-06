<?php

declare(strict_types=1);
namespace MyInvoice\Routes;
use MyInvoice\Action\Property\Catalog\PropertyAccountingCatalogAction as A;
use Slim\Interfaces\RouteCollectorProxyInterface;
final class PropertyAccountingCatalogRoutes
{
    public static function register(RouteCollectorProxyInterface $app):void
    {
        $base='/api/property/catalog';
        $app->get($base.'/service-lifecycles',[A::class,'lifecycles']);
        $app->get($base.'/service-lifecycles/reconciliation',[A::class,'reconciliation']);
        $app->get($base.'/service-lifecycles/{id:[0-9]+}',[A::class,'lifecycle']);
        $app->get($base.'/funds/operations',[A::class,'operations']);
        $app->get($base.'/funds/balances',[A::class,'balances']);
    }
}
