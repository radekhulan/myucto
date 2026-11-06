<?php

declare(strict_types=1);

namespace MyInvoice\Routes;

use MyInvoice\Action\Property\Services\Settlement\ServiceLifecycleAction as A;
use Slim\Interfaces\RouteCollectorProxyInterface;

final class ServiceLifecycleRoutes
{
    public static function register(RouteCollectorProxyInterface $app): void
    {
        $base='/api/property/service-lifecycles';
        $app->get($base,[A::class,'page']);
        $app->get($base.'/choices',[A::class,'choices']);
        $app->get($base.'/sources/{kind:estimates|invoices|openings}',[A::class,'sources']);
        $app->get($base.'/policies',[A::class,'policies']);
        $app->post($base.'/policies',[A::class,'declarePolicy']);
        $app->get($base.'/{id:[0-9]+}',[A::class,'detail']);
        $app->post($base.'/preview/{operation:start|start_opening|unwind|finalize}',[A::class,'preview']);
        $app->post($base.'/commands/{operation:start|start_opening|unwind|finalize}',[A::class,'execute']);
    }
}
