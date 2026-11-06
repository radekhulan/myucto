<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mzdový list čte doložení podmínek § 38ch odst. 1 a 3 přímo z evidence žádosti.
 *
 * Test běží proti skutečnému schématu, protože jediné, co se tu může pokazit,
 * je rozejití dotazu se sloupci tabulky — a to unit test nad polem neodhalí.
 */
#[Group('integration')]
final class PayrollSheetAnnualSettlementEvidenceTest extends TestCase
{
    public function testEvidenceQueryMatchesTheSchemaAndReportsMissingRowAsNull(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        if (!$connection->hasTable('payroll_annual_settlement_requests')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $builder = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(PayrollSheetSnapshotBuilder::class);
        self::assertInstanceOf(PayrollSheetSnapshotBuilder::class, $builder);

        $method = new \ReflectionMethod(
            PayrollSheetSnapshotBuilder::class,
            'annualSettlementEvidence',
        );

        // Chybějící záznam NENÍ „nepožádal" — doklad ho musí umět odlišit.
        self::assertNull($method->invoke($builder, 1, PHP_INT_MAX, 2026));

        $row = $connection->pdo()->query(
            'SELECT supplier_id, employee_id, tax_year
               FROM payroll_annual_settlement_requests
              LIMIT 1'
        )->fetch();
        if (!is_array($row)) {
            return;
        }

        $evidence = $method->invoke(
            $builder,
            (int) $row['supplier_id'],
            (int) $row['employee_id'],
            (int) $row['tax_year'],
        );
        self::assertIsArray($evidence);
        self::assertSame([
            'request_status',
            'prior_employers',
            'filing_obligation',
            'annual_claims',
        ], array_keys($evidence));
    }
}
