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

    public function testCodeFlowUsesPkceAndOnlyReadScope(): void
    {
        $history = [];
        $url = $this->client([], $history)->authorizationUrl($this->credentials(), str_repeat('b', 64));
        self::assertStringStartsWith('https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('siblings.accounts', $query['scope']);
        self::assertSame('offline', $query['access_type']);
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
