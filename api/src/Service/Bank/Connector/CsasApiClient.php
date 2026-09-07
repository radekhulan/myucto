<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;

final class CsasApiClient
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly ClientInterface $http, private readonly bool $sandbox = false,
        private readonly \Psr\Log\LoggerInterface $logger = new \Psr\Log\NullLogger()) {}

    public function environment(): string
    {
        return $this->sandbox ? 'sandbox' : 'production';
    }

    public function accountsBaseUrl(): string
    {
        return $this->sandbox ? 'https://webapi.developers.erstegroup.com/api/csas/public/sandbox/v3/accounts'
            : 'https://www.csas.cz/webapi/api/v3/accounts';
    }

    public function paymentsBaseUrl(): string
    {
        return $this->sandbox ? 'https://webapi.developers.erstegroup.com/api/csas/public/sandbox/v1/payments'
            : 'https://www.csas.cz/webapi/api/v1/payments';
    }

    public function assertEnvironment(#[\SensitiveParameter] array $credentials): void
    {
        if (($credentials['environment'] ?? 'production') !== $this->environment()) {
            throw new BankConnectorException('csas_environment_mismatch', 'Změnilo se prostředí České spořitelny. Připojte účet znovu s údaji pro aktuální prostředí.');
        }
    }

    private function identityBaseUrl(): string
    {
        return $this->sandbox ? 'https://webapi.developers.erstegroup.com/api/csas/sandbox/v1/sandbox-idp'
            : 'https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1';
    }

    public function authorizationUrl(#[\SensitiveParameter] array $credentials, string $state): string
    {
        $this->assertEnvironment($credentials);
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw $this->invalid();
        return $this->identityBaseUrl() . '/auth?' . http_build_query([
            'response_type' => 'code', 'client_id' => $this->value($credentials, 'client_id'),
            'redirect_uri' => $this->value($credentials, 'redirect_uri'), 'scope' => 'siblings.accounts',
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->value($credentials, 'code_verifier'), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $code): array
    {
        if ($code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7f]/', $code)) throw $this->invalid();
        $tokens = $this->tokens($credentials, ['grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $this->value($credentials, 'redirect_uri'), 'code_verifier' => $this->value($credentials, 'code_verifier')]);
        if (!isset($tokens['refresh_token'])) throw $this->invalid();
        return $tokens;
    }

    public function refresh(#[\SensitiveParameter] array $credentials): array
    {
        return $this->tokens($credentials, ['grant_type' => 'refresh_token', 'refresh_token' => $this->value($credentials, 'refresh_token')]);
    }

    public function accounts(#[\SensitiveParameter] array $credentials): array
    {
        $this->assertEnvironment($credentials);
        $accounts = $this->pages($this->accountsBaseUrl() . '/my/accounts', 'accounts', $credentials, []);
        foreach ($accounts as $account) {
            if (!is_array($account) || !is_string($account['id'] ?? null) || $account['id'] === '' || strlen($account['id']) > 512
                || !is_string($account['identification']['iban'] ?? null) || !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $account['identification']['iban'])
                || !(new \MyInvoice\Service\Payment\IbanValidator())->isValid($account['identification']['iban'])
                || !is_string($account['currency'] ?? null) || !preg_match('/^[A-Z]{3}$/D', $account['currency'])) {
                $this->logger->warning('csas_response_invalid', ['environment' => $this->environment(), 'stage' => 'account_schema',
                    'id_type' => get_debug_type($account['id'] ?? null),
                    'iban_present' => is_string($account['identification']['iban'] ?? null),
                    'currency_type' => get_debug_type($account['currency'] ?? null)]);
                throw $this->invalid();
            }
        }
        return $accounts;
    }

    public function transactions(#[\SensitiveParameter] array $credentials, string $from, string $to): array
    {
        $this->assertEnvironment($credentials);
        foreach ([$from, $to] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw $this->invalid();
        }
        if ($from > $to) throw $this->invalid();
        $rows = $this->pages($this->accountsBaseUrl() . '/my/accounts/' . rawurlencode($this->value($credentials, 'account_id')) . '/transactions',
            'transactions', $credentials, ['fromDate' => $from, 'toDate' => $to]);
        if (!$this->sandbox) return $rows;
        return array_values(array_filter($rows, function (array $row) use ($from, $to): bool {
            if (in_array($row['status'] ?? null, ['INFO', 'PDNG', 'PENDING'], true)) return false;
            $value = $row['bookingDate']['date'] ?? null;
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T00:00:00(?:\.0+)?Z)?$/D', $value)) throw $this->invalid();
            $date = substr($value, 0, 10);
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw $this->invalid();
            return $date >= $from && $date <= $to;
        }));
    }

    private function pages(string $url, string $field, #[\SensitiveParameter] array $credentials, array $query): array
    {
        $result = [];
        $bytes = 0;
        $deadline = microtime(true) + 120;
        $expectedPages = null;
        for ($page = 0; $page < 50; $page++) {
            if (microtime(true) > $deadline) throw $this->invalid();
            $data = $this->request('GET', $url, ['headers' => [
                'Authorization' => 'Bearer ' . $this->value($credentials, 'access_token'),
                'WEB-API-key' => $this->value($credentials, 'api_key'),
            ], 'query' => $query + ['page' => $page, 'size' => 200]], $bytes);
            $count = $data['pageCount'] ?? null;
            $metadata = [];
            foreach (['pageCount', 'pageNumber', 'pageSize', 'nextPage'] as $key) {
                $value = $data[$key] ?? null;
                $metadata[$key] = is_int($value) && $value >= 0 && $value <= 100000 ? $value : get_debug_type($value);
            }
            $this->logger->info('csas_response_shape', ['environment' => $this->environment(), 'operation' => $field,
                'pagination' => $metadata, 'collection_type' => get_debug_type($data[$field] ?? null),
                'collection_count' => is_array($data[$field] ?? null) ? count($data[$field]) : null]);
            if (!is_int($count) || $count < 0 || $count > 50 || ($data['pageNumber'] ?? null) !== $page
                || !is_int($data['pageSize'] ?? null) || $data['pageSize'] < 1 || $data['pageSize'] > 200
                || !is_array($data[$field] ?? null) || !array_is_list($data[$field]) || count($data[$field]) > $data['pageSize']
                || ($expectedPages !== null && $count !== $expectedPages)) throw $this->invalid();
            $expectedPages = $count;
            if ($count === 0 && $data[$field] !== []) throw $this->invalid();
            foreach ($data[$field] as $row) {
                if (!is_array($row)) throw $this->invalid();
                $result[] = $row;
            }
            if ($page + 1 >= $count) {
                if (isset($data['nextPage'])) throw $this->invalid();
                return $result;
            }
            if (isset($data['nextPage']) && $data['nextPage'] !== $page + 1) throw $this->invalid();
            if ($data[$field] === []) throw $this->invalid();
        }
        throw $this->invalid();
    }

    private function tokens(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] array $form): array
    {
        $this->assertEnvironment($credentials);
        $bytes = 0;
        $data = $this->request('POST', $this->identityBaseUrl() . '/token', ['form_params' => $form + [
            'client_id' => $this->value($credentials, 'client_id'), 'client_secret' => $this->value($credentials, 'client_secret'),
        ]], $bytes);
        if (strcasecmp((string) ($data['token_type'] ?? ''), 'Bearer') !== 0 || !is_int($data['expires_in'] ?? null)
            || $data['expires_in'] < 1 || $data['expires_in'] > 86400) throw $this->invalid();
        if (isset($data['scope']) && (!is_string($data['scope']) || !in_array('siblings.accounts', explode(' ', $data['scope']), true))) throw $this->invalid();
        $result = ['access_token' => $this->value($data, 'access_token'), 'expires_in' => $data['expires_in']];
        if (isset($data['refresh_token'])) $result['refresh_token'] = $this->value($data, 'refresh_token');
        return $result;
    }

    private function request(string $method, string $url, #[\SensitiveParameter] array $options, int &$bytes): array
    {
        $options['headers']['Accept'] = 'application/json';
        $options += ['allow_redirects' => false, 'verify' => true, 'debug' => false, 'http_errors' => false,
            'connect_timeout' => 5, 'timeout' => 20, 'stream' => true];
        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable) {
            $this->logger->warning('csas_http_failed', ['environment' => $this->environment(),
                'operation' => str_ends_with($url, '/token') ? 'token' : 'accounts', 'reason' => 'transport']);
            throw new BankConnectorException(BankConnectorException::REMOTE_UNAVAILABLE, 'API České spořitelny není dostupné.');
        }
        $status = $response->getStatusCode();
        $this->logger->info('csas_http_response', ['environment' => $this->environment(),
            'operation' => str_ends_with($url, '/token') ? 'token' : (str_ends_with($url, '/transactions') ? 'transactions' : 'accounts'),
            'http_status' => $status]);
        if ($status !== 200) throw new BankConnectorException(match ($status) {
            401, 403 => BankConnectorException::INVALID_TOKEN, 429 => BankConnectorException::RATE_LIMITED,
            default => BankConnectorException::REMOTE_HTTP_ERROR,
        }, 'API České spořitelny požadavek odmítlo.', false, $status);
        try {
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                if ($chunk === '') throw new \RuntimeException();
                $body .= $chunk;
            }
            $bytes += strlen($body);
            if (strlen($body) > self::MAX_BYTES || $bytes > 32 * 1024 * 1024) throw new \RuntimeException();
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) throw new \RuntimeException();
            return $data;
        } catch (\Throwable) {
            throw $this->invalid();
        }
    }

    private function value(#[\SensitiveParameter] array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 16384 || preg_match('/[\x00-\x20\x7f]/', $value)) throw $this->invalid();
        return $value;
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatná odpověď nebo konfigurace České spořitelny.');
    }
}
