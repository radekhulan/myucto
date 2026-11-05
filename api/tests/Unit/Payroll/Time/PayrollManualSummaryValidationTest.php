<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollManualSummaryValidationTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidInputs(): iterable
    {
        $segment = ['date_from' => '2026-03-01', 'date_to' => '2026-03-31', 'worked_duration_units' => 3000, 'overtime_duration_units' => null, 'worked_days' => null];
        yield 'neplatné datum' => [['segments' => [array_replace($segment, ['date_from' => '2026-02-30'])]]];
        yield 'mimo měsíc' => [['segments' => [array_replace($segment, ['date_to' => '2026-04-01'])]]];
        yield 'hodiny chybí' => [['segments' => [array_replace($segment, ['worked_duration_units' => null])]]];
        yield 'záporné hodiny' => [['segments' => [array_replace($segment, ['worked_duration_units' => -1])]]];
        yield 'zlomek jednotky' => [['segments' => [array_replace($segment, ['worked_duration_units' => 1.5])]]];
        yield 'nad 24 hodin za den' => [['segments' => [array_replace($segment, ['date_to' => '2026-03-01', 'worked_duration_units' => 72001])]]];
        yield 'přesčas vyšší než práce' => [['segments' => [array_replace($segment, ['overtime_duration_units' => 3001])]]];
        yield 'kladné hodiny bez dnů' => [['segments' => [array_replace($segment, ['worked_days' => 0])]]];
        yield 'nulové hodiny s dny' => [['segments' => [array_replace($segment, ['worked_duration_units' => 0, 'worked_days' => 1])]]];
        yield 'překryv' => [['segments' => [$segment, $segment]]];
        yield 'bez podkladu' => [['segments' => []]];
        yield 'bez důvodu' => [['reason' => ' ']];
        yield 'neplatné potvrzení' => [['replace_source' => 'true']];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsAmbiguousOrContradictoryEvidence(array $changes): void
    {
        $service = (new \ReflectionClass(PayrollTimeService::class))->newInstanceWithoutConstructor();
        $input = array_replace([
            'employment_id' => 1, 'row_version' => 0, 'reason' => 'Syntetický podklad', 'replace_source' => false,
            'segments' => [['date_from' => '2026-03-01', 'date_to' => '2026-03-31', 'worked_duration_units' => 0, 'overtime_duration_units' => null, 'worked_days' => null]],
        ], $changes);
        $this->expectException(\InvalidArgumentException::class);
        $service->saveManualSummary(1, '2026-03', $input, null);
    }
}
