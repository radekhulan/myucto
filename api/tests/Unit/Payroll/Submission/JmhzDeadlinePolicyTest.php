<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use PHPUnit\Framework\TestCase;

final class JmhzDeadlinePolicyTest extends TestCase
{
    private JmhzDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new JmhzDeadlinePolicy();
    }

    public function testUsesTransitionWindowForFirstQuarterOf2026(): void
    {
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
            $window = $this->policy->forPeriod($period);

            self::assertSame('2026-04-01', $window->earliestSubmissionOn);
            self::assertSame('2026-06-30', $window->dueOn);
            self::assertSame('business_days', $window->calendarBasis);
            self::assertSame(
                'cz-jmhz-deadlines-2026.transition.v1',
                $window->rulesetId,
            );
        }
    }

    public function testUsesFirstThroughTwentiethOfFollowingMonth(): void
    {
        $april = $this->policy->forPeriod('2026-04-01');
        self::assertSame('2026-05-01', $april->earliestSubmissionOn);
        self::assertSame('2026-05-20', $april->dueOn);

        $december = $this->policy->forPeriod('2026-12-01');
        self::assertSame('2027-01-01', $december->earliestSubmissionOn);
        self::assertSame('2027-01-20', $december->dueOn);
        self::assertSame(
            'cz-jmhz-deadlines-2026.regular.v1',
            $december->rulesetId,
        );
    }

    public function testMovesWeekendOrHolidayDueDateToNextWorkingDay(): void
    {
        $may = $this->policy->forPeriod('2026-05-01');

        self::assertSame('2026-06-01', $may->earliestSubmissionOn);
        self::assertSame('2026-06-22', $may->dueOn);
        self::assertSame('business_days', $may->calendarBasis);
    }

    public function testCorrectionDeadlineUsesTheYearInWhichTheReportWasDue(): void
    {
        self::assertSame('2036-12-31', $this->policy->lastCorrectionOn('2026-11-01'));
        self::assertSame('2037-12-31', $this->policy->lastCorrectionOn('2026-12-01'));
    }

    public function testRulesetHashIsStableAndDifferentByWindow(): void
    {
        $transition = $this->policy->forPeriod('2026-03-01');
        $regular = $this->policy->forPeriod('2026-04-01');
        $laterRegular = $this->policy->forPeriod('2026-12-01');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $transition->rulesetHash,
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $regular->rulesetHash,
        );
        self::assertSame($regular->rulesetHash, $laterRegular->rulesetHash);
        self::assertNotSame($transition->rulesetHash, $regular->rulesetHash);
    }

    public function testRejectsUnsupportedOrNonMonthlyPeriod(): void
    {
        foreach (['2025-12-01', '2026-04-02', '2026-13-01'] as $period) {
            try {
                $this->policy->forPeriod($period);
                self::fail("Období {$period} musí být odmítnuto.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
