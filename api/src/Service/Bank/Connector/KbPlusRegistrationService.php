<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class KbPlusRegistrationService
{
    private const REGISTRATION_URL = 'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register';
    private const MAX_CALLBACK_BYTES = 64 * 1024;
    private const SCOPES = ['adaa', 'bpisp', 'card_data', 'statda'];

    /**
     * @param array<string,mixed> $application
     * @return array{url:string,state:string,encryption_key:string}
     */
    public function begin(
        #[\SensitiveParameter] string $softwareStatement,
        #[\SensitiveParameter] array $application,
        #[\SensitiveParameter] string $state,
    ): array {
        if (!$this->isCompactJws($softwareStatement)) {
            throw $this->invalid('Software statement KB+ nemá platný formát.');
        }
        if (array_diff(array_keys($application), ['client_name', 'client_name_en', 'redirect_uris', 'scopes']) !== []) {
            throw $this->invalid('Registrační metadata KB+ obsahují neočekávaná data.');
        }
        $state = $this->state($state);
        $clientName = $this->text($application['client_name'] ?? null, 5, 50);
        $clientNameEn = $this->text($application['client_name_en'] ?? null, 5, 50);
        $redirectUris = $this->uris($application['redirect_uris'] ?? null);
        $scopes = $this->scopes($application['scopes'] ?? null);
        $encryptionKey = random_bytes(32);
        $request = [
            'clientName' => $clientName,
            'clientNameEn' => $clientNameEn,
            'applicationType' => 'web',
            'redirectUris' => $redirectUris,
            'scope' => $scopes,
            'encryptionKey' => base64_encode($encryptionKey),
            'encryptionAlg' => 'AES-256',
            'softwareStatement' => $softwareStatement,
        ];
        try {
            $encodedRequest = base64_encode(json_encode(
                $request,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                16,
            ));
        } catch (\JsonException) {
            throw $this->invalid('Registrační požadavek KB+ nelze serializovat.');
        }
        $url = self::REGISTRATION_URL . '?' . http_build_query([
            'registrationRequest' => $encodedRequest,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => $url,
            'state' => $state,
            'encryption_key' => base64_encode($encryptionKey),
        ];
    }

    /**
     * @param list<string> $expectedScopes
     * @param array<string,mixed> $callback
     * @return array{client_id:string,client_secret:string,scope:string,client_id_issued_at:int}
     */
    public function complete(
        #[\SensitiveParameter] string $encryptionKey,
        #[\SensitiveParameter] string $expectedState,
        string $expectedRedirectUri,
        array $expectedScopes,
        #[\SensitiveParameter] array $callback,
    ): array {
        $expectedState = $this->state($expectedState);
        $expectedRedirectUri = $this->uri($expectedRedirectUri);
        $expectedScopes = $this->scopes($expectedScopes);
        if (array_diff(array_keys($callback), ['salt', 'encryptedData', 'state']) !== []) {
            throw $this->invalid('Registrační callback KB+ obsahuje neočekávaná data.');
        }
        $key = base64_decode($encryptionKey, true);
        $salt = $this->base64UrlDecode($callback['salt'] ?? null);
        $encrypted = $this->base64UrlDecode($callback['encryptedData'] ?? null);
        if (
            !is_string($key)
            || strlen($key) !== 32
            || $salt === null
            || strlen($salt) !== 12
            || $encrypted === null
            || strlen($encrypted) < 17
            || strlen($encrypted) > self::MAX_CALLBACK_BYTES
        ) {
            throw $this->invalid('Registrační callback KB+ nemá platný formát.');
        }
        $tag = substr($encrypted, -16);
        $ciphertext = substr($encrypted, 0, -16);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $salt,
            $tag,
        );
        $this->clearOpenSslErrors();
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > self::MAX_CALLBACK_BYTES) {
            throw $this->invalid('Registrační callback KB+ nelze ověřit.');
        }
        try {
            $data = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->invalid('Registrační callback KB+ obsahuje neplatná data.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw $this->invalid('Registrační callback KB+ obsahuje neplatná data.');
        }

        $callbackState = $callback['state'] ?? null;
        $encryptedState = $data['state'] ?? null;
        $matchedState = false;
        foreach ([$callbackState, $encryptedState] as $returnedState) {
            if ($returnedState === null) {
                continue;
            }
            if (!is_string($returnedState) || !hash_equals($expectedState, $returnedState)) {
                throw $this->invalid('Registrační callback KB+ má neplatný state.');
            }
            $matchedState = true;
        }
        if (!$matchedState) {
            throw $this->invalid('Registrační callback KB+ neobsahuje state.');
        }

        $returnedRedirects = $this->returnedRedirects($data['redirect_uris'] ?? null);
        $returnedScopes = $this->returnedScopes($data);
        $grantTypes = $this->stringList($data['grant_types'] ?? null);
        $responseTypes = $this->stringList($data['response_types'] ?? null);
        if (
            ($data['application_type'] ?? null) !== 'web'
            || ($data['token_endpoint_auth_method'] ?? null) !== 'client_secret_post'
            || !in_array($expectedRedirectUri, $returnedRedirects, true)
            || $expectedScopes !== $returnedScopes
            || array_diff(['authorization_code', 'refresh_token'], $grantTypes) !== []
            || !in_array('code', $responseTypes, true)
        ) {
            throw $this->invalid('Registrační callback KB+ neodpovídá požadované aplikaci.');
        }

        $clientId = $this->secret($data['client_id'] ?? null, 1, 200);
        $clientSecret = $this->secret($data['client_secret'] ?? null, 10, 2048);
        $issuedAt = $data['client_id_issued_at'] ?? null;
        if (!is_int($issuedAt) || $issuedAt <= 0) {
            throw $this->invalid('Registrační callback KB+ neobsahuje platné časové údaje.');
        }
        sort($returnedScopes);
        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => implode(' ', $returnedScopes),
            'client_id_issued_at' => $issuedAt,
        ];
    }

    /** @return list<string> */
    private function returnedRedirects(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        return $this->uris($value);
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function returnedScopes(array $data): array
    {
        $scopes = $data['scopes'] ?? null;
        $scopeText = $data['scope'] ?? null;
        if (!is_array($scopes) && is_string($scopeText)) {
            $scopes = preg_split('/\s+/', trim($scopeText)) ?: [];
        }
        $normalized = $this->scopes($scopes);
        if (is_string($scopeText)) {
            $fromText = $this->scopes(preg_split('/\s+/', trim($scopeText)) ?: []);
            if ($normalized !== $fromText) {
                throw $this->invalid('Registrační callback KB+ vrátil nejednoznačné scopes.');
            }
        }
        return $normalized;
    }

    /** @return list<string> */
    private function scopes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $this->invalid('Registrační scopes KB+ nejsou platné.');
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || !in_array($scope, self::SCOPES, true) || in_array($scope, $scopes, true)) {
                throw $this->invalid('Registrační scopes KB+ nejsou platné.');
            }
            $scopes[] = $scope;
        }
        if (!in_array('adaa', $scopes, true)) {
            throw $this->invalid('Registrační scopes KB+ musí obsahovat ADAA.');
        }
        sort($scopes);
        return $scopes;
    }

    /** @return list<string> */
    private function uris(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 10) {
            throw $this->invalid('Registrační URI KB+ nejsou platná.');
        }
        $uris = [];
        foreach ($value as $uri) {
            $uris[] = $this->uri($uri);
        }
        if (count(array_unique($uris)) !== count($uris)) {
            throw $this->invalid('Registrační URI KB+ nejsou jedinečná.');
        }
        return $uris;
    }

    private function uri(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            throw $this->invalid('Registrační URI KB+ není platná.');
        }
        $parts = parse_url($value);
        if (
            ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw $this->invalid('Registrační URI KB+ není bezpečná HTTPS adresa.');
        }
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return [];
            }
        }
        return $value;
    }

    private function state(string $state): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $state)) {
            throw $this->invalid('Registrační state KB+ nemá platný formát.');
        }
        return $state;
    }

    private function text(mixed $value, int $min, int $max): string
    {
        if (
            !is_string($value)
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) < $min
            || mb_strlen($value) > $max
            || preg_match('/[\x00-\x1F\x7F]/u', $value)
        ) {
            throw $this->invalid('Registrační metadata KB+ nejsou platná.');
        }
        return $value;
    }

    private function secret(mixed $value, int $min, int $max): string
    {
        if (
            !is_string($value)
            || strlen($value) < $min
            || strlen($value) > $max
            || preg_match('/[\x00-\x20\x7F]/', $value)
        ) {
            throw $this->invalid('Registrační callback KB+ neobsahuje platné credentials.');
        }
        return $value;
    }

    private function isCompactJws(string $value): bool
    {
        if (strlen($value) < 20 || strlen($value) > 32768) {
            return false;
        }
        $parts = explode('.', $value);
        if (count($parts) !== 3
            || in_array('', $parts, true)
            || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[2]) !== 1
        ) {
            return false;
        }
        $header = $this->base64UrlDecode($parts[0]);
        if ($header === null) {
            return false;
        }
        try {
            $decoded = json_decode($header, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        return is_array($decoded)
            && is_string($decoded['alg'] ?? null)
            && in_array($decoded['alg'], ['HS256', 'RS256', 'PS256', 'ES256'], true);
    }

    private function base64UrlDecode(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) {
            return null;
        }
        $normalized = strtr(rtrim($value, '='), '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($normalized, true);
        return $decoded === false ? null : $decoded;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {}
    }

    private function invalid(string $message): BankConnectorException
    {
        return new BankConnectorException('kb_plus_registration_invalid', $message);
    }
}
