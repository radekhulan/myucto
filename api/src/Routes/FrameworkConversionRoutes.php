<?php

declare(strict_types=1);
namespace MyInvoice\Routes;
use MyInvoice\Action\Accounting\FrameworkConversionAction as A;
use Slim\Interfaces\RouteCollectorProxyInterface;
final class FrameworkConversionRoutes
{
    public static function register(RouteCollectorProxyInterface $app):void
    {
        $base='/api/accounting/framework-conversions';$app->get($base,[A::class,'page']);$app->get($base.'/choices',[A::class,'choices']);$app->post($base.'/preview',[A::class,'preview']);$app->post($base.'/commands',[A::class,'confirm']);$app->post($base.'/{id:[0-9]+}/cancellation-preview',[A::class,'cancellationPreview']);$app->post($base.'/{id:[0-9]+}/cancellations',[A::class,'cancel']);$app->get($base.'/{id:[0-9]+}/result-decision-choices',[A::class,'decisionChoices']);$app->post($base.'/{id:[0-9]+}/result-decision-preview',[A::class,'decisionPreview']);$app->post($base.'/{id:[0-9]+}/result-decisions',[A::class,'decisionConfirm']);
    }
}
