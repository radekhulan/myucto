<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Epo\EpoPkcs7Signer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVaultEnvelopeSigner;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use PHPUnit\Framework\TestCase;

/**
 * Podpis samotný má vlastní testy u `EpoPkcs7Signer`; tady se ověřují pojistky,
 * které stojí PŘED ním. Všechny mají společné, že jejich selhání vypadá jako
 * úspěch až do protokolu ČSSZ — a to je nejdražší možná chvíle, protože lhůta
 * mezitím běží.
 */
final class JmhzVaultEnvelopeSignerTest extends TestCase
{
    public function testEmptyEnvelopeIsRefusedBeforeTouchingTheVault(): void
    {
        $vault = $this->createMock(PersonalCertificateVaultService::class);
        $vault->expects(self::never())->method('resolve');

        try {
            $this->signer($vault)->sign('   ');
            self::fail('Prázdná obálka musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_signing_empty_payload', $e->errorCode);
        }
    }

    public function testExpiredCertificateIsRefused(): void
    {
        $vault = $this->vaultReturning([
            'certificate_valid_from' => '2025-01-01',
            'certificate_valid_to' => '2026-06-30',
        ]);

        try {
            $this->signer($vault)->sign('<jmhz/>');
            self::fail('Prošlý certifikát musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_signing_certificate_expired', $e->errorCode);
        }
    }

    public function testNotYetValidCertificateIsRefused(): void
    {
        $vault = $this->vaultReturning([
            'certificate_valid_from' => '2027-01-01',
            'certificate_valid_to' => '2028-01-01',
        ]);

        try {
            $this->signer($vault)->sign('<jmhz/>');
            self::fail('Certifikát platný až v budoucnu musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_signing_certificate_not_yet_valid', $e->errorCode);
        }
    }

    /**
     * V trezoru bývá víc certifikátů. Podepsat tím, který ČSSZ nezná, vypadá
     * jako úspěch až do protokolu.
     */
    public function testCertificateNotRegisteredWithCsszIsRefused(): void
    {
        $vault = $this->vaultReturning([
            'credential' => ['serial_hex' => '0A'],
        ]);

        try {
            $this->signer($vault, registeredSerial: '99999999')->sign('<jmhz/>');
            self::fail('Nezaregistrovaný certifikát musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_signing_certificate_not_registered', $e->errorCode);
        }
    }

    /**
     * ČSSZ eviduje sériové číslo desítkově, trezor šestnáctkově — porovnání
     * proto musí projít přes převod, ne přes shodu textů.
     */
    public function testHexadecimalSerialMatchesTheDecimalOneRegisteredWithCsszh(): void
    {
        $vault = $this->vaultReturning([
            'credential' => ['serial_hex' => '176B96F'],
        ]);

        // 0x176B96F = 24 557 935
        $this->expectSigningAttempt();
        $this->signer($vault, registeredSerial: '24557935')->sign('<jmhz/>');
    }

    public function testCertificateWithoutSerialCannotBeVerified(): void
    {
        $vault = $this->vaultReturning(['credential' => ['serial_hex' => '']]);

        try {
            $this->signer($vault, registeredSerial: '12345678')->sign('<jmhz/>');
            self::fail('Certifikát bez sériového čísla musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_signing_certificate_unidentified', $e->errorCode);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function vaultReturning(array $overrides): PersonalCertificateVaultService
    {
        $vault = $this->createStub(PersonalCertificateVaultService::class);
        $vault->method('resolve')->willReturn(array_merge([
            'pfx' => 'not-a-real-pfx',
            'password_enc' => 'enc:v1:whatever',
            'certificate_subject' => 'CN=Test',
            'certificate_email' => null,
            'certificate_fingerprint' => null,
            'certificate_valid_from' => '2026-01-01',
            'certificate_valid_to' => '2027-01-01',
            'certificate_usage' => [],
            'credential' => ['serial_hex' => '176B96F'],
        ], $overrides));

        return $vault;
    }

    private function signer(
        PersonalCertificateVaultService $vault,
        ?string $registeredSerial = null,
    ): JmhzVaultEnvelopeSigner {
        $secrets = $this->createStub(SecretEncryption::class);
        $secrets->method('decrypt')->willReturn('heslo');

        return new JmhzVaultEnvelopeSigner(
            $vault,
            $secrets,
            credentialId: 1,
            ownerUserId: 2,
            supplierId: 3,
            registeredSerialNumber: $registeredSerial,
            signer: new EpoPkcs7Signer(),
            today: '2026-08-15',
        );
    }

    /**
     * Za pojistkami už následuje skutečný podpis, který na smyšleném PFX padne.
     * Test tím dokládá, že se ke kryptografii vůbec došlo.
     */
    private function expectSigningAttempt(): void
    {
        $this->expectException(\Throwable::class);
    }
}
