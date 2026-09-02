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

    /**
     * Pracovní řádek se smí nahradit, odeslaný podklad ne.
     *
     * UPDATE musí zůstat zakázaný: řádek nese šifrovaný obsah a jeho otisk,
     * takže se nikdy nemění na místě — nahrazení je smazání plus vložení.
     */
    public function testWorkingRowMayBeReplacedUntilRegistrationWasSubmitted(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1716_payroll_registration_a1_profile_working_row.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        foreach ([
            'DROP TRIGGER IF EXISTS trg_payroll_registration_a1_profile_immutable_delete',
            'BEFORE DELETE ON payroll_registration_a1_profiles',
            'payroll_submission_parts',
            "'submitted', 'processing', 'accepted', 'partially_accepted'",
        ] as $required) {
            self::assertStringContainsString($required, $sql);
        }
        self::assertStringNotContainsString(
            'BEFORE UPDATE ON payroll_registration_a1_profiles',
            $sql,
        );
    }
}
