<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class KbPlusApiClient
{
    private const API_BASE = 'https://api-gateway.kb.cz/adaa/v2';
    private const BATCH_API_BASE = 'https://api.kb.cz/directapi/batchda/v3';
    private const STATEMENT_API_BASE = 'https://api.kb.cz/directapi/statda/v1';
    private const STATEMENT_FORMAT = 'KM';
    private const MAX_STATEMENT_BYTES = 10 * 1024 * 1024;
    private const MAX_STATEMENT_FILES = 400;
    private const MAX_STATEMENT_POLLS = 30;
    private const MAX_STATEMENT_DURATION_SECONDS = 120;
    /**
     * Ruční načtení běží ve webovém požadavku. IIS počítá max_execution_time
     * i FastCGI activityTimeout/requestTimeout jako reálný čas včetně spánku,
     * takže dvouminutové čekání na výpis STATDA proces zabil dřív, než stihl
     * vrátit chybu, a prohlížeč dostal holou 500 (activityTimeout 70 s,
     * requestTimeout 90 s). Web proto celé stažení výpisu utne po 20 s
     * a vrátí kb_plus_statement_pending; cron (CLI) má plný limit.
     */
    public const INTERACTIVE_STATEMENT_DURATION_SECONDS = 20;
    private const TOKEN_URL = 'https://api-gateway.kb.cz/oauth2/v3/access_token';
    private const AUTHORIZE_URL = 'https://login.kb.cz/autfe/ssologin';
    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 1000;
    private const MAX_TRANSACTION_RESPONSE_BYTES = 32 * 1024 * 1024;
    private const MAX_TRANSACTIONS = 50_000;
    private const MAX_TRANSACTION_DURATION_SECONDS = 120.0;

    private readonly int $maxStatementDurationSeconds;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?int $maxStatementDurationSeconds = null,
    ) {
        $this->maxStatementDurationSeconds = max(1, min(
            self::MAX_STATEMENT_DURATION_SECONDS,
            $maxStatementDurationSeconds ?? (PHP_SAPI === 'cli'
                ? self::MAX_STATEMENT_DURATION_SECONDS
                : self::INTERACTIVE_STATEMENT_DURATION_SECONDS),
        ));
    }

    /** BATCHDA autorizuje access token; dávky smí jen souhlas se scope bpisp. */
    public static function grantsBatchPayments(string $scope): bool
    {
        return in_array('bpisp', preg_split('/\s+/', trim($scope)) ?: [], true);
    }

    /** Výpisy STATDA (varianta Basic) smí jen souhlas se scope statda. */
    public static function grantsStatements(string $scope): bool
    {
        return in_array('statda', preg_split('/\s+/', trim($scope)) ?: [], true);
    }

    /** @param array<string,mixed> $credentials */
    public function authorizationUrl(#[\SensitiveParameter] array $credentials, string $state): string
    {
        $clientId = $this->credential($credentials, 'client_id', 200);
        $redirectUri = $this->redirectUri($credentials);
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $state)) {
            throw $this->invalidToken('OAuth state nemá platný formát.');
        }
        $scope = trim((string) ($credentials['scope'] ?? 'adaa'));
        $scopes = preg_split('/\s+/', $scope) ?: [];
        if (!in_array('adaa', $scopes, true) && !in_array('statda', $scopes, true)) {
            throw $this->invalidToken('OAuth scope musí obsahovat ADAA nebo STATDA.');
        }
        foreach ($scopes as $item) {
            if (!in_array($item, ['adaa', 'bpisp', 'statda'], true)) {
                throw $this->invalidToken('OAuth scope obsahuje nepodporovanou hodnotu.');
            }
        }

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', array_values(array_unique($scopes))),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{access_token:string,token_type:string,expires_in:int,scope:string,refresh_token:string}
     */
    public function exchangeAuthorizationCode(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $code,
    ): array {
        if (!preg_match('/^[A-Za-z0-9_-]{10,512}$/D', $code)) {
            throw $this->invalidToken('Autorizační kód nemá platný formát.');
        }
        $tokens = $this->tokenRequest($credentials, [
            'redirect_uri' => $this->redirectUri($credentials),
            'code' => $code,
            'client_id' => $this->credential($credentials, 'client_id', 200),
            'client_secret' => $this->credential($credentials, 'client_secret', 2048),
            'grant_type' => 'authorization_code',
        ]);
        if (!isset($tokens['refresh_token'])) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'KB+ nevrátila refresh token.',
            );
        }
        return $tokens;
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{access_token:string,token_type:string,expires_in:int,scope:string,refresh_token?:string}
     */
    public function refreshAccessToken(#[\SensitiveParameter] array $credentials): array
    {
        return $this->tokenRequest($credentials, [
            'redirect_uri' => $this->redirectUri($credentials),
            'client_id' => $this->credential($credentials, 'client_id', 200),
            'client_secret' => $this->credential($credentials, 'client_secret', 2048),
            'refresh_token' => $this->credential($credentials, 'refresh_token', 16384),
            'grant_type' => 'refresh_token',
        ]);
    }

    /** @param array<string,mixed> $credentials @return list<array<string,mixed>> */
    public function accounts(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $accessToken,
    ): array {
        $response = $this->apiRequest('GET', self::API_BASE . '/accounts', $credentials, $accessToken);
        $accounts = $this->json($response, false);
        if (!array_is_list($accounts) || count($accounts) > 1000) {
            throw $this->invalidResponse($response, 'KB+ vrátila neplatný seznam účtů.');
        }
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                throw $this->invalidResponse($response, 'KB+ vrátila neplatný účet.');
            }
            $this->accountId($account['accountId'] ?? null);
            if (!is_string($account['currency'] ?? null) || !preg_match('/^[A-Z]{3}$/D', $account['currency'])) {
                throw $this->invalidResponse($response, 'KB+ vrátila neplatnou měnu účtu.');
            }
            if (!is_string($account['iban'] ?? null) || !$this->isIban($account['iban'])) {
                throw $this->invalidResponse($response, 'KB+ vrátila neplatný IBAN účtu.');
            }
        }
        return $accounts;
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{transactions:list<array<string,mixed>>,pages:int}
     */
    public function transactions(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $accessToken,
        string $accountId,
        string $from,
        string $to,
    ): array {
        $accountId = $this->accountId($accountId);
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        if ($fromDate > $toDate) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE_RANGE,
                'Počáteční datum musí předcházet koncovému datu.',
            );
        }

        $fromDateTime = $from . 'T00:00:00.000Z';
        $toDateTime = min(
            $to . 'T23:59:59.999Z',
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        );
        if ($fromDateTime > $toDateTime) {
            throw new BankConnectorException(BankConnectorException::INVALID_DATE_RANGE, 'Počáteční datum nesmí být v budoucnosti.');
        }
        $transactions = [];
        $totalBytes = 0;
        $startedAt = microtime(true);
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if (microtime(true) - $startedAt > self::MAX_TRANSACTION_DURATION_SECONDS) {
                throw $this->paginationLimit('Načítání transakcí KB+ překročilo časový limit.');
            }
            $url = self::API_BASE . '/accounts/' . rawurlencode($accountId) . '/transactions?'
                . http_build_query([
                    'fromDateTime' => $fromDateTime,
                    'toDateTime' => $toDateTime,
                    'size' => self::PAGE_SIZE,
                    'page' => $page,
                ], '', '&', PHP_QUERY_RFC3986);
            $response = $this->apiRequest('GET', $url, $credentials, $accessToken);
            if (microtime(true) - $startedAt > self::MAX_TRANSACTION_DURATION_SECONDS) {
                throw $this->paginationLimit('Načítání transakcí KB+ překročilo časový limit.');
            }
            $body = $this->json($response, true, false, $totalBytes);
            $content = $body['content'] ?? null;
            $pageNumber = $body['pageNumber'] ?? null;
            $totalPages = $body['totalPages'] ?? null;
            $last = $body['last'] ?? null;
            $pagination = ['requested_page' => $page, 'content_type' => get_debug_type($content), 'content_count' => is_array($content) ? count($content) : null];
            foreach (['pageNumber', 'totalPages', 'pageSize', 'numberOfElements', 'first', 'last', 'empty'] as $field) {
                $value = $body[$field] ?? null;
                $pagination[$field] = is_int($value) || is_bool($value) ? $value : get_debug_type($value);
            }
            $this->logger->info('kb_plus_transaction_page', $pagination);
            if (
                !is_array($content)
                || !array_is_list($content)
                || count($content) > self::PAGE_SIZE
                || !is_int($pageNumber)
                || $pageNumber !== $page
                || !is_int($totalPages)
                || $totalPages < 0
                || $totalPages > self::MAX_PAGES
                || !is_int($body['pageSize'] ?? null)
                || $body['pageSize'] !== self::PAGE_SIZE
                || !is_int($body['numberOfElements'] ?? null)
                || $body['numberOfElements'] !== count($content)
                || !is_bool($body['first'] ?? null)
                || $body['first'] !== ($page === 0)
                || !is_bool($last)
                || !is_bool($body['empty'] ?? null)
                || $body['empty'] !== ($content === [])
            ) {
                throw $this->invalidResponse($response, 'KB+ vrátila neplatnou stránku transakcí.');
            }
            if ($totalPages > (int) ceil(self::MAX_TRANSACTIONS / self::PAGE_SIZE)) {
                throw $this->paginationLimit('Počet transakcí KB+ překročil bezpečný limit.');
            }
            if (count($transactions) + count($content) > self::MAX_TRANSACTIONS) {
                throw $this->paginationLimit('Počet transakcí KB+ překročil bezpečný limit.');
            }
            foreach ($content as $transaction) {
                if (!is_array($transaction) || !$this->isTransaction($transaction)) {
                    $shape = [];
                    foreach (['lastUpdated', 'accountType', 'transactionType', 'amount'] as $field) {
                        $shape[$field] = get_debug_type(is_array($transaction) ? ($transaction[$field] ?? null) : null);
                    }
                    $this->logger->warning('kb_plus_transaction_invalid', $shape);
                    throw $this->invalidResponse($response, 'KB+ vrátila neplatný pohyb.');
                }
                $transactions[] = $transaction;
            }
            if ($last) {
                return ['transactions' => $transactions, 'pages' => $page + 1];
            }
            if ($totalPages <= $page + 1) {
                throw $this->invalidResponse($response, 'KB+ stránkování transakcí je nekonzistentní.');
            }
        }

        throw new BankConnectorException(
            BankConnectorException::RESPONSE_TOO_LARGE,
            'KB+ vrátila příliš mnoho stránek transakcí.',
        );
    }

    /**
     * Účty dostupné pro výpisy STATDA. Seznam nenese měnu, ta se bere z účtu v MyÚčtu.
     *
     * @return list<array{accountId:string,iban:string}>
     */
    public function statementAccounts(#[\SensitiveParameter] string $accessToken): array
    {
        $response = $this->statementRequest('GET', self::STATEMENT_API_BASE . '/accounts', $accessToken);
        $accounts = $this->json($response, true)['accounts'] ?? null;
        if (!is_array($accounts) || !array_is_list($accounts) || count($accounts) > 1000) {
            throw $this->invalidResponse($response, 'KB+ vrátila neplatný seznam účtů pro výpisy.');
        }
        $result = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || !is_string($account['iban'] ?? null) || !$this->isIban($account['iban'])) {
                throw $this->invalidResponse($response, 'KB+ vrátila neplatný IBAN účtu pro výpisy.');
            }
            $result[] = ['accountId' => $this->accountId($account['accountId'] ?? null), 'iban' => $account['iban']];
        }
        return $result;
    }

    /**
     * Výpisy STATDA ve formátu KM (GPC). Banka výpis nejdřív vygeneruje a teprve
     * potom jde stáhnout; dokud není hotový, vrací PENDING s doporučeným odstupem.
     * Prázdný seznam znamená, že v období nebyl žádný pohyb.
     *
     * @return list<string>
     */
    public function statements(
        #[\SensitiveParameter] string $accessToken,
        string $accountId,
        string $from,
        string $to,
    ): array {
        $accountId = $this->accountId($accountId);
        if ($this->date($from) > $this->date($to)) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE_RANGE,
                'Počáteční datum musí předcházet koncovému datu.',
            );
        }
        $url = self::STATEMENT_API_BASE . '/accounts/' . rawurlencode($accountId) . '/statements';
        $startedAt = $this->clock->now();
        $response = $this->statementRequest('POST', $url . '?' . http_build_query([
            'fromDate' => $from,
            'toDate' => $to,
            'format' => self::STATEMENT_FORMAT,
            'preferredLanguage' => 'cs',
        ], '', '&', PHP_QUERY_RFC3986), $accessToken, $this->statementTimeout($startedAt));
        if ($response->getStatusCode() === 204) {
            return [];
        }
        $status = $this->statementStatus($response);
        $statementId = $status['statementId'];
        for ($poll = 0; ; $poll++) {
            if ($status['status'] === 'PENDING') {
                $this->waitForStatement($poll, $status['pollingInterval'], $startedAt);
            }
            $response = $this->statementRequest('GET', $url . '/' . rawurlencode($statementId), $accessToken, $this->statementTimeout($startedAt));
            if ($response->getStatusCode() === 204) {
                return [];
            }
            if (!str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
                return $this->statementFiles($response);
            }
            $status = $this->statementStatus($response);
            if ($status['status'] !== 'PENDING' || $status['statementId'] !== $statementId) {
                throw $this->invalidResponse($response, 'KB+ vrátila neplatný stav výpisu.');
            }
        }
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array{exchange_identification:mixed,processing_mode:mixed,instruction_name?:mixed,payments:mixed} $batch
     * @return array{accepted:true,reference:string,batch_digest:string,status:string}
     */
    public function submitPaymentBatch(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $accessToken,
        #[\SensitiveParameter] array $batch,
    ): array {
        $exchangeId = $this->batchText($batch['exchange_identification'] ?? null, 1, 14);
        $processingMode = $batch['processing_mode'] ?? null;
        if (!is_string($processingMode) || !in_array($processingMode, ['ONLINE', 'CONTINUOUS', 'BATCH'], true)) {
            throw $this->invalidPaymentOrder('Režim zpracování KB+ dávky není platný.');
        }
        $instructionName = $batch['instruction_name'] ?? null;
        if ($instructionName !== null) {
            $instructionName = $this->batchText($instructionName, 1, 35);
        }
        $payments = $batch['payments'] ?? null;
        if (!is_array($payments) || !array_is_list($payments) || $payments === [] || count($payments) > 100) {
            throw $this->invalidPaymentOrder('KB+ dávka musí obsahovat 1 až 100 plateb.');
        }
        foreach ($payments as $payment) {
            if (!is_array($payment) || !$this->isBatchPayment($payment)) {
                throw $this->invalidPaymentOrder('KB+ dávka obsahuje neplatnou platbu.');
            }
        }
        try {
            $payload = BankPaymentJson::encode(
                ['batchPaymentDataCollectionRequest' => $payments],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                64,
            );
        } catch (\JsonException|\LogicException) {
            throw $this->invalidPaymentOrder('KB+ dávku nelze bezpečně serializovat.');
        }
        if (strlen($payload) > self::MAX_RESPONSE_BYTES) {
            throw $this->invalidPaymentOrder('KB+ dávka je příliš velká.');
        }
        if (!self::grantsBatchPayments((string) ($credentials['scope'] ?? ''))) {
            throw $this->invalidToken('Souhlas KB+ nezahrnuje oprávnění bpisp pro dávky.');
        }
        $apiKey = $this->batchApiKey($credentials);

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken($accessToken),
            'Content-Type' => 'application/json',
            'x-correlation-id' => $this->correlationId(),
            'x-exchange-identification' => $exchangeId,
            'x-batch-processing-mode' => $processingMode,
            'User-Agent' => 'MyUcto-KBPlus-Connector/1.0',
        ];
        if ($apiKey !== null) {
            $headers['apiKey'] = $apiKey;
            $headers['x-api-key'] = $apiKey;
        }
        if ($instructionName !== null) {
            $headers['x-instruction-name'] = $instructionName;
        }
        $response = $this->request('POST', self::BATCH_API_BASE . '/batchPayments', [
            'headers' => $headers,
            'body' => $payload,
        ], true);
        $body = $this->json($response, true, true);
        $reference = $body['transactionIdentification'] ?? null;
        $digest = $body['batchDigest'] ?? null;
        $status = $body['instructionStatus'] ?? null;
        $creditCount = $body['transactionCreditCount'] ?? null;
        $debitCount = $body['transactionDebitCount'] ?? null;
        $rejectedCredit = $body['rejectedTransactionCreditCount'] ?? null;
        $rejectedDebit = $body['rejectedTransactionDebitCount'] ?? null;
        if (
            !is_string($reference)
            || !$this->isText($reference, 1, 10)
            || !is_string($digest)
            || !$this->isText($digest, 1, 50)
            || !in_array($status, ['ACTC', 'ACWC'], true)
            || !is_int($creditCount)
            || !is_int($debitCount)
            || !is_int($rejectedCredit)
            || !is_int($rejectedDebit)
            || min($creditCount, $debitCount, $rejectedCredit, $rejectedDebit) < 0
            || max($creditCount, $debitCount, $rejectedCredit, $rejectedDebit) > 100
            || $rejectedCredit > $creditCount
            || $rejectedDebit > $debitCount
            || $creditCount + $debitCount !== count($payments)
            || !is_array($body['signInfo'] ?? null)
            || !in_array($body['signInfo']['state'] ?? null, ['OPEN', 'REJECT', 'CLOSE', 'NONE'], true)
            || !$this->isNonNegativeNumber($body['totalCreditAmount'] ?? null)
            || !$this->isNonNegativeNumber($body['totalDebitAmount'] ?? null)
            || ($body['batchProcessingMode'] ?? null) !== $processingMode
            || ($body['exchangeIdentification'] ?? null) !== $exchangeId
        ) {
            throw $this->invalidResponse($response, 'KB+ vrátila neplatnou odpověď k dávce.', true);
        }
        $rejected = $rejectedCredit + $rejectedDebit;
        $accepted = $creditCount + $debitCount - $rejected;
        if ($status === 'ACWC' || $rejected > 0) {
            throw new BankConnectorException(
                BankConnectorException::PAYMENT_REJECTED,
                'KB+ přijala dávku pouze částečně.',
                true,
                $response->getStatusCode(),
                $accepted,
                $rejected,
            );
        }

        return [
            'accepted' => true,
            'reference' => $reference,
            'batch_digest' => $digest,
            'status' => 'accepted_awaiting_authorization',
        ];
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,string> $form
     * @return array{access_token:string,token_type:string,expires_in:int,scope:string,refresh_token?:string}
     */
    private function tokenRequest(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] array $form,
    ): array {
        $response = $this->request('POST', self::TOKEN_URL, [
            'headers' => [
                'Accept' => 'application/json',
                'apiKey' => $this->credential($credentials, 'oauth_api_key', 16384),
                'x-correlation-id' => $this->correlationId(),
                'User-Agent' => 'MyUcto-KBPlus-Connector/1.0',
            ],
            'form_params' => $form,
        ], false);
        $body = $this->json($response, true);
        $returnedScopes = is_string($body['scope'] ?? null)
            ? (preg_split('/\s+/', trim($body['scope'])) ?: [])
            : [];
        $expectedScopes = preg_split('/\s+/', trim($this->credential($credentials, 'scope', 100))) ?: [];
        if (
            !is_string($body['access_token'] ?? null)
            || strlen($body['access_token']) < 20
            || strlen($body['access_token']) > 16384
            || ($body['token_type'] ?? null) !== 'Bearer'
            || !is_int($body['expires_in'] ?? null)
            || $body['expires_in'] < 1
            || $body['expires_in'] > 3600
            || !is_string($body['scope'] ?? null)
            || array_diff($expectedScopes, $returnedScopes) !== []
            || array_diff($returnedScopes, ['adaa', 'bpisp', 'statda']) !== []
            || (isset($body['refresh_token']) && (
                !is_string($body['refresh_token'])
                || strlen($body['refresh_token']) < 20
                || strlen($body['refresh_token']) > 16384
            ))
        ) {
            throw $this->invalidResponse($response, 'KB+ vrátila neplatnou OAuth odpověď.');
        }

        return array_filter([
            'access_token' => $body['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $body['expires_in'],
            'scope' => trim($body['scope']),
            'refresh_token' => $body['refresh_token'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $credentials */
    private function apiRequest(
        string $method,
        string $url,
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $accessToken,
    ): ResponseInterface {
        $accessToken = $this->accessToken($accessToken);
        return $this->request($method, $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
                'apiKey' => $this->credential($credentials, 'adaa_api_key', 16384),
                'x-correlation-id' => $this->correlationId(),
                'User-Agent' => 'MyUcto-KBPlus-Connector/1.0',
            ],
        ], false, $method === 'GET');
    }

    /** @param array<string,mixed> $options */
    private function request(
        string $method,
        string $url,
        #[\SensitiveParameter] array $options,
        bool $payment,
        bool $retrySafe = false,
    ): ResponseInterface {
        $options += [
            'allow_redirects' => false,
            'connect_timeout' => 5.0,
            'timeout' => 20.0,
            'verify' => true,
            'http_errors' => false,
            'stream' => true,
            'debug' => false,
        ];
        for ($attempt = 0; $attempt < ($retrySafe ? 2 : 1); $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
            } catch (GuzzleException) {
                if ($retrySafe && $attempt === 0) {
                    continue;
                }
                throw new BankConnectorException(
                    BankConnectorException::REMOTE_UNAVAILABLE,
                    'KB+ API je dočasně nedostupné.',
                    $payment,
                );
            }
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $response;
            }
            if ($retrySafe && $attempt === 0 && in_array($status, [502, 503, 504], true)) {
                continue;
            }
            throw new BankConnectorException(
                match ($status) {
                    401, 403 => BankConnectorException::INVALID_TOKEN,
                    429 => BankConnectorException::RATE_LIMITED,
                    default => $status >= 500
                        ? BankConnectorException::REMOTE_UNAVAILABLE
                        : BankConnectorException::REMOTE_HTTP_ERROR,
                },
                'KB+ API požadavek odmítlo.',
                $payment && $status >= 500,
                $status,
            );
        }
        throw new BankConnectorException(
            BankConnectorException::REMOTE_UNAVAILABLE,
            'KB+ API je dočasně nedostupné.',
            $payment,
        );
    }

    /** @return array<mixed> */
    private function json(
        ResponseInterface $response,
        bool $object,
        bool $ambiguousPaymentOutcome = false,
        ?int &$totalBytes = null,
    ): array
    {
        try {
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    throw new \RuntimeException();
                }
                $body .= $chunk;
            }
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException();
            }
            if ($totalBytes !== null) {
                $totalBytes += strlen($body);
                if ($totalBytes > self::MAX_TRANSACTION_RESPONSE_BYTES) {
                    throw new BankConnectorException(
                        BankConnectorException::RESPONSE_TOO_LARGE,
                        'Souhrnná velikost odpovědí KB+ překročila povolený limit.',
                        $ambiguousPaymentOutcome,
                        $response->getStatusCode(),
                    );
                }
            }
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (BankConnectorException $e) {
            throw $e;
        } catch (\Throwable) {
            throw $this->invalidResponse($response, 'KB+ vrátila nečitelnou odpověď.', $ambiguousPaymentOutcome);
        }
        if (!is_array($decoded) || ($object && array_is_list($decoded))) {
            throw $this->invalidResponse($response, 'KB+ vrátila neplatnou JSON odpověď.', $ambiguousPaymentOutcome);
        }
        return $decoded;
    }

    /** @param array<string,mixed> $credentials */
    private function credential(#[\SensitiveParameter] array $credentials, string $key, int $maxLength): string
    {
        $value = $credentials[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw $this->invalidToken('Konfigurace KB+ OAuth není úplná.');
        }
        return $value;
    }

    /**
     * BATCHDA v3 autorizuje jen access token (OpenAPI definice žádnou hlavičku
     * s klíčem nezná, technický manuál vede x-api-key jako nepovinný
     * identifikátor). Klíč se proto posílá jen tehdy, když ho správce pro
     * BATCHDA výslovně zadal; klíč ADAA do jiné služby vědomě neodchází.
     *
     * @param array<string,mixed> $credentials
     */
    private function batchApiKey(#[\SensitiveParameter] array $credentials): ?string
    {
        return trim((string) ($credentials['batchda_api_key'] ?? '')) !== ''
            ? $this->credential($credentials, 'batchda_api_key', 16384)
            : null;
    }

    /** @param array<string,mixed> $credentials */
    private function redirectUri(#[\SensitiveParameter] array $credentials): string
    {
        $uri = $this->credential($credentials, 'redirect_uri', 2048);
        $parts = parse_url($uri);
        if (($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw $this->invalidToken('OAuth redirect URI musí být bezpečná HTTPS adresa.');
        }
        return $uri;
    }

    private function accountId(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 400 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'KB+ accountId nemá platný formát.',
            );
        }
        return $value;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE,
                'Datum nemá platný formát.',
            );
        }
        return $date;
    }

    private function correlationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function invalidToken(string $message): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_TOKEN, $message);
    }

    private function invalidPaymentOrder(string $message): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, $message);
    }

    private function invalidResponse(
        ResponseInterface $response,
        string $message,
        bool $ambiguousPaymentOutcome = false,
    ): BankConnectorException
    {
        $this->logger->warning('kb_plus_response_invalid', ['reason' => $message, 'http_status' => $response->getStatusCode()]);
        return new BankConnectorException(
            BankConnectorException::INVALID_RESPONSE,
            $message,
            $ambiguousPaymentOutcome,
            $response->getStatusCode(),
        );
    }

    private function paginationLimit(string $message): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::RESPONSE_TOO_LARGE, $message);
    }

    private function accessToken(#[\SensitiveParameter] string $accessToken): string
    {
        if (!preg_match('/^[A-Za-z0-9._~+\/=:-]{20,16384}$/D', $accessToken)) {
            throw $this->invalidToken('Access token nemá platný formát.');
        }
        return $accessToken;
    }

    /** STATDA autorizuje jen access token se scope statda; API klíč její OpenAPI definice nezná. */
    private function statementRequest(
        string $method,
        string $url,
        #[\SensitiveParameter] string $accessToken,
        ?float $timeout = null,
    ): ResponseInterface {
        return $this->request($method, $url, [
            'headers' => [
                'Accept' => 'application/json, application/octet-stream, application/zip',
                'Authorization' => 'Bearer ' . $this->accessToken($accessToken),
                'x-correlation-id' => $this->correlationId(),
                'User-Agent' => 'MyUcto-KBPlus-Connector/1.0',
            ],
        ] + ($timeout !== null ? ['timeout' => $timeout] : []), false, $method === 'GET');
    }

    /**
     * Každý požadavek výpisu smí trvat jen do termínu celého stažení, jinak by
     * se k čekání na PENDING připočetly plné timeouty požadavků a webový
     * požadavek by přesto přetekl limit IIS.
     */
    private function statementTimeout(\DateTimeImmutable $startedAt): float
    {
        $remaining = $this->maxStatementDurationSeconds
            - ($this->clock->now()->getTimestamp() - $startedAt->getTimestamp());
        if ($remaining < 1) {
            throw new BankConnectorException('kb_plus_statement_pending', 'KB+ výpis v časovém limitu nepřipravila.');
        }
        return (float) min(20, $remaining);
    }

    /** @return array{status:string,statementId:string,pollingInterval:?int} */
    private function statementStatus(ResponseInterface $response): array
    {
        $body = $this->json($response, true);
        $statementId = $body['statementId'] ?? null;
        $interval = $body['pollingInterval'] ?? null;
        if (!in_array($body['status'] ?? null, ['READY', 'PENDING'], true)
            || !is_string($statementId)
            || $statementId === ''
            || strlen($statementId) > 400
            || preg_match('/[\x00-\x1F\x7F]/', $statementId)
            || ($interval !== null && (!is_int($interval) || $interval < 0))
        ) {
            throw $this->invalidResponse($response, 'KB+ vrátila neplatný stav výpisu.');
        }
        return ['status' => $body['status'], 'statementId' => $statementId, 'pollingInterval' => $interval];
    }

    private function waitForStatement(int $poll, ?int $interval, \DateTimeImmutable $startedAt): void
    {
        $seconds = max(1, min(10, $interval ?? 2));
        $elapsed = $this->clock->now()->getTimestamp() - $startedAt->getTimestamp();
        if ($poll >= self::MAX_STATEMENT_POLLS || $elapsed + $seconds > $this->maxStatementDurationSeconds) {
            throw new BankConnectorException('kb_plus_statement_pending', 'KB+ výpis v časovém limitu nepřipravila.');
        }
        if ($this->clock instanceof \Symfony\Component\Clock\ClockInterface) {
            $this->clock->sleep($seconds);
        } else {
            sleep($seconds);
        }
    }

    /**
     * KB posílá jeden soubor KM, nebo ZIP se souborem za každý obchodní den.
     *
     * @return list<string>
     */
    private function statementFiles(ResponseInterface $response): array
    {
        $body = $this->rawBody($response);
        if (!str_starts_with($body, "PK\x03\x04")) {
            return [$body];
        }
        $tmp = class_exists(\ZipArchive::class) ? tempnam(sys_get_temp_dir(), 'kbstatda-') : false;
        if ($tmp === false) {
            throw $this->invalidResponse($response, 'ZIP s výpisy KB+ nelze na serveru rozbalit.');
        }
        try {
            $zip = new \ZipArchive();
            if (file_put_contents($tmp, $body) === false || $zip->open($tmp) !== true) {
                throw $this->invalidResponse($response, 'KB+ vrátila nečitelný ZIP s výpisy.');
            }
            try {
                if ($zip->numFiles > self::MAX_STATEMENT_FILES) {
                    throw $this->statementTooLarge();
                }
                $files = [];
                $total = 0;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    if ($stat === false) {
                        throw $this->invalidResponse($response, 'KB+ vrátila nečitelný ZIP s výpisy.');
                    }
                    if (str_ends_with($stat['name'], '/')) {
                        continue;
                    }
                    $total += (int) $stat['size'];
                    if ($total > self::MAX_STATEMENT_BYTES) {
                        throw $this->statementTooLarge();
                    }
                    $content = $zip->getFromIndex($index, self::MAX_STATEMENT_BYTES + 1);
                    if (!is_string($content) || strlen($content) !== (int) $stat['size']) {
                        throw $this->invalidResponse($response, 'KB+ vrátila nečitelný ZIP s výpisy.');
                    }
                    $files[$stat['name']] = $content;
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
        ksort($files, SORT_STRING);
        return array_values($files);
    }

    private function rawBody(ResponseInterface $response): string
    {
        try {
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_STATEMENT_BYTES) {
                $chunk = $stream->read(min(65536, self::MAX_STATEMENT_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    throw new \RuntimeException();
                }
                $body .= $chunk;
            }
        } catch (\Throwable) {
            throw $this->invalidResponse($response, 'KB+ vrátila nečitelný výpis.');
        }
        if (strlen($body) > self::MAX_STATEMENT_BYTES) {
            throw $this->statementTooLarge();
        }
        if ($body === '') {
            throw $this->invalidResponse($response, 'KB+ vrátila prázdný výpis.');
        }
        return $body;
    }

    private function statementTooLarge(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::RESPONSE_TOO_LARGE, 'Výpisy KB+ překračují povolený objem.');
    }

    /** @param array<string,mixed> $transaction */
    private function isTransaction(array $transaction): bool
    {
        $amount = $transaction['amount'] ?? null;
        return is_string($transaction['lastUpdated'] ?? null)
            && $this->isDateTime($transaction['lastUpdated'])
            && in_array($transaction['accountType'] ?? null, ['KB', 'AG'], true)
            && is_string($transaction['iban'] ?? null)
            && $this->isIban($transaction['iban'])
            && in_array($transaction['creditDebitIndicator'] ?? null, ['CREDIT', 'DEBIT'], true)
            && in_array($transaction['transactionType'] ?? null, ['INTEREST', 'FEE', 'DOMESTIC', 'FOREIGN', 'SEPA', 'CASH', 'CARD', 'OTHER'], true)
            && is_array($amount)
            && (is_int($amount['value'] ?? null) || is_float($amount['value'] ?? null))
            && is_finite((float) $amount['value'])
            && is_string($amount['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/D', $amount['currency']) === 1;
    }

    /** @param array<string,mixed> $payment */
    private function isBatchPayment(array $payment): bool
    {
        $allowed = [
            'paymentIdentification', 'paymentTypeInformation', 'amount', 'requestedExecutionDate',
            'exchangeRateInformation', 'chargeBearer', 'ultimateDebtor', 'debtor', 'debtorAccount',
            'creditorAgent', 'creditor', 'creditorAccount', 'ultimateCreditor', 'purpose',
            'remittanceInformation',
        ];
        if (array_diff(array_keys($payment), $allowed) !== []) {
            return false;
        }
        $identification = $payment['paymentIdentification'] ?? null;
        $amount = $payment['amount']['instructedAmount'] ?? null;
        return is_array($identification)
            && is_string($identification['instructionIdentification'] ?? null)
            && $this->isText($identification['instructionIdentification'], 1, 35)
            && $this->isPaymentAccount($payment['debtorAccount'] ?? null)
            && $this->isPaymentAccount($payment['creditorAccount'] ?? null)
            && is_array($amount)
            && ($amount['value'] ?? null) instanceof BankPaymentAmount
            && $amount['value']->minor >= 1
            && $amount['value']->minor <= 100000000000000000
            && is_string($amount['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/D', $amount['currency']) === 1
            && is_string($payment['requestedExecutionDate'] ?? null)
            && $this->isDate($payment['requestedExecutionDate']);
    }

    private function isPaymentAccount(mixed $account): bool
    {
        return is_array($account)
            && array_diff(array_keys($account), ['identification', 'currency']) === []
            && is_array($account['identification'] ?? null)
            && array_keys($account['identification']) === ['iban']
            && is_string($account['identification']['iban'] ?? null)
            && $this->isIban($account['identification']['iban'])
            && (!isset($account['currency']) || (
                is_string($account['currency'])
                && preg_match('/^[A-Z]{3}$/D', $account['currency']) === 1
            ));
    }

    private function batchText(mixed $value, int $minLength, int $maxLength): string
    {
        if (!is_string($value) || !$this->isText($value, $minLength, $maxLength)) {
            throw $this->invalidPaymentOrder('Identifikace KB+ dávky nemá platný formát.');
        }
        return $value;
    }

    private function isText(string $value, int $minLength, int $maxLength): bool
    {
        $length = mb_strlen($value, 'UTF-8');
        return $length >= $minLength
            && $length <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);
            return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1;
        } catch (\Exception) {
            return false;
        }
    }

    private function isNonNegativeNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }

    private function isIban(string $value): bool
    {
        $iban = strtoupper($value);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }
}
