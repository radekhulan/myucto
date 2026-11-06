<?php
declare(strict_types=1);
namespace MyInvoice\Routes;
use MyInvoice\Action\Property\Funds\FundingAction as A;
use Slim\Interfaces\RouteCollectorProxyInterface;
final class FundingRoutes
{
    public static function register(RouteCollectorProxyInterface $app):void {$base='/api/property/funds';$app->get($base,[A::class,'page']);$app->get($base.'/choices',[A::class,'choices']);$app->get($base.'/sources/{kind:costs|banks|coverages}',[A::class,'sources']);$app->get($base.'/balances',[A::class,'balances']);$app->post($base.'/preview',[A::class,'preview']);$app->post($base.'/commands',[A::class,'execute']);}
}
