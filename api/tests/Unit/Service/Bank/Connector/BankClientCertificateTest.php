<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\BankClientCertificate;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MyInvoice\Tests\Support\OpensslConfigTrait;

final class BankClientCertificateTest extends TestCase
{
    use OpensslConfigTrait;
    public function testInvalidPkcs12IsRejectedWithoutExposingPassword(): void
    {
        try {
            BankClientCertificate::curlOptions(base64_encode('synthetic-invalid-certificate'), 'synthetic-password');
            self::fail('Invalid certificate accepted.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('certificate_invalid', $e->errorCode);
            self::assertStringNotContainsString('synthetic-password', $e->getMessage());
        }
    }

    public static function passwords(): array
    {
        return [['synthetic-password'], ['']];
    }

    #[DataProvider('passwords')]
    public function testValidCertificateAndKeyAreKeptInMemoryOnly(string $password): void
    {
        $options = ['private_key_bits' => 2048] + self::opensslConfigArgs();
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'synthetic.example.invalid'], $key, $options);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($cert);
        self::assertTrue(openssl_pkcs12_export($cert, $p12, $key, $password));
        $curl = BankClientCertificate::curlOptions(base64_encode($p12), $password);
        self::assertSame('PEM', $curl[CURLOPT_SSLCERTTYPE]);
        self::assertStringContainsString('BEGIN CERTIFICATE', $curl[CURLOPT_SSLCERT_BLOB]);
        self::assertStringContainsString('PRIVATE KEY', $curl[CURLOPT_SSLKEY_BLOB]);
        self::assertArrayNotHasKey(CURLOPT_SSLCERT, $curl);
        self::assertArrayNotHasKey(CURLOPT_SSLKEY, $curl);
    }
}
