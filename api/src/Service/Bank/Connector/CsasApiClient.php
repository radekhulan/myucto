<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;

final class CsasApiClient
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_EMPTY_READS = 200;
    private const SCOPE_ACCOUNTS = 'siblings.accounts';
    private const SCOPE_PAYMENTS = 'siblings.payments';
    private const PAYMENT_MAX_BYTES = 256 * 1024;
    /** Stavy federovaného podpisu (GET …/sign/poll/{pollId}). */
    public const SIGN_STATES = ['DONE', 'AUTHORIZATION_NOT_STARTED', 'PROCESSING', 'REJECTED', 'EXPIRED', 'ORDER_PROCESSING_FAILED'];
    /** Stavy platby (GET /my/payments/{signId}/status). */
    public const INSTRUCTION_STATES = ['ACTC', 'ACSP', 'ACSC', 'RJCT'];
    /** Chyby banky, které znamenají jisté odmítnutí s vlastní hláškou v aplikaci. */
    private const PAYMENT_ERRORS = [
        'INVALID_DATE_IN_PAST' => 'payment_execution_date_too_early',
        'FIELD_INVALID_TOO_FAR_IN_FUTURE' => 'payment_execution_date_too_far',
        'ACC_DIFFERENT' => 'payment_order_account_mismatch',
        'INVALID_DOMESTIC_PAYMENT_CURRENCY' => 'payment_order_not_domestic',
        'TOO_MANY_ITEMS' => 'payment_order_too_many_items',
        'BATCH_PAYMENT_CONTAINS_DUPLICITIES' => 'payment_order_duplicate_items',
        'OPERATION_NOT_SUPPORTED_FOR_MASS_AUTHORIZATION' => 'csas_mass_authorization_forbidden',
    ];

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

    /** Scope souhlasu: čtení účtů a volitelně zakládání plateb (Premium Payments v1). */
    public static function scope(bool $payments): string
    {
        return $payments ? self::SCOPE_ACCOUNTS . ' ' . self::SCOPE_PAYMENTS : self::SCOPE_ACCOUNTS;
    }

    public static function grantsPayments(string $scope): bool
    {
        return in_array(self::SCOPE_PAYMENTS, preg_split('/\s+/', trim($scope)) ?: [], true);
    }

    public function authorizationUrl(#[\SensitiveParameter] array $credentials, string $state, bool $payments = false): string
    {
        $this->assertEnvironment($credentials);
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw $this->invalid();
        return $this->identityBaseUrl() . '/auth?' . http_build_query([
            'response_type' => 'code', 'client_id' => $this->value($credentials, 'client_id'),
            'redirect_uri' => $this->value($credentials, 'redirect_uri'), 'scope' => self::scope($payments),
            // „login" vynutí nové přihlášení i při živé relaci banky v prohlížeči. Bez něj banka
            // použije výchozí pracovní prostor George Business a souhlas vydá za jinou firmu.
            'access_type' => 'offline', 'prompt' => 'login consent', 'state' => $state,
            'code_challenge' => \MyInvoice\Service\OAuth\Pkce::challenge($this->value($credentials, 'code_verifier')),
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

    /**
     * Založí jednu platbu (POST /my/payments). Banka ji jen přijme k podpisu; neprovede
     * ji, dokud ji uživatel nepodepíše. Požadavek se nikdy neopakuje: API nemá
     * idempotenční klíč, takže výpadek, 5xx nebo nečitelná odpověď znamenají nejasný výsledek.
     *
     * @param array<string,mixed> $payment
     * @return array{sign_id:string,hash:string,instruction_status:string}
     */
    public function createPayment(#[\SensitiveParameter] array $credentials, array $payment): array
    {
        $this->assertEnvironment($credentials);
        $data = $this->paymentRequest('POST', $this->paymentsBaseUrl() . '/my/payments', $credentials, 'payment_create', ['json' => $payment], true);
        $status = $data['instructionStatus'] ?? null;
        if ($status === 'RJCT') {
            throw new BankConnectorException(BankConnectorException::PAYMENT_REJECTED, 'Česká spořitelna platbu odmítla.');
        }
        $sign = $this->signInfo($data, true);
        if ($status !== 'ACTC') throw $this->invalid(true);
        return ['sign_id' => $sign['sign_id'], 'hash' => $sign['hash'], 'instruction_status' => $status];
    }

    /**
     * Seskupí založené platby do dávky (POST /my/batchpayments). Platby se v dávce znovu
     * nepopisují, jen se odkáže na jejich signId.
     *
     * @param list<string> $signIds
     * @return array{sign_id:string,hash:string}
     */
    public function createBatch(#[\SensitiveParameter] array $credentials, string $exchangeIdentification, array $signIds): array
    {
        $this->assertEnvironment($credentials);
        if (!preg_match('/^[A-Za-z0-9]{1,35}$/D', $exchangeIdentification) || $signIds === [] || count($signIds) > CsasAboBatchMapper::MAX_ITEMS
            || !array_is_list($signIds)) {
            throw new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'Dávku České spořitelny nelze sestavit.');
        }
        $payments = [];
        foreach ($signIds as $signId) {
            $payments[] = ['signId' => $this->identifier($signId)];
        }
        $data = $this->paymentRequest('POST', $this->paymentsBaseUrl() . '/my/batchpayments', $credentials, 'batch_create',
            ['json' => ['exchangeIdentification' => $exchangeIdentification, 'payments' => $payments]], true);
        if (($data['instructionStatus'] ?? 'ACTC') === 'RJCT') {
            throw new BankConnectorException(BankConnectorException::PAYMENT_REJECTED, 'Česká spořitelna dávku odmítla.');
        }
        return $this->signInfo($data, true);
    }

    /**
     * Adresa federovaného podpisu dávky v bance. Po podpisu se uživatel vrátí na `$callbackUri`.
     *
     * @return array{signing_url:string,valid_to:?string,poll_id:string,poll_interval_ms:int}
     */
    public function federatedSign(#[\SensitiveParameter] array $credentials, string $signId, string $hash, string $callbackUri): array
    {
        $this->assertEnvironment($credentials);
        if (!preg_match('#^https://[^\s/?\#]+/[^\s\#]*$#D', $callbackUri) || strlen($callbackUri) > 2048) throw $this->invalid();
        $data = $this->paymentRequest('GET', $this->paymentsBaseUrl() . '/my/batchpayments/federate/sign/' . rawurlencode($this->identifier($signId))
            . '/hash/' . rawurlencode($this->hash($hash)), $credentials, 'batch_sign', ['headers' => ['Callback-Uri' => $callbackUri]], false);
        $url = $data['signingUrl'] ?? null;
        $poll = $data['poll'] ?? null;
        if (!is_string($url) || !$this->trustedSigningUrl($url) || !is_array($poll)) throw $this->invalid();
        $interval = $poll['interval'] ?? 5000;
        $validTo = $data['validTo'] ?? null;
        return [
            'signing_url' => $url,
            'valid_to' => is_string($validTo) && strlen($validTo) <= 40 ? $validTo : null,
            'poll_id' => $this->identifier($poll['id'] ?? null),
            'poll_interval_ms' => is_int($interval) && $interval >= 1000 && $interval <= 600000 ? $interval : 5000,
        ];
    }

    /** @return array{state:string,poll_interval_ms:int} */
    public function pollSign(#[\SensitiveParameter] array $credentials, string $pollId): array
    {
        $this->assertEnvironment($credentials);
        $data = $this->paymentRequest('GET', $this->paymentsBaseUrl() . '/my/batchpayments/sign/poll/' . rawurlencode($this->identifier($pollId)),
            $credentials, 'batch_sign_poll', [], false);
        $state = $data['state'] ?? null;
        if (!is_string($state) || !in_array($state, self::SIGN_STATES, true)) throw $this->invalid();
        $interval = $data['pollInterval'] ?? 5000;
        return ['state' => $state, 'poll_interval_ms' => is_int($interval) && $interval >= 1000 && $interval <= 600000 ? $interval : 5000];
    }

    /** @return array{instruction_status:string,status_change_info:?string} */
    public function paymentStatus(#[\SensitiveParameter] array $credentials, string $signId): array
    {
        $this->assertEnvironment($credentials);
        $data = $this->paymentRequest('GET', $this->paymentsBaseUrl() . '/my/payments/' . rawurlencode($this->identifier($signId)) . '/status',
            $credentials, 'payment_status', [], false);
        $status = $data['instructionStatus'] ?? null;
        $info = $data['statusChangeInfo'] ?? null;
        if (!is_string($status) || !in_array($status, self::INSTRUCTION_STATES, true)) throw $this->invalid();
        return [
            'instruction_status' => $status,
            'status_change_info' => is_string($info) && preg_match('/^[A-Z0-9_]{1,64}$/D', $info) ? $info : null,
        ];
    }

    /**
     * Platební volání. Bez opakování; u zakládajících požadavků (`$mutation`) je každý
     * výsledek kromě úspěchu nebo jistého odmítnutí (4xx) nejasný.
     */
    private function paymentRequest(string $method, string $url, #[\SensitiveParameter] array $credentials, string $operation,
        #[\SensitiveParameter] array $options, bool $mutation): array
    {
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => 'Bearer ' . $this->value($credentials, 'access_token'),
            'WEB-API-key' => $this->value($credentials, 'api_key'),
            'Accept' => 'application/json',
        ];
        $options += ['allow_redirects' => false, 'verify' => true, 'debug' => false, 'http_errors' => false,
            'connect_timeout' => 5, 'timeout' => 30, 'stream' => true];
        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable) {
            $this->logger->warning('csas_http_failed', ['environment' => $this->environment(), 'operation' => $operation, 'reason' => 'transport']);
            throw new BankConnectorException(BankConnectorException::REMOTE_UNAVAILABLE, 'API České spořitelny není dostupné.', $mutation);
        }
        $status = $response->getStatusCode();
        $body = $this->paymentBody($response);
        $this->logger->info('csas_http_response', ['environment' => $this->environment(), 'operation' => $operation, 'http_status' => $status]);
        if ($status === 200 || $status === 201) {
            $data = $body === null ? null : json_decode($body, true, 32);
            if (!is_array($data) || array_is_list($data)) throw $this->invalid($mutation);
            return $data;
        }
        if ($status >= 500 || $status < 400 || $status === 408) {
            throw new BankConnectorException($status >= 500 ? BankConnectorException::REMOTE_UNAVAILABLE : BankConnectorException::REMOTE_HTTP_ERROR,
                'API České spořitelny požadavek nedokončilo.', $mutation, $status);
        }
        $remote = $this->remoteError($body);
        if ($remote !== null) {
            $this->logger->info('csas_payment_rejected', ['environment' => $this->environment(), 'operation' => $operation, 'error' => $remote]);
        }
        throw new BankConnectorException(match (true) {
            $status === 401, $status === 403 && $remote === null => BankConnectorException::INVALID_TOKEN,
            $status === 429 => BankConnectorException::RATE_LIMITED,
            $remote !== null && isset(self::PAYMENT_ERRORS[$remote]) => self::PAYMENT_ERRORS[$remote],
            default => BankConnectorException::PAYMENT_REJECTED,
        }, 'Česká spořitelna požadavek odmítla.', false, $status);
    }

    private function paymentBody(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        try {
            $stream = $response->getBody();
            $body = '';
            $emptyStreak = 0;
            while (!$stream->eof() && strlen($body) <= self::PAYMENT_MAX_BYTES) {
                $chunk = $stream->read(min(8192, self::PAYMENT_MAX_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    if (++$emptyStreak > self::MAX_EMPTY_READS) return null;
                    usleep(10_000);
                    continue;
                }
                $emptyStreak = 0;
                $body .= $chunk;
            }
            return strlen($body) > self::PAYMENT_MAX_BYTES ? null : $body;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Kód chyby banky (`errors[0].error`), jen pokud má očekávaný tvar; tělo se jinak nečte ani neloguje. */
    private function remoteError(?string $body): ?string
    {
        if ($body === null || $body === '') return null;
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $code = is_array($data) ? ($data['errors'][0]['error'] ?? null) : null;
        return is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $code) ? $code : null;
    }

    /** @return array{sign_id:string,hash:string} */
    private function signInfo(array $data, bool $mutation): array
    {
        $sign = $data['signInfo'] ?? null;
        if (!is_array($sign) || ($sign['state'] ?? null) !== 'OPEN') throw $this->invalid($mutation);
        try {
            return ['sign_id' => $this->identifier($sign['signId'] ?? null), 'hash' => $this->hash($sign['hash'] ?? null)];
        } catch (BankConnectorException) {
            throw $this->invalid($mutation);
        }
    }

    private function identifier(mixed $value): string
    {
        if (is_int($value)) $value = (string) $value;
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value)) throw $this->invalid();
        return $value;
    }

    private function hash(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value)) throw $this->invalid();
        return $value;
    }

    /** Podpis musí proběhnout u banky: https bez přihlašovacích údajů v adrese, na doméně ČS (v sandboxu na portálu Erste). */
    private function trustedSigningUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $this->sandbox
            ? $host === 'webapi.developers.erstegroup.com'
            : $host === 'csas.cz' || str_ends_with($host, '.csas.cz');
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
                || !is_int($data['pageSize'] ?? null) || $data['pageSize'] < ($count === 0 ? 0 : 1) || $data['pageSize'] > 200
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
        if (isset($data['scope']) && (!is_string($data['scope']) || strlen($data['scope']) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $data['scope'])
            || !in_array(self::SCOPE_ACCOUNTS, preg_split('/\s+/', trim($data['scope'])) ?: [], true))) throw $this->invalid();
        $result = ['access_token' => $this->value($data, 'access_token'), 'expires_in' => $data['expires_in']];
        if (isset($data['refresh_token'])) $result['refresh_token'] = $this->value($data, 'refresh_token');
        if (isset($data['scope'])) $result['scope'] = trim((string) preg_replace('/\s+/', ' ', $data['scope']));
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
            // Chunkovaná odpověď vrací mezi kusy prázdné čtení ještě před koncem streamu
            // (stejně jako u Fio). Vadná je až odpověď, která se dlouho nehne.
            $emptyStreak = 0;
            while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    if (++$emptyStreak > self::MAX_EMPTY_READS) throw new \RuntimeException();
                    usleep(10_000);
                    continue;
                }
                $emptyStreak = 0;
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

    private function invalid(bool $ambiguousPaymentOutcome = false): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatná odpověď nebo konfigurace České spořitelny.',
            $ambiguousPaymentOutcome);
    }
}
