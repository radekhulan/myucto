<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthCallbackHandler;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Návrat ze zdroje stavu tachometru na společný OAuth callback. Kód jen uloží
 * k rozpracovanému přihlášení ({@see OdometerSourceService::receiveCallback()});
 * připojení dokončí dialog, který se na něj dál ptá.
 */
final class OdometerOAuthCallbackHandler implements OAuthCallbackHandler
{
    public function __construct(private readonly OdometerSourceService $service) {}

    public function domain(): string
    {
        return OdometerSourceService::OAUTH_DOMAIN;
    }

    public function allows(Request $request): bool
    {
        return RequestAuthorization::canManageOdometerSources($request);
    }

    public function complete(OAuthCallback $callback): string
    {
        try {
            return self::location('authorized', $this->service->receiveCallback($callback));
        } catch (OdometerSourceException $e) {
            return self::location('error', null, $e->errorCode);
        }
    }

    public function failureLocation(array $session, string $errorCode): string
    {
        return self::location('error', null, $errorCode);
    }

    private static function location(string $status, ?int $carId, ?string $errorCode = null): string
    {
        $query = ['tab' => 'cars', 'odometer_source' => $status];
        if ($carId !== null) $query['car_id'] = $carId;
        if ($errorCode !== null && preg_match('/^[a-z0-9_]{1,64}$/D', $errorCode) === 1) $query['code'] = $errorCode;
        return '/logbook?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
