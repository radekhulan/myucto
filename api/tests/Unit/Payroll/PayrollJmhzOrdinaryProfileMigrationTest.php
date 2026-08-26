<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzOrdinaryProfileMigrationTest extends TestCase
{
    public function testMigrationAddsEffectiveExceptionFlagsWithOrdinaryDefaults(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1540_payroll_jmhz_ordinary_profile.sql',
        );

        self::assertIsString($sql);
        foreach ([
            'jmhz_orchard_discount_eligible',
            'jmhz_specific_legal_fact_applies',
            'jmhz_ozp_employment_support_applies',
            'jmhz_deep_mining_work_applies',
        ] as $column) {
            self::assertStringContainsString("ADD COLUMN IF NOT EXISTS {$column}", $sql);
            self::assertMatchesRegularExpression(
                "/{$column}\\s+TINYINT\\(1\\) NOT NULL DEFAULT 0/",
                $sql,
            );
        }
        self::assertStringContainsString(
            'chk_payroll_employment_terms_jmhz_ordinary_profile',
            $sql,
        );
    }
}
