<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\AnonymizationPolicy;
use MyInvoice\Service\Anonymization\AnonymizationService;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PHPUnit\Framework\TestCase;

/**
 * H28 — anonymizovaná kopie (klon firmy pro testovací instanci) musí rodné číslo
 * z daňového profilu přezapečetit pseudonymem stejně jako mzdové: šifra, otisk
 * i maska patří k pseudonymu a originál v kopii nezůstane. Otevřený sloupec
 * (stav před převodem migrací 2160) zůstává na strategii `birth_number`.
 */
final class TaxFamilyBirthNumberResealTest extends TestCase
{
    private const SUPPLIER = 41;
    private const ROW = 7;

    public function testPolicyCoversSealedAndLegacyColumns(): void
    {
        foreach (['tax_profile_children', 'tax_profile_spouse_claims'] as $table) {
            $columns = AnonymizationPolicy::COLUMNS[$table];
            self::assertSame('birth_number', $columns['birth_number']);
            self::assertSame('sealed_companion', $columns['birth_number_hash']);
            self::assertSame('sealed_companion', $columns['birth_number_masked']);
            $spec = AnonymizationPolicy::parseSealed($columns['birth_number_ciphertext']);
            self::assertSame(PayrollSensitiveField::TAX_FAMILY_IDENTIFIER->value, $spec['field']);
            self::assertSame('id', $spec['entity']);
        }
    }

    public function testSealedTaxFamilyBirthNumberIsResealedWithPseudonym(): void
    {
        $config = new Config(['app' => [
            'secret_encryption_key' => base64_encode(str_repeat('k', 32)),
            'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
        ]]);
        $sensitive = new PayrollSensitiveData(new SecretEncryption($config), $config);
        $original = '8551011235';
        $sealed = $sensitive->seal($original, PayrollSensitiveField::TAX_FAMILY_IDENTIFIER, self::SUPPLIER, self::ROW);

        $service = new AnonymizationService($config);
        $reflection = new \ReflectionClass($service);
        $pseudonymizer = new Pseudonymizer(str_repeat('s', 32));
        $reflection->getProperty('pseudonymizer')->setValue($service, $pseudonymizer);
        $reflection->getProperty('scrubber')->setValue($service, new TextScrubber($pseudonymizer));
        $reflection->getProperty('sensitive')->setValue($service, $sensitive);

        $out = $reflection->getMethod('reseal')->invoke(
            $service,
            'birth_number_ciphertext',
            AnonymizationPolicy::COLUMNS['tax_profile_children']['birth_number_ciphertext'],
            $sealed->ciphertext,
            ['id' => self::ROW, 'supplier_id' => self::SUPPLIER],
        );

        self::assertSame(['birth_number_ciphertext', 'birth_number_hash', 'birth_number_masked'], array_keys($out));
        $pseudo = $sensitive->reveal($out['birth_number_ciphertext'], PayrollSensitiveField::TAX_FAMILY_IDENTIFIER, self::SUPPLIER, self::ROW, PayrollRevealPurpose::ANONYMIZATION);
        self::assertNotSame($original, $pseudo);
        self::assertTrue(Pseudonymizer::isValidBirthNumber($pseudo));
        self::assertSame($sensitive->lookupHash($pseudo, PayrollSensitiveField::TAX_FAMILY_IDENTIFIER, self::SUPPLIER), $out['birth_number_hash']);
        self::assertSame($sensitive->mask($pseudo, PayrollSensitiveField::TAX_FAMILY_IDENTIFIER), $out['birth_number_masked']);
    }
}
