<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

final class CreditasPremiumClient
{
    public const ERROR_INVALID_CREDENTIALS = 'creditas_invalid_credentials';
    public const ERROR_INVALID_ACCOUNT_ID = 'creditas_invalid_account_id';
    public const ERROR_ACCOUNT_MISMATCH = 'creditas_account_mismatch';
    public const ERROR_INVALID_CURRENCY = 'creditas_invalid_currency';
    public const ERROR_PAGINATION_LIMIT = 'creditas_pagination_limit';
    public const ERROR_INVALID_EXPORT = 'creditas_invalid_export';

    private const BASE_URL = 'https://api.creditas.cz/oam/v1';
    private const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;
    private const MAX_TRANSACTION_RESPONSE_BYTES = 32 * 1024 * 1024;
    private const MAX_EXPORT_BYTES = 10 * 1024 * 1024;
    private const MAX_ABO_BYTES = 10 * 1024 * 1024;
    private const MAX_CERTIFICATE_BYTES = 2 * 1024 * 1024;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 100;
    private const MAX_TRANSACTIONS = 50_000;
    private const MAX_TRANSACTION_DURATION_SECONDS = 120.0;

    public function __construct(private readonly ClientInterface $http)
    {
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    public function currentAccount(
        #[\SensitiveParameter] array $credentials,
        string $accountId,
    ): array {
        return $this->account($credentials, $accountId, 'current');
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    public function savingsAccount(
        #[\SensitiveParameter] array $credentials,
        string $accountId,
    ): array {
        return $this->account($credentials, $accountId, 'savings');
    }

    /**
     * @param array<string,mixed> $credentials
     * @return list<array<string,mixed>>
     */
    public function transactions(
        #[\SensitiveParameter] array $credentials,
        string $accountId,
        string $from,
        string $to,
    ): array {
        [$token, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAccountId($accountId);
        $this->validateDateRange($from, $to);

        $all = [];
        $seen = [];
        $expectedCount = null;
        $totalBytes = 0;
        $startedAt = microtime(true);
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            if (microtime(true) - $startedAt > self::MAX_TRANSACTION_DURATION_SECONDS) {
                throw $this->paginationLimit('Načítání transakcí překročilo časový limit.');
            }
            $response = $this->postJson(
                '/account/transaction/search',
                $token,
                $curlOptions,
                [
                    'accountId' => $accountId,
                    'filter' => ['dateFrom' => $from, 'dateTo' => $to],
                    'pageItemCount' => self::PAGE_SIZE,
                    'pageIndex' => $page,
                ],
            );
            $data = $this->decodeJson($response, false, $totalBytes);
            [$items, $itemCount] = $this->validateTransactionPage($data, $response->getStatusCode());
            if ($expectedCount === null) {
                $expectedCount = $itemCount;
                if ($expectedCount > self::MAX_TRANSACTIONS) {
                    throw $this->paginationLimit('Počet bankovních transakcí překročil bezpečný limit.');
                }
            } elseif ($expectedCount !== $itemCount) {
                throw $this->invalidResponse('Banka během stránkování změnila počet transakcí.', $response);
            }

            foreach ($items as $item) {
                $reference = $item['transactionId'];
                if (isset($seen[$reference])) {
                    throw $this->invalidResponse('Banka vrátila duplicitní transakci.', $response);
                }
                $seen[$reference] = true;
                $all[] = $item;
            }
            if (count($all) > $itemCount) {
                throw $this->invalidResponse('Banka vrátila více transakcí, než deklarovala.', $response);
            }
            if (count($all) === $itemCount) {
                return $all;
            }
            if ($items === []) {
                throw $this->invalidResponse('Banka vrátila předčasně prázdnou stránku transakcí.', $response);
            }
        }

        throw $this->paginationLimit('Počet stránek bankovních transakcí překročil bezpečný limit.');
    }

    /**
     * @param array<string,mixed> $credentials
     */
    public function exportTransactions(
        #[\SensitiveParameter] array $credentials,
        string $accountId,
        string $from,
        string $to,
    ): string {
        [$token, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAccountId($accountId);
        $this->validateDateRange($from, $to);
        $response = $this->postJson(
            '/account/transaction/export',
            $token,
            $curlOptions,
            [
                'accountId' => $accountId,
                'format' => 'XML',
                'filter' => ['dateFrom' => $from, 'dateTo' => $to],
            ],
        );
        $data = $this->decodeJson($response);
        $encoded = $data['export'] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw $this->invalidExport($response->getStatusCode());
        }
        $xml = base64_decode($encoded, true);
        if ($xml === false || $xml === '' || strlen($xml) > self::MAX_EXPORT_BYTES) {
            throw $this->invalidExport($response->getStatusCode());
        }
        $this->validateCamtXml($xml, $response->getStatusCode());
        return $xml;
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{status:'import_started',reference:string}
     */
    public function startPaymentImport(
        #[\SensitiveParameter] array $credentials,
        string $accountId,
        #[\SensitiveParameter] string $abo,
        string $reference,
    ): array {
        [$token, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAccountId($accountId);
        $this->validateAbo($abo);
        if (preg_match('/^[A-Za-z0-9._:-]{1,40}$/D', $reference) !== 1) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Reference platební dávky nemá platný formát.',
            );
        }
        $response = $this->postJson(
            '/payment/import',
            $token,
            $curlOptions,
            [
                'accountId' => $accountId,
                'format' => 'KPC',
                'type' => 'DOMESTIC',
                'importMode' => 'CANCEL_ON_ERROR',
                'reference' => $reference,
                'fileName' => 'myucto-' . $reference . '.kpc',
                'data' => base64_encode($abo),
            ],
            true,
        );
        $data = $this->decodeJson($response, true);
        $importId = $data['importId'] ?? null;
        if (!is_string($importId) || preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $importId) !== 1) {
            throw $this->invalidResponse('Banka nepotvrdila identifikátor importu plateb.', $response, true);
        }
        return ['status' => 'import_started', 'reference' => $importId];
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{status:'IN_PROGRESS'|'COMPLETE'|'CANCELLED'|'FAILED',item_count:int,error_count:int,reference:?string,bulk_payment_order_id:?string}
     */
    public function paymentImportStatus(
        #[\SensitiveParameter] array $credentials,
        string $importId,
    ): array {
        [$token, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateImportId($importId);
        $response = $this->postJson(
            '/payment/import/status/get',
            $token,
            $curlOptions,
            ['importId' => $importId],
        );
        $data = $this->decodeJson($response);
        $status = $data['status'] ?? null;
        $itemCount = $data['itemCount'] ?? null;
        $errorCount = $data['itemCountError'] ?? null;
        if (
            !is_string($status)
            || !in_array($status, ['IN_PROGRESS', 'COMPLETE', 'CANCELLED', 'FAILED'], true)
            || !is_int($itemCount)
            || !is_int($errorCount)
            || $itemCount < 0
            || $errorCount < 0
            || $errorCount > $itemCount
        ) {
            throw $this->invalidResponse('Banka vrátila neplatný stav importu plateb.', $response);
        }
        if ($status === 'COMPLETE' && $errorCount > 0) {
            throw new BankConnectorException(
                BankConnectorException::PAYMENT_REJECTED,
                'Import plateb obsahuje odmítnuté položky.',
                $itemCount > $errorCount,
                $response->getStatusCode(),
                max(0, $itemCount - $errorCount),
                $errorCount,
            );
        }
        $reference = $this->optionalString($data, 'reference', 255, $response);
        $bulkId = $this->optionalString($data, 'bulkPaymentOrderId', 255, $response);
        return [
            'status' => $status,
            'item_count' => $itemCount,
            'error_count' => $errorCount,
            'reference' => $reference,
            'bulk_payment_order_id' => $bulkId,
        ];
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    private function account(
        #[\SensitiveParameter] array $credentials,
        string $accountId,
        string $kind,
    ): array {
        [$token, $curlOptions] = $this->validateCredentials($credentials);
        $this->validateAccountId($accountId);
        $response = $this->postJson(
            '/account/' . $kind . '/get',
            $token,
            $curlOptions,
            ['accountId' => $accountId],
        );
        $data = $this->decodeJson($response);
        $key = $kind . 'Account';
        $account = $data[$key] ?? null;
        if (!is_array($account) || array_is_list($account)) {
            throw $this->invalidResponse('Banka vrátila neplatná data účtu.', $response);
        }
        $returnedId = $account['accountId'] ?? null;
        $currency = $account['currency'] ?? null;
        if (!is_string($returnedId) || $returnedId !== $accountId) {
            throw new BankConnectorException(
                self::ERROR_ACCOUNT_MISMATCH,
                'Banka vrátila jiný identifikátor účtu.',
                false,
                $response->getStatusCode(),
            );
        }
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new BankConnectorException(
                self::ERROR_INVALID_CURRENCY,
                'Banka vrátila neplatnou měnu účtu.',
                false,
                $response->getStatusCode(),
            );
        }
        foreach (['iban' => 42, 'bban' => 42] as $field => $maxLength) {
            if (isset($account[$field]) && (!is_string($account[$field]) || $account[$field] === '' || strlen($account[$field]) > $maxLength)) {
                throw $this->invalidResponse('Banka vrátila neplatnou identitu účtu.', $response);
            }
        }
        return $account;
    }

    /**
     * @param array<int,mixed> $curlOptions
     * @param array<string,mixed> $payload
     */
    private function postJson(
        string $path,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] array $curlOptions,
        #[\SensitiveParameter] array $payload,
        bool $payment = false,
    ): ResponseInterface {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Požadavek pro banku se nepodařilo bezpečně sestavit.',
                $payment,
            );
        }
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
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'MyUcto-Creditas-Premium/1.0',
                ],
                'body' => $body,
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
            $response = $this->http->request('POST', self::BASE_URL . $path, $options);
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
        if ($status === 200) {
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
            $payment ? 'Banka nepotvrdila přijetí platebního importu.' : 'Banka odmítla požadavek na bankovní data.',
            $payment,
            $status,
        );
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{string,array<int,mixed>}
     */
    private function validateCredentials(#[\SensitiveParameter] array $credentials): array
    {
        if (array_diff(array_keys($credentials), ['bearer_token', 'curl_options']) !== []) {
            throw $this->invalidCredentials();
        }
        $token = $credentials['bearer_token'] ?? null;
        $curlOptions = $credentials['curl_options'] ?? [];
        if (!is_string($token) || preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1 || !is_array($curlOptions)) {
            throw $this->invalidCredentials();
        }
        if ($curlOptions === []) {
            return [$token, []];
        }
        $certKey = defined('CURLOPT_SSLCERT_BLOB') ? constant('CURLOPT_SSLCERT_BLOB') : null;
        $privateKey = defined('CURLOPT_SSLKEY_BLOB') ? constant('CURLOPT_SSLKEY_BLOB') : null;
        if (!is_int($certKey) || !is_int($privateKey)) {
            throw $this->invalidCredentials();
        }
        $allowed = $this->allowedCurlOptions();
        foreach ($curlOptions as $key => $value) {
            if (
                !is_int($key)
                || !isset($allowed[$key])
                || !is_string($value)
                || strlen($value) > self::MAX_CERTIFICATE_BYTES
                || ($key !== $certKey && $key !== $privateKey && strlen($value) > 4096)
            ) {
                throw $this->invalidCredentials();
            }
        }
        if (
            !isset($curlOptions[$certKey], $curlOptions[$privateKey])
            || $curlOptions[$certKey] === ''
            || $curlOptions[$privateKey] === ''
        ) {
            throw $this->invalidCredentials();
        }
        return [$token, $curlOptions];
    }

    /** @return array<int,true> */
    private function allowedCurlOptions(): array
    {
        $allowed = [];
        foreach (['CURLOPT_SSLCERT_BLOB', 'CURLOPT_SSLKEY_BLOB', 'CURLOPT_KEYPASSWD', 'CURLOPT_SSLCERTTYPE', 'CURLOPT_SSLKEYTYPE'] as $name) {
            if (defined($name)) {
                $allowed[constant($name)] = true;
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

    private function validateAccountId(string $accountId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $accountId) !== 1) {
            throw new BankConnectorException(self::ERROR_INVALID_ACCOUNT_ID, 'Identifikátor bankovního účtu nemá platný formát.');
        }
    }

    private function validateImportId(string $importId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $importId) !== 1) {
            throw new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'Identifikátor importu plateb nemá platný formát.');
        }
    }

    private function validateDateRange(string $from, string $to): void
    {
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        if ($fromDate > $toDate || $toDate > new \DateTimeImmutable('today') || $fromDate->modify('+366 days') < $toDate) {
            throw new BankConnectorException(BankConnectorException::INVALID_DATE_RANGE, 'Období bankovních transakcí nemá platný rozsah.');
        }
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BankConnectorException(BankConnectorException::INVALID_DATE, 'Datum bankovních transakcí nemá platný formát.');
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
            throw new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'Platební dávka KPC nemá platný formát nebo velikost.');
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
            throw new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'Platební dávka KPC nemá platnou strukturu.');
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{list<array<string,mixed>>,int}
     */
    private function validateTransactionPage(#[\SensitiveParameter] array $data, int $httpStatus): array
    {
        $items = $data['transactions'] ?? null;
        $itemCount = $data['itemCount'] ?? null;
        if (!is_array($items) || !array_is_list($items) || !is_int($itemCount) || $itemCount < 0 || count($items) > self::PAGE_SIZE) {
            throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Banka vrátila neplatnou stránku transakcí.', false, $httpStatus);
        }
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset($item['transactionId'], $item['category'], $item['type'], $item['code'], $item['amount'], $item['effectiveDate'])
                || !is_string($item['transactionId'])
                || $item['transactionId'] === ''
                || strlen($item['transactionId']) > 256
                || !in_array($item['category'], ['DOMESTIC', 'FOREIGN', 'CARD', 'OTHER'], true)
                || !in_array($item['type'], ['CREDIT', 'DEBIT'], true)
                || !is_string($item['code'])
                || $item['code'] === ''
                || !is_array($item['amount'])
                || !isset($item['amount']['value'], $item['amount']['currency'])
                || !is_string($item['amount']['value'])
                || !$this->validAmount($item['amount']['value'])
                || !is_string($item['amount']['currency'])
                || preg_match('/^[A-Z]{3}$/D', $item['amount']['currency']) !== 1
                || !is_string($item['effectiveDate'])
                || !$this->isDate($item['effectiveDate'])
            ) {
                throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Banka vrátila neplatnou transakci.', false, $httpStatus);
            }
        }
        return [$items, $itemCount];
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function validAmount(string $value): bool
    {
        if (preg_match('/^-?(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,8}))?$/D', $value, $match) !== 1) {
            return false;
        }
        if (strlen(rtrim($match[2] ?? '', '0')) > 2) {
            return false;
        }
        return preg_match('/[1-9]/', $value) === 1;
    }

    /** @return array<string,mixed> */
    private function decodeJson(
        #[\SensitiveParameter] ResponseInterface $response,
        bool $payment = false,
        ?int &$totalBytes = null,
    ): array {
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
            throw $this->invalidResponse('Banka vrátila neočekávaný formát odpovědi.', $response, $payment);
        }
        $body = $this->readResponse($response, $payment);
        if ($totalBytes !== null) {
            $totalBytes += strlen($body);
            if ($totalBytes > self::MAX_TRANSACTION_RESPONSE_BYTES) {
                throw new BankConnectorException(BankConnectorException::RESPONSE_TOO_LARGE, 'Souhrnná velikost odpovědí banky překročila povolený limit.', $payment, $response->getStatusCode());
            }
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->invalidResponse('Banka vrátila nečitelnou JSON odpověď.', $response, $payment);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw $this->invalidResponse('Banka vrátila neplatnou strukturu JSON odpovědi.', $response, $payment);
        }
        return $data;
    }

    private function readResponse(#[\SensitiveParameter] ResponseInterface $response, bool $payment): string
    {
        try {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (strlen($body) <= self::MAX_RESPONSE_BYTES && !$stream->eof()) {
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    throw new \RuntimeException('Response stream made no progress.');
                }
                $body .= $chunk;
            }
        } catch (\RuntimeException) {
            throw $this->invalidResponse('Odpověď banky se nepodařilo bezpečně přečíst.', $response, $payment);
        }
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new BankConnectorException(
                strlen($body) > self::MAX_RESPONSE_BYTES ? BankConnectorException::RESPONSE_TOO_LARGE : BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky je prázdná nebo překročila povolenou velikost.',
                $payment,
                $response->getStatusCode(),
            );
        }
        return $body;
    }

    private function validateCamtXml(#[\SensitiveParameter] string $xml, int $httpStatus): void
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw $this->invalidExport($httpStatus);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (
            !$loaded
            || $root === null
            || $root->localName !== 'Document'
            || $root->namespaceURI !== 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02'
        ) {
            throw $this->invalidExport($httpStatus);
        }
    }

    private function invalidExport(int $httpStatus): BankConnectorException
    {
        return new BankConnectorException(self::ERROR_INVALID_EXPORT, 'Banka vrátila neplatný export transakcí.', false, $httpStatus);
    }

    private function invalidResponse(string $message, ResponseInterface $response, bool $payment = false): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, $message, $payment, $response->getStatusCode());
    }

    private function paginationLimit(string $message): BankConnectorException
    {
        return new BankConnectorException(self::ERROR_PAGINATION_LIMIT, $message);
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $key, int $maxLength, ResponseInterface $response): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }
        if (!is_string($data[$key]) || strlen($data[$key]) > $maxLength) {
            throw $this->invalidResponse('Banka vrátila neplatná metadata importu plateb.', $response);
        }
        return $data[$key];
    }
}
