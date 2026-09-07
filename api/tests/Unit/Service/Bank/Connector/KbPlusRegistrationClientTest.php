<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusRegistrationClient;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class KbPlusRegistrationClientTest extends TestCase
{
    use OpensslConfigTrait;

    public function testRequestsBankSignedSoftwareStatementWithMutualTls(): void
    {
        $history = [];
        $statement = $this->jwt(['alg' => 'HS256'], ['software_id' => 'synthetic-software-001']);
        $client = $this->client([new Response(201, ['Content-Type' => 'text/plain'], $statement)], $history);

        self::assertSame($statement, $client->createSoftwareStatement(
            $this->credentials(),
            $this->metadata(),
        ));

        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame(
            'https://client-registration.api-gateway.kb.cz/v3/software-statements',
            (string) $history[0]['request']->getUri(),
        );
        self::assertSame('synthetic-api-key-0001', $history[0]['request']->getHeaderLine('apiKey'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/D', $history[0]['request']->getHeaderLine('x-correlation-id'));
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertTrue($history[0]['options']['stream']);
        self::assertInstanceOf(StreamInterface::class, $history[0]['options']['sink']);
        self::assertLessThanOrEqual(64 * 1024, $history[0]['options']['sink']->getSize());
        self::assertArrayHasKey(CURLOPT_SSLCERT_BLOB, $history[0]['options']['curl']);
        self::assertArrayHasKey(CURLOPT_SSLKEY_BLOB, $history[0]['options']['curl']);
        self::assertArrayNotHasKey(CURLOPT_SSLCERT, $history[0]['options']['curl']);
        self::assertArrayNotHasKey(CURLOPT_SSLKEY, $history[0]['options']['curl']);

        $body = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('client_secret_post', $body['tokenEndpointAuthMethod']);
        self::assertSame(['authorization_code', 'refresh_token'], $body['grantTypes']);
        self::assertSame(['code'], $body['responseTypes']);
        self::assertSame($this->metadata()['redirectUris'], $body['redirectUris']);
    }

    public function testRejectsUnsignedOrNoneAlgorithmStatement(): void
    {
        foreach (['not-a-jwt', $this->jwt(['alg' => 'none'], ['synthetic' => true])] as $body) {
            $client = $this->client([new Response(201, [], $body)]);
            try {
                $client->createSoftwareStatement($this->credentials(), $this->metadata());
                self::fail('Neplatný software statement nesmí být přijat.');
            } catch (BankConnectorException $e) {
                self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            }
        }
    }

    public function testRejectsInvalidMetadataBeforeHttp(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $metadata = $this->metadata();
        $metadata['registrationBackUri'] = 'http://example.invalid/insecure';

        $this->expectException(BankConnectorException::class);
        try {
            $client->createSoftwareStatement($this->credentials(), $metadata);
        } finally {
            self::assertSame([], $history);
        }
    }

    public function testSanitizesRejectedRegistrationResponse(): void
    {
        $privateBody = 'synthetic-private-registration-error';
        $client = $this->client([new Response(422, [], $privateBody)]);

        try {
            $client->createSoftwareStatement($this->credentials(), $this->metadata());
            self::fail('Odmítnutá registrace musí vyhodit výjimku.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::REMOTE_HTTP_ERROR, $e->errorCode);
            self::assertSame(422, $e->remoteHttpStatus);
            self::assertStringNotContainsString($privateBody, $e->getMessage());
            self::assertStringNotContainsString('synthetic-api-key', $e->getMessage());
            self::assertStringNotContainsString('synthetic-password', $e->getMessage());
        }
    }

    public function testBoundedSinkRejectsOversizedResponseWithoutDiskBuffering(): void
    {
        $history = [];
        $privateBody = str_repeat('sensitive-bank-response-', 4096);
        $client = $this->client([new Response(201, [], $privateBody)], $history);

        try {
            $client->createSoftwareStatement($this->credentials(), $this->metadata());
            self::fail('Nadměrná odpověď se nesmí načíst bez pevného limitu.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::RESPONSE_TOO_LARGE, $e->errorCode);
            self::assertStringNotContainsString('sensitive-bank-response', $e->getMessage());
        }

        self::assertCount(1, $history);
        self::assertInstanceOf(StreamInterface::class, $history[0]['options']['sink']);
        self::assertLessThanOrEqual(64 * 1024, $history[0]['options']['sink']->getSize());
    }

    /** @param list<mixed> $queue @param array<int,array<string,mixed>> $history */
    private function client(array $queue, array &$history = []): KbPlusRegistrationClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        return new KbPlusRegistrationClient(new Client(['handler' => $stack]));
    }

    /** @return array<string,mixed> */
    private function credentials(): array
    {
        $options = ['private_key_bits' => 2048] + self::opensslConfigArgs();
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new([
            'commonName' => 'synthetic.example.invalid',
            'organizationName' => 'Synthetic Company s.r.o.',
        ], $key, $options);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($cert);
        self::assertTrue(openssl_pkcs12_export($cert, $p12, $key, 'synthetic-password'));
        return [
            'client_registration_api_key' => 'synthetic-api-key-0001',
            'certificate_p12' => base64_encode($p12),
            'certificate_password' => 'synthetic-password',
        ];
    }

    /** @return array<string,mixed> */
    private function metadata(): array
    {
        return [
            'softwareName' => 'Syntetické MyÚčto',
            'softwareNameEn' => 'Synthetic MyUcto',
            'softwareId' => 'synthetic-software-001',
            'softwareVersion' => '1.0',
            'softwareUri' => 'https://example.invalid',
            'redirectUris' => ['https://example.invalid/api/bank/kb-plus/oauth/callback'],
            'registrationBackUri' => 'https://example.invalid/api/bank/kb-plus/registration/callback',
            'contacts' => ['email: bank-api@example.invalid'],
            'logoUri' => 'https://example.invalid/logo.png',
            'tosUri' => 'https://example.invalid/terms',
            'policyUri' => 'https://example.invalid/privacy',
        ];
    }

    /** @param array<string,mixed> $header @param array<string,mixed> $payload */
    private function jwt(array $header, array $payload): string
    {
        return $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR)) . '.'
            . $this->base64Url('synthetic-signature');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
