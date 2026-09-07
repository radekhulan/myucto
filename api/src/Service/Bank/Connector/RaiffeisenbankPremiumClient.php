<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

final class RaiffeisenbankPremiumClient
{
    public const ERROR_INVALID_CREDENTIALS = 'rb_invalid_credentials';
    public const ERROR_INVALID_ACCOUNT = 'rb_invalid_account';
    public const ERROR_INVALID_CURRENCY = 'rb_invalid_currency';
    public const ERROR_ACCOUNT_MISMATCH = 'rb_account_mismatch';
    public const ERROR_CURRENCY_MISMATCH = 'rb_currency_mismatch';
    public const ERROR_PAGINATION_LIMIT = 'rb_pagination_limit';

    private const BASE_URL = 'https://api.rb.cz/rbcz/premium/api/';
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;
    private const MAX_TRANSACTION_RESPONSE_BYTES = 32 * 1024 * 1024;
    private const MAX_ABO_BYTES = 10 * 1024 * 1024;
    private const MAX_PAGES = 100;
    private const MAX_TRANSACTIONS = 50_000;
    private const MAX_TRANSACTION_DURATION_SECONDS = 120.0;
    private const MAX_CERTIFICATE_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly ClientInterface $http)
    {
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    public function account(
        #[\SensitiveParameter] array $credentials,
        string $accountNumber,
    ): array {
        [$clientId, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAccountNumber($accountNumber);
        $response = $this->request(
            'GET',
            self::BASE_URL . 'accounts/' . $accountNumber . '/balance',
            $clientId,
            $curlOptions,
        );
        if ($response->getStatusCode() !== 200) {
            throw $this->unexpectedSuccessStatus($response->getStatusCode(), false);
        }

        $data = $this->decodeJson($response, false);
        $this->validateAccountResponse($data, $accountNumber, $response->getStatusCode());
        return $data;
    }

    /**
     * @param array<string,mixed> $credentials
     * @return list<array<string,mixed>>
     */
    public function transactions(
        #[\SensitiveParameter] array $credentials,
        string $accountNumber,
        string $currency,
        string $from,
        string $to,
    ): array {
        [$clientId, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAccountNumber($accountNumber);
        $this->validateCurrency($currency);
        $this->validateDateRange($from, $to);

        $all = [];
        $references = [];
        $responseBytes = 0;
        $startedAt = microtime(true);
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            if (microtime(true) - $startedAt > self::MAX_TRANSACTION_DURATION_SECONDS) {
                throw new BankConnectorException(
                    self::ERROR_PAGINATION_LIMIT,
                    'Načítání bankovních transakcí překročilo časový limit.',
                );
            }
            $response = $this->request(
                'GET',
                self::BASE_URL . 'accounts/' . $accountNumber . '/' . $currency . '/transactions',
                $clientId,
                $curlOptions,
                ['from' => $from, 'to' => $to, 'page' => $page],
            );
            if ($response->getStatusCode() === 204) {
                return $all;
            }
            if ($response->getStatusCode() !== 200) {
                throw $this->unexpectedSuccessStatus($response->getStatusCode(), false);
            }

            $data = $this->decodeJson($response, false, $responseBytes);
            [$pageTransactions, $lastPage] = $this->validateTransactionPage(
                $data,
                $currency,
                $response->getStatusCode(),
            );
            foreach ($pageTransactions as $transaction) {
                $reference = $transaction['entryReference'];
                if (isset($references[$reference])) {
                    throw new BankConnectorException(
                        BankConnectorException::INVALID_RESPONSE,
                        'Banka vrátila duplicitní transakci ve stránkované odpovědi.',
                        false,
                        $response->getStatusCode(),
                    );
                }
                $references[$reference] = true;
                $all[] = $transaction;
                if (count($all) > self::MAX_TRANSACTIONS) {
                    throw new BankConnectorException(
                        self::ERROR_PAGINATION_LIMIT,
                        'Počet bankovních transakcí překročil bezpečný limit.',
                        false,
                        $response->getStatusCode(),
                    );
                }
            }
            if ($lastPage) {
                return $all;
            }
            if ($pageTransactions === []) {
                throw new BankConnectorException(
                    BankConnectorException::INVALID_RESPONSE,
                    'Banka vrátila prázdnou stránku, která nebyla označena jako poslední.',
                    false,
                    $response->getStatusCode(),
                );
            }
        }

        throw new BankConnectorException(
            self::ERROR_PAGINATION_LIMIT,
            'Počet stránek bankovních transakcí překročil bezpečný limit.',
        );
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{accepted:true,reference:string}
     */
    public function submitBatch(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $abo,
    ): array {
        [$clientId, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAbo($abo);
        $response = $this->request(
            'POST',
            self::BASE_URL . 'payments/batches',
            $clientId,
            $curlOptions,
            [],
            [
                'Content-Type' => 'text/plain',
                'Batch-Import-Format' => 'ABO-KPC',
                'Batch-Autocorrect' => 'false',
            ],
            $abo,
            true,
        );
        if ($response->getStatusCode() !== 200) {
            throw $this->unexpectedSuccessStatus($response->getStatusCode(), true);
        }

        $data = $this->decodeJson($response, true);
        $batchFileId = $data['batchFileId'] ?? null;
        if (!is_int($batchFileId) || $batchFileId <= 0) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka nepotvrdila identifikátor přijaté platební dávky.',
                true,
                $response->getStatusCode(),
            );
        }

        return ['accepted' => true, 'reference' => (string) $batchFileId];
    }

    /**
     * @param array<int,mixed> $curlOptions
     * @param array<string,int|string> $query
     * @param array<string,string> $headers
     */
    private function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $clientId,
        #[\SensitiveParameter] array $curlOptions,
        array $query = [],
        array $headers = [],
        #[\SensitiveParameter] ?string $body = null,
        bool $payment = false,
    ): ResponseInterface {
        $headers += [
            'Accept' => 'application/json',
            'X-IBM-Client-Id' => $clientId,
            'X-Request-Id' => 'myucto-' . bin2hex(random_bytes(16)),
            'User-Agent' => 'MyUcto-RB-Premium/1.0',
        ];
        $tooLarge = false;
        try {
            $memory = Utils::streamFor(Utils::tryFopen('php://memory', 'w+b'));
            $bytes = 0;
            $rejectLarge = static function () use (&$tooLarge): never {
                $tooLarge = true;
                throw new \RuntimeException('Response size limit exceeded.');
            };
            $sink = FnStream::decorate($memory, [
                'write' => static function (string $data) use ($memory, &$bytes, $rejectLarge): int {
                    if (strlen($data) > self::MAX_RESPONSE_BYTES - $bytes) {
                        $rejectLarge();
                    }
                    $bytes += strlen($data);
                    return $memory->write($data);
                },
            ]);
            $options = [
                'headers' => $headers,
                'curl' => $curlOptions,
                'allow_redirects' => false,
                'connect_timeout' => 5.0,
                'timeout' => 30.0,
                'verify' => true,
                'http_errors' => false,
                'stream' => false,
                'sink' => $sink,
                'on_headers' => static function (ResponseInterface $response) use ($rejectLarge): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (preg_match('/^[0-9]+$/D', $length) !== 1 || (float) $length > self::MAX_RESPONSE_BYTES)) {
                        $rejectLarge();
                    }
                },
                'progress' => static function (int|float $downloadTotal, int|float $downloadedBytes, int|float $uploadTotal, int|float $uploadedBytes) use ($rejectLarge): void {
                    if ($downloadTotal > self::MAX_RESPONSE_BYTES || $downloadedBytes > self::MAX_RESPONSE_BYTES) {
                        $rejectLarge();
                    }
                },
                'debug' => false,
            ];
            if ($query !== []) {
                $options['query'] = $query;
            }
            if ($body !== null) {
                $options['body'] = $body;
            }
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException|\RuntimeException) {
            if ($tooLarge) {
                throw new BankConnectorException(
                    BankConnectorException::RESPONSE_TOO_LARGE,
                    'Odpověď banky překročila povolenou velikost.',
                    $payment,
                );
            }
            throw new BankConnectorException(
                BankConnectorException::REMOTE_UNAVAILABLE,
                'Bankovní služba je dočasně nedostupná.',
                $payment,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $response;
        }
        if ($status === 429) {
            throw new BankConnectorException(
                BankConnectorException::RATE_LIMITED,
                'Bankovní služba překročila povolený počet požadavků.',
                $payment,
                $status,
            );
        }

        throw new BankConnectorException(
            $status >= 500
                ? BankConnectorException::REMOTE_UNAVAILABLE
                : ($payment ? BankConnectorException::PAYMENT_REJECTED : BankConnectorException::REMOTE_HTTP_ERROR),
            $payment
                ? 'Banka nepotvrdila přijetí platební dávky.'
                : 'Banka odmítla požadavek na bankovní data.',
            $payment,
            $status,
        );
    }

    private function unexpectedSuccessStatus(int $status, bool $payment): BankConnectorException
    {
        return new BankConnectorException(
            BankConnectorException::INVALID_RESPONSE,
            'Banka vrátila neočekávaný stav odpovědi.',
            $payment,
            $status,
        );
    }

    /** @return array<string,mixed> */
    private function decodeJson(
        #[\SensitiveParameter] ResponseInterface $response,
        bool $payment,
        ?int &$totalBytes = null,
    ): array {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'application/json')) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila neočekávaný formát odpovědi.',
                $payment,
                $response->getStatusCode(),
            );
        }
        $body = $this->readResponse($response, $payment);
        if ($totalBytes !== null) {
            $totalBytes += strlen($body);
            if ($totalBytes > self::MAX_TRANSACTION_RESPONSE_BYTES) {
                throw new BankConnectorException(
                    BankConnectorException::RESPONSE_TOO_LARGE,
                    'Souhrnná velikost odpovědí banky překročila povolený limit.',
                    $payment,
                    $response->getStatusCode(),
                );
            }
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila nečitelnou JSON odpověď.',
                $payment,
                $response->getStatusCode(),
            );
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila neplatnou strukturu JSON odpovědi.',
                $payment,
                $response->getStatusCode(),
            );
        }
        return $data;
    }

    private function readResponse(
        #[\SensitiveParameter] ResponseInterface $response,
        bool $payment,
    ): string {
        try {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (strlen($body) <= self::MAX_RESPONSE_BYTES) {
                if ($stream->eof()) {
                    break;
                }
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    throw new \RuntimeException('Response stream made no progress.');
                }
                $body .= $chunk;
            }
        } catch (\RuntimeException) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky se nepodařilo bezpečně přečíst.',
                $payment,
                $response->getStatusCode(),
            );
        }
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new BankConnectorException(
                strlen($body) > self::MAX_RESPONSE_BYTES
                    ? BankConnectorException::RESPONSE_TOO_LARGE
                    : BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky je prázdná nebo překročila povolenou velikost.',
                $payment,
                $response->getStatusCode(),
            );
        }
        return $body;
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{string,array<int,mixed>}
     */
    private function validateCredentials(#[\SensitiveParameter] array $credentials): array
    {
        if (array_diff(array_keys($credentials), ['client_id', 'curl_options']) !== []) {
            throw $this->invalidCredentials();
        }
        $clientId = $credentials['client_id'] ?? null;
        $curlOptions = $credentials['curl_options'] ?? null;
        if (
            !is_string($clientId)
            || preg_match('/^[\x21-\x7E]{1,256}$/D', $clientId) !== 1
            || !is_array($curlOptions)
        ) {
            throw $this->invalidCredentials();
        }

        $allowed = $this->allowedCurlOptions();
        $certKey = defined('CURLOPT_SSLCERT_BLOB') ? constant('CURLOPT_SSLCERT_BLOB') : null;
        $privateKey = defined('CURLOPT_SSLKEY_BLOB') ? constant('CURLOPT_SSLKEY_BLOB') : null;
        if (!is_int($certKey) || !is_int($privateKey)) {
            throw $this->invalidCredentials();
        }
        foreach ($curlOptions as $key => $value) {
            if (!is_int($key) || !isset($allowed[$key]) || !is_string($value)) {
                throw $this->invalidCredentials();
            }
            if (
                strlen($value) > self::MAX_CERTIFICATE_BYTES
                || ($key !== $certKey && $key !== $privateKey && strlen($value) > 4096)
            ) {
                throw $this->invalidCredentials();
            }
        }
        if (
            !isset($curlOptions[$certKey], $curlOptions[$privateKey])
            || $curlOptions[$certKey] === ''
            || $curlOptions[$privateKey] === ''
            || strlen($curlOptions[$certKey]) > self::MAX_CERTIFICATE_BYTES
            || strlen($curlOptions[$privateKey]) > self::MAX_CERTIFICATE_BYTES
        ) {
            throw $this->invalidCredentials();
        }

        return [$clientId, $curlOptions];
    }

    /** @return array<int,true> */
    private function allowedCurlOptions(): array
    {
        $allowed = [];
        foreach ([
            'CURLOPT_SSLCERT_BLOB',
            'CURLOPT_SSLKEY_BLOB',
            'CURLOPT_KEYPASSWD',
            'CURLOPT_SSLCERTTYPE',
            'CURLOPT_SSLKEYTYPE',
        ] as $name) {
            if (defined($name)) {
                $value = constant($name);
                $allowed[$value] = true;
            }
        }
        return $allowed;
    }

    private function invalidCredentials(): BankConnectorException
    {
        return new BankConnectorException(
            self::ERROR_INVALID_CREDENTIALS,
            'Přihlašovací údaje k bankovnímu API nemají platný formát.',
        );
    }

    private function validateAccountNumber(string $accountNumber): void
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $accountNumber) !== 1) {
            throw new BankConnectorException(
                self::ERROR_INVALID_ACCOUNT,
                'Číslo bankovního účtu nemá platný formát.',
            );
        }
    }

    private function validateCurrency(string $currency): void
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new BankConnectorException(
                self::ERROR_INVALID_CURRENCY,
                'Měna bankovního účtu nemá platný formát.',
            );
        }
    }

    private function validateDateRange(string $from, string $to): void
    {
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        if ($fromDate > $toDate || $toDate > new \DateTimeImmutable('today')) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE_RANGE,
                'Období bankovních transakcí nemá platný rozsah.',
            );
        }
        if ($fromDate < (new \DateTimeImmutable('today'))->modify('-90 days')) {
            throw new BankConnectorException(
                BankConnectorException::HISTORY_LOCKED,
                'Bankovní API zpřístupňuje transakce nejvýše 90 dní zpětně.',
            );
        }
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE,
                'Datum bankovních transakcí nemá platný formát.',
            );
        }
        return $date;
    }

    private function validateAbo(#[\SensitiveParameter] string $abo): void
    {
        if (
            $abo === ''
            || strlen($abo) > self::MAX_ABO_BYTES
            || !mb_check_encoding($abo, 'ASCII')
            || !str_ends_with($abo, "\r\n")
            || preg_match('/(?<!\r)\n|\r(?!\n)/', $abo) === 1
        ) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Platební dávka ABO nemá platný formát nebo velikost.',
            );
        }
        $lines = explode("\r\n", substr($abo, 0, -2));
        $last = count($lines) - 1;
        if (
            count($lines) < 6
            || !str_starts_with($lines[0], 'UHL1')
            || preg_match('/^1 1501 /D', $lines[1]) !== 1
            || !str_starts_with($lines[2], '2 ')
            || $lines[$last - 1] !== '3 +'
            || $lines[$last] !== '5 +'
        ) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Platební dávka ABO nemá platnou strukturu.',
            );
        }
    }

    /** @param array<string,mixed> $data */
    private function validateAccountResponse(
        #[\SensitiveParameter] array $data,
        string $accountNumber,
        int $httpStatus,
    ): void {
        if (
            !isset($data['numberPart2'], $data['bankCode'])
            || !is_string($data['numberPart2'])
            || $data['numberPart2'] !== $accountNumber
            || !is_string($data['bankCode'])
            || preg_match('/^[0-9]{4}$/D', $data['bankCode']) !== 1
        ) {
            throw new BankConnectorException(
                isset($data['numberPart2']) && $data['numberPart2'] !== $accountNumber
                    ? self::ERROR_ACCOUNT_MISMATCH
                    : BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila neplatnou identitu účtu.',
                false,
                $httpStatus,
            );
        }
        if (isset($data['numberPart1']) && (!is_string($data['numberPart1']) || preg_match('/^[0-9]{1,6}$/D', $data['numberPart1']) !== 1)) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila neplatné předčíslí účtu.',
                false,
                $httpStatus,
            );
        }
        if (!isset($data['currencyFolders'])) {
            return;
        }
        if (!is_array($data['currencyFolders']) || !array_is_list($data['currencyFolders'])) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila neplatný seznam měnových složek.',
                false,
                $httpStatus,
            );
        }
        $seen = [];
        foreach ($data['currencyFolders'] as $folder) {
            if (
                !is_array($folder)
                || !isset($folder['currency'], $folder['status'])
                || !is_string($folder['currency'])
                || preg_match('/^[A-Z]{3}$/D', $folder['currency']) !== 1
                || !is_string($folder['status'])
                || $folder['status'] === ''
                || isset($seen[$folder['currency']])
            ) {
                throw new BankConnectorException(
                    BankConnectorException::INVALID_RESPONSE,
                    'Banka vrátila neplatnou měnovou složku účtu.',
                    false,
                    $httpStatus,
                );
            }
            $seen[$folder['currency']] = true;
            $balances = $folder['balances'] ?? [];
            if (!is_array($balances) || !array_is_list($balances)) {
                throw new BankConnectorException(
                    BankConnectorException::INVALID_RESPONSE,
                    'Banka vrátila neplatné zůstatky účtu.',
                    false,
                    $httpStatus,
                );
            }
            foreach ($balances as $balance) {
                if (
                    !is_array($balance)
                    || !isset($balance['balanceType'], $balance['currency'], $balance['value'])
                    || !is_string($balance['balanceType'])
                    || $balance['balanceType'] === ''
                    || $balance['currency'] !== $folder['currency']
                    || !$this->finiteNumber($balance['value'])
                ) {
                    throw new BankConnectorException(
                        self::ERROR_CURRENCY_MISMATCH,
                        'Banka vrátila neplatnou měnu nebo hodnotu zůstatku.',
                        false,
                        $httpStatus,
                    );
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{list<array<string,mixed>>,bool}
     */
    private function validateTransactionPage(
        #[\SensitiveParameter] array $data,
        string $currency,
        int $httpStatus,
    ): array {
        if (
            !array_key_exists('lastPage', $data)
            || !is_bool($data['lastPage'])
            || !isset($data['transactions'])
            || !is_array($data['transactions'])
            || !array_is_list($data['transactions'])
        ) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila neplatnou stránku transakcí.',
                false,
                $httpStatus,
            );
        }
        foreach ($data['transactions'] as $transaction) {
            if (
                !is_array($transaction)
                || !isset(
                    $transaction['entryReference'],
                    $transaction['amount'],
                    $transaction['creditDebitIndication'],
                    $transaction['bankTransactionCode'],
                )
                || !is_string($transaction['entryReference'])
                || $transaction['entryReference'] === ''
                || strlen($transaction['entryReference']) > 256
                || !is_array($transaction['amount'])
                || !isset($transaction['amount']['value'], $transaction['amount']['currency'])
                || !$this->finiteNumber($transaction['amount']['value'])
                || $transaction['amount']['currency'] !== $currency
                || !in_array($transaction['creditDebitIndication'], ['DBIT', 'CRDT'], true)
                || !is_array($transaction['bankTransactionCode'])
                || !isset($transaction['bankTransactionCode']['code'])
                || !is_string($transaction['bankTransactionCode']['code'])
                || $transaction['bankTransactionCode']['code'] === ''
            ) {
                $errorCode = is_array($transaction)
                    && isset($transaction['amount'])
                    && is_array($transaction['amount'])
                    && isset($transaction['amount']['currency'])
                    && $transaction['amount']['currency'] !== $currency
                        ? self::ERROR_CURRENCY_MISMATCH
                        : BankConnectorException::INVALID_RESPONSE;
                throw new BankConnectorException(
                    $errorCode,
                    'Banka vrátila neplatnou transakci nebo měnu částky.',
                    false,
                    $httpStatus,
                );
            }
        }

        return [$data['transactions'], $data['lastPage']];
    }

    private function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
