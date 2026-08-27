<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollRegistrationEventBusinessDeduplicationMigrationTest extends TestCase
{
    public function testMigrationPreflightsImmutableHistoryBeforeAddingBusinessKey(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1605_payroll_registration_event_business_deduplication.sql',
        );
        self::assertIsString($sql);

        self::assertStringContainsString(
            'GROUP BY supplier_id, environment, employment_id, interaction_code,',
            $sql,
        );
        self::assertStringContainsString(
            'effective_on, source_reference',
            $sql,
        );
        self::assertStringContainsString(
            'business-key duplicates require manual resolution',
            $sql,
        );
        self::assertStringContainsString(
            'COLLATE utf8mb4_bin NOT NULL',
            $sql,
        );
        self::assertStringContainsString(
            'ADD UNIQUE INDEX IF NOT EXISTS uq_payroll_registration_event_business',
            $sql,
        );
        self::assertStringNotContainsString(
            'UPDATE payroll_registration_event_snapshots',
            $sql,
        );
        self::assertStringNotContainsString(
            'DELETE FROM payroll_registration_event_snapshots',
            $sql,
        );

        $compatibilitySql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1605a_payroll_registration_event_source_reference_collation.sql',
        );
        self::assertIsString($compatibilitySql);
        self::assertStringContainsString(
            'COLLATE utf8mb4_bin NOT NULL',
            $compatibilitySql,
        );
    }
}
