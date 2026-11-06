<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Calculation\MoneyRateCalculator;
use MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator;
use MyInvoice\Service\Payroll\Component\PayrollRecurringComponentValidator;
use PHPUnit\Framework\TestCase;

final class PayrollRecurringComponentTest extends TestCase
{
    public function testHourlyMonthCannotMaterializeMonthlyBaseOrMonthlyPercentage(): void
    {
        $calculator = new PayrollRecurringAmountCalculator(new MoneyRateCalculator());
        $base = [
            'hourly_remuneration_in_period' => true,
            'component_kind' => 'base_wage',
            'calculation_kind' => 'fixed_amount',
            'amount_minor' => 4_200_000,
            'monthly_gross_minor' => 4_200_000,
            'rate_basis_points' => 1000,
            'allocation_rule' => 'full_month',
            'employment_start' => '2026-01-01',
            'employment_end' => null,
            'employment_effective_status' => 'active',
            'employment_suspended_in_month' => false,
        ];
        foreach ([$base, array_replace($base, [
            'component_kind' => 'bonus',
            'calculation_kind' => 'employment_gross_basis_points',
        ])] as $row) {
            $result = $calculator->calculate($row, '2026-06-01');
            self::assertSame('manual_review', $result['status']);
            self::assertNull($result['amount_minor']);
            self::assertStringNotContainsString('výkaznictví', (string) $result['blocker']);
            self::assertStringContainsString('hodinový výpočet', $calculator->calculate($base, '2026-06-01')['blocker'] ?? '');
        }
        $percent = $calculator->calculate(array_replace($base, [
            'component_kind' => 'bonus',
            'calculation_kind' => 'employment_gross_basis_points',
        ]), '2026-06-01');
        self::assertStringContainsString('Určete základ složky', (string) $percent['blocker']);
        // Pevná odměna není měsíční základ ani podíl ze smluvní měsíční mzdy.
        $bonus = $calculator->calculate(array_replace($base, ['component_kind' => 'bonus']), '2026-06-01');
        self::assertSame(4_200_000, $bonus['amount_minor']);
    }

    public function testValidatorAcceptsClosedFormulaAndRejectsAmbiguousAmount(): void
    {
        $validator = new PayrollRecurringComponentValidator();
        $validated = $validator->validate([
            'employment_id' => 10,
            'component_id' => 20,
            'calculation_kind' => 'employment_gross_basis_points',
            'amount_minor' => null,
            'rate_basis_points' => 1250,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'allocation_rule' => 'calendar_days',
            'maximum_amount_minor' => 500000,
            'note' => 'Syntetický předpis',
            'is_active' => true,
        ]);

        self::assertSame(1250, $validated['rate_basis_points']);
        self::assertNull($validated['amount_minor']);

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate([
            ...$validated,
            'amount_minor' => 1000,
        ]);
    }

    public function testCalculatorUsesIntegerHalfUpAllocationFormulaAndCap(): void
    {
        $calculator = new PayrollRecurringAmountCalculator(new MoneyRateCalculator());
        $calendar = $calculator->calculate([
            'calculation_kind' => 'fixed_amount',
            'amount_minor' => 10001,
            'rate_basis_points' => null,
            'allocation_rule' => 'calendar_days',
            'valid_from' => '2026-06-16',
            'valid_to' => null,
            'maximum_amount_minor' => null,
            'employment_start' => '2026-01-01',
            'employment_end' => null,
            'employment_effective_status' => 'active',
            'employment_suspended_in_month' => false,
        ], '2026-06-01');
        self::assertSame(5001, $calendar['amount_minor']);
        self::assertSame(15, $calendar['trace']['active_days']);

        $percentage = $calculator->calculate([
            'calculation_kind' => 'employment_gross_basis_points',
            'amount_minor' => null,
            'rate_basis_points' => 1000,
            'monthly_gross_minor' => 4000001,
            'allocation_rule' => 'full_month',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'maximum_amount_minor' => 300000,
            'employment_start' => '2026-01-01',
            'employment_end' => null,
            'employment_effective_status' => 'active',
            'employment_suspended_in_month' => false,
        ], '2026-06-01');
        self::assertSame(300000, $percentage['amount_minor']);
        self::assertTrue($percentage['trace']['maximum_applied']);
    }

    public function testManualAllocationDoesNotCreateInventedAmount(): void
    {
        $result = (new PayrollRecurringAmountCalculator(new MoneyRateCalculator()))
            ->calculate([
                'calculation_kind' => 'fixed_amount',
                'amount_minor' => 10000,
                'rate_basis_points' => null,
                'allocation_rule' => 'hours',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'maximum_amount_minor' => null,
                'employment_start' => '2026-01-01',
                'employment_end' => null,
                'employment_effective_status' => 'active',
                'employment_suspended_in_month' => false,
            ], '2026-06-01');

        self::assertSame('manual_review', $result['status']);
        self::assertNull($result['amount_minor']);
    }
}
