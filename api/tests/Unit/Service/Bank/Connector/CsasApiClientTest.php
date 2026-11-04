<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\CsasApiClient;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use PHPUnit\Framework\TestCase;

final class CsasApiClientTest extends TestCase
{
    public function testSandboxFiltersStaticTransactionsOutsideRequestedDates(): void
    {
        $rows = [['bookingDate' => ['date' => '2020-01-01'], 'status' => 'BOOK'],
            ['bookingDate' => ['date' => '2026-01-02T00:00:00Z'], 'status' => 'BOOK']];
        foreach ([false, true] as $sandbox) {
            $client = new CsasApiClient(new Client(['handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode(['pageCount' => 1, 'pageNumber' => 0, 'pageSize' => 200, 'transactions' => $rows])),
            ]))]), $sandbox);
            $result = $client->transactions($this->credentials() + ['environment' => $sandbox ? 'sandbox' : 'production'], '2026-01-01', '2026-01-31');
            self::assertSame($sandbox ? [$rows[1]] : $rows, $result);
        }
    }

    public function testDiagnosticsNeverLogCredentialsOrResponseBody(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('csas_http_response', [
            'environment' => 'sandbox', 'operation' => 'token', 'http_status' => 400,
        ]);
        $client = new CsasApiClient(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(400, [], '{"error_description":"synthetic-sensitive-response"}'),
        ]))]), true, $logger);
        $credentials = $this->credentials() + ['environment' => 'sandbox'];
        $this->expectException(BankConnectorException::class);
        $client->exchange($credentials, 'synthetic-code');
    }

    private function credentials(): array
    {
        return ['client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'api_key' => 'synthetic-key',
            'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'account_id' => 'synthetic-account',
            'redirect_uri' => 'https://example.invalid/callback', 'code_verifier' => str_repeat('a', 64)];
    }

    private function client(array $responses, array &$history): CsasApiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new CsasApiClient(new Client(['handler' => $stack]));
    }

    public function testCodeFlowUsesPkceAndRequestsAccountsWithPaymentsUnlessDeclined(): void
    {
        $history = [];
        $readOnly = $this->client([], $history)->authorizationUrl($this->credentials(), str_repeat('b', 64), false);
        parse_str(parse_url($readOnly, PHP_URL_QUERY), $readOnlyQuery);
        self::assertSame('siblings.accounts', $readOnlyQuery['scope']);
        $url = $this->client([], $history)->authorizationUrl($this->credentials(), str_repeat('b', 64), true);
        self::assertStringStartsWith('https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('siblings.accounts siblings.payments', $query['scope']);
        self::assertSame('offline', $query['access_type']);
        // Nové přihlášení i při živé relaci banky: jen tak George Business nabídne výběr
        // pracovního prostoru (firmy) a souhlas se nevydá za výchozí firmu.
        self::assertSame('login consent', $query['prompt']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='), $query['code_challenge']);
        self::assertStringNotContainsString('synthetic-secret', $url);
        self::assertStringNotContainsString('synthetic-key', $url);
    }

    public function testTokenRequestNeverSendsApiKeyAndUsesVerifiedTls(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['access_token' => 'synthetic-new-access',
            'refresh_token' => 'synthetic-new-refresh', 'expires_in' => 300, 'token_type' => 'Bearer']))], $history);
        self::assertSame('synthetic-new-access', $client->exchange($this->credentials(), 'synthetic-code')['access_token']);
        self::assertSame('https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1/token', (string) $history[0]['request']->getUri());
        self::assertFalse($history[0]['request']->hasHeader('WEB-API-key'));
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertSame(str_repeat('a', 64), $form['code_verifier']);
        self::assertSame('synthetic-secret', $form['client_secret']);
    }

    public function testReadsAllPagesAndEncodesAccountId(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode(['pageNumber' => 0, 'pageCount' => 2, 'pageSize' => 200, 'nextPage' => 1, 'transactions' => [['entryReference' => 'synthetic-1']]])),
            new Response(200, [], json_encode(['pageNumber' => 1, 'pageCount' => 2, 'pageSize' => 200, 'transactions' => [['entryReference' => 'synthetic-2']]])),
        ], $history);
        $credentials = $this->credentials();
        $credentials['account_id'] = 'synthetic/account';
        self::assertCount(2, $client->transactions($credentials, '2026-01-01', '2026-01-31'));
        self::assertCount(2, $history);
        self::assertStringContainsString('synthetic%2Faccount/transactions', (string) $history[0]['request']->getUri());
        self::assertSame('synthetic-key', $history[0]['request']->getHeaderLine('WEB-API-key'));
        self::assertSame('Bearer synthetic-access', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testEmptyPeriodWithZeroPageSizeReturnsNoTransactions(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['pageNumber' => 0, 'pageCount' => 0, 'pageSize' => 0, 'transactions' => []]))], $history);
        self::assertSame([], $client->transactions($this->credentials(), '2026-01-01', '2026-01-03'));
    }

    /** Chunkovaná odpověď může před koncem streamu vrátit prázdné čtení; to není vadná odpověď. */
    public function testToleratesTransientEmptyReadsBeforeEndOfStream(): void
    {
        $inner = \GuzzleHttp\Psr7\Utils::streamFor(json_encode(['pageNumber' => 0, 'pageCount' => 0, 'pageSize' => 0, 'transactions' => []]));
        $empty = true;
        $stream = \GuzzleHttp\Psr7\FnStream::decorate($inner, [
            'read' => static function (int $length) use ($inner, &$empty): string {
                $empty = !$empty;
                return $empty ? '' : $inner->read(min($length, 10));
            },
        ]);
        $history = [];
        $client = $this->client([new Response(200, ['Transfer-Encoding' => 'chunked'], $stream)], $history);
        self::assertSame([], $client->transactions($this->credentials(), '2026-01-01', '2026-01-03'));
    }

    public function testStalledStreamIsInvalidResponse(): void
    {
        $inner = \GuzzleHttp\Psr7\Utils::streamFor('{"pageNumber":0');
        $stream = \GuzzleHttp\Psr7\FnStream::decorate($inner, [
            'read' => static fn (int $length): string => '',
            'eof' => static fn (): bool => false,
        ]);
        $history = [];
        $client = $this->client([new Response(200, [], $stream)], $history);
        $this->expectException(BankConnectorException::class);
        $client->transactions($this->credentials(), '2026-01-01', '2026-01-03');
    }

    public function testRejectsZeroPageSizeWithNonEmptyPagination(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['pageNumber' => 0, 'pageCount' => 1, 'pageSize' => 0, 'transactions' => []]))], $history);
        $this->expectException(BankConnectorException::class);
        $client->transactions($this->credentials(), '2026-01-01', '2026-01-31');
    }

    public function testRejectsIncompletePaginationInsteadOfPartialImport(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['pageNumber' => 0, 'pageCount' => 51, 'pageSize' => 200, 'transactions' => []]))], $history);
        $this->expectException(BankConnectorException::class);
        $client->transactions($this->credentials(), '2026-01-01', '2026-01-31');
    }

    public function testSandboxUsesSandboxAccountsTokenAndAuthorizationEndpoints(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 300, 'token_type' => 'Bearer'])),
            new Response(200, [], json_encode(['pageNumber' => 0, 'pageCount' => 0, 'pageSize' => 200, 'accounts' => []])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new CsasApiClient(new Client(['handler' => $stack]), sandbox: true);
        $credentials = $this->credentials() + ['environment' => 'sandbox'];
        self::assertStringStartsWith('https://webapi.developers.erstegroup.com/api/csas/sandbox/v1/sandbox-idp/auth?', $client->authorizationUrl($credentials, str_repeat('b', 64)));
        $client->exchange($credentials, 'synthetic-code');
        $client->accounts($credentials);
        self::assertSame('https://webapi.developers.erstegroup.com/api/csas/sandbox/v1/sandbox-idp/token', (string) $history[0]['request']->getUri());
        self::assertSame('https://webapi.developers.erstegroup.com/api/csas/public/sandbox/v3/accounts/my/accounts', explode('?', (string) $history[1]['request']->getUri())[0]);
        self::assertSame('https://webapi.developers.erstegroup.com/api/csas/public/sandbox/v1/payments', $client->paymentsBaseUrl());
        self::assertFalse($history[0]['request']->hasHeader('WEB-API-key'));
        self::assertTrue($history[1]['options']['verify']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('environmentPairs')]
    public function testChangingEnvironmentRejectsStoredCredentialsBeforeAnyHttpCall(bool $sandbox, ?string $environment): void
    {
        $client = new CsasApiClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]), $sandbox);
        $credentials = $this->credentials();
        if ($environment !== null) $credentials['environment'] = $environment;
        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('Změnilo se prostředí');
        $client->refresh($credentials);
    }

    public static function environmentPairs(): array
    {
        return [[true, 'production'], [false, 'sandbox'], [true, null]];
    }

    public function testTokenExchangeReturnsGrantedScope(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'expires_in' => 300, 'token_type' => 'Bearer', 'scope' => 'siblings.accounts  siblings.payments']))], $history);
        self::assertSame('siblings.accounts siblings.payments', $client->exchange($this->credentials(), 'synthetic-code')['scope']);
        self::assertTrue(CsasApiClient::grantsPayments('siblings.accounts siblings.payments'));
        self::assertFalse(CsasApiClient::grantsPayments('siblings.accounts'));
        self::assertFalse(CsasApiClient::grantsPayments(''));
    }

    public function testCreatePaymentSendsBodyWithApiKeyAndReturnsSignInfo(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['instructionStatus' => 'ACTC',
            'signInfo' => ['state' => 'OPEN', 'signId' => '166609107206666', 'hash' => 'synthetic-hash']]))], $history);
        $payment = ['paymentIdentification' => ['instructionIdentification' => 'MU001synthetic'], 'amount' => ['instructedAmount' => ['value' => 1.5, 'currency' => 'CZK']]];

        self::assertSame(['sign_id' => '166609107206666', 'hash' => 'synthetic-hash', 'instruction_status' => 'ACTC'],
            $client->createPayment($this->credentials(), $payment));
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://www.csas.cz/webapi/api/v1/payments/my/payments', (string) $request->getUri());
        self::assertSame('synthetic-key', $request->getHeaderLine('WEB-API-key'));
        self::assertSame('Bearer synthetic-access', $request->getHeaderLine('Authorization'));
        self::assertSame($payment, json_decode((string) $request->getBody(), true));
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ambiguousPaymentResponses')]
    public function testPaymentWithoutClearAnswerIsAmbiguousAndNeverRetried(\Closure $response): void
    {
        $history = [];
        $success = new Response(200, [], json_encode(['instructionStatus' => 'ACTC', 'signInfo' => ['state' => 'OPEN', 'signId' => '1', 'hash' => 'h']]));
        $client = $this->client([$response(), $success], $history);
        try {
            $client->createPayment($this->credentials(), ['synthetic' => true]);
            self::fail('Nejasný výsledek musí skončit výjimkou.');
        } catch (BankConnectorException $e) {
            self::assertTrue($e->ambiguousPaymentOutcome);
        }
        self::assertCount(1, $history, 'Zakládání platby se nikdy neopakuje.');
    }

    public static function ambiguousPaymentResponses(): iterable
    {
        yield 'výpadek spojení' => [static fn () => new \GuzzleHttp\Exception\ConnectException('synthetic',
            new \GuzzleHttp\Psr7\Request('POST', 'https://www.csas.cz/webapi/api/v1/payments/my/payments'))];
        yield 'chyba serveru' => [static fn () => new Response(503, [], '')];
        yield 'nečitelná odpověď' => [static fn () => new Response(200, [], 'synthetic-not-json')];
        yield 'odpověď bez signInfo' => [static fn () => new Response(200, [], json_encode(['instructionStatus' => 'ACTC']))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentedRejections')]
    public function testDocumentedRejectionIsDefiniteWithOwnCode(string $remote, string $expected): void
    {
        $history = [];
        $client = $this->client([new Response(400, [], json_encode(['status' => 400, 'errors' => [['error' => $remote]]]))], $history);
        try {
            $client->createPayment($this->credentials(), ['synthetic' => true]);
            self::fail('Odmítnutí musí skončit výjimkou.');
        } catch (BankConnectorException $e) {
            self::assertSame($expected, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
            self::assertSame(400, $e->remoteHttpStatus);
        }
    }

    public static function documentedRejections(): iterable
    {
        yield ['INVALID_DATE_IN_PAST', 'payment_execution_date_too_early'];
        yield ['ACC_DIFFERENT', 'payment_order_account_mismatch'];
        yield ['INVALID_DOMESTIC_PAYMENT_CURRENCY', 'payment_order_not_domestic'];
        yield ['BATCH_PAYMENT_CONTAINS_DUPLICITIES', 'payment_order_duplicate_items'];
        yield ['SOMETHING_ELSE', BankConnectorException::PAYMENT_REJECTED];
    }

    public function testBatchReferencesOnlySignIdsOfCreatedPayments(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], json_encode(['transactionIdentification' => '048885570000001020045',
            'instructionStatus' => 'ACTC', 'signInfo' => ['state' => 'OPEN', 'signId' => '164298331754922', 'hash' => 'synthetic-batch-hash']]))], $history);

        self::assertSame(['sign_id' => '164298331754922', 'hash' => 'synthetic-batch-hash'],
            $client->createBatch($this->credentials(), 'MUabcdef12345678', ['111', '222']));
        self::assertSame('https://www.csas.cz/webapi/api/v1/payments/my/batchpayments', (string) $history[0]['request']->getUri());
        self::assertSame(['exchangeIdentification' => 'MUabcdef12345678', 'payments' => [['signId' => '111'], ['signId' => '222']]],
            json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testFederatedSignSendsCallbackUriAndAcceptsOnlyBankSigningUrl(): void
    {
        $response = static fn (string $url): Response => new Response(200, [], json_encode(['validTo' => '2026-10-22T12:00:00Z',
            'signingUrl' => $url, 'poll' => ['url' => 'https://www.csas.cz/poll', 'id' => '160527036796442', 'interval' => 5000],
            'signInfo' => ['state' => 'OPEN', 'signId' => '151112531008554']]));
        $history = [];
        $client = $this->client([$response('https://george.csas.cz/sign?x=1'), $response('https://evil.example/sign')], $history);
        $callback = 'https://ucto.example.test/api/oauth/callback?state=' . str_repeat('c', 64);

        $sign = $client->federatedSign($this->credentials(), '164298331754922', 'synthetic-batch-hash', $callback);
        self::assertSame(['signing_url' => 'https://george.csas.cz/sign?x=1', 'valid_to' => '2026-10-22T12:00:00Z',
            'poll_id' => '160527036796442', 'poll_interval_ms' => 5000], $sign);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('https://www.csas.cz/webapi/api/v1/payments/my/batchpayments/federate/sign/164298331754922/hash/synthetic-batch-hash',
            (string) $history[0]['request']->getUri());
        self::assertSame($callback, $history[0]['request']->getHeaderLine('Callback-Uri'));
        $this->expectException(BankConnectorException::class);
        $client->federatedSign($this->credentials(), '164298331754922', 'synthetic-batch-hash', $callback);
    }

    public function testPollAndPaymentStatusAcceptOnlyDocumentedStates(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode(['state' => 'DONE', 'pollInterval' => 5000])),
            new Response(200, [], json_encode(['instructionStatus' => 'ACSC', 'statusChangeInfo' => 'INSUFFICIENT_FUNDS'])),
            new Response(200, [], json_encode(['state' => 'SOMETHING'])),
        ], $history);
        self::assertSame(['state' => 'DONE', 'poll_interval_ms' => 5000], $client->pollSign($this->credentials(), '160527036796442'));
        self::assertSame('https://www.csas.cz/webapi/api/v1/payments/my/batchpayments/sign/poll/160527036796442', (string) $history[0]['request']->getUri());
        self::assertSame(['instruction_status' => 'ACSC', 'status_change_info' => 'INSUFFICIENT_FUNDS'], $client->paymentStatus($this->credentials(), '111'));
        self::assertSame('https://www.csas.cz/webapi/api/v1/payments/my/payments/111/status', (string) $history[1]['request']->getUri());
        $this->expectException(BankConnectorException::class);
        $client->pollSign($this->credentials(), '160527036796442');
    }

    public function testRedirectAndRemoteBodyAreNotExposed(): void
    {
        $history = [];
        $client = $this->client([new Response(302, ['Location' => 'https://example.invalid'], 'synthetic-sensitive-body')], $history);
        try {
            $client->accounts($this->credentials());
            self::fail('Expected exception');
        } catch (BankConnectorException $e) {
            self::assertSame(302, $e->remoteHttpStatus);
            self::assertStringNotContainsString('synthetic-sensitive', $e->getMessage());
            self::assertCount(1, $history);
        }
    }
}
