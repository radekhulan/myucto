<?php

declare(strict_types=1);

namespace MyInvoice\Routes;

use MyInvoice\Action\Eet\EetCapabilitiesAction;
use MyInvoice\Action\Eet\EetCertificatesAction;
use MyInvoice\Action\Eet\EetDevicesAction;
use MyInvoice\Action\Eet\EetDiagnosticsAction;
use MyInvoice\Action\Eet\EetSettingsAction;
use MyInvoice\Action\Eet\EetUnitsAction;
use Slim\Interfaces\RouteCollectorProxyInterface;

/** EET 2.0 — schopnosti, nastavení, jednotky, zařízení, certifikáty a diagnostika. */
final class EetSettingsRoutes
{
    public static function register(RouteCollectorProxyInterface $app): void
    {
        $app->get('/api/eet/capabilities', EetCapabilitiesAction::class);

        $app->get('/api/eet/settings', [EetSettingsAction::class, 'show']);
        $app->put('/api/eet/settings', [EetSettingsAction::class, 'update']);

        $app->get('/api/eet/units', [EetUnitsAction::class, 'list']);
        $app->post('/api/eet/units', [EetUnitsAction::class, 'create']);
        $app->put('/api/eet/units/{id:[0-9]+}', [EetUnitsAction::class, 'update']);
        $app->delete('/api/eet/units/{id:[0-9]+}', [EetUnitsAction::class, 'endValidity']);

        $app->get('/api/eet/devices', [EetDevicesAction::class, 'list']);
        $app->post('/api/eet/devices', [EetDevicesAction::class, 'create']);
        $app->put('/api/eet/devices/{id:[0-9]+}', [EetDevicesAction::class, 'update']);
        $app->delete('/api/eet/devices/{id:[0-9]+}', [EetDevicesAction::class, 'deactivate']);

        $app->get('/api/eet/certificates', [EetCertificatesAction::class, 'list']);
        $app->post('/api/eet/certificates', [EetCertificatesAction::class, 'upload']);
        $app->post('/api/eet/certificates/{id:[0-9]+}/deactivate', [EetCertificatesAction::class, 'deactivate']);

        $app->post('/api/eet/diagnostics', EetDiagnosticsAction::class);
    }
}
