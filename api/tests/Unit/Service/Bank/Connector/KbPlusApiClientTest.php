<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusApiClient;
use PHPUnit\Framework\TestCase;

final class KbPlusApiClientTest extends TestCase
{
    private const ACCESS_TOKEN = 'synthetic-access-token-0001';
    private const ACCOUNT_ID = 'U1lOVEhFVElDLUFDQ09VTlQtSUQtMDAwMQ==';

    public function testBuildsAuthorizationCodeUrlForRegisteredApplication(): void
    {
        $client = $this->client([]);
        $url = $client->authorizationUrl(
            $this->credentials(['scope' => 'adaa statda']),
            'synthetic_oauth_state_000000000001',
        );
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith('https://login.kb.cz/autfe/ssologin?', $url);
        self::assertSame('code', $query['response_type']);
        self::assertSame('synthetic-client-001', $query['client_id']);
        self::assertSame('https://example.invalid/bank/kb-plus/callback', $query['redirect_uri']);
        self::assertSame('adaa statda', $query['scope']);
        self::assertSame('synthetic_oauth_state_000000000001', $query['state']);
    }

    public function testExchangesCodeUsingDocumentedTokenEndpointAndHardenedOptions(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->json([
            'access_token' => self::ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 179,
            'scope' => 'adaa',
            'refresh_token' => 'synthetic-refresh-token-0001',
        ]))], $history);

        $tokens = $client->exchangeAuthorizationCode(
            $this->credentials(),
            'synthetic_authorization_code_001',
        );

        self::assertSame(self::ACCESS_TOKEN, $tokens['access_token']);
        self::assertSame('synthetic-refresh-token-0001', $tokens['refresh_token']);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('https://api-gateway.kb.cz/oauth2/v3/access_token', (string) $history[0]['request']->getUri());
        self::assertSame('synthetic-api-key-0001', $history[0]['request']->getHeaderLine('apiKey'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $history[0]['request']->getHeaderLine('x-correlation-id'));
        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('synthetic_authorization_code_001', $form['code']);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertTrue($history[0]['options']['stream']);
        self::assertFalse($history[0]['options']['http_errors']);
    }

    public function testRefreshesShortLivedAccessTokenWithoutRequiringRotatedRefreshToken(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->json([
            'access_token' => self::ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 180,
            'scope' => 'adaa',
        ]))], $history);

        $tokens = $client->refreshAccessToken($this->credentials());

        self::assertArrayNotHasKey('refresh_token', $tokens);
        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('synthetic-refresh-token-0001', $form['refresh_token']);
        self::assertSame('synthetic-client-secret-0001', $form['client_secret']);
    }

    public function testReadsAndValidatesSelectedCurrencyAccounts(): void
    {
        $history = [];
        $accounts = [[
            'accountId' => self::ACCOUNT_ID,
            'iban' => 'CZ0401000000191000000005',
            'currency' => 'CZK',
            'nameI18N' => 'Syntetický účet',
            'productI18N' => 'Syntetický produkt',
        ]];
        $client = $this->client([new Response(200, [], $this->json($accounts))], $history);

        self::assertSame($accounts, $client->accounts($this->credentials(), self::ACCESS_TOKEN));
        self::assertSame('https://api-gateway.kb.cz/adaa/v2/accounts', (string) $history[0]['request']->getUri());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('synthetic-adaa-key-0001', $history[0]['request']->getHeaderLine('apiKey'));
    }

    public function testDownloadsAllTransactionPagesImmediatelyFromPageZero(): void
    {
        $history = [];
        $first = $this->transactionPage(0, 2, false, [$this->transaction('SYNTHETIC-001')]);
        $second = $this->transactionPage(1, 2, true, [$this->transaction('SYNTHETIC-002')]);
        $client = $this->client([
            new Response(200, [], $this->json($first)),
            new Response(200, [], $this->json($second)),
        ], $history);

        $result = $client->transactions(
            $this->credentials(),
            self::ACCESS_TOKEN,
            self::ACCOUNT_ID,
            '2026-09-01',
            '2026-09-07',
        );

        self::assertSame(2, $result['pages']);
        self::assertSame(['SYNTHETIC-001', 'SYNTHETIC-002'], array_column($result['transactions'], 'entryReference'));
        self::assertCount(2, $history);
        parse_str($history[0]['request']->getUri()->getQuery(), $firstQuery);
        parse_str($history[1]['request']->getUri()->getQuery(), $secondQuery);
        self::assertSame('2026-09-01T00:00:00.000Z', $firstQuery['fromDateTime']);
        self::assertSame('2026-09-07T23:59:59.999Z', $firstQuery['toDateTime']);
        self::assertSame('100', $firstQuery['size']);
        self::assertSame('0', $firstQuery['page']);
        self::assertSame('1', $secondQuery['page']);
        self::assertStringContainsString(rawurlencode(self::ACCOUNT_ID), (string) $history[0]['request']->getUri());
    }

    public function testRetriesOneTransientGetButNeverFollowsRedirects(): void
    {
        $history = [];
        $client = $this->client([
            new Response(503, [], $this->json(['error' => 'synthetic'])),
            new Response(200, [], $this->json([])),
        ], $history);

        self::assertSame([], $client->accounts($this->credentials(), self::ACCESS_TOKEN));
        self::assertCount(2, $history);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertFalse($history[1]['options']['allow_redirects']);
    }

    public function testMapsAuthenticationAndRateLimitWithoutExposingResponseOrCredentials(): void
    {
        foreach ([401 => BankConnectorException::INVALID_TOKEN, 429 => BankConnectorException::RATE_LIMITED] as $status => $code) {
            $secretBody = 'synthetic-private-bank-error';
            $client = $this->client([new Response($status, [], $secretBody)]);
            try {
                $client->accounts($this->credentials(), self::ACCESS_TOKEN);
                self::fail('HTTP chyba musí být mapována.');
            } catch (BankConnectorException $e) {
                self::assertSame($code, $e->errorCode);
                self::assertSame($status, $e->remoteHttpStatus);
                self::assertStringNotContainsString($secretBody, $e->getMessage());
                self::assertStringNotContainsString('synthetic-api-key', $e->getMessage());
            }
        }
    }

    public function testRejectsMalformedPaginationInsteadOfReturningPartialHistory(): void
    {
        $client = $this->client([new Response(200, [], $this->json(
            $this->transactionPage(3, 4, false, []),
        ))]);

        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('neplatnou stránku');
        $client->transactions(
            $this->credentials(),
            self::ACCESS_TOKEN,
            self::ACCOUNT_ID,
            '2026-09-01',
            '2026-09-07',
        );
    }

    public function testRejectsPaginationThatCanExceedFiftyThousandTransactions(): void
    {
        $history = [];
        $content = [];
        for ($i = 0; $i < 100; $i++) {
            $content[] = $this->transaction(sprintf('SYNTHETIC-%03d', $i));
        }
        $client = $this->client([new Response(200, [], $this->json(
            $this->transactionPage(0, 501, false, $content),
        ))], $history);

        try {
            $client->transactions(
                $this->credentials(),
                self::ACCESS_TOKEN,
                self::ACCOUNT_ID,
                '2026-09-01',
                '2026-09-07',
            );
            self::fail('Stránkování nad 50 000 pohybů musí být odmítnuto před dalším HTTP voláním.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::RESPONSE_TOO_LARGE, $e->errorCode);
        }
        self::assertCount(1, $history);
    }

    public function testRejectsAggregateTransactionResponsesOverThirtyTwoMebibytes(): void
    {
        $history = [];
        $queue = [];
        for ($page = 0; $page < 18; $page++) {
            $queue[] = function () use ($page): Response {
                $transaction = $this->transaction(sprintf('SYNTHETIC-LARGE-%02d', $page));
                $transaction['syntheticPadding'] = str_repeat('x', 1_900_000);
                return new Response(200, [], $this->json(
                    $this->transactionPage($page, 18, $page === 17, [$transaction]),
                ));
            };
        }
        $client = $this->client($queue, $history);

        try {
            $client->transactions(
                $this->credentials(),
                self::ACCESS_TOKEN,
                self::ACCOUNT_ID,
                '2026-09-01',
                '2026-09-07',
            );
            self::fail('Součet stránkovaných odpovědí nad 32 MiB musí být odmítnut.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::RESPONSE_TOO_LARGE, $e->errorCode);
        }
        self::assertCount(18, $history);
    }

    public function testUploadsDocumentedJsonBatchForLaterBankAuthorization(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->json(
            $this->batchResponse('ACTC', 1, 0),
        ))], $history);

        $result = $client->submitPaymentBatch(
            $this->credentials(['scope' => 'adaa bpisp']),
            self::ACCESS_TOKEN,
            $this->batch([$this->payment()]),
        );

        self::assertSame([
            'accepted' => true,
            'reference' => '000012GYPU',
            'batch_digest' => 'AAF21A859D225FD1D1889B2F08DA3E4A9AD6F7CC',
            'status' => 'accepted_awaiting_authorization',
        ], $result);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('https://api.kb.cz/directapi/batchda/v3/batchPayments', (string) $history[0]['request']->getUri());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('synthetic-batchda-key-0001', $history[0]['request']->getHeaderLine('apiKey'));
        self::assertSame('synthetic-0001', $history[0]['request']->getHeaderLine('x-exchange-identification'));
        self::assertSame('BATCH', $history[0]['request']->getHeaderLine('x-batch-processing-mode'));
        self::assertSame('Syntetická dávka', $history[0]['request']->getHeaderLine('x-instruction-name'));
        self::assertSame(
            ['batchPaymentDataCollectionRequest' => [$this->payment()]],
            json_decode((string) $history[0]['request']->getBody(), true, 64, JSON_THROW_ON_ERROR),
        );
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
    }

    public function testReportsPartiallyAcceptedBatchAsAmbiguousTerminalOutcome(): void
    {
        $client = $this->client([new Response(200, [], $this->json(
            $this->batchResponse('ACWC', 2, 1),
        ))]);

        try {
            $client->submitPaymentBatch(
                $this->credentials(),
                self::ACCESS_TOKEN,
                $this->batch([$this->payment('SYNTHETIC-001'), $this->payment('SYNTHETIC-002')]),
            );
            self::fail('Částečně přijatá dávka nesmí být vydána za úspěch.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::PAYMENT_REJECTED, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertSame(1, $e->acceptedCount);
            self::assertSame(1, $e->rejectedCount);
        }
    }

    public function testRejectsInvalidBatchBeforeAnyHttpRequest(): void
    {
        $history = [];
        $client = $this->client([], $history);

        try {
            $client->submitPaymentBatch($this->credentials(), self::ACCESS_TOKEN, $this->batch([]));
            self::fail('Prázdná dávka nesmí být odeslána.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_PAYMENT_ORDER, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
        self::assertSame([], $history);
    }

    public function testBatchTransportFailureIsAmbiguousAndIsNotRetried(): void
    {
        $history = [];
        $client = $this->client([
            new ConnectException('synthetic transport failure', new Request('POST', 'https://example.invalid')),
        ], $history);

        try {
            $client->submitPaymentBatch(
                $this->credentials(),
                self::ACCESS_TOKEN,
                $this->batch([$this->payment()]),
            );
            self::fail('Neznámý výsledek odeslání musí být terminální.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::REMOTE_UNAVAILABLE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
        }
        self::assertCount(1, $history);
    }

    /** @param list<mixed> $queue @param array<int,array<string,mixed>> $history */
    private function client(array $queue, array &$history = []): KbPlusApiClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return new KbPlusApiClient(new Client(['handler' => $stack]));
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function credentials(array $override = []): array
    {
        return array_replace([
            'oauth_api_key' => 'synthetic-api-key-0001',
            'adaa_api_key' => 'synthetic-adaa-key-0001',
            'batchda_api_key' => 'synthetic-batchda-key-0001',
            'client_id' => 'synthetic-client-001',
            'client_secret' => 'synthetic-client-secret-0001',
            'redirect_uri' => 'https://example.invalid/bank/kb-plus/callback',
            'refresh_token' => 'synthetic-refresh-token-0001',
            'scope' => 'adaa',
        ], $override);
    }

    /** @param list<array<string,mixed>> $content @return array<string,mixed> */
    private function transactionPage(int $page, int $totalPages, bool $last, array $content): array
    {
        return [
            'content' => $content,
            'totalPages' => $totalPages,
            'pageNumber' => $page,
            'pageSize' => 100,
            'numberOfElements' => count($content),
            'first' => $page === 0,
            'last' => $last,
            'empty' => $content === [],
        ];
    }

    /** @return array<string,mixed> */
    private function transaction(string $entryReference): array
    {
        return [
            'lastUpdated' => '2026-09-07T12:00:00.123Z',
            'accountType' => 'KB',
            'entryReference' => $entryReference,
            'iban' => 'CZ0401000000191000000005',
            'creditDebitIndicator' => 'CREDIT',
            'transactionType' => 'DOMESTIC',
            'amount' => ['value' => 1234.56, 'currency' => 'CZK'],
            'bookingDate' => '2026-09-07',
            'status' => 'BOOK',
            'references' => ['accountServicer' => 'SYNTHETIC-BANK-ID-001'],
        ];
    }

    /** @param list<array<string,mixed>> $payments @return array<string,mixed> */
    private function batch(array $payments): array
    {
        return [
            'exchange_identification' => 'synthetic-0001',
            'instruction_name' => 'Syntetická dávka',
            'processing_mode' => 'BATCH',
            'payments' => $payments,
        ];
    }

    /** @return array<string,mixed> */
    private function payment(string $instructionId = 'SYNTHETIC-001'): array
    {
        return [
            'PaymentIdentification' => ['instructionIdentification' => $instructionId],
            'amount' => ['instructedAmount' => ['value' => 1234.56, 'currency' => 'CZK']],
            'requestedExecutionDate' => '2026-09-08',
            'debtorAccount' => ['identification' => ['iban' => 'CZ0401000000191000000005']],
            'creditorAccount' => ['identification' => ['iban' => 'CZ6108000000191000000005']],
            'remittanceInformation' => ['unstructured' => 'Syntetická platba'],
        ];
    }

    /** @return array<string,mixed> */
    private function batchResponse(string $status, int $count, int $rejected): array
    {
        return [
            'transactionIdentification' => '000012GYPU',
            'batchDigest' => 'AAF21A859D225FD1D1889B2F08DA3E4A9AD6F7CC',
            'transactionCreditCount' => 0,
            'rejectedTransactionCreditCount' => 0,
            'transactionDebitCount' => $count,
            'rejectedTransactionDebitCount' => $rejected,
            'totalCreditAmount' => 0,
            'totalDebitAmount' => 1234.56 * $count,
            'batchProcessingMode' => 'BATCH',
            'signInfo' => ['state' => 'OPEN', 'signId' => 'SYNTHETIC-SIGN-001'],
            'instructionStatus' => $status,
            'exchangeIdentification' => 'synthetic-0001',
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
