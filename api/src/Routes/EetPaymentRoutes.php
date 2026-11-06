<?php

declare(strict_types=1);

namespace MyInvoice\Routes;

use MyInvoice\Action\Eet\EetPaymentAction;
use MyInvoice\Action\Eet\EetRecordAction;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * Platby s evidencí EET, QR záměry, evidence, opravy, fronta a export.
 * Oprávnění drží RoutePermissionMap (skupina `eet`).
 */
final class EetPaymentRoutes
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public static function register(RouteCollectorProxyInterface $app): void
    {
        $app->post('/api/eet/payments/preview', [EetPaymentAction::class, 'preview']);
        $app->post('/api/eet/payments', [EetPaymentAction::class, 'accept']);
        $app->get('/api/eet/payments/{publicId:' . self::UUID . '}', [EetPaymentAction::class, 'detail']);
        $app->post('/api/eet/payment-intents', [EetPaymentAction::class, 'createIntent']);
        $app->post('/api/eet/payment-intents/{publicId:' . self::UUID . '}/confirm', [EetPaymentAction::class, 'confirmIntent']);
        $app->post('/api/eet/payment-intents/{publicId:' . self::UUID . '}/cancel', [EetPaymentAction::class, 'cancelIntent']);

        $app->get('/api/eet/records', [EetRecordAction::class, 'list']);
        $app->get('/api/eet/records/{id:[0-9]+}', [EetRecordAction::class, 'detail']);
        $app->post('/api/eet/records/{id:[0-9]+}/retry', [EetRecordAction::class, 'retry']);
        $app->post('/api/eet/records/{id:[0-9]+}/corrections/preview', [EetRecordAction::class, 'correctionPreview']);
        $app->post('/api/eet/records/{id:[0-9]+}/corrections', [EetRecordAction::class, 'correction']);
        $app->get('/api/eet/queue', [EetRecordAction::class, 'queue']);
        $app->get('/api/eet/summary', [EetRecordAction::class, 'summary']);
        $app->get('/api/eet/export', [EetRecordAction::class, 'export']);
        $app->get('/api/invoices/{id:[0-9]+}/eet', [EetRecordAction::class, 'invoice']);
    }
}
