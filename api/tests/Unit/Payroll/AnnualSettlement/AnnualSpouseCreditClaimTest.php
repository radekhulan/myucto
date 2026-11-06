<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSpouseCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Podmínky slevy na manžela (§ 35bb) na hranicích. Rok 2026, limit příjmu 68 000 Kč.
 */
final class AnnualSpouseCreditClaimTest extends TestCase
{
    private const YEAR = 2026;
    private const LIMIT = 6_800_000;

    private const JAN = 1 << 0;
    private const MAR = 1 << 2;
    private const APR = 1 << 3;
    private const MAY = 1 << 4;
    private const JUN = 1 << 5;

    public function testFullYearWithYoungChildHasNoFailures(): void
    {
        $claim = $this->claim(0xFFF);

        self::assertSame([], $claim->conditionFailures(self::YEAR, self::LIMIT));
        self::assertSame(12, $claim->months());
    }

    /** Dítě narozené 15. 5. 2023 dovrší 3 roky 15. 5. 2026: 1. 5. ještě ne, 1. 6. už ano. */
    public function testChildTurningThreeInMayStillQualifiesForMay(): void
    {
        $claim = $this->claim(self::MAY, birth: '2023-05-15');

        self::assertSame([], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testChildTurningThreeInMayDoesNotQualifyForJune(): void
    {
        $claim = $this->claim(self::JUN, birth: '2023-05-15');

        self::assertSame(['child_not_under_three'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    /** Nárok květen i červen společně: červen shodí podmínku. */
    public function testMarkingJuneAmongOtherMonthsFailsChildAge(): void
    {
        $claim = $this->claim(self::MAY | self::JUN, birth: '2023-05-15');

        self::assertSame(['child_not_under_three'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    /** Dítě narozené 2. 3. 2026: na počátku března (1. 3.) ještě nebylo na světě, na počátku dubna ano. */
    public function testChildBornSecondOfMarchQualifiesFromApril(): void
    {
        $march = $this->claim(self::MAR, birth: '2026-03-02');
        $april = $this->claim(self::APR, birth: '2026-03-02');

        self::assertSame(['child_not_under_three'], $march->conditionFailures(self::YEAR, self::LIMIT));
        self::assertSame([], $april->conditionFailures(self::YEAR, self::LIMIT));
    }

    /** Dítě narozené přesně 1. 3. je na počátku března už na světě. */
    public function testChildBornOnFirstOfMonthQualifiesForThatMonth(): void
    {
        $claim = $this->claim(self::MAR, birth: '2026-03-01');

        self::assertSame([], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testSpouseIncomeAtLimitPasses(): void
    {
        $claim = $this->claim(0xFFF, income: self::LIMIT);

        self::assertSame([], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testSpouseIncomeOneHalerOverLimitFails(): void
    {
        $claim = $this->claim(0xFFF, income: self::LIMIT + 1);

        self::assertSame(['spouse_income_over_limit'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testUnknownSpouseIncomeFails(): void
    {
        $claim = $this->claim(0xFFF, income: null);

        self::assertSame(['spouse_income_missing'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testZtpPMonthOutsideClaimMonthsFails(): void
    {
        $claim = $this->claim(self::JAN | self::MAR, ztpMask: self::APR, ztpP: true);

        self::assertSame(['ztp_p_month_outside_claim'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testZtpPMonthsWithoutZtpPInEvidenceFail(): void
    {
        $claim = $this->claim(0xFFF, ztpMask: self::JAN, ztpP: false);

        self::assertSame(['ztp_p_not_in_evidence'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    public function testZtpPMonthsInsideClaimMonthsPass(): void
    {
        $claim = $this->claim(0xFFF, ztpMask: 0x007, ztpP: true);

        self::assertSame([], $claim->conditionFailures(self::YEAR, self::LIMIT));
        self::assertSame(3, $claim->ztpPMonths());
    }

    /** Vnuk se nepočítá (§ 35bb odst. 3). */
    public function testGrandchildDoesNotQualify(): void
    {
        $claim = $this->claim(0xFFF, childRelation: 'grandchild');

        self::assertSame(['child_relation'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    #[DataProvider('qualifyingChildRelations')]
    public function testOwnAdoptedAndInCareChildrenQualify(string $relation): void
    {
        $claim = $this->claim(0xFFF, childRelation: $relation);

        self::assertSame([], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    /** @return iterable<string,array{string}> */
    public static function qualifyingChildRelations(): iterable
    {
        yield 'vlastní' => ['child_own'];
        yield 'osvojené' => ['child_adopted'];
        yield 'v péči' => ['child_in_care'];
        yield 'dítě manžela' => ['child_of_spouse'];
    }

    public function testNonSpouseRelationFails(): void
    {
        $claim = $this->claim(0xFFF, spouseRelation: 'partner');

        self::assertSame(['spouse_relation'], $claim->conditionFailures(self::YEAR, self::LIMIT));
    }

    /** Manžel v evidenci od 15. 3.: na počátku března ještě ne, na počátku dubna ano. */
    public function testSpouseMustBeInEvidenceOnFirstDayOfMonth(): void
    {
        $march = $this->claim(self::MAR, spouseFrom: '2026-03-15');
        $april = $this->claim(self::APR, spouseFrom: '2026-03-15');

        self::assertSame(['spouse_not_in_evidence'], $march->conditionFailures(self::YEAR, self::LIMIT));
        self::assertSame([], $april->conditionFailures(self::YEAR, self::LIMIT));
    }

    // ---- konstruktor ----

    /** @param callable():AnnualSpouseCreditClaim $factory */
    #[DataProvider('invalidSpouseProvider')]
    public function testInvalidConstructionIsRejected(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string,array{callable}> */
    public static function invalidSpouseProvider(): iterable
    {
        $make = static fn (array $o) => static fn () => new AnnualSpouseCreditClaim(
            'm',
            'spouse',
            $o['from'] ?? '2020-01-01',
            $o['to'] ?? null,
            false,
            'd',
            'child_own',
            $o['birth'] ?? '2025-06-01',
            $o['mask'] ?? 0xFFF,
            $o['ztp'] ?? 0,
            array_key_exists('income', $o) ? $o['income'] : 0,
        );

        yield 'žádný měsíc' => [$make(['mask' => 0])];
        yield 'maska mimo rok' => [$make(['mask' => 0x1000])];
        yield 'záporná maska' => [$make(['mask' => -1])];
        yield 'ZTP/P maska mimo rok' => [$make(['ztp' => 0x1000])];
        yield 'záporná ZTP/P maska' => [$make(['ztp' => -1])];
        yield 'záporný příjem' => [$make(['income' => -1])];
        yield 'neexistující datum' => [$make(['birth' => '2026-02-30'])];
        yield 'špatný formát data' => [$make(['from' => '1.1.2020'])];
        yield 'špatné datum do' => [$make(['to' => '2026-13-01'])];
    }

    private function claim(
        int $mask,
        int $ztpMask = 0,
        bool $ztpP = false,
        ?int $income = 0,
        string $birth = '2025-06-01',
        string $childRelation = 'child_own',
        string $spouseRelation = 'spouse',
        string $spouseFrom = '2020-01-01',
    ): AnnualSpouseCreditClaim {
        return new AnnualSpouseCreditClaim(
            'spouse-1',
            $spouseRelation,
            $spouseFrom,
            null,
            $ztpP,
            'child-1',
            $childRelation,
            $birth,
            $mask,
            $ztpMask,
            $income,
            TaxEvidenceStatus::Verified,
        );
    }
}
