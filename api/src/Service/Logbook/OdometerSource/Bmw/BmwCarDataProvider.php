<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource\Bmw;

use MyInvoice\Service\Logbook\OdometerSource\ConnectContext;
use MyInvoice\Service\Logbook\OdometerSource\ConnectStep;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSample;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceDefinition;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceProvider;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceSession;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVehicle;

/**
 * BMW CarData: uživatel si v portálu MyBMW založí CarData Client (client ID) a přihlásí
 * ho ke službě CarData API; připojení pak proběhne přes Device Code Flow s PKCE ze
 * společné vrstvy OAuth klienta (verifier drží jádro, ne rozpracované přihlášení zdroje).
 *
 * Přístupy: client_id, access_token, refresh_token, access_expires_at a container_id
 * (kontejner s klíčem travelledDistance; zakládá se při prvním načtení).
 *
 * Do denního limitu BMW (50) se počítají jen volání CarData API, ne přihlášení a obnova tokenu.
 */
final class BmwCarDataProvider implements OdometerSourceProvider
{
    public const CODE = 'bmw_cardata';
    private const CREDENTIAL_KEYS = ['client_id', 'access_token', 'refresh_token', 'access_expires_at'];
    private const PENDING_KEYS = ['client_id', 'device_code', 'interval'];

    public function __construct(private readonly BmwCarDataClient $api) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function definition(): OdometerSourceDefinition
    {
        return new OdometerSourceDefinition(self::CODE, 'BMW CarData', [
            ['key' => 'client_id', 'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'],
        ], 50);
    }

    public function startConnect(#[\SensitiveParameter] array $input, ConnectContext $context): ConnectStep
    {
        $clientId = strtolower(trim((string) ($input['client_id'] ?? '')));
        if (!BmwCarDataClient::isValidClientId($clientId)) {
            throw new OdometerSourceException(OdometerSourceException::INVALID_INPUT, 'Client ID má tvar UUID, zkopírujte ho z portálu MyBMW.');
        }
        if ($context->codeVerifier === null) {
            throw new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Přihlášení k BMW vyžaduje PKCE.');
        }
        $code = $this->api->requestDeviceCode($clientId, $context->codeVerifier);
        return ConnectStep::deviceCode($code['user_code'], $code['verification_url'], $code['interval'], $code['expires_in'], [
            'client_id' => $clientId, 'device_code' => $code['device_code'], 'interval' => $code['interval'],
        ]);
    }

    public function continueConnect(#[\SensitiveParameter] array $pending, #[\SensitiveParameter] array $input): ConnectStep
    {
        $this->require($pending, self::PENDING_KEYS);
        $verifier = (string) ($input['code_verifier'] ?? '');
        if ($verifier === '') {
            throw new OdometerSourceException(OdometerSourceException::EXPIRED, 'Přihlášení k BMW vypršelo, začněte znovu.');
        }
        try {
            $tokens = $this->api->pollToken((string) $pending['client_id'], (string) $pending['device_code'], $verifier);
        } catch (OdometerSourceException $e) {
            $interval = (int) $pending['interval'];
            if ($e->errorCode === BmwCarDataClient::AUTHORIZATION_PENDING) return ConnectStep::waiting($interval);
            if ($e->errorCode === BmwCarDataClient::SLOW_DOWN) {
                return ConnectStep::waiting($interval + 5, ['interval' => $interval + 5] + $pending);
            }
            throw $e;
        }
        return ConnectStep::connected([
            'client_id' => (string) $pending['client_id'], 'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'], 'access_expires_at' => time() + $tokens['expires_in'],
        ]);
    }

    public function vehicles(OdometerSourceSession $session): array
    {
        $token = $this->accessToken($session);
        $session->spendCalls();
        return array_map(static fn (array $v): OdometerSourceVehicle => new OdometerSourceVehicle($v['vin'], $v['vin'], null, $v['primary']),
            $this->api->vehicleMappings($token));
    }

    public function readOdometer(OdometerSourceSession $session, string $vehicleRef): ?OdometerSample
    {
        $token = $this->accessToken($session);
        $containerId = $session->credentials()['container_id'] ?? null;
        $containerId = is_string($containerId) ? $containerId : $this->newContainer($session, $token);
        try {
            $session->spendCalls();
            $value = $this->api->travelledDistance($token, $vehicleRef, $containerId);
        } catch (OdometerSourceException $e) {
            if ($e->errorCode !== BmwCarDataClient::CONTAINER_INVALID) throw $e;
            $containerId = $this->newContainer($session, $token);
            $session->spendCalls();
            $value = $this->api->travelledDistance($token, $vehicleRef, $containerId);
        }
        return $value === null ? null : new OdometerSample($value['value'], $value['unit'], $value['at']);
    }

    /**
     * Platný access token; propadlý obnoví pod zámkem napojení — BMW přitom vždy vydá nový
     * refresh token a starý zneplatní, jádro ho proto uloží dřív, než se použije.
     */
    private function accessToken(OdometerSourceSession $session): string
    {
        $this->require($session->credentials(), self::CREDENTIAL_KEYS);
        $credentials = $session->refresh(
            static fn (array $c): bool => (int) ($c['access_expires_at'] ?? 0) > time() + 300,
            function (array $c): array {
                $this->require($c, self::CREDENTIAL_KEYS);
                $tokens = $this->api->refresh((string) $c['client_id'], (string) $c['refresh_token']);
                return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                    'access_expires_at' => time() + $tokens['expires_in']] + $c;
            },
        );
        return (string) $credentials['access_token'];
    }

    private function newContainer(OdometerSourceSession $session, #[\SensitiveParameter] string $token): string
    {
        $session->spendCalls();
        $containerId = $this->api->createContainer($token);
        $session->update(['container_id' => $containerId] + $session->credentials());
        return $containerId;
    }

    /** @param list<string> $keys */
    private function require(#[\SensitiveParameter] array $data, array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                throw new OdometerSourceException(OdometerSourceException::REAUTH_REQUIRED, 'Uložený přístup k BMW je neúplný, připojte auto znovu.');
            }
        }
        if (!BmwCarDataClient::isValidClientId((string) $data['client_id'])) {
            throw new OdometerSourceException(OdometerSourceException::REAUTH_REQUIRED, 'Uložený přístup k BMW je neplatný, připojte auto znovu.');
        }
    }
}
