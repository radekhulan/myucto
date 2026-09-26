<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollPlan;
use PHPUnit\Framework\TestCase;

final class StereoNxPayrollPlanTest extends TestCase
{
    private function tables(): array
    {
        return [
            'MZAMEST' => [['Prac' => 'TEST1', 'KrestniJmeno' => 'Test', 'Prijmeni' => 'Employee',
                'DatumNastupu' => '2020-01-01', 'DatumUkonceni' => null]],
            'MMzdy' => [['Klic' => 1, 'Prac' => 'TEST1', 'Rok' => 2026, 'Mesic' => 1]],
        ];
    }

    public function testReportsLinksWithoutPersonalValues(): void
    {
        $report = StereoNxPayrollPlan::fromTables($this->tables());
        self::assertTrue($report['ok']);
        self::assertSame(1, $report['counts']['monthly_linked']);
        self::assertSame(['2026-01'], $report['periods']);
        self::assertStringNotContainsString('TEST1', json_encode($report));
        self::assertStringNotContainsString('Employee', json_encode($report));
    }

    public function testMissingAndDuplicateEmployeesAndOrphanWagesAreBlocking(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][] = $tables['MZAMEST'][0];
        $tables['MZAMEST'][] = ['Prac' => ''];
        $tables['MMzdy'][0]['Prac'] = 'MISSING';
        $report = StereoNxPayrollPlan::fromTables($tables);
        self::assertFalse($report['ok']);
        self::assertSame(3, $report['counts']['employees']);
        self::assertSame(1, $report['diagnostics']['employee_key_missing']);
        self::assertSame(1, $report['diagnostics']['employee_key_duplicate']);
        self::assertSame(1, $report['diagnostics']['monthly_employee_orphan']);
    }

    public function testDuplicateAndMissingWageKeysAreBlocking(): void
    {
        $tables = $this->tables();
        $tables['MMzdy'][] = $tables['MMzdy'][0];
        $tables['MMzdy'][] = $tables['MMzdy'][0];
        $tables['MMzdy'][2]['Klic'] = null;
        $report = StereoNxPayrollPlan::fromTables($tables);
        self::assertFalse($report['ok']);
        self::assertSame(3, $report['counts']['monthly']);
        self::assertSame(1, $report['diagnostics']['monthly_key_duplicate']);
        self::assertSame(1, $report['diagnostics']['monthly_key_invalid']);
    }

    public function testInvalidDateAndPeriodAreBlocking(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][0]['DatumNastupu'] = '2026-02-31';
        $tables['MMzdy'][0]['Mesic'] = 13;
        $report = StereoNxPayrollPlan::fromTables($tables);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['diagnostics']['employee_date_invalid']);
        self::assertSame(1, $report['diagnostics']['monthly_period_invalid']);
    }
}
