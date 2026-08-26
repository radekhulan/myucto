<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\Isds\IsdsClientCertificate;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;

final class IsdsClientCertificateTest extends TestCase
{
    use OpensslConfigTrait;

    public function testPkcs12IsAppliedToCurlFromMemory(): void
    {
        $certificate = IsdsClientCertificate::fromBase64(
            base64_encode($this->pkcs12('synthetic-passphrase')),
            'synthetic-passphrase',
        );
        $handle = curl_init('https://example.invalid');
        self::assertInstanceOf(\CurlHandle::class, $handle);

        try {
            $certificate->applyTo($handle);
            self::assertTrue(true);
        } finally {
            $certificate->clear();
            unset($handle);
        }
    }

    public function testWrongPassphraseIsRejectedBeforeNetworkCall(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        IsdsClientCertificate::fromBase64(
            base64_encode($this->pkcs12('correct-passphrase')),
            'wrong-passphrase',
        );
    }

    public function testIsdsCertificateClientsNeverMaterializePrivateKeyOnDisk(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Service/Submission/Channel/Isds';
        $helper = file_get_contents($root . '/IsdsClientCertificate.php');
        self::assertIsString($helper);
        self::assertStringContainsString('CURLOPT_SSLCERT_BLOB', $helper);
        self::assertStringNotContainsString('file_put_contents', $helper);
        self::assertStringNotContainsString('sys_get_temp_dir', $helper);

        foreach ([
            $root . '/DirectIsdsInboxTransport.php',
            $root . '/Gateway/SoapIsdsGatewayClient.php',
        ] as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('sys_get_temp_dir', $source, $path);
            self::assertStringNotContainsString('file_put_contents', $source, $path);
        }
    }

    private function pkcs12(string $passphrase): string
    {
        $options = self::opensslConfigArgs();
        $key = openssl_pkey_new($options + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(
            ['commonName' => 'synthetic-isds.test', 'countryName' => 'CZ'],
            $key,
            $options + ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr, self::opensslErrors());
        $x509 = openssl_csr_sign($csr, null, $key, 1, $options + ['digest_alg' => 'sha256']);
        self::assertNotFalse($x509, self::opensslErrors());
        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($x509, $pkcs12, $key, $passphrase));

        return $pkcs12;
    }
}
