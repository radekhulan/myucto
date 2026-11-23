<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaPreparationService;
use PHPUnit\Framework\TestCase;

/**
 * Audit #23: účtárna bez variabilního symbolu ČSSZ nezastaví hlášení
 * za účtárny, které symbol mají; zbytek se vypíše ve varování.
 */
final class PayrollMonthlyAgendaOfficePartitionTest extends TestCase
{
    public function testOfficeWithoutSymbolIsSkippedAndListed(): void
    {
        $result = PayrollMonthlyAgendaPreparationService::partitionOffices([
            ['office_id' => 1, 'name' => 'Praha', 'submittable' => true],
            ['office_id' => 2, 'name' => 'Brno', 'submittable' => false],
            ['office_id' => 3, 'name' => 'Ostrava', 'submittable' => true],
        ]);

        self::assertSame([1, 3], array_column($result['offices'], 'office_id'));
        self::assertSame([2], array_column($result['skipped'], 'office_id'));
        self::assertIsString($result['warning']);
        self::assertStringContainsString('Brno', $result['warning']);
        self::assertStringContainsString('Hlášení za ostatní účtárny se připravilo', $result['warning']);
    }

    public function testAllOfficesWithSymbolPassUnchanged(): void
    {
        $offices = [['office_id' => 1, 'name' => 'Praha', 'submittable' => true]];

        self::assertSame(
            ['offices' => $offices, 'skipped' => [], 'warning' => null],
            PayrollMonthlyAgendaPreparationService::partitionOffices($offices),
        );
        self::assertSame([], PayrollMonthlyAgendaPreparationService::partitionOffices([])['offices']);
    }

    public function testNoOfficeWithSymbolStillStops(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Mzdová účtárna Brno nemá desetimístný variabilní symbol');

        PayrollMonthlyAgendaPreparationService::partitionOffices([
            ['office_id' => 2, 'name' => 'Brno', 'submittable' => false],
        ]);
    }
}
