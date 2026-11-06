<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource\Bmw;

use GuzzleHttp\ClientInterface;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSample;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\OAuth\OAuthDeviceAuthorization;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Service\OAuth\OAuthProviderConfig;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use MyInvoice\Service\OAuth\OAuthTokenSet;
use MyInvoice\Service\OAuth\Pkce;
use Psr\Http\Message\ResponseInterface;

/**
 * Klient BMW CarData (customer API, EU). Přihlášení přes OAuth Device Code Flow s PKCE
 * (RFC 8628) běží přes společnou vrstvu OAuth klienta ({@see OAuthTokenClient}), čtení
 * stavu tachometru přes REST telematicData s vlastním kontejnerem.
 *
 * Limity služby: access token platí hodinu, refresh token dva týdny a při každém
 * obnovení se mění (nový je nutné uložit), REST API povoluje 50 volání denně.
 */
final class BmwCarDataClient
{
    public const AUTH_BASE_URL = 'https://customer.bmwgroup.com/gcdm/oauth';
    public const API_BASE_URL = 'https://api-cardata.bmwgroup.com';
    public const SCOPE = 'authenticate_user openid cardata:api:read';
    public const ODOMETER_KEY = 'vehicle.vehicle.travelledDistance';
    private const MAX_BYTES = 1024 * 1024;

    // Kódy, které zdroj zpracuje sám (polling, nový kontejner); ven se nedostanou
    // nebo jen s vlastním textem v i18n `logbook.odosrc_providers.bmw_cardata.err`.
    public const AUTHORIZATION_PENDING = 'authorization_pending';
    public const SLOW_DOWN             = 'slow_down';
    public const CONTAINER_INVALID     = 'container_invalid';
    public const CONTAINER_LIMIT       = 'container_limit';

    private readonly OAuthTokenClient $oauth;

    public function __construct(private readonly ClientInterface $http,
        private readonly \Psr\Log\LoggerInterface $logger = new \Psr\Log\NullLogger())
    {
        $this->oauth = new OAuthTokenClient($http, $logger, 'bmw_cardata');
    }

    public static function isValidClientId(string $clientId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $clientId) === 1;
    }

    public static function isValidVin(string $vin): bool
    {
        return preg_match('/^[A-HJ-NPR-Z0-9]{17}$/D', $vin) === 1;
    }

    /**
     * @return array{device_code:string, user_code:string, verification_url:string, interval:int, expires_in:int}
     */
    public function requestDeviceCode(string $clientId, #[\SensitiveParameter] string $codeVerifier): array
    {
        $code = $this->oauthCall(fn (): OAuthDeviceAuthorization =>
            $this->oauth->requestDeviceCode($this->config($clientId), Pkce::challenge($codeVerifier)));
        $uri = $code->verificationUri;
        $parts = parse_url($uri);
        if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)bmwgroup\.com$/D', (string) ($parts['host'] ?? ''))) {
            throw $this->invalid();
        }
        return [
            'device_code'      => $code->deviceCode,
            'user_code'        => $code->userCode,
            'verification_url' => $uri . (str_contains($uri, '?') ? '&' : '?') . 'user_code=' . rawurlencode($code->userCode),
            'interval'         => $code->interval,
            'expires_in'       => $code->expiresIn,
        ];
    }

    /**
     * Jeden pokus o výměnu device code za tokeny. Dokud uživatel přihlášení nepotvrdí,
     * hází AUTHORIZATION_PENDING (resp. SLOW_DOWN).
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     */
    public function pollToken(string $clientId, #[\SensitiveParameter] string $deviceCode, #[\SensitiveParameter] string $codeVerifier): array
    {
        return $this->tokens(fn (OAuthProviderConfig $config): OAuthTokenSet => $this->oauth->pollDeviceCode($config, $deviceCode, $codeVerifier),
            $clientId, false);
    }

    /** @return array{access_token:string, refresh_token:string, expires_in:int} */
    public function refresh(string $clientId, #[\SensitiveParameter] string $refreshToken): array
    {
        return $this->tokens(fn (OAuthProviderConfig $config): OAuthTokenSet => $this->oauth->refresh($config, $refreshToken),
            $clientId, true);
    }

    /** @return list<array{vin:string, primary:bool}> */
    public function vehicleMappings(#[\SensitiveParameter] string $accessToken): array
    {
        $data = $this->api('GET', '/customers/vehicles/mappings', $accessToken, [], 'mappings');
        $list = array_is_list($data) ? $data : ($data['mappings'] ?? $data['vehicles'] ?? null);
        if (!is_array($list) || !array_is_list($list)) throw $this->invalid();
        $result = [];
        foreach ($list as $item) {
            if (!is_array($item) || !is_string($item['vin'] ?? null) || !self::isValidVin($item['vin'])) continue;
            $result[] = ['vin' => $item['vin'], 'primary' => strtoupper((string) ($item['mappingType'] ?? '')) === 'PRIMARY'];
        }
        return $result;
    }

    public function createContainer(#[\SensitiveParameter] string $accessToken): string
    {
        $data = $this->api('POST', '/customers/containers', $accessToken, ['json' => [
            'name' => 'MyUcto odometer', 'purpose' => 'Kniha jizd: denni stav tachometru',
            'technicalDescriptors' => [self::ODOMETER_KEY],
        ]], 'container');
        $id = $data['containerId'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $id)) throw $this->invalid();
        return $id;
    }

    /**
     * Poslední stav tachometru, který auto odeslalo. Null, když ho auto zatím nehlásí.
     *
     * @return array{value:float, unit:string, at:\DateTimeImmutable}|null
     */
    public function travelledDistance(#[\SensitiveParameter] string $accessToken, string $vin, string $containerId): ?array
    {
        if (!self::isValidVin($vin)) throw new OdometerSourceException(OdometerSourceException::VEHICLE_NOT_PERMITTED, 'Neplatný VIN.');
        $data = $this->api('GET', '/customers/vehicles/' . rawurlencode($vin) . '/telematicData', $accessToken,
            ['query' => ['containerId' => $containerId]], 'telematic');
        $values = $data['telematicData'] ?? null;
        if (!is_array($values)) throw $this->invalid();
        $entry = $values[self::ODOMETER_KEY] ?? null;
        if (array_is_list($values)) {
            $entry = null;
            foreach ($values as $item) {
                if (is_array($item) && ($item['name'] ?? null) === self::ODOMETER_KEY) $entry = $item;
            }
        }
        if (!is_array($entry) || ($entry['value'] ?? null) === null || ($entry['value'] ?? '') === '') return null;
        $raw = $entry['value'];
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 10_000_000) throw $this->invalid();
        // Převod na km dělá jádro; metry jen přepočteme na km, aby jednotka byla km nebo mi.
        [$value, $unit] = match (strtolower(trim((string) ($entry['unit'] ?? 'km')))) {
            'km', '' => [(float) $raw, OdometerSample::UNIT_KM],
            'mi', 'miles' => [(float) $raw, OdometerSample::UNIT_MI],
            'm' => [(float) $raw / 1000, OdometerSample::UNIT_KM],
            default => throw $this->invalid(),
        };
        if (!is_string($entry['timestamp'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $entry['timestamp'])) throw $this->invalid();
        try {
            $at = new \DateTimeImmutable($entry['timestamp']);
        } catch (\Throwable) {
            throw $this->invalid();
        }
        return ['value' => $value, 'unit' => $unit, 'at' => $at];
    }

    /** Autorizační server BMW pro společnou vrstvu OAuth klienta. */
    private function config(string $clientId): OAuthProviderConfig
    {
        if (!self::isValidClientId($clientId)) throw new OdometerSourceException(OdometerSourceException::INVALID_INPUT, 'Neplatné client ID.');
        return new OAuthProviderConfig($clientId, self::AUTH_BASE_URL . '/token', null, self::AUTH_BASE_URL . '/device/code',
            self::SCOPE, null, [], ['response_type' => 'device_code']);
    }

    /**
     * @param callable(OAuthProviderConfig):OAuthTokenSet $call
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     */
    private function tokens(callable $call, string $clientId, bool $refresh): array
    {
        $config = $this->config($clientId);
        try {
            $tokens = $this->oauthCall(fn (): OAuthTokenSet => $call($config));
        } catch (OdometerSourceException $e) {
            // Při obnovení znamená odmítnutí propadlý nebo zneplatněný refresh token.
            if ($refresh && in_array($e->errorCode, [OdometerSourceException::REMOTE_ERROR,
                OdometerSourceException::INVALID_INPUT, OdometerSourceException::EXPIRED], true) && ($e->httpStatus ?? 500) < 500) {
                throw new OdometerSourceException(OdometerSourceException::REAUTH_REQUIRED, 'Přihlášení k BMW vypršelo, připojte auto znovu.', $e->httpStatus);
            }
            throw $e;
        }
        // BMW při každé obnově vydá nový refresh token; odpověď bez něj je neplatná.
        if ($tokens->refreshToken === null) throw $this->invalid();
        return ['access_token' => $tokens->accessToken, 'refresh_token' => $tokens->refreshToken, 'expires_in' => $tokens->expiresIn];
    }

    /**
     * Chyby společné vrstvy převede na kódy zdroje.
     *
     * @template T
     * @param callable():T $call
     * @return T
     */
    private function oauthCall(callable $call): mixed
    {
        try {
            return $call();
        } catch (OAuthException $e) {
            throw match ($e->errorCode) {
                OAuthException::REMOTE_UNAVAILABLE => new OdometerSourceException(OdometerSourceException::REMOTE_UNAVAILABLE, 'BMW CarData není dostupné.'),
                OAuthException::INVALID_RESPONSE   => $this->invalid(),
                default => new OdometerSourceException(match ($e->errorCode) {
                    OAuthException::AUTHORIZATION_PENDING => self::AUTHORIZATION_PENDING,
                    OAuthException::SLOW_DOWN             => self::SLOW_DOWN,
                    OAuthException::ACCESS_DENIED         => OdometerSourceException::ACCESS_DENIED,
                    OAuthException::EXPIRED_TOKEN         => OdometerSourceException::EXPIRED,
                    OAuthException::INVALID_CLIENT        => OdometerSourceException::INVALID_INPUT,
                    OAuthException::RATE_LIMITED          => OdometerSourceException::RATE_LIMITED,
                    default                               => OdometerSourceException::REMOTE_ERROR,
                }, 'Přihlášení k BMW se nepodařilo.', $e->httpStatus),
            };
        }
    }

    private function api(string $method, string $path, #[\SensitiveParameter] string $accessToken, array $options, string $operation): array
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $accessToken, 'x-version' => 'v1'];
        [$status, $data] = $this->request($method, self::API_BASE_URL . $path, $options, $operation);
        if ($status >= 200 && $status < 300) return $data;
        $code = is_string($data['exveErrorId'] ?? null) ? $data['exveErrorId'] : '';
        throw new OdometerSourceException(match (true) {
            $code === 'CU-429' || $status === 429 => OdometerSourceException::RATE_LIMITED,
            $code === 'CU-105'                    => self::CONTAINER_INVALID,
            $code === 'CU-124'                    => self::CONTAINER_LIMIT,
            in_array($code, ['CU-104', 'CU-120'], true) => OdometerSourceException::VEHICLE_NOT_PERMITTED,
            in_array($code, ['CU-102', 'CU-103'], true), $status === 401 => OdometerSourceException::TOKEN_INVALID,
            default => OdometerSourceException::REMOTE_ERROR,
        }, 'BMW CarData požadavek odmítlo.', $status);
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function request(string $method, string $url, #[\SensitiveParameter] array $options, string $operation): array
    {
        $options['headers']['Accept'] = 'application/json';
        $options += ['allow_redirects' => false, 'verify' => true, 'debug' => false, 'http_errors' => false,
            'connect_timeout' => 5, 'timeout' => 20, 'stream' => true];
        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable) {
            $this->logger->warning('bmw_cardata_http_failed', ['operation' => $operation, 'reason' => 'transport']);
            throw new OdometerSourceException(OdometerSourceException::REMOTE_UNAVAILABLE, 'BMW CarData není dostupné.');
        }
        $status = $response->getStatusCode();
        $this->logger->info('bmw_cardata_http_response', ['operation' => $operation, 'http_status' => $status]);
        return [$status, $this->body($response, $status)];
    }

    private function body(ResponseInterface $response, int $status): array
    {
        try {
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                if ($chunk === '') break;
                $body .= $chunk;
            }
            if (strlen($body) > self::MAX_BYTES) throw new \RuntimeException();
            if (trim($body) === '') return [];
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \RuntimeException();
            return $data;
        } catch (\Throwable) {
            // Chybová odpověď bez JSON těla nese informaci jen ve stavovém kódu.
            if ($status < 200 || $status >= 300) return [];
            throw $this->invalid();
        }
    }

    private function invalid(): OdometerSourceException
    {
        return new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Neplatná odpověď BMW CarData.');
    }
}
