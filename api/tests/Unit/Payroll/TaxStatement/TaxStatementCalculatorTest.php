<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\TaxStatement;

use MyInvoice\Service\Payroll\TaxStatement\DependentActivityStatement;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementBasis;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementCalculator;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementMonth;
use MyInvoice\Service\Payroll\TaxStatement\WithholdingTaxStatement;
use MyInvoice\Service\Payroll\TaxStatement\WorkplaceHeadcount;
use PHPUnit\Framework\TestCase;

final class TaxStatementCalculatorTest extends TestCase
{
    /**
     * @param array<int,array<string,int|bool>> $overrides
     */
    private function basis(
        array $overrides = [],
        int $nonResidents = 0,
        ?array $workplaces = null,
    ): TaxStatementBasis {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $values = ($overrides[$month] ?? []) + [
                'headcount' => 3,
                'advance' => 1_000_00,
                'bonus' => 0,
                'withholding' => 200_00,
                'overpayment' => 0,
                'topup' => 0,
                'remitted_advance' => 1_000_00,
                'remitted_withholding' => 200_00,
                'has_run' => true,
            ];
            $months[] = new TaxStatementMonth(
                $month,
                (int) $values['headcount'],
                (int) $values['advance'],
                (int) $values['bonus'],
                (int) $values['withholding'],
                (int) $values['overpayment'],
                (int) $values['topup'],
                (int) $values['remitted_advance'],
                (int) $values['remitted_withholding'],
                (bool) $values['has_run'],
            );
        }

        return new TaxStatementBasis(
            2025,
            $months,
            $workplaces ?? [new WorkplaceHeadcount('554782', 'Hlavní město Praha', 'Hlavní město Praha', 3)],
            $nonResidents,
        );
    }

    public function testMonthlyColumnsFollowTheForm(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([
            3 => ['overpayment' => 4_500_00, 'topup' => 1_200_00, 'bonus' => 300_00],
        ]));

        $march = $statement->months[3];
        self::assertSame(1_000, $march->advanceDue);
        // Sl. 2 se od sl. 1 může lišit jen § 38i opravou, kterou modul nevede.
        self::assertSame(1_000, $march->advanceWithheld);
        self::assertSame(4_500, $march->annualOverpayment);
        // Sl. 5 sčítá měsíční bonus a doplatek na bonusu z ročního zúčtování.
        self::assertSame(1_500, $march->bonusPaid);
        // Sl. 8 = sl. 4 + sl. 5.
        self::assertSame(6_000, $march->adjustments());
        // Sl. 9 = sl. 1 − sl. 3 − sl. 4 − sl. 5.
        self::assertSame(-5_000, $march->settledAmount());
    }

    public function testTotalIsTheColumnwiseSumOfMonths(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([
            7 => ['bonus' => 900_00],
        ]));

        $total = $statement->total();
        self::assertSame(12_000, $total->advanceDue);
        self::assertSame(900, $total->bonusPaid);
        self::assertSame(900, $total->adjustments());
        self::assertSame(11_100, $total->settledAmount());
        self::assertSame(12_000, $total->remitted);
    }

    public function testOverpaymentPayoutsAreTimeResolvedByMonth(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([
            2 => ['overpayment' => 1_000_00],
            3 => ['overpayment' => 2_500_00, 'topup' => 400_00],
        ]));

        self::assertSame(
            [['month' => 2, 'amount' => 1_000], ['month' => 3, 'amount' => 2_500]],
            $statement->overpaymentPayouts,
        );
        self::assertSame(3_500, $statement->annualOverpaymentTotal);
        self::assertSame(400, $statement->annualBonusTopUpTotal);
    }

    public function testMonthWithoutApprovedRunGetsNoRowAndAWarning(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([
            11 => ['has_run' => false, 'advance' => 0, 'withholding' => 0,
                   'remitted_advance' => 0, 'remitted_withholding' => 0, 'headcount' => 0],
        ]));

        self::assertArrayNotHasKey(11, $statement->months);
        self::assertArrayNotHasKey(11, $statement->headcounts);
        self::assertNotEmpty(array_filter(
            $statement->warnings,
            static fn (string $warning): bool => str_contains($warning, 'nemají schválený mzdový běh'),
        ));
    }

    public function testYearWithoutAnyApprovedRunIsRefused(): void
    {
        $overrides = [];
        for ($month = 1; $month <= 12; $month++) {
            $overrides[$month] = [
                'has_run' => false, 'advance' => 0, 'withholding' => 0,
                'remitted_advance' => 0, 'remitted_withholding' => 0, 'headcount' => 0,
            ];
        }

        $this->expectException(\DomainException::class);
        (new TaxStatementCalculator())->dependentActivity($this->basis($overrides));
    }

    public function testNonResidentsProduceAnAnnexWarningInsteadOfAHalfFilledAnnex(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([], 2));

        self::assertSame(2, $statement->nonResidentCount);
        self::assertNotEmpty(array_filter(
            $statement->warnings,
            static fn (string $warning): bool => str_contains($warning, 'Příloha č. 2'),
        ));
    }

    public function testWorkplaceWithoutMunicipalityIsDroppedAndReported(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([], 0, [
            new WorkplaceHeadcount('554782', 'Hlavní město Praha', 'Hlavní město Praha', 2),
            new WorkplaceHeadcount(null, null, null, 1),
        ]));

        self::assertCount(1, $statement->workplaces);
        self::assertNotEmpty(array_filter(
            $statement->warnings,
            static fn (string $warning): bool => str_contains($warning, 'obec místa výkonu'),
        ));
    }

    public function testHalfCrownAmountsAreRoundedWithAWarning(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity($this->basis([
            5 => ['advance' => 1_000_49],
        ]));

        self::assertSame(1_000, $statement->months[5]->advanceDue);
        self::assertNotEmpty(array_filter(
            $statement->warnings,
            static fn (string $warning): bool => str_contains($warning, 'celých korunách'),
        ));
    }

    public function testWithholdingStatementKeepsHellersAndSkipsEmptyMonths(): void
    {
        $statement = (new TaxStatementCalculator())->withholdingTax($this->basis([
            4 => ['withholding' => 0, 'remitted_withholding' => 0],
            6 => ['withholding' => 150_50, 'remitted_withholding' => 150_50],
        ]));

        self::assertArrayNotHasKey(4, $statement->months);
        self::assertSame(150_50, $statement->months[6]->taxDueMinor);
        self::assertSame(WithholdingTaxStatement::DRUH_PRIJMU_FO, $statement->incomeKind);
        // Ř. 5 části II. = ř. 4 − ř. 1; odvedeno přesně tolik, kolik mělo být sraženo.
        self::assertSame(0, $statement->balanceMinor());
    }

    public function testVariantMustBeOneOfTheFourFormTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TaxStatementCalculator())->dependentActivity($this->basis(), ['variant' => 'X']);
    }

    public function testAdditionalStatementKeepsItsVariant(): void
    {
        $statement = (new TaxStatementCalculator())->dependentActivity(
            $this->basis(),
            ['variant' => DependentActivityStatement::TYP_DODATECNE],
        );

        self::assertSame(DependentActivityStatement::TYP_DODATECNE, $statement->variant);
    }
}
