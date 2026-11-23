<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * MONETA API (VIP v4). Přístup je jen tokenem, který si klient vygeneruje
 * v Internet Bance (Nastavení / Ostatní / Správa API tokenů). Klíče aplikace
 * z portálu banky slouží jen pro sandbox, v produkci se neposílají.
 */
final class MonetaApiClient
{
    private const BASE_URL = 'https://api.moneta.cz';
    private const APPLICATION_NAME = 'MyUcto';
    private const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 200;
    public const MAX_BATCH_PAYMENTS = 200;

    public function __construct(private readonly ClientInterface $http) {}

    /** @return string|null expirace tokenu (ISO 8601), pokud ji banka vrátí */
    public function tokenExpiration(#[\SensitiveParameter] string $token): ?string
    {
        $data = $this->json('GET', '/api/v1/vip/token/introspect', $token);
        $expiration = $data['expiration'] ?? null;
        return is_string($expiration) && $expiration !== '' ? $expiration : null;
    }

    /** @return list<array<string,mixed>> */
    public function accounts(#[\SensitiveParameter] string $token): array
    {
        $accounts = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->json('GET', '/api/v4/vip/aisp/my/accounts', $token, ['size' => self::PAGE_SIZE, 'page' => $page]);
            $rows = $data['accounts'] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw $this->invalidResponse();
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw $this->invalidResponse();
                }
                $accounts[] = $row;
            }
            if (!$this->hasNextPage($data, $page)) {
                return $accounts;
            }
        }
        throw $this->invalidResponse();
    }

    /** @return list<array<string,mixed>> */
    public function transactions(#[\SensitiveParameter] string $token, string $accountId, string $from, string $to): array
    {
        $this->assertAccountId($accountId);
        $transactions = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->json('GET', '/api/v4/vip/aisp/my/accounts/' . rawurlencode($accountId) . '/transactions', $token, [
                'fromDate' => $from,
                'toDate' => $to,
                'size' => self::PAGE_SIZE,
                'page' => $page,
            ]);
            $rows = $data['transactions'] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw $this->invalidResponse();
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw $this->invalidResponse();
                }
                $transactions[] = $row;
            }
            if (!$this->hasNextPage($data, $page)) {
                return $transactions;
            }
        }
        throw new BankConnectorException(
            BankConnectorException::STATEMENT_TOO_LARGE,
            'Požadované období obsahuje příliš mnoho bankovních pohybů.',
        );
    }

    /**
     * @param list<array<string,mixed>> $payments
     * @return string batchId
     */
    public function submitBatch(#[\SensitiveParameter] string $token, #[\SensitiveParameter] array $payments): string
    {
        if ($payments === [] || count($payments) > self::MAX_BATCH_PAYMENTS) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Hromadný příkaz MONETA musí mít 1 až 200 plateb.',
            );
        }
        $data = $this->json('POST', '/api/v4/vip/pisp/my/payments/batch', $token, [], ['payments' => $payments]);
        $batchId = $data['batchId'] ?? null;
        if (!is_string($batchId) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $batchId) !== 1) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka nepotvrdila referenci hromadného příkazu.',
                true,
            );
        }
        return $batchId;
    }

    /** @return 'ACTC'|'ACSP'|'RJCT' */
    public function batchStatus(#[\SensitiveParameter] string $token, string $batchId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $batchId) !== 1) {
            throw $this->invalidResponse();
        }
        $data = $this->json('GET', '/api/v4/vip/pisp/my/payments/batch/' . rawurlencode($batchId) . '/status', $token);
        $status = $data['instructionStatus'] ?? null;
        if (!in_array($status, ['ACTC', 'ACSP', 'RJCT'], true)) {
            throw $this->invalidResponse();
        }
        return $status;
    }

    /**
     * @param array<string,scalar> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function json(
        string $method,
        string $path,
        #[\SensitiveParameter] string $token,
        array $query = [],
        #[\SensitiveParameter] ?array $body = null,
    ): array {
        $this->assertToken($token);
        $payment = $method === 'POST';
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'MyUcto-Bank-Connector/1.0',
                'application_name' => self::APPLICATION_NAME,
            ],
            'allow_redirects' => false,
            'connect_timeout' => 5.0,
            'timeout' => 60.0,
            'verify' => true,
            'http_errors' => false,
            'stream' => true,
            'debug' => false,
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($body !== null) {
            $options['body'] = BankPaymentJson::encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $options['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->http->request($method, self::BASE_URL . $path, $options);
        } catch (GuzzleException) {
            throw new BankConnectorException(
                BankConnectorException::REMOTE_UNAVAILABLE,
                'Bankovní služba MONETA je dočasně nedostupná.',
                $payment,
            );
        }

        $status = $response->getStatusCode();
        $raw = $this->read($response, $payment);
        if ($status < 200 || $status >= 300) {
            throw $this->httpException($status, $raw, $payment);
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw $this->invalidResponse($payment, $status);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw $this->invalidResponse($payment, $status);
        }
        return $data;
    }

    private function httpException(int $status, #[\SensitiveParameter] string $raw, bool $payment): BankConnectorException
    {
        $codes = [];
        $decoded = json_decode($raw, true);
        foreach ((is_array($decoded) && is_array($decoded['errors'] ?? null)) ? $decoded['errors'] : [] as $error) {
            if (is_array($error) && is_string($error['error'] ?? null)) {
                $codes[] = $error['error'];
            }
        }
        if ($status === 401 && in_array('NARR', $codes, true)) {
            return new BankConnectorException(
                BankConnectorException::HISTORY_LOCKED,
                'Starší historie pohybů vyžaduje v bance dvoufázové ověření.',
                false,
                $status,
            );
        }
        if ($status === 401 || $status === 403) {
            return new BankConnectorException(
                BankConnectorException::INVALID_TOKEN,
                'Token MONETA API vypršel, byl zrušen nebo nemá potřebné oprávnění.',
                false,
                $status,
            );
        }
        if ($status === 429) {
            return new BankConnectorException(
                BankConnectorException::RATE_LIMITED,
                'Banka dočasně omezila počet dotazů.',
                false,
                $status,
            );
        }
        if ($payment && $status >= 400 && $status < 500) {
            // Validační chyba (např. REC_SEND): banka dávku nezaložila.
            return new BankConnectorException(
                BankConnectorException::PAYMENT_REJECTED,
                'Banka hromadný příkaz odmítla.',
                false,
                $status,
            );
        }
        return new BankConnectorException(
            $status >= 500 ? BankConnectorException::REMOTE_UNAVAILABLE : BankConnectorException::REMOTE_HTTP_ERROR,
            $payment ? 'Banka nepotvrdila přijetí hromadného příkazu.' : 'Banka odmítla požadavek na pohyby.',
            $payment && $status >= 500,
            $status,
        );
    }

    private function read(#[\SensitiveParameter] ResponseInterface $response, bool $payment): string
    {
        try {
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
                $chunk = $stream->read(min(65536, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
        } catch (\RuntimeException) {
            throw $this->invalidResponse($payment, $response->getStatusCode());
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new BankConnectorException(
                BankConnectorException::RESPONSE_TOO_LARGE,
                'Odpověď banky překročila povolenou velikost.',
                $payment,
                $response->getStatusCode(),
            );
        }
        return $body;
    }

    /** @param array<string,mixed> $data */
    private function hasNextPage(array $data, int $page): bool
    {
        $count = $data['pageCount'] ?? null;
        if (!is_int($count) || $count < 0) {
            throw $this->invalidResponse();
        }
        return $page + 1 < $count;
    }

    private function assertToken(#[\SensitiveParameter] string $token): void
    {
        if (preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $token) !== 1) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_TOKEN,
                'Token MONETA API nemá platný formát.',
            );
        }
    }

    private function assertAccountId(string $accountId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $accountId) !== 1) {
            throw $this->invalidResponse();
        }
    }

    private function invalidResponse(bool $payment = false, ?int $status = null): BankConnectorException
    {
        return new BankConnectorException(
            BankConnectorException::INVALID_RESPONSE,
            'Banka MONETA vrátila neplatnou odpověď.',
            $payment,
            $status,
        );
    }
}
