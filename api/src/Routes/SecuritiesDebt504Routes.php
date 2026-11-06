<?php

declare(strict_types=1);
namespace MyInvoice\Routes;
use MyInvoice\Action\Accounting\Closing\SecuritiesDebt504Action as A;
use Slim\Interfaces\RouteCollectorProxyInterface;
final class SecuritiesDebt504Routes
{
    public static function register(RouteCollectorProxyInterface $app): void
    {
        $base='/api/accounting/securities/debt';
        $app->get($base,[A::class,'page']);
        $app->get($base.'/sources/{kind:acquisitions|receipts|proceeds}',[A::class,'sources']);
        $app->get($base.'/profile-choices',[A::class,'profileChoices']);
        $app->get($base.'/{id:[0-9]+}',[A::class,'detail']);
        $app->post($base.'/preview',[A::class,'preview']);
        $app->post($base.'/commands',[A::class,'execute']);
    }
}
