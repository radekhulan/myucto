<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Deadline;

use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Report\CzechWorkingDays;
use PHPUnit\Framework\TestCase;

final class PayrollLevyDeadlinePolicyTest extends TestCase
{
    private PayrollLevyDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PayrollLevyDeadlinePolicy();
    }

    public function testMovesSaturdayDueDateToTheFollowingMonday(): void
    {
        foreach ([
            PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
            PayrollLevyDeadlinePolicy::HEALTH_INSURANCE,
            PayrollLevyDeadlinePolicy::ADVANCE_TAX,
        ] as $levy) {
            $window = $this->policy->forPeriod($levy, '2026-05-01');

            self::assertSame('2026-06-20', $window->statutoryDueOn);
            self::assertSame('Sat', $this->weekday($window->statutoryDueOn));
            self::assertSame('2026-06-22', $window->dueOn);
            self::assertTrue($window->isShifted);
            self::assertSame('business_days', $window->calendarBasis);
        }
    }

    public function testMovesSundayDueDateToTheFollowingMonday(): void
    {
        $window = $this->policy->forPeriod(
            PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
            '2026-08-01',
        );

        self::assertSame('2026-09-20', $window->statutoryDueOn);
        self::assertSame('Sun', $this->weekday($window->statutoryDueOn));
        self::assertSame('2026-09-21', $window->dueOn);
        self::assertTrue($window->isShifted);
    }

    /**
     * 20. 4. 2030 je sobota, 21. 4. neděle a 22. 4. Velikonoční pondělí —
     * termín tedy padne až na úterý 23. 4. Tohle je případ, na kterém by se
     * posun počítaný jen přes víkend rozešel se zákonem.
     */
    public function testMovesDueDateOverEasterMondayToTheNextWorkingDay(): void
    {
        $easter = CzechWorkingDays::easterSunday(2030);
        self::assertSame('2030-04-21', $easter->format('Y-m-d'));

        $window = $this->policy->forPeriod(
            PayrollLevyDeadlinePolicy::HEALTH_INSURANCE,
            '2030-03-01',
        );

        self::assertSame('2030-04-20', $window->statutoryDueOn);
        self::assertSame('2030-04-23', $window->dueOn);
        self::assertTrue($window->isShifted);
    }

    /**
     * Srážková daň za 11/2028 je splatná 31. 12. 2028 — to je neděle, po ní
     * pondělní Nový rok. Skutečný termín je až úterý 2. 1. 2029, tedy i přes
     * hranici roku.
     */
    public function testMovesLastDayOfYearOverNewYearHoliday(): void
    {
        $window = $this->policy->forPeriod(
            PayrollLevyDeadlinePolicy::WITHHOLDING_TAX,
            '2028-11-01',
        );

        self::assertSame('2028-12-31', $window->statutoryDueOn);
        self::assertSame('Sun', $this->weekday($window->statutoryDueOn));
        self::assertSame('2029-01-02', $window->dueOn);
        self::assertTrue($window->isShifted);

        $saturday = $this->policy->forPeriod(
            PayrollLevyDeadlinePolicy::WITHHOLDING_TAX,
            '2033-11-01',
        );

        self::assertSame('2033-12-31', $saturday->statutoryDueOn);
        self::assertSame('2034-01-02', $saturday->dueOn);
    }

    public function testKeepsDueDateThatAlreadyIsAWorkingDay(): void
    {
        $advance = $this->policy->forPeriod(
            PayrollLevyDeadlinePolicy::ADVANCE_TAX,
            '2026-06-01',
        );

        self::assertSame('2026-07-20', $advance->statutoryDueOn);
        self::assertSame('Mon', $this->weekday($advance->dueOn));
        self::assertSame('2026-07-20', $advance->dueOn);
        self::assertFalse($advance->isShifted);

        $withholding = $this->policy->forPeriod(
            PayrollLevyDeadlinePolicy::WITHHOLDING_TAX,
            '2026-06-01',
        );

        self::assertSame('2026-07-31', $withholding->statutoryDueOn);
        self::assertSame('2026-07-31', $withholding->dueOn);
        self::assertFalse($withholding->isShifted);
    }

    public function testInsuranceOpensOnTheFirstDayOfTheFollowingMonth(): void
    {
        foreach ([
            PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
            PayrollLevyDeadlinePolicy::HEALTH_INSURANCE,
            PayrollLevyDeadlinePolicy::JMHZ_MONTHLY_REPORT,
        ] as $levy) {
            self::assertSame(
                '2026-07-01',
                $this->policy->forPeriod($levy, '2026-06-01')
                    ->earliestPaymentOn,
            );
        }

        foreach ([
            PayrollLevyDeadlinePolicy::ADVANCE_TAX,
            PayrollLevyDeadlinePolicy::WITHHOLDING_TAX,
        ] as $levy) {
            self::assertNull(
                $this->policy->forPeriod($levy, '2026-06-01')
                    ->earliestPaymentOn,
            );
        }
    }

    public function testShiftedDueDateIsNeverAWeekendOrHoliday(): void
    {
        foreach (PayrollLevyDeadlinePolicy::levies() as $levy) {
            for ($year = 2026; $year <= 2045; ++$year) {
                for ($month = 1; $month <= 12; ++$month) {
                    $due = $this->policy->dueOn(
                        $levy,
                        sprintf('%04d-%02d-01', $year, $month),
                    );
                    self::assertTrue(
                        CzechWorkingDays::isWorkingDay(
                            new \DateTimeImmutable($due),
                        ),
                        "Termín {$levy} {$due} není pracovní den.",
                    );
                }
            }
        }
    }

    public function testShortcutReturnsTheShiftedDueDate(): void
    {
        self::assertSame(
            '2026-06-22',
            $this->policy->dueOn(
                PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
                '2026-05-01',
            ),
        );
    }

    public function testAgreesWithJmhzPolicyOnRegularMonthlyReports(): void
    {
        $jmhz = new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider());
        foreach (['2026-04-01', '2026-05-01', '2026-12-01'] as $period) {
            $window = $this->policy->forPeriod(
                PayrollLevyDeadlinePolicy::JMHZ_MONTHLY_REPORT,
                $period,
            );
            $reference = $jmhz->forPeriod($period);

            self::assertSame($reference->dueOn, $window->dueOn);
            self::assertSame(
                $reference->earliestSubmissionOn,
                $window->earliestPaymentOn,
            );
            self::assertSame(
                $reference->calendarBasis,
                $window->calendarBasis,
            );
        }
    }

    public function testEveryLevyCarriesACitedAndClassifiedSource(): void
    {
        $statuses = ['repo_verified', 'external_unverified'];
        foreach (PayrollLevyDeadlinePolicy::levies() as $levy) {
            $window = $this->policy->forPeriod($levy, '2026-06-01');

            self::assertNotSame('', $window->source);
            self::assertNotSame('', $window->shiftSource);
            self::assertContains($window->sourceStatus, $statuses);
            self::assertContains($window->shiftSourceStatus, $statuses);
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/D',
                $window->rulesetHash,
            );
        }
    }

    public function testRulesetHashIsStablePerLevyAndDiffersBetweenLevies(): void
    {
        $hashes = [];
        foreach (PayrollLevyDeadlinePolicy::levies() as $levy) {
            $first = $this->policy->forPeriod($levy, '2026-06-01');
            $second = $this->policy->forPeriod($levy, '2027-02-01');

            self::assertSame($first->rulesetHash, $second->rulesetHash);
            $hashes[$levy] = $first->rulesetHash;
        }

        self::assertCount(count($hashes), array_unique($hashes));
    }

    public function testRejectsUnknownLevyAndUnsupportedPeriod(): void
    {
        try {
            $this->policy->forPeriod('road_tax', '2026-06-01');
            self::fail('Neznámý odvod musí být odmítnut.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        foreach (['2026-06-02', '2026-13-01', '', '2025-12-01'] as $period) {
            try {
                $this->policy->forPeriod(
                    PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
                    $period,
                );
                self::fail("Období {$period} musí být odmítnuto.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function weekday(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('D');
    }
}
