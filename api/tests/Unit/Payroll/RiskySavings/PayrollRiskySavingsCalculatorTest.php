<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\RiskySavings;

use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsCalculator;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use PHPUnit\Framework\TestCase;

final class PayrollRiskySavingsCalculatorTest extends TestCase
{
    public function testCalculatesFourPercentAndRoundsUpToWholeCrown(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_234_567,
            $this->evidence(24),
        );

        self::assertSame('calculated', $result['status']);
        self::assertSame(49_400, $result['contribution_minor']);
        self::assertSame('2026-09-30', $result['payment_due_on']);
    }

    public function testExactCrownIsNotRoundedAgain(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            $this->evidence(24),
        );

        self::assertSame(40_000, $result['contribution_minor']);
    }

    public function testUsesUncappedAssessmentBase(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            20_000_000,
            $this->evidence(24),
        );

        self::assertSame(800_000, $result['contribution_minor']);
    }

    public function testFewerThanThreeRiskShiftsCreatesAuditableZero(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            $this->evidence(23),
        );

        self::assertSame('not_due', $result['status']);
        self::assertSame(0, $result['contribution_minor']);
    }

    public function testPeriodBeforeLegalEffectiveDateCreatesAuditableZero(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2025-12-01',
            1_000_000,
            [
                ...$this->evidence(24),
                'right_claimed_on' => '2025-11-30',
            ],
        );

        self::assertSame('not_due', $result['status']);
        self::assertSame(0, $result['contribution_minor']);
        self::assertSame([], $result['issues']);
    }

    public function testExactlyTwentyFourEighthsCreatesObligation(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            $this->evidence(24),
        );

        self::assertSame('calculated', $result['status']);
    }

    public function testClaimFromLastDayOfPreviousMonthIsEffective(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            $this->evidence(24),
        );

        self::assertSame('calculated', $result['status']);
    }

    public function testClaimMadeDuringPeriodIsAuditableButNotYetDue(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            [
                ...$this->evidence(24),
                'right_claimed_on' => '2026-08-01',
            ],
        );

        self::assertSame('not_due', $result['status']);
        self::assertSame(0, $result['contribution_minor']);
        self::assertSame([], $result['issues']);
    }

    public function testOnlyEnumeratedCategoryThreeRiskCreatesObligation(): void
    {
        foreach (['vibration', 'cold', 'heat', 'dynamic_physical_load'] as $factor) {
            $result = $this->calculator()->calculate(
                17,
                '2026-08-01',
                1_000_000,
                [...$this->evidence(24), 'risk_factor' => $factor],
            );
            self::assertSame('calculated', $result['status'], $factor);
        }

        $wrongFactor = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            [...$this->evidence(24), 'risk_factor' => 'noise'],
        );
        self::assertSame('manual_review', $wrongFactor['status']);
        self::assertContains('risky_savings_risk_factor_invalid', $wrongFactor['issues']);

        $wrongCategory = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            [...$this->evidence(24), 'work_category' => 2],
        );
        self::assertSame('manual_review', $wrongCategory['status']);
        self::assertContains('risky_savings_work_category_invalid', $wrongCategory['issues']);
    }

    public function testMissingInformationDutyProducesWarningButDoesNotChangeAmount(): void
    {
        $evidence = $this->evidence(24);
        $evidence['employee_informed_on'] = null;

        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            $evidence,
        );

        self::assertSame('calculated', $result['status']);
        self::assertSame(40_000, $result['contribution_minor']);
        self::assertSame(
            ['risky_savings_employee_not_informed'],
            (new PayrollRiskySavingsPolicy())->warnings($evidence, '2026-08-01'),
        );
    }

    public function testDueDateIsEndOfFollowingMonthAcrossYearBoundary(): void
    {
        $result = $this->calculator()->calculate(
            17,
            '2026-12-01',
            1_000_000,
            [
                ...$this->evidence(24),
                'right_claimed_on' => '2026-11-30',
            ],
        );

        self::assertSame('2027-01-31', $result['payment_due_on']);
    }

    public function testUnapprovedEvidenceFailsClosed(): void
    {
        $evidence = $this->evidence(24);
        $evidence['status'] = 'draft';

        $result = $this->calculator()->calculate(
            17,
            '2026-08-01',
            1_000_000,
            $evidence,
        );

        self::assertSame('manual_review', $result['status']);
        self::assertNull($result['contribution_minor']);
        self::assertSame([
            'risky_savings_evidence_not_approved',
        ], $result['issues']);
    }

    /** @return array<string,mixed> */
    private function evidence(int $eighths): array
    {
        return [
            'id' => 91,
            'status' => 'approved',
            'risk_factor' => 'vibration',
            'work_category' => 3,
            'qualifying_shift_eighths' => $eighths,
            'right_claimed_on' => '2026-07-31',
            'employee_informed_on' => '2026-07-01',
            'pension_company' => 'Testovací penzijní společnost',
            'product_reference' => 'SYNTHETIC-PRODUCT',
            'institution_account_id' => 44,
            'institution_account_row_version' => 2,
            'institution_account_hash' => str_repeat('a', 64),
            'institution_account_masked' => '******0005 / 0100',
            'variable_symbol' => '123456',
            'specific_symbol' => null,
        ];
    }

    private function calculator(): PayrollRiskySavingsCalculator
    {
        return new PayrollRiskySavingsCalculator(new PayrollRiskySavingsPolicy());
    }
}
