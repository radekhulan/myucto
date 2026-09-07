<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request;
use MyInvoice\Service\Bank\Connector\BankCertificateCurlFactory;
use PHPUnit\Framework\TestCase;

final class BankCertificateCurlFactoryTest extends TestCase
{
    public function testExplicitTokenOnlyTransportCreatesHandleWithoutCertificateOrNetworkIo(): void
    {
        $options = $this->options();
        $options['curl'] = [];
        $factory = new BankCertificateCurlFactory(allowTokenOnly: true);
        $easy = $factory->create($this->request(), $options);
        self::assertInstanceOf(\CurlHandle::class, $easy->handle);
        $factory->release($easy);
    }

    public function testCertificateIsStillRequiredByDefault(): void
    {
        $options = $this->options();
        $options['curl'] = [];
        $this->expectException(InvalidArgumentException::class);
        (new BankCertificateCurlFactory())->create($this->request(), $options);
    }
    public function testStockGuzzleFactoryRejectsCertificateBlobOptionsBeforeNetworkIo(): void
    {
        $factory = new CurlFactory(0);
        $this->expectException(InvalidArgumentException::class);
        $factory->create($this->request(), $this->options());
    }

    public function testWrapperAppliesCertificateOptionsAfterGuzzleCreateAndDoesNotRetainCredentials(): void
    {
        $factory = new BankCertificateCurlFactory();
        $easy = $factory->create($this->request(), $this->options());

        self::assertInstanceOf(\CurlHandle::class, $easy->handle);
        self::assertSame([], $easy->options['curl']);
        self::assertSame('https', $easy->request->getUri()->getScheme());

        $factory->release($easy);
        self::assertFalse(isset($easy->handle));
    }

    public function testWrapperStripsExplicitTlsMinimumAndKeepsOtherValidationWithGuzzle(): void
    {
        $options = $this->options();
        $options['curl'][CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        $factory = new BankCertificateCurlFactory();
        $easy = $factory->create($this->request(), $options);
        self::assertSame([], $easy->options['curl']);
        $factory->release($easy);
    }

    /** @return iterable<string,array{string,bool,bool}> */
    public static function insecureTransportProvider(): iterable
    {
        yield 'plain HTTP' => ['http', true, false];
        yield 'disabled verification' => ['https', false, false];
        yield 'enabled redirects' => ['https', true, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('insecureTransportProvider')]
    public function testWrapperRejectsInsecureTransportBeforeCreatingHandle(string $scheme, bool $verify, bool $redirects): void
    {
        $options = $this->options();
        $options['verify'] = $verify;
        $options['allow_redirects'] = $redirects;
        $this->expectException(InvalidArgumentException::class);
        (new BankCertificateCurlFactory())->create(new Request('GET', $scheme . '://api.example.invalid/test'), $options);
    }

    public function testWrapperLeavesUnrelatedCurlOptionsForGuzzleToReject(): void
    {
        $options = $this->options();
        $options['curl'][CURLOPT_URL] = 'https://attacker.example.invalid/';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicts with Guzzle-managed');
        (new BankCertificateCurlFactory())->create($this->request(), $options);
    }

    public function testWrapperRejectsNonPemOrIncompleteCertificateOptionsWithoutLeakingValue(): void
    {
        $secret = 'synthetic-private-key-marker';
        $options = $this->options();
        $options['curl'][CURLOPT_SSLKEY_BLOB] = $secret;
        $options['curl'][CURLOPT_SSLKEYTYPE] = 'DER';
        try {
            (new BankCertificateCurlFactory())->create($this->request(), $options);
            self::fail('Non-PEM option should be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.example.invalid/test');
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return [
            'verify' => true,
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_SSLCERT_BLOB => "-----BEGIN CERTIFICATE-----\nsynthetic\n-----END CERTIFICATE-----\n",
                CURLOPT_SSLKEY_BLOB => "-----BEGIN PRIVATE KEY-----\nsynthetic\n-----END PRIVATE KEY-----\n",
                CURLOPT_SSLCERTTYPE => 'PEM',
                CURLOPT_SSLKEYTYPE => 'PEM',
            ],
        ];
    }
}
