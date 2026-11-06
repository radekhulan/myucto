<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetCredentialException;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetPkcs12Reader;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;
use MyInvoice\Service\Eet\Transport\Rc2Cipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EetSigningCredentialTest extends TestCase
{
    public function testPkcs12ExposesMetadataButNeverTheKey(): void
    {
        $credential = EetSigningCredential::fromPkcs12(EetTestPki::pkcs12(), EetTestPki::PASSWORD);

        self::assertSame('CZ00000019', $credential->eic());
        self::assertSame(EetEnvironment::Playground, $credential->environmentHint());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $credential->fingerprintSha256());
        self::assertSame('03', $credential->serialNumber());
        self::assertTrue($credential->notAfter() > $credential->notBefore());
        self::assertStringContainsString('BEGIN CERTIFICATE', $credential->certificatePem());

        $dump = print_r($credential, true);
        self::assertStringNotContainsString('PRIVATE', $dump);
        self::assertStringContainsString('***', $dump);
    }

    public function testCredentialCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(EetTestPki::credential());
    }

    public function testWrongPasswordIsReportedAsSuch(): void
    {
        try {
            EetSigningCredential::fromPkcs12(EetTestPki::pkcs12(), 'Wrong-password-1');
            self::fail('Špatné heslo prošlo.');
        } catch (EetCredentialException $exception) {
            self::assertSame('invalid_password', $exception->reason);
            self::assertStringNotContainsString('Wrong-password-1', $exception->getMessage());
        }
    }

    public function testGarbageIsUnreadable(): void
    {
        try {
            EetSigningCredential::fromPkcs12('not a pkcs12 file', 'x');
            self::fail('Nesmyslný soubor prošel.');
        } catch (EetCredentialException $exception) {
            self::assertSame('unreadable', $exception->reason);
        }
    }

    public function testMissingOrForeignKeyIsRejected(): void
    {
        $certificate = EetTestPki::cashier()['cert'];
        try {
            EetSigningCredential::fromPem($certificate, 'not a key');
            self::fail('Chybějící klíč prošel.');
        } catch (EetCredentialException $exception) {
            self::assertSame('private_key_missing', $exception->reason);
        }
        $foreign = '';
        openssl_pkey_export(EetTestPki::ca()['key'], $foreign, null, EetTestPki::configArgs());
        try {
            EetSigningCredential::fromPem($certificate, $foreign);
            self::fail('Cizí klíč prošel.');
        } catch (EetCredentialException $exception) {
            self::assertSame('key_mismatch', $exception->reason);
        }
    }

    public function testEnvironmentHintFollowsCertificatePolicy(): void
    {
        self::assertSame(EetEnvironment::Production, EetTestPki::credential('CZ00000019', 'v3_cashier_prod')->environmentHint());
        self::assertNull(EetTestPki::credential('CZ00000019', 'v3_plain')->environmentHint());
    }

    /**
     * Záložní čtečka (bez OpenSSL legacy provideru) musí dát tentýž certifikát a klíč
     * jako OpenSSL — tady nad moderním PKCS#12 (PBES2/AES), RC2 pokrývá test níže.
     */
    public function testPurePhpDecoderMatchesOpenssl(): void
    {
        $pkcs12 = EetTestPki::pkcs12();
        $decoded = EetPkcs12Reader::decode($pkcs12, EetTestPki::PASSWORD);
        $native = [];
        self::assertTrue(openssl_pkcs12_read($pkcs12, $native, EetTestPki::PASSWORD));

        self::assertSame(openssl_x509_fingerprint($native['cert'], 'sha256'), openssl_x509_fingerprint($decoded['cert'], 'sha256'));
        self::assertTrue(openssl_x509_check_private_key($native['cert'], $decoded['pkey']));
        self::assertCount(1, $decoded['extracerts']);

        try {
            EetPkcs12Reader::decode($pkcs12, 'wrong');
            self::fail('Špatné heslo prošlo záložní čtečkou.');
        } catch (EetCredentialException $exception) {
            self::assertSame('invalid_password', $exception->reason);
        }
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function rc2Vectors(): iterable
    {
        // RFC 2268, kap. 5 (klíč, efektivní bity, otevřený text, šifrový text)
        yield 'vektor 1' => ['0000000000000000', 63, '0000000000000000', 'ebb773f993278eff'];
        yield 'vektor 2' => ['ffffffffffffffff', 64, 'ffffffffffffffff', '278b27e42e2f0d49'];
        yield 'vektor 3' => ['3000000000000000', 64, '1000000000000001', '30649edf9be7d2c2'];
        yield 'vektor 4' => ['88', 64, '0000000000000000', '61a8a244adacccf0'];
        yield 'vektor 5' => ['88bca90e90875a', 64, '0000000000000000', '6ccf4308974c267f'];
        yield 'vektor 6' => ['88bca90e90875a7f0f79c384627bafb2', 64, '0000000000000000', '1a807d272bbe5db1'];
        yield 'vektor 7' => ['88bca90e90875a7f0f79c384627bafb2', 128, '0000000000000000', '2269552ab0f85ca6'];
    }

    #[DataProvider('rc2Vectors')]
    public function testRc2MatchesRfc2268(string $key, int $bits, string $plain, string $cipher): void
    {
        self::assertSame($plain, bin2hex((new Rc2Cipher((string) hex2bin($key), $bits))->decryptBlock((string) hex2bin($cipher))));
    }

    public function testPkcs12KdfMatchesKnownVector(): void
    {
        // RFC 7292 nemá vektory; tento je ze sady OpenSSL/BouncyCastle (heslo "smeg",
        // sůl 0A58CF64530D823F, 1 iterace, ID 1, 24 bajtů).
        $password = mb_convert_encoding('smeg', 'UTF-16BE', 'UTF-8') . "\0\0";
        self::assertSame(
            '8aaae6297b6cb04642ab5b077851284eb7128f1a2a7fbca3',
            bin2hex(EetPkcs12Reader::pkcs12Kdf('sha1', $password, (string) hex2bin('0A58CF64530D823F'), 1, 1, 24)),
        );
    }
}
