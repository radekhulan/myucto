<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Migration\Myucto\MyuctoImportSealedValues;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Obnova firmy do jiné instalace: mzdový údaj zašifrovaný klíčem a kontextem zdroje
 * (firma 10, řádek 5) se přešifruje pro cílovou firmu a řádek klíčem cíle; otisk
 * a maska odpovídají cíli, otevřená hodnota se nemění.
 */
final class MyuctoImportSealedValuesTest extends TestCase
{
    private const VALUE = '8551011235';

    public function testResealsIdentifierForTargetCompanyRowAndKey(): void
    {
        [$sourceConfig, $source] = $this->installation('a', 'p');
        [$targetConfig, $target] = $this->installation('b', 'q');
        $sealed = $source->seal(self::VALUE, PayrollSensitiveField::PERSONAL_IDENTIFIER, 10, 5);
        $sourceRow = ['id' => 5, 'supplier_id' => 10, 'identifier_type' => 'birth_number', 'value_ciphertext' => $sealed->ciphertext,
            'value_hash' => $sealed->lookupHash, 'value_masked' => $sealed->masked];
        $targetRow = ['id' => 77, 'supplier_id' => 16] + $sourceRow;

        $values = MyuctoImportSealedValues::create($targetConfig, $this->keys($sourceConfig));
        $out = $values->reseal('payroll_person_identifiers', $sourceRow, $targetRow);

        self::assertNotSame($sealed->ciphertext, $out['value_ciphertext']);
        self::assertSame(self::VALUE, $target->reveal($out['value_ciphertext'], PayrollSensitiveField::PERSONAL_IDENTIFIER, 16, 77, PayrollRevealPurpose::RESTORE_RESEAL));
        self::assertSame($target->lookupHash(self::VALUE, PayrollSensitiveField::PERSONAL_IDENTIFIER, 16), $out['value_hash']);
        self::assertSame($sealed->masked, $out['value_masked']);
        self::assertSame(1, $values->resealedCount());
        self::assertStringStartsWith('enc:v2:' . (new SecretEncryption($targetConfig))->currentKeyId() . ':', $out['value_ciphertext']);
    }

    public function testContactTypeColumnSelectsField(): void
    {
        [$sourceConfig, $source] = $this->installation('a', 'p');
        [$targetConfig, $target] = $this->installation('b', 'q');
        $sealed = $source->seal('Jan@Example.cz', PayrollSensitiveField::CONTACT_EMAIL, 10, 3);
        $row = ['id' => 3, 'supplier_id' => 10, 'contact_type' => 'email', 'contact_value_ciphertext' => $sealed->ciphertext,
            'contact_value_hash' => $sealed->lookupHash, 'contact_value_masked' => $sealed->masked];

        $out = MyuctoImportSealedValues::create($targetConfig, $this->keys($sourceConfig))
            ->reseal('payroll_person_contacts', $row, ['id' => 9, 'supplier_id' => 16] + $row);

        self::assertSame('Jan@Example.cz', $target->reveal($out['contact_value_ciphertext'], PayrollSensitiveField::CONTACT_EMAIL, 16, 9, PayrollRevealPurpose::RESTORE_RESEAL));
    }

    public function testWrongSourceKeyStopsRestore(): void
    {
        [, $source] = $this->installation('a', 'p');
        [$targetConfig] = $this->installation('b', 'q');
        [$otherConfig] = $this->installation('c', 'p');
        $sealed = $source->seal(self::VALUE, PayrollSensitiveField::PERSONAL_IDENTIFIER, 10, 5);
        $row = ['id' => 5, 'supplier_id' => 10, 'identifier_type' => 'birth_number', 'value_ciphertext' => $sealed->ciphertext,
            'value_hash' => $sealed->lookupHash, 'value_masked' => $sealed->masked];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nejde dešifrovat');
        MyuctoImportSealedValues::create($targetConfig, $this->keys($otherConfig))
            ->reseal('payroll_person_identifiers', $row, ['id' => 6, 'supplier_id' => 16] + $row);
    }

    public function testWrongSourceHashKeyIsDetected(): void
    {
        [$sourceConfig, $source] = $this->installation('a', 'p');
        [$targetConfig] = $this->installation('b', 'q');
        $sealed = $source->seal(self::VALUE, PayrollSensitiveField::PERSONAL_IDENTIFIER, 10, 5);
        $row = ['id' => 5, 'supplier_id' => 10, 'identifier_type' => 'birth_number', 'value_ciphertext' => $sealed->ciphertext,
            'value_hash' => $sealed->lookupHash, 'value_masked' => $sealed->masked];
        $keys = $this->keys($sourceConfig);
        $keys['payroll_hash_key'] = base64_encode(str_repeat('x', 32));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nesedí na svůj otisk');
        MyuctoImportSealedValues::create($targetConfig, $keys)
            ->reseal('payroll_person_identifiers', $row, ['id' => 6, 'supplier_id' => 16] + $row);
    }

    public function testUnknownCiphertextColumnIsRejected(): void
    {
        MyuctoImportSealedValues::assertNoUnknownCiphertext('payroll_person_identifiers', ['value_ciphertext' => 'enc:v2:abc:def']);
        $this->expectException(RuntimeException::class);
        MyuctoImportSealedValues::assertNoUnknownCiphertext('payroll_employees', ['note' => 'enc:v1:abc']);
    }

    /** @return array{0:Config,1:PayrollSensitiveData} */
    private function installation(string $key, string $hash): array
    {
        $config = new Config(['app' => [
            'secret_encryption_key' => base64_encode(str_repeat($key, 32)),
            'payroll_hash_key' => base64_encode(str_repeat($hash, 32)),
        ]]);
        return [$config, new PayrollSensitiveData(new SecretEncryption($config), $config)];
    }

    /** @return array<string,mixed> */
    private function keys(Config $config): array
    {
        return ['secret_encryption_key' => $config->get('app.secret_encryption_key'), 'payroll_hash_key' => $config->get('app.payroll_hash_key')];
    }
}
