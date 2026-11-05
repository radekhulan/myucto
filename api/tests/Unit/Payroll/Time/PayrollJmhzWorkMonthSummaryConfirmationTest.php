<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use PHPUnit\Framework\TestCase;

final class PayrollJmhzWorkMonthSummaryConfirmationTest extends TestCase
{
    public function testManualSummaryConfirmsProjectedHoursButDoesNotInventDaysOrOvertime(): void
    {
        $builder = (new \ReflectionClass(PayrollJmhzWorkMonthSummaryBuilder::class))->newInstanceWithoutConstructor();
        $hash = str_repeat('a', 64);
        $summary = $builder->confirm([
            'derivation_version' => PayrollJmhzWorkMonthSummaryBuilder::MANUAL_SUMMARY_DERIVATION_VERSION,
            'work_source' => 'manual_summary', 'source_snapshot_sha256' => $hash, 'source_snapshot_json' => '{}',
            'suggestions' => ['evidence_days' => 31, 'worked_days' => null, 'overtime_hours' => null, 'worked_hours' => '0.018'],
        ], [
            'source_snapshot_sha256' => $hash, 'standard_fund_hours' => '168',
            'agreed_fund_hours' => '168', 'weekly_work_hours' => '40', 'worked_hours' => '0.018',
            'unworked_hours_occurred' => false, 'work_obstacles_occurred' => false,
        ]);
        self::assertSame(18, $summary['values']['worked_millihours']);
        self::assertNull($summary['values']['worked_days']);
        self::assertNull($summary['values']['overtime_millihours']);
        self::assertSame('manual_summary', $summary['provenance']['work_source']);
        self::assertSame('not_provided_by_manual_summary', $summary['provenance']['attributes']['10267']);
        self::assertSame('manual_source_preserved_export_half_up_to_millihour', $summary['provenance']['decimal_policy']);
    }

    public function testConditionalWorkBlocksRejectInvalidInputs(): void
    {
        $builder = (new \ReflectionClass(PayrollJmhzWorkMonthSummaryBuilder::class))->newInstanceWithoutConstructor();
        $hash = str_repeat('a', 64);
        $preview = [
            'source_snapshot_sha256' => $hash,
            'suggestions' => [
                'evidence_days' => 31,
                'worked_days' => 1,
                'overtime_hours' => null,
            ],
        ];
        $core = [
            'source_snapshot_sha256' => $hash,
            'standard_fund_hours' => '168',
            'agreed_fund_hours' => '168',
            'weekly_work_hours' => '40',
            'worked_hours' => '7.5',
        ];
        $invalidCases = [
            'orphan_obstacles' => [
                ['unworked_hours_occurred' => false, 'work_obstacles_occurred' => true, 'employee_obstacle_paid_hours' => '8'],
                'Interakce IN08 vyžaduje aktivní IN07.',
            ],
            'detail_without_interaction' => [
                ['unworked_hours_occurred' => false, 'work_obstacles_occurred' => false, 'vacation_hours' => '8'],
                'Hodnoty 10275–10280 nelze uvést, pokud interakce IN07 nenastala.',
            ],
            'boolean_as_string' => [
                ['unworked_hours_occurred' => 'false', 'work_obstacles_occurred' => false],
                'unworked_hours_occurred musí být výslovně ano nebo ne.',
            ],
            'missing_total' => [
                ['unworked_hours_occurred' => true, 'work_obstacles_occurred' => false],
                'Při aktivní IN07 musí být celkové neodpracované hodiny kladné.',
            ],
            'empty_optional_value' => [
                ['unworked_hours_occurred' => true, 'work_obstacles_occurred' => false, 'unworked_total_hours' => '8', 'vacation_hours' => ''],
                'vacation_hours musí být nezáporné desetinné číslo.',
            ],
            'obstacle_without_value' => [
                ['unworked_hours_occurred' => true, 'work_obstacles_occurred' => true, 'unworked_total_hours' => '8'],
                'Při aktivní IN08 musí být uveden alespoň jeden atribut 10471/10472.',
            ],
            'vacation_above_paid' => [
                ['unworked_hours_occurred' => true, 'work_obstacles_occurred' => false, 'unworked_total_hours' => '16', 'unworked_paid_hours' => '7.999', 'vacation_hours' => '8'],
                'Placené neodpracované hodiny 10276 nesmí být nižší než dovolená 10279.',
            ],
            'obstacle_above_fund' => [
                ['unworked_hours_occurred' => true, 'work_obstacles_occurred' => true, 'unworked_total_hours' => '169', 'employee_obstacle_paid_hours' => '168.001'],
                'Hodiny překážek nesmí překročit sjednaný fond 10260.',
            ],
            'conditional_value_above_product_cap' => [
                ['unworked_hours_occurred' => true, 'work_obstacles_occurred' => false, 'unworked_total_hours' => '100000'],
                'unworked_total_hours překračuje podporovaný měsíční rozsah.',
            ],
            'core_value_above_product_cap' => [
                ['standard_fund_hours' => '10000', 'unworked_hours_occurred' => false, 'work_obstacles_occurred' => false],
                'standard_fund_hours překračuje podporovaný měsíční rozsah.',
            ],
        ];

        foreach ($invalidCases as $name => [$invalid, $message]) {
            try {
                $builder->confirm($preview, array_replace($core, $invalid));
                self::fail("{$name}: neplatný souhrn byl přijat.");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }
        }
    }
}
