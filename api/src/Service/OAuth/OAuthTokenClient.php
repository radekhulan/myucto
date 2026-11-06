<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP strana OAuth klienta: autorizační URL, výměna kódu, obnova tokenu a Device Code
 * Flow (RFC 6749, RFC 7636, RFC 8628). Chyby serveru vrací jako {@see OAuthException}
 * s kódem z odpovědi; tělo odpovědi ani tokeny se nelogují.
 */
final class OAuthTokenClient
{
    private const MAX_BYTES = 1024 * 1024;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $logPrefix = 'oauth',
    ) {}

    public function authorizationUrl(OAuthProviderConfig $config, string $redirectUri, OAuthAuthorization $authorization): string
    {
        if ($config->authorizationUrl === null) {
            throw new OAuthException(OAuthException::INVALID_REQUEST, 'Poskytovatel nemá autorizační adresu.');
        }
        $query = ['response_type' => 'code', 'client_id' => $config->clientId, 'redirect_uri' => $redirectUri]
            + ($config->scope !== '' ? ['scope' => $config->scope] : [])
            + $config->authorizationParams
            + ['state' => $authorization->state];
        $challenge = $authorization->codeChallenge();
        if ($challenge !== null) {
            $query += ['code_challenge' => $challenge, 'code_challenge_method' => Pkce::METHOD];
        }
        return $config->authorizationUrl . (str_contains($config->authorizationUrl, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(OAuthProviderConfig $config, #[\SensitiveParameter] string $code, string $redirectUri,
        #[\SensitiveParameter] ?string $codeVerifier): OAuthTokenSet
    {
        self::assertToken($code);
        return $this->tokens($config, 'token_exchange', ['grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $redirectUri] + ($codeVerifier !== null ? ['code_verifier' => $codeVerifier] : []));
    }

    public function refresh(OAuthProviderConfig $config, #[\SensitiveParameter] string $refreshToken): OAuthTokenSet
    {
        self::assertToken($refreshToken);
        return $this->tokens($config, 'token_refresh', ['refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);
    }

    public function requestDeviceCode(OAuthProviderConfig $config, ?string $codeChallenge): OAuthDeviceAuthorization
    {
        if ($config->deviceAuthorizationUrl === null) {
            throw new OAuthException(OAuthException::INVALID_REQUEST, 'Poskytovatel nepodporuje Device Code Flow.');
        }
        $form = ['client_id' => $config->clientId] + $config->deviceParams
            + ($config->scope !== '' ? ['scope' => $config->scope] : [])
            + ($codeChallenge !== null ? ['code_challenge' => $codeChallenge, 'code_challenge_method' => Pkce::METHOD] : []);
        [$status, $data] = $this->post($config->deviceAuthorizationUrl, $form, 'device_code');
        if ($status !== 200) throw $this->serverError($status, $data);
        return new OAuthDeviceAuthorization(
            self::str($data, 'device_code'),
            self::str($data, 'user_code'),
            self::str($data, 'verification_uri'),
            self::int($data, 'interval', 1, 60, 5),
            self::int($data, 'expires_in', 30, 3600, 300),
        );
    }

    /**
     * Jeden pokus o výměnu device code za tokeny. Dokud uživatel přihlášení nepotvrdí,
     * hází {@see OAuthException::AUTHORIZATION_PENDING}, resp. {@see OAuthException::SLOW_DOWN}.
     */
    public function pollDeviceCode(OAuthProviderConfig $config, #[\SensitiveParameter] string $deviceCode,
        #[\SensitiveParameter] ?string $codeVerifier): OAuthTokenSet
    {
        self::assertToken($deviceCode);
        return $this->tokens($config, 'token_poll', ['device_code' => $deviceCode]
            + ($codeVerifier !== null ? ['code_verifier' => $codeVerifier] : [])
            + ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code']);
    }

    private function tokens(OAuthProviderConfig $config, string $operation, #[\SensitiveParameter] array $grant): OAuthTokenSet
    {
        $form = ['client_id' => $config->clientId] + $grant
            + ($config->clientSecret !== null ? ['client_secret' => $config->clientSecret] : []);
        [$status, $data] = $this->post($config->tokenUrl, $form, $operation);
        if ($status !== 200) throw $this->serverError($status, $data);
        if (strcasecmp((string) ($data['token_type'] ?? 'Bearer'), 'Bearer') !== 0) throw self::invalid();
        $scope = $data['scope'] ?? null;
        if ($scope !== null && (!is_string($scope) || strlen($scope) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $scope))) throw self::invalid();
        return new OAuthTokenSet(
            self::str($data, 'access_token'),
            array_key_exists('refresh_token', $data) ? self::str($data, 'refresh_token') : null,
            self::int($data, 'expires_in', 60, 86400, 3600),
            $scope,
        );
    }

    private function serverError(int $status, array $data): OAuthException
    {
        $error = is_string($data['error'] ?? null) ? $data['error'] : '';
        return new OAuthException(match (true) {
            in_array($error, OAuthException::SERVER_ERRORS, true) => $error,
            $status === 429 => OAuthException::RATE_LIMITED,
            default => OAuthException::REMOTE_ERROR,
        }, 'Autorizační server požadavek odmítl.', $status);
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function post(string $url, #[\SensitiveParameter] array $form, string $operation): array
    {
        try {
            $response = $this->http->request('POST', $url, ['form_params' => $form, 'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => false, 'verify' => true, 'debug' => false, 'http_errors' => false,
                'connect_timeout' => 5, 'timeout' => 20, 'stream' => true]);
        } catch (\Throwable) {
            $this->logger->warning($this->logPrefix . '_http_failed', ['operation' => $operation, 'reason' => 'transport']);
            throw new OAuthException(OAuthException::REMOTE_UNAVAILABLE, 'Autorizační server není dostupný.');
        }
        $status = $response->getStatusCode();
        $this->logger->info($this->logPrefix . '_http_response', ['operation' => $operation, 'http_status' => $status]);
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
            throw self::invalid();
        }
    }

    private static function assertToken(#[\SensitiveParameter] string $value): void
    {
        if ($value === '' || strlen($value) > 16384 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new OAuthException(OAuthException::INVALID_REQUEST, 'Neplatný kód nebo token.');
        }
    }

    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 16384 || preg_match('/[\x00-\x20\x7f]/', $value)) throw self::invalid();
        return $value;
    }

    private static function int(array $data, string $key, int $min, int $max, int $default): int
    {
        $value = $data[$key] ?? $default;
        if (is_string($value) && ctype_digit($value)) $value = (int) $value;
        if (!is_int($value)) throw self::invalid();
        return max($min, min($max, $value));
    }

    private static function invalid(): OAuthException
    {
        return new OAuthException(OAuthException::INVALID_RESPONSE, 'Neplatná odpověď autorizačního serveru.');
    }
}
