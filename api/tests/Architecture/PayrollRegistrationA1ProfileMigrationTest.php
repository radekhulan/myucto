<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollRegistrationA1ProfileMigrationTest extends TestCase
{
    public function testA1ProfileIsTenantScopedVersionedEncryptedAndImmutable(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1609_payroll_registration_a1_profiles.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        foreach ([
            'CREATE TABLE IF NOT EXISTS payroll_registration_a1_profiles',
            'UNIQUE KEY uq_payroll_registration_a1_profile_version',
            'FOREIGN KEY (supplier_id, employment_id, employee_id)',
            "profile_ciphertext LIKE 'enc:v2:%'",
            'reference_hash REGEXP',
            'BEFORE UPDATE ON payroll_registration_a1_profiles',
            'BEFORE DELETE ON payroll_registration_a1_profiles',
        ] as $required) {
            self::assertStringContainsString($required, $sql);
        }
        self::assertStringNotContainsString('PREPARE ', $sql);
        self::assertStringNotContainsString('EXECUTE ', $sql);
    }
}

