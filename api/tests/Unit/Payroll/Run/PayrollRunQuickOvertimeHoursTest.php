<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunQuickOvertimeHours;
use PHPUnit\Framework\TestCase;

/**
 * Přesčas zaplacený rychlým měsíčním vstupem bez hodin ve schválené docházce
 * zastaví schválení běhu varováním s odkazem na docházku.
 */
final class PayrollRunQuickOvertimeHoursTest extends TestCase
{
    private const EMPLOYMENT = ['employment_id' => 7, 'full_name' => 'Syntetický Přesčasový'];

    public function testQuickOvertimeMissingInAttendanceIsNamedWithHoursAndLink(): void
    {
        $validations = PayrollRunQuickOvertimeHours::validations(
            [self::EMPLOYMENT],
            [7 => [self::quickPremium(6_500)]],
            [7 => self::month('approved', 0)],
            '2026-04-01',
        );

        self::assertCount(1, $validations);
        self::assertSame('warning', $validations[0]->severity);
        self::assertTrue($validations[0]->requiresOverride);
        self::assertSame('quick_overtime_hours_not_in_attendance', $validations[0]->code);
        self::assertSame('employment', $validations[0]->entityType);
        self::assertSame(7, $validations[0]->entityId);
        self::assertStringContainsString('Syntetický Přesčasový: rychlý měsíční vstup platí 6,5 h přesčasu', $validations[0]->message);
        self::assertStringContainsString('eviduje 0', $validations[0]->message);
        self::assertLessThanOrEqual(500, mb_strlen($validations[0]->message), 'Sloupec zprávy validace má 500 znaků.');
        self::assertSame('/payroll/time?employment=7&period=2026-04', $validations[0]->remediationPath);
    }

    public function testNotProvidedAttendanceOvertimeCountsAsZero(): void
    {
        self::assertCount(1, PayrollRunQuickOvertimeHours::validations(
            [self::EMPLOYMENT],
            [7 => [self::quickPremium(2_000)]],
            [7 => self::month('approved', null)],
            '2026-04-01',
        ));
    }

    public function testAttendanceCoveringThePaidHoursPasses(): void
    {
        foreach ([6_000, 8_000] as $attendance) {
            self::assertSame([], PayrollRunQuickOvertimeHours::validations(
                [self::EMPLOYMENT],
                [7 => [self::quickPremium(6_000)]],
                [7 => self::month('approved', $attendance)],
                '2026-04-01',
            ));
        }
    }

    public function testOtherInputsAndUnapprovedAttendanceAreLeftToTheirOwnChecks(): void
    {
        // Příplatek založený schválením docházky a ruční vstup mimo rychlé zadání.
        $rows = [7 => [
            ['source_kind' => 'time', 'external_id' => 'time-surcharge:overtime', 'quantity_milliunits' => 6_000],
            ['source_kind' => 'manual', 'external_id' => 'rucne-1', 'quantity_milliunits' => 6_000],
            ['source_kind' => 'manual', 'external_id' => 'quick-monthly:MZDA_MESICNI', 'quantity_milliunits' => null],
        ]];
        self::assertSame([], PayrollRunQuickOvertimeHours::validations([self::EMPLOYMENT], $rows, [7 => self::month('approved', 0)], '2026-04-01'));

        $quick = [7 => [self::quickPremium(6_000)]];
        self::assertSame([], PayrollRunQuickOvertimeHours::validations([self::EMPLOYMENT], $quick, [7 => self::month('open', 0)], '2026-04-01'));
        self::assertSame([], PayrollRunQuickOvertimeHours::validations([self::EMPLOYMENT], $quick, [], '2026-04-01'));
        self::assertSame([], PayrollRunQuickOvertimeHours::validations(
            [self::EMPLOYMENT],
            $quick,
            [7 => ['status' => 'approved', 'summary_id' => null, 'overtime_millihours' => null]],
            '2026-04-01',
        ));
    }

    /** @return array<string,mixed> */
    private static function quickPremium(int $millihours): array
    {
        return [
            'source_kind' => 'manual',
            'external_id' => 'quick-monthly:PRIPLATEK_PRESCAS',
            'quantity_milliunits' => $millihours,
        ];
    }

    /** @return array<string,mixed> */
    private static function month(string $status, ?int $overtime): array
    {
        return ['status' => $status, 'summary_id' => 3, 'overtime_millihours' => $overtime];
    }
}
