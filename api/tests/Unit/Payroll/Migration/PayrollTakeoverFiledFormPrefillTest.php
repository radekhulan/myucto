<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceCalculator;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverFiledFormPrefill;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverManualEntryService;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Návrh ručního zadání z přijatého hlášení: co hlášení nese, se opíše, co nenese,
 * se dopočítá stejně jako ve vlastním běhu a vyplněná čísla zůstanou.
 */
final class PayrollTakeoverFiledFormPrefillTest extends TestCase
{
    public function testLowIncomeMonthGetsHealthSplitAndNetLikeOwnPayrollRun(): void
    {
        $row = $this->prefill()->row(self::row(['gross_minor' => 450_000, 'social_base_minor' => 450_000]), self::form(), '2026-07-01', true);

        self::assertSame(32_000, $row['employee_social_minor']);
        self::assertSame(111_600, $row['employer_social_minor']);
        self::assertSame(261_900, $row['employee_health_minor']);
        self::assertSame(40_500, $row['employer_health_minor']);
        self::assertSame(241_600, $row['health_minimum_top_up_minor']);
        self::assertSame(2_240_000, $row['health_base_minor']);
        self::assertSame(67_500, $row['advance_tax_minor']);
        self::assertSame(88_600, $row['net_minor']);
        self::assertSame(88_600, $row['net_payable_minor']);
        self::assertSame(31, $row['insurance_days']);
        self::assertSame(
            ['employer_health_minor', 'health_minimum_top_up_minor', 'health_base_minor', 'net_minor'],
            $row['derived'],
        );
    }

    public function testFilledValuesStayAndConcurrentMonthIsNotDerived(): void
    {
        $row = $this->prefill()->row(
            self::row(['gross_minor' => 450_000, 'employee_social_minor' => 99_900]),
            self::form(),
            '2026-07-01',
            false,
        );

        self::assertSame(99_900, $row['employee_social_minor']);
        self::assertSame(0, $row['employer_health_minor']);
        self::assertSame(0, $row['net_minor']);
        self::assertSame([], $row['derived']);
    }

    public function testDeductionsLeavePayoutEmpty(): void
    {
        $row = $this->prefill()->row(self::row(), self::form(deductions: true), '2026-07-01', true);

        self::assertSame(88_600, $row['net_minor']);
        self::assertSame(0, $row['net_payable_minor']);
    }

    private function prefill(): PayrollTakeoverFiledFormPrefill
    {
        $rulesets = CzechPayrollRulesets2026::provider();

        return new PayrollTakeoverFiledFormPrefill(
            (new ReflectionClass(JmhzExternalSubmissionStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PayrollTakeoverManualEntryService::class))->newInstanceWithoutConstructor(),
            new MonthlyHealthInsuranceCalculator($rulesets),
            $rulesets,
        );
    }

    /**
     * @param array<string,int> $overrides
     * @return array<string,mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'employment_id' => 11,
            'month' => 7,
            'gross_minor' => 0,
            'net_minor' => 0,
            'deductions_minor' => 0,
            'net_payable_minor' => 0,
            'social_base_minor' => 0,
            'health_base_minor' => 0,
            'employee_social_minor' => 0,
            'employee_health_minor' => 0,
            'employer_social_minor' => 0,
            'employer_health_minor' => 0,
            'health_minimum_top_up_minor' => 0,
            'advance_base_minor' => 0,
            'advance_tax_minor' => 0,
            'withholding_base_minor' => 0,
            'withholding_tax_minor' => 0,
            'tax_bonus_minor' => 0,
            'insurance_days' => 0,
            'excluded_days' => 0,
            'worked_days_hundredths' => 0,
            'worked_minutes' => 0,
        ];
    }

    private static function form(bool $deductions = false): JmhzReportForm
    {
        return new JmhzReportForm(
            1,
            'A0000000-0000-4000-8000-000000000001',
            'R',
            true,
            'cinnostKS',
            hasSummary: true,
            incomeTotal: 4_500,
            advance: ['base' => 4_500, 'computed' => 675, 'after_credits' => 675, 'bonus' => 0],
            socialBase: 4_500,
            employeeHealth: 2_619,
            taxableIncome: 4_500,
            eldp: ['code' => 'S++', 'insurance_days' => 31, 'excluded_days' => 0],
            employeeSocial: 320,
            employerSocial: 1_116,
            deductionsRecorded: $deductions,
        );
    }
}
