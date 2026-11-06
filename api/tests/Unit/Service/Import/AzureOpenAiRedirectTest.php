<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Tests\Support\FakeAiProviderHttpClientFactory;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Azure endpoint zadává admin firmy a `*.azure-api.net` si může postavit kdokoli
 * (APIM s redirect politikou). Klient smí následovat jen jedno přesměrování
 * v rámci téhož hostu: 30x na jiný host by poslalo klíč i tělo dotazu do
 * interní sítě provozovatele a odpověď vrátilo zpátky volajícímu.
 */
final class AzureOpenAiRedirectTest extends TestCase
{
    public function testAzureClientDoesNotFollowRedirectToInternalHost(): void
    {
        $sent = [];
        $client = $this->azureClient('https://tenant.azure-api.net', [
            self::reply($sent, new Response(302, ['Location' => 'https://canary.internal.test/canary-200'])),
            self::reply($sent, new Response(200, [], (string) json_encode(['model' => 'INTERNAL-BODY']))),
        ]);

        $result = $client->testConnection(1);

        self::assertFalse($result['ok']);
        self::assertSame(['tenant.azure-api.net'], array_map(static fn (RequestInterface $r) => $r->getUri()->getHost(), $sent),
            'Na cíl přesměrování nesmí odejít žádný požadavek.');
    }

    public function testAzureClientDoesNotFollowRedirectToPlainHttp(): void
    {
        $sent = [];
        $client = $this->azureClient('https://tenant.azure-api.net', [
            self::reply($sent, new Response(302, ['Location' => 'http://tenant.azure-api.net/openai/x'])),
            self::reply($sent, new Response(200, [], (string) json_encode(['model' => 'PLAIN']))),
        ]);

        self::assertFalse($client->testConnection(1)['ok']);
        self::assertCount(1, $sent);
    }

    public function testAzureClientFollowsOneRedirectWithinSameHost(): void
    {
        $sent = [];
        $client = $this->azureClient('https://tenant.azure-api.net', [
            self::reply($sent, new Response(307, ['Location' => 'https://tenant.azure-api.net/v2/openai/deployments/gpt-5/chat/completions'])),
            self::reply($sent, new Response(200, [], (string) json_encode(['model' => 'gpt-5']))),
        ]);

        $result = $client->testConnection(1);

        self::assertTrue($result['ok']);
        self::assertCount(2, $sent);
        self::assertSame('/v2/openai/deployments/gpt-5/chat/completions', $sent[1]->getUri()->getPath());
        self::assertSame('synthetic-azure-key', $sent[1]->getHeaderLine('api-key'));
        self::assertSame('POST', $sent[1]->getMethod());
    }

    public function testAzureClientRefusesSecondRedirect(): void
    {
        $sent = [];
        $client = $this->azureClient('https://tenant.azure-api.net', [
            self::reply($sent, new Response(307, ['Location' => 'https://tenant.azure-api.net/a'])),
            self::reply($sent, new Response(307, ['Location' => 'https://tenant.azure-api.net/b'])),
            self::reply($sent, new Response(200, [], (string) json_encode(['model' => 'gpt-5']))),
        ]);

        self::assertFalse($client->testConnection(1)['ok']);
        self::assertCount(2, $sent);
    }

    public function testStoredEndpointOutsideAzureIsRefusedAtRequestTime(): void
    {
        $sent = [];
        $client = $this->azureClient('https://169.254.169.254', [self::reply($sent, new Response(200, [], '{}'))]);

        $result = $client->testConnection(1);

        self::assertFalse($result['ok']);
        self::assertCount(0, $sent);
    }

    public function testEndpointRuleIsShared(): void
    {
        self::assertNull(AzureOpenAiClient::endpointError('https://x.openai.azure.com'));
        self::assertNotNull(AzureOpenAiClient::endpointError('http://x.openai.azure.com'));
        self::assertNotNull(AzureOpenAiClient::endpointError('https://x.azure-api.net.attacker.test'));
    }

    public function testAiProviderHttpClientDoesNotFollowRedirectToForeignHost(): void
    {
        $sent = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'openai', [
            self::reply($sent, new Response(307, ['Location' => 'https://canary.internal.test/post'])),
            self::reply($sent, new Response(200, [], '{}')),
        ]);

        $result = $client->completeJson(1, 'system', 'user', ['type' => 'object']);

        self::assertFalse($result['ok']);
        self::assertCount(1, $sent);
    }

    public function testAiProviderHttpClientFollowsOneRedirectWithinSameHost(): void
    {
        $sent = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'openai', [
            self::reply($sent, new Response(308, ['Location' => 'https://api.openai.test/proxy/v1/chat/completions'])),
            self::reply($sent, new Response(500, [], '{}')),
        ]);

        $client->completeJson(1, 'system', 'user', ['type' => 'object']);

        self::assertCount(2, $sent);
        self::assertSame('/proxy/v1/chat/completions', $sent[1]->getUri()->getPath());
    }

    /**
     * @param list<RequestInterface> $sent
     * @return \Closure(RequestInterface): Response
     */
    private static function reply(array &$sent, Response $response): \Closure
    {
        return static function (RequestInterface $request) use (&$sent, $response): Response {
            $sent[] = $request;
            return $response;
        };
    }

    /**
     * @param list<\Closure> $queue
     */
    private function azureClient(string $endpoint, array $queue): AzureOpenAiClient
    {
        // Klient bez explicitního allow_redirects = výchozí chování Guzzle (následuje).
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($queue)), 'http_errors' => false]);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($endpoint) {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(str_contains($sql, 'azure_openai_api_key_enc') ? [
                'azure_openai_api_key_enc' => 'ENC',
                'azure_openai_endpoint' => $endpoint,
                'azure_openai_deployment' => 'gpt-5',
                'azure_openai_api_version' => '2024-10-21',
            ] : []);
            return $stmt;
        });
        $conn = $this->createStub(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('synthetic-azure-key');

        return new AzureOpenAiClient($conn, $crypto, new NullLogger(), $http);
    }
}
