<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PHPUnit\Framework\TestCase;

final class PayrollSensitiveDataTest extends TestCase
{
    private PayrollSensitiveData $service;

    protected function setUp(): void
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('e', 32)),
                'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
            ],
        ]);
        $this->service = new PayrollSensitiveData(
            new SecretEncryption($config),
            $config,
        );
    }

    public function testSealProducesCiphertextBinaryHashAndMask(): void
    {
        $sealed = $this->service->seal(
            'TEST / ID-0001',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            10,
            20,
        );

        self::assertStringStartsWith('enc:v2:', $sealed->ciphertext);
        self::assertSame(32, strlen($sealed->lookupHash));
        self::assertStringNotContainsString('TEST', $sealed->ciphertext);
        self::assertStringEndsWith('0001', $sealed->masked);
        self::assertSame(
            'TEST / ID-0001',
            $this->service->reveal(
                $sealed->ciphertext,
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                10,
                20,
            ),
        );
    }

    public function testNormalizationMakesEquivalentIdentifiersFindable(): void
    {
        self::assertSame(
            $this->service->lookupHash(
                'TEST / ID-0001',
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                10,
            ),
            $this->service->lookupHash(
                'testid0001',
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                10,
            ),
        );
    }

    public function testSameValueCannotBeCorrelatedAcrossTenantsOrFields(): void
    {
        $value = 'SYNTHETIC-0001';

        self::assertNotSame(
            $this->service->lookupHash($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, 10),
            $this->service->lookupHash($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, 11),
        );
        self::assertNotSame(
            $this->service->lookupHash($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, 10),
            $this->service->lookupHash($value, PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER, 10),
        );
    }

    public function testCanonicalFingerprintIsTenantAndPurposeBound(): void
    {
        $canonical = '{"identifier":"0001010009","name":"Syntetická Osoba"}';

        $first = $this->service->keyedFingerprint(
            $canonical,
            'annual-payroll-sheet',
            10,
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        self::assertSame(
            $first,
            $this->service->keyedFingerprint(
                $canonical,
                'annual-payroll-sheet',
                10,
            ),
        );
        self::assertNotSame(
            $first,
            $this->service->keyedFingerprint(
                $canonical,
                'annual-payroll-sheet',
                11,
            ),
        );
        self::assertNotSame(
            $first,
            $this->service->keyedFingerprint(
                $canonical,
                'another-purpose',
                10,
            ),
        );
    }

    public function testA1ProfileKeepsCanonicalJsonCaseAndSupportsFullPayload(): void
    {
        $canonical = json_encode([
            'position_name' => 'Účetní',
            'attachment' => str_repeat('A', 500),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sealed = $this->service->seal(
            $canonical,
            PayrollSensitiveField::REGISTRATION_A1_PROFILE,
            10,
            20,
        );

        self::assertSame('••••••••', $sealed->masked);
        self::assertSame(
            $canonical,
            $this->service->reveal(
                $sealed->ciphertext,
                PayrollSensitiveField::REGISTRATION_A1_PROFILE,
                10,
                20,
            ),
        );
        self::assertSame(
            $sealed->lookupHash,
            $this->service->lookupHash(
                $canonical,
                PayrollSensitiveField::REGISTRATION_A1_PROFILE,
                10,
            ),
        );
    }

    public function testContactsAreNormalizedEncryptedAndContextBound(): void
    {
        $email = $this->service->seal(
            ' Jana.Testovaci@Example.Invalid ',
            PayrollSensitiveField::CONTACT_EMAIL,
            10,
            21,
        );
        $phone = $this->service->seal(
            '+420 111 222 333',
            PayrollSensitiveField::CONTACT_PHONE,
            10,
            22,
        );

        self::assertStringStartsWith('enc:v2:', $email->ciphertext);
        self::assertStringStartsWith('j', $email->masked);
        self::assertStringEndsWith('@example.invalid', $email->masked);
        self::assertStringEndsWith('2333', $phone->masked);
        self::assertSame(
            $email->lookupHash,
            $this->service->lookupHash(
                'jana.testovaci@example.invalid',
                PayrollSensitiveField::CONTACT_EMAIL,
                10,
            ),
        );
        self::assertSame(
            $phone->lookupHash,
            $this->service->lookupHash(
                '+420111222333',
                PayrollSensitiveField::CONTACT_PHONE,
                10,
            ),
        );
        self::assertSame(
            'Jana.Testovaci@Example.Invalid',
            $this->service->reveal(
                $email->ciphertext,
                PayrollSensitiveField::CONTACT_EMAIL,
                10,
                21,
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->service->reveal(
            $email->ciphertext,
            PayrollSensitiveField::CONTACT_PHONE,
            10,
            21,
        );
    }

    public function testCiphertextIsBoundToTenantEntityAndField(): void
    {
        $sealed = $this->service->seal(
            'SYNTHETIC-0001',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            10,
            20,
        );

        foreach ([
            [11, 20, PayrollSensitiveField::PERSONAL_IDENTIFIER],
            [10, 21, PayrollSensitiveField::PERSONAL_IDENTIFIER],
            [10, 20, PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER],
        ] as [$supplierId, $entityId, $field]) {
            try {
                $this->service->reveal($sealed->ciphertext, $field, $supplierId, $entityId);
                self::fail('Změněný kontext musí decrypt odmítnout.');
            } catch (\RuntimeException $e) {
                self::assertSame('Decryption failed', $e->getMessage());
            }
        }
    }

    public function testRevealRejectsLegacyPlaintextAndUnboundCiphertext(): void
    {
        foreach ([
            'SYNTHETIC-PLAINTEXT',
            'enc:v1:' . base64_encode('synthetic'),
        ] as $stored) {
            try {
                $this->service->reveal(
                    $stored,
                    PayrollSensitiveField::PERSONAL_IDENTIFIER,
                    10,
                    20,
                );
                self::fail('Mzdový wrapper nesmí přijmout plaintext ani starý ciphertext bez kontextu.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('kontextově šifrovaná', $e->getMessage());
            }
        }
    }

    public function testMissingHashKeyFailsClosed(): void
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('e', 32)),
            ],
        ]);
        $service = new PayrollSensitiveData(new SecretEncryption($config), $config);

        $this->expectException(\RuntimeException::class);
        $service->lookupHash(
            'SYNTHETIC-0001',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            10,
        );
    }

    public function testShortValueIsFullyMasked(): void
    {
        self::assertSame(
            '••••',
            $this->service->mask('ABC', PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER),
        );
    }

    public function testPepperFallbackUsesSeparatedHkdfContext(): void
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('e', 32)),
                'pepper' => 'synthetic-test-pepper',
            ],
        ]);
        $service = new PayrollSensitiveData(new SecretEncryption($config), $config);

        self::assertSame(
            $service->lookupHash('SYNTHETIC-0001', PayrollSensitiveField::PERSONAL_IDENTIFIER, 10),
            $service->lookupHash('synthetic / 0001', PayrollSensitiveField::PERSONAL_IDENTIFIER, 10),
        );
    }

    public function testInvalidConfigurationAndInputAreRejected(): void
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('e', 32)),
                'payroll_hash_key' => base64_encode('short'),
            ],
        ]);
        $service = new PayrollSensitiveData(new SecretEncryption($config), $config);

        try {
            $service->lookupHash('SYNTHETIC-0001', PayrollSensitiveField::PERSONAL_IDENTIFIER, 10);
            self::fail('Krátký hash key musí být odmítnut.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('32B', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service->seal(' ', PayrollSensitiveField::BANK_ACCOUNT, 10, 20);
    }
}
