<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Eet;

use MyInvoice\Service\Eet\Certificate\EetCertificateInspector;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\Transport\EetCredentialException;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;
use MyInvoice\Tests\Support\EetTestPki;
use PHPUnit\Framework\TestCase;

final class EetCertificateInspectorTest extends TestCase
{
    private EetCertificateInspector $inspector;

    protected function setUp(): void
    {
        if (!function_exists('openssl_pkcs12_read')) {
            self::markTestSkipped('OpenSSL není k dispozici.');
        }
        $this->inspector = new EetCertificateInspector(EetTestPki::trustStore());
    }

    public function testPlaygroundCertificateIsAcceptedAndEnvironmentComesFromIssuer(): void
    {
        $result = $this->inspector->inspect(EetTestPki::pkcs12('playground', 'CZ00000019'), EetTestPki::PASSWORD);

        self::assertSame('playground', $result->environment);
        self::assertSame('CZ00000019', $result->eic);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->fingerprintSha256);
        self::assertStringContainsString('Test playground SubCA', $result->issuer);
        self::assertGreaterThan(new \DateTimeImmutable(), $result->notAfter);
    }

    public function testProductionCertificateIsRecognisedAsProduction(): void
    {
        $result = $this->inspector->inspect(EetTestPki::pkcs12('production', 'CZ1234567890'), EetTestPki::PASSWORD);

        self::assertSame('production', $result->environment);
        self::assertSame('CZ1234567890', $result->eic);
    }

    public function testStoredPkcs12OpensForSigning(): void
    {
        $result = $this->inspector->inspect(EetTestPki::pkcs12(), EetTestPki::PASSWORD);

        $credential = EetSigningCredential::fromPkcs12($result->pkcs12, EetTestPki::PASSWORD);
        self::assertSame($result->fingerprintSha256, $credential->fingerprintSha256());
        self::assertSame('CZ00000019', $credential->eic());
    }
    public function testWrongPasswordIsRejectedWithoutLeakingDetails(): void
    {
        $this->expectCode('eet_certificate_unreadable', fn () => $this->inspector->inspect(EetTestPki::pkcs12(), 'spatne-heslo'));
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectCode('eet_certificate_unreadable', fn () => $this->inspector->inspect('neni to p12', EetTestPki::PASSWORD));
        $this->expectCode('eet_validation', fn () => $this->inspector->inspect('', EetTestPki::PASSWORD));
    }

    public function testMissingOrForeignPrivateKeyIsReportedWithoutDetails(): void
    {
        $bundle = EetTestPki::bundle();
        $foreign = EetTestPki::bundle(foreignKey: true);

        $this->expectCode('eet_certificate_no_private_key', fn () => self::credential($bundle['cert'], ''));
        $this->expectCode('eet_certificate_key_mismatch', fn () => self::credential($bundle['cert'], $foreign['pkey']));
    }
    public function testSubjectWithoutEicIsRejected(): void
    {
        $this->expectCode('eet_certificate_subject', fn () => $this->inspector->inspect(EetTestPki::pkcs12(cn: 'Jan Testovaci'), EetTestPki::PASSWORD));
        $this->expectCode('eet_certificate_subject', fn () => $this->inspector->inspect(EetTestPki::pkcs12(cn: 'CZ123'), EetTestPki::PASSWORD));
    }

    public function testCertificateWithoutDigitalSignatureUsageIsRejected(): void
    {
        $this->expectCode('eet_certificate_key_usage', fn () => $this->inspector->inspect(
            EetTestPki::pkcs12(extensions: 'v3_leaf_encipherment'),
            EetTestPki::PASSWORD,
        ));
    }

    public function testExpiredAndNotYetValidCertificatesAreRejected(): void
    {
        $p12 = EetTestPki::pkcs12(days: 30);
        $this->expectCode('eet_certificate_expired', fn () => $this->inspector->inspect($p12, EetTestPki::PASSWORD, new \DateTimeImmutable('+60 days')));
        $this->expectCode('eet_certificate_not_yet_valid', fn () => $this->inspector->inspect($p12, EetTestPki::PASSWORD, new \DateTimeImmutable('-2 days')));
    }

    public function testCertificateFromForeignAuthorityIsUntrusted(): void
    {
        $this->expectCode('eet_certificate_untrusted', fn () => $this->inspector->inspect(EetTestPki::pkcs12('foreign'), EetTestPki::PASSWORD));
    }

    public function testChainInsideTheFileIsNotTrustedOnItsOwn(): void
    {
        // Řetězec přibalený v souboru (cizí kořen) nesmí nahradit připnuté autority.
        $inspector = new EetCertificateInspector(new \MyInvoice\Service\Eet\Certificate\EetTrustStore(['playground' => [], 'production' => []]));
        $this->expectCode('eet_certificate_untrusted', fn () => $inspector->inspect(EetTestPki::pkcs12('playground'), EetTestPki::PASSWORD));
    }

    public function testBundledAuthoritiesCoverBothEnvironments(): void
    {
        $anchors = (new \MyInvoice\Service\Eet\Certificate\EetTrustStore())->anchors();

        self::assertCount(2, $anchors['playground'] ?? []);
        self::assertCount(2, $anchors['production'] ?? []);
        foreach ($anchors as $environment => $pems) {
            foreach ($pems as $pem) {
                $parsed = openssl_x509_parse($pem);
                self::assertIsArray($parsed);
                self::assertStringContainsString('CA:TRUE', (string) ($parsed['extensions']['basicConstraints'] ?? ''));
                self::assertSame($environment === 'playground', str_starts_with((string) $parsed['subject']['CN'], 'playground'));
            }
        }
    }

    private static function credential(string $certificate, string $key): void
    {
        try {
            EetSigningCredential::fromPem($certificate, $key);
        } catch (EetCredentialException $exception) {
            throw EetCertificateInspector::fromCredentialException($exception);
        }
    }

    private function expectCode(string $code, callable $call): void
    {
        try {
            $call();
            self::fail("Očekávaná chyba {$code} nenastala.");
        } catch (EetException $exception) {
            self::assertSame($code, $exception->errorCode, $exception->getMessage());
            self::assertStringNotContainsString(EetTestPki::PASSWORD, $exception->getMessage());
            self::assertStringNotContainsStringIgnoringCase('error:', $exception->getMessage());
        }
    }
}
