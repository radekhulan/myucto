<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunAttendanceOvertimeWage;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Dosažená mzda za přesčas z docházky (§ 114 odst. 1 zákoníku práce):
 * upozornění na automaticky založený vstup, varování na ruční duplicitu
 * a nález, když se mzda nezaložila.
 */
final class PayrollRunAttendanceOvertimeWageTest extends TestCase
{
    private const EMPLOYMENT = [
        'employment_id' => 7,
        'employee_id' => 3,
        'full_name' => 'Syntetický Docházkový',
        'remuneration_type' => 'monthly',
        'monthly_gross_minor' => 4_200_000,
    ];

    public function testAutomaticWageIsAnnouncedWithPersonHoursAmountAndLinkToInputs(): void
    {
        $validations = $this->validate([self::autoWage(143_200, 6_000), self::attendancePremium()]);

        self::assertCount(1, $validations);
        self::assertSame('overtime_wage_from_attendance', $validations[0]->code);
        self::assertSame('warning', $validations[0]->severity);
        self::assertFalse($validations[0]->requiresOverride);
        self::assertSame(7, $validations[0]->entityId);
        self::assertStringContainsString('Syntetický Docházkový: schválení docházky založilo dosaženou mzdu za 6 h přesčasu 1 432 Kč', $validations[0]->message);
        self::assertStringContainsString('ruční vstup zrušte, jinak se vyplatí dvakrát', $validations[0]->message);
        self::assertSame('/payroll/components?tab=inputs&period=2026-04&employment=7', $validations[0]->remediationPath);
        // Upozornění jde po vztazích trvale skrýt, varování s potvrzením ne.
        self::assertTrue(PayrollWarningSuppressionCatalog::isHideable('overtime_wage_from_attendance', 'warning', false));
        self::assertSame('employment', PayrollWarningSuppressionCatalog::subjectType('overtime_wage_from_attendance'));
        self::assertFalse(PayrollWarningSuppressionCatalog::isHideableCode('overtime_wage_manual_duplicate'));
    }

    public function testDifferenceRowsOfTheAutomaticWageAreSummed(): void
    {
        $validations = $this->validate([self::autoWage(143_200, 6_000), self::autoWage(47_700, null)]);

        self::assertStringContainsString('za 6 h přesčasu 1 909 Kč', $validations[0]->message);
    }

    public function testManualInputNextToTheAutomaticWageNeedsConfirmation(): void
    {
        foreach (['MZDA_HODINOVA', 'MZDA_DOSAZENA_PRESCAS', 'PREMIE_PRIPLATKY', 'PRIPLATEK_PRESCAS'] as $code) {
            $validations = $this->validate([
                self::autoWage(143_200, 6_000),
                self::manual($code, 'Ruční ' . $code, 143_200),
            ]);

            self::assertCount(1, $validations, $code);
            self::assertSame('overtime_wage_manual_duplicate', $validations[0]->code, $code);
            self::assertTrue($validations[0]->requiresOverride, $code);
            self::assertStringContainsString("Ruční {$code} 1 432 Kč", $validations[0]->message);
            self::assertStringContainsString('ruční vstup zrušte', $validations[0]->message);
            self::assertLessThanOrEqual(500, mb_strlen($validations[0]->message));
            self::assertSame('/payroll/components?tab=inputs&period=2026-04&employment=7', $validations[0]->remediationPath);
        }
    }

    public function testOtherManualInputsAreNotADuplicate(): void
    {
        $validations = $this->validate([
            self::autoWage(143_200, 6_000),
            self::manual('ODMENA', 'Odměna', 500_000),
            self::manual('MZDA_MESICNI', 'Mzda měsíční', 4_200_000),
            self::manual('MZDA_HODINOVA', 'Zrušeno', 0),
        ]);

        self::assertSame(['overtime_wage_from_attendance'], array_column($validations, 'code'));
    }

    public function testMissingAgreedWageIsReportedWithLinkToEmploymentTerms(): void
    {
        $validations = PayrollRunAttendanceOvertimeWage::validations(
            [['monthly_gross_minor' => null] + self::EMPLOYMENT],
            [7 => [self::attendancePremium()]],
            [7 => ['status' => 'approved', 'overtime_millihours' => 6_000]],
            '2026-04-01',
        );

        self::assertCount(1, $validations);
        self::assertSame('overtime_achieved_wage_basis_missing', $validations[0]->code);
        self::assertTrue($validations[0]->requiresOverride);
        self::assertStringContainsString('Syntetický Docházkový: docházka eviduje 6 h přesčasu', $validations[0]->message);
        self::assertStringContainsString('chybí sjednaná měsíční mzda', $validations[0]->message);
        self::assertSame(
            '/payroll/people?person=3&employment=7&panel=employment_terms&field=monthly_gross_minor',
            $validations[0]->remediationPath,
        );
    }

    public function testAttendanceApprovedBeforeTheWageWasCreatedAsksForReapproval(): void
    {
        $validations = PayrollRunAttendanceOvertimeWage::validations(
            [self::EMPLOYMENT],
            [7 => [self::attendancePremium()]],
            [7 => ['status' => 'approved', 'overtime_millihours' => 6_000]],
            '2026-04-01',
        );

        self::assertSame(['overtime_achieved_wage_missing'], array_column($validations, 'code'));
        self::assertStringContainsString('schvalte ji znovu', $validations[0]->message);
        self::assertSame('/payroll/time?employment=7&period=2026-04', $validations[0]->remediationPath);
    }

    public function testNothingIsReportedWhereNoWageIsDueOrItIsPaidAnotherWay(): void
    {
        $month = [7 => ['status' => 'approved', 'overtime_millihours' => 6_000]];
        // Mzda sjednaná s přihlédnutím k přesčasu: docházka příplatek nezakládá.
        self::assertSame([], PayrollRunAttendanceOvertimeWage::validations([self::EMPLOYMENT], [7 => []], $month, '2026-04-01'));
        // Hodinová mzda: přesčas platí výpočet hodinové mzdy.
        self::assertSame([], PayrollRunAttendanceOvertimeWage::validations(
            [['remuneration_type' => 'hourly', 'monthly_gross_minor' => null] + self::EMPLOYMENT],
            [7 => [self::attendancePremium()]],
            $month,
            '2026-04-01',
        ));
        // Účetní dosaženou mzdu doplnila ručně.
        self::assertSame([], PayrollRunAttendanceOvertimeWage::validations(
            [['monthly_gross_minor' => null] + self::EMPLOYMENT],
            [7 => [self::attendancePremium(), self::manual('MZDA_HODINOVA', 'Mzda hodinová', 143_200)]],
            $month,
            '2026-04-01',
        ));
        // Docházka bez přesčasu a neschválená docházka.
        self::assertSame([], PayrollRunAttendanceOvertimeWage::validations([self::EMPLOYMENT], [7 => []], [7 => ['status' => 'approved', 'overtime_millihours' => 0]], '2026-04-01'));
        self::assertSame([], PayrollRunAttendanceOvertimeWage::validations([['monthly_gross_minor' => null] + self::EMPLOYMENT], [7 => []], [7 => ['status' => 'open', 'overtime_millihours' => 6_000]], '2026-04-01'));
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return list<\MyInvoice\Service\Payroll\Run\PayrollRunValidation>
     */
    private function validate(array $inputs): array
    {
        return PayrollRunAttendanceOvertimeWage::validations(
            [self::EMPLOYMENT],
            [7 => $inputs],
            [7 => ['status' => 'approved', 'overtime_millihours' => 6_000]],
            '2026-04-01',
        );
    }

    /** @return array<string,mixed> */
    private static function autoWage(int $amount, ?int $millihours): array
    {
        return [
            'source_kind' => 'time',
            'external_id' => 'surcharge:overtime_wage:2026-04-01:1',
            'amount_minor' => $amount,
            'quantity_milliunits' => $millihours,
            'component_snapshot_json' => json_encode(['code' => 'MZDA_HODINOVA', 'name' => 'Mzda hodinová']),
        ];
    }

    /** @return array<string,mixed> */
    private static function attendancePremium(): array
    {
        return [
            'source_kind' => 'time',
            'external_id' => 'surcharge:overtime:2026-04-01:1',
            'amount_minor' => 37_500,
            'quantity_milliunits' => 6_000,
            'component_snapshot_json' => json_encode(['code' => 'PRIPLATEK_PRESCAS', 'name' => 'Příplatek za přesčas']),
        ];
    }

    /** @return array<string,mixed> */
    private static function manual(string $code, string $name, int $amount): array
    {
        return [
            'source_kind' => 'manual',
            'external_id' => 'rucne-' . $code,
            'amount_minor' => $amount,
            'quantity_milliunits' => null,
            'component_snapshot_json' => json_encode(['code' => $code, 'name' => $name]),
        ];
    }
}
