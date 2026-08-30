<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
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
        // Osobní identifikátor odhaluje jen DVĚ poslední číslice (W1/P-06) —
        // se čtyřmi by šlo z data narození a pohlaví složit celé rodné číslo.
        self::assertStringEndsWith('01', $sealed->masked);
        self::assertStringNotContainsString('0001', $sealed->masked);
        self::assertSame(
            'TEST / ID-0001',
            $this->service->reveal(
                $sealed->ciphertext,
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                10,
                20,
                PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
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
                PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
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
                PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->service->reveal(
            $email->ciphertext,
            PayrollSensitiveField::CONTACT_PHONE,
            10,
            21,
            PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
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
                $this->service->reveal($sealed->ciphertext, $field, $supplierId, $entityId, PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL);
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
                    PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
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

    /**
     * W1/P-03 — účel odhalení musí umět odlišit zákonnou náležitost dokumentu
     * nebo podání (systémový průchod, na interaktivní důvod není kde se zeptat)
     * od platebního styku a od odhalení na výslovnou žádost uživatele.
     */
    public function testRevealPurposeSeparatesStatutoryOutputsFromTheRest(): void
    {
        foreach ([
            PayrollRevealPurpose::DOCUMENT_PAYROLL_SHEET,
            PayrollRevealPurpose::DOCUMENT_ANNUAL_TAX_CERTIFICATE,
            PayrollRevealPurpose::DOCUMENT_ANNUAL_SETTLEMENT,
            PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
            PayrollRevealPurpose::SUBMISSION_HEALTH_BULK_NOTIFICATION,
        ] as $purpose) {
            self::assertTrue(
                $purpose->isStatutoryOutput(),
                $purpose->value . ' je zákonná náležitost výstupu.',
            );
        }
        foreach ([
            PayrollRevealPurpose::PAYMENT_INSTITUTION_ACCOUNT,
            PayrollRevealPurpose::PAYMENT_LIABILITY_ACCOUNT,
            PayrollRevealPurpose::PAYMENT_BATCH,
            PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
        ] as $purpose) {
            self::assertFalse(
                $purpose->isStatutoryOutput(),
                $purpose->value . ' není zákonná náležitost výstupu.',
            );
        }
    }

    /**
     * Maska rodného čísla nesmí umožnit jeho rekonstrukci (W1/P-06).
     *
     * Ve stejné odpovědi jako maska chodí `birth_date` a `sex`, ze kterých plyne
     * prvních šest číslic. Kdyby maska ukazovala celou čtyřmístnou koncovku,
     * bylo by rodné číslo známé beze zbytku — a to za pouhé právo `payroll` READ.
     */
    public function testPersonalIdentifierMaskHidesEnoughForReconstruction(): void
    {
        $masked = $this->service->mask(
            '900101/1234',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
        );

        self::assertSame('••••••••34', $masked);
        self::assertStringNotContainsString('1234', $masked);
        self::assertStringNotContainsString('900101', $masked);
    }

    /** Číslo účtu ani telefon se nezkracují — u nich rekonstrukce z jiných polí nehrozí. */
    public function testBankAccountAndPhoneMasksAreUnchanged(): void
    {
        self::assertSame(
            '••••123456',
            $this->service->mask('9876123456', PayrollSensitiveField::BANK_ACCOUNT),
        );
        self::assertSame(
            '•••••••••2333',
            $this->service->mask('+420 111 222 333', PayrollSensitiveField::CONTACT_PHONE),
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
