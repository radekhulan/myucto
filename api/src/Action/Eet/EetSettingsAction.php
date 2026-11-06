<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetSettingsService;
use MyInvoice\Service\License\CommercialFeatureAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Profil EET firmy, historie změn a překážky aktivace. */
final class EetSettingsAction
{
    public function __construct(
        private readonly EetSettingsService $settings,
        private readonly CommercialFeatureAccess $commercial,
        private readonly ActivityLogger $logger,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $settings = $this->settings->get($supplierId);

        return Json::ok($response, [
            'settings' => $settings,
            'activation_obstacles' => $this->settings->activationObstacles($supplierId, $settings),
            'history' => $this->settings->history($supplierId),
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = EetActionSupport::body($request);

        // Zapnutí evidence je nová obchodní operace a patří za licenci; úpravy už
        // zapnuté evidence (a vypnutí) běží i po expiraci.
        $enabling = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        if ($enabling && !$this->settings->get($supplierId)['enabled'] && !$this->commercial->isAvailable()) {
            return Json::error(
                $response,
                'license_commercial_feature_unavailable',
                'Zapnutí evidence EET vyžaduje aktivní komerční licenci MyÚčto.',
                403,
            );
        }

        try {
            $saved = $this->settings->update($supplierId, $body, EetActionSupport::userId($request));
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->logger->log(
            'eet_settings_update',
            EetActionSupport::userId($request),
            'eet_settings',
            $supplierId,
            ['fields' => array_values(array_diff(array_keys($body), ['row_version'])), 'enabled' => $saved['enabled'], 'environment' => $saved['environment']],
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, [
            'settings' => $saved,
            'activation_obstacles' => $this->settings->activationObstacles($supplierId, $saved),
        ]);
    }
}
