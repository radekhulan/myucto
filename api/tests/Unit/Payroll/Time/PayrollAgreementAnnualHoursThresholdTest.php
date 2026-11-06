<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact;
use MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hlídání ročního limitu DPP předem (§ 75 ZP): upozornění od prahu 80 %
 * (240 h), překročení až nad 300 h. Přesně 300 h je ještě v limitu.
 */
final class PayrollAgreementAnnualHoursThresholdTest extends TestCase
{
    public function testWarningThresholdIsEightyPercentOfTheLimit(): void
    {
        self::assertSame(300 * 60, PayrollAgreementAnnualHours::LIMIT_MINUTES);
        self::assertSame(240 * 60, PayrollAgreementAnnualHours::WARNING_MINUTES);
    }

    /** @return iterable<string,array{int,string}> */
    public static function totals(): iterable
    {
        yield 'nic' => [0, PayrollAgreementAnnualHours::STATUS_OK];
        yield 'těsně pod prahem' => [240 * 60 - 1, PayrollAgreementAnnualHours::STATUS_OK];
        yield 'přesně na prahu' => [240 * 60, PayrollAgreementAnnualHours::STATUS_APPROACHING];
        yield 'mezi prahem a limitem' => [280 * 60, PayrollAgreementAnnualHours::STATUS_APPROACHING];
        yield 'přesně na limitu' => [300 * 60, PayrollAgreementAnnualHours::STATUS_APPROACHING];
        yield 'nad limitem' => [300 * 60 + 1, PayrollAgreementAnnualHours::STATUS_EXCEEDED];
    }

    #[DataProvider('totals')]
    public function testStatusByTotal(int $minutes, string $expected): void
    {
        self::assertSame($expected, PayrollAgreementAnnualHours::status($minutes));
    }

    public function testSummaryReportsRemainingHoursAndNeverGoesNegative(): void
    {
        $summary = PayrollAgreementAnnualHours::summary(2026, [
            'own_minutes' => 200 * 60,
            'takeover_minutes' => 45 * 60,
            'total_minutes' => 245 * 60,
        ]);
        self::assertSame(55 * 60, $summary['remaining_minutes']);
        self::assertSame('approaching', $summary['status']);
        self::assertSame(45 * 60, $summary['takeover_minutes']);

        $over = PayrollAgreementAnnualHours::summary(2026, [
            'own_minutes' => 310 * 60,
            'takeover_minutes' => 0,
            'total_minutes' => 310 * 60,
        ]);
        self::assertSame(0, $over['remaining_minutes']);
        self::assertSame('exceeded', $over['status']);
    }

    public function testApproachingIsOnlyInformationAndHasGuidance(): void
    {
        self::assertSame('dpp_annual_hours_approaching', PayrollAgreementAnnualHours::validationCode('approaching'));
        self::assertSame('dpp_annual_hours_exceeded', PayrollAgreementAnnualHours::validationCode('exceeded'));
        $described = PayrollRunReadinessImpact::describe('dpp_annual_hours_approaching');
        self::assertSame(PayrollRunReadinessImpact::IMPACT_ANYTIME, $described['impact']);
        self::assertNotSame('blocker', $described['severity']);
        $guidance = PayrollRunIssueGuidance::describe('dpp_annual_hours_approaching', 5, 7);
        self::assertStringContainsString('blíží ročnímu limitu', $guidance['message']);
        self::assertStringContainsString('panel=employment_terms', $guidance['remediation_path']);
    }

    public function testApproachingMessageNamesRemainingHours(): void
    {
        $message = PayrollAgreementAnnualHours::approachingMessage('Syntetická brigádnice', 2026, 250 * 60, 30 * 60);

        self::assertStringContainsString('Syntetická brigádnice', $message);
        self::assertStringContainsString('250 h', $message);
        self::assertStringContainsString('Zbývá 50 h', $message);
        self::assertStringContainsString('30 h převzatých', $message);
        self::assertSame('', PayrollAgreementAnnualHours::statusMessage('ok', 'X', 2026, 10, 0));
    }

    /**
     * Náhled importu docházky: dosavadní součet + importované odpracované
     * hodiny. Hlásí jen osoby přiřazené na DPP a jen od prahu.
     */
    public function testAttendancePreviewAddsImportedHoursToYearToDate(): void
    {
        $options = [
            ['employment_id' => 11, 'employee_id' => 1, 'relation_type' => 'dpp'],
            ['employment_id' => 12, 'employee_id' => 2, 'relation_type' => 'dpp'],
            ['employment_id' => 13, 'employee_id' => 3, 'relation_type' => 'hpp'],
            ['employment_id' => 14, 'employee_id' => 4, 'relation_type' => 'dpp'],
        ];
        $persons = [
            self::person('Pod prahem', 11, 1, 20_000),
            self::person('Na prahu', 12, 2, 40_000),
            self::person('Hlavní poměr', 13, 3, 160_000),
            self::person('Nad limitem', 14, 4, 30_500),
        ];
        $prior = [
            1 => ['own_minutes' => 200 * 60, 'takeover_minutes' => 0, 'total_minutes' => 200 * 60],
            2 => ['own_minutes' => 100 * 60, 'takeover_minutes' => 100 * 60, 'total_minutes' => 200 * 60],
            4 => ['own_minutes' => 270 * 60, 'takeover_minutes' => 0, 'total_minutes' => 270 * 60],
        ];

        $result = AttendanceImportService::annotateAgreementHours($persons, $options, $prior, 2026);

        self::assertSame(220 * 60, $result[0]['dpp_annual_hours']['total_minutes']);
        self::assertSame('ok', $result[0]['dpp_annual_hours']['status']);
        self::assertSame([], $result[0]['warnings']);

        self::assertSame(240 * 60, $result[1]['dpp_annual_hours']['total_minutes']);
        self::assertSame(40 * 60, $result[1]['dpp_annual_hours']['imported_minutes']);
        self::assertSame('approaching', $result[1]['dpp_annual_hours']['status']);
        self::assertCount(1, $result[1]['warnings']);
        self::assertStringContainsString('Zbývá 60 h', $result[1]['warnings'][0]);

        self::assertArrayNotHasKey('dpp_annual_hours', $result[2]);
        self::assertSame([], $result[2]['warnings']);

        self::assertSame(300 * 60 + 30, $result[3]['dpp_annual_hours']['total_minutes']);
        self::assertSame('exceeded', $result[3]['dpp_annual_hours']['status']);
        self::assertStringContainsString('víc než 300 h', $result[3]['warnings'][0]);
    }

    /** @return array<string,mixed> */
    private static function person(string $name, int $employmentId, int $employeeId, int $workedMillihours): array
    {
        return [
            'display_name' => $name,
            'match' => [
                'status' => 'matched',
                'employment_id' => $employmentId,
                'employee_id' => $employeeId,
                'employee_name' => $name,
            ],
            'warnings' => [],
            '_metrics' => ['worked_hours' => ['millihours' => $workedMillihours, 'source' => 'A1']],
        ];
    }
}
