<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitRules;
use PHPUnit\Framework\TestCase;

final class OvertimeLimitRulesTest extends TestCase
{
    public function testStatutoryLimitsAreReadFromTheEffectiveRuleset(): void
    {
        $limits = (new OvertimeLimitRules(CzechPayrollRulesets2026::provider()))
            ->forDate('2026-06-01');

        self::assertSame(480, $limits->orderedWeeklyMaxMinutes);
        self::assertSame(9_000, $limits->orderedYearlyMaxMinutes);
        self::assertSame(480, $limits->averagingWeeklyMaxMinutes);
        self::assertSame(26, $limits->averagingMaxWeeks);
        self::assertSame(8_000, $limits->annualEarlyWarningBasisPoints);
        self::assertTrue($limits->fromRuleset);
    }

    public function testMissingStatutoryLimitFailsClosedInsteadOfUsingACodeFallback(): void
    {
        $parameters = $this->employmentThresholdParameters();
        unset($parameters[OvertimeLimitRules::KEY_YEARLY]);

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage(OvertimeLimitRules::KEY_YEARLY);
        (new OvertimeLimitRules($this->provider($parameters)))->forDate('2026-06-01');
    }

    public function testManualReviewStatutoryLimitFailsClosed(): void
    {
        $parameters = $this->employmentThresholdParameters();
        $parameters[OvertimeLimitRules::KEY_WEEKLY] = PayrollRuleValue::manualReview(
            'Sazba čeká na odborné potvrzení.',
        );
        ksort($parameters, SORT_STRING);

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage(OvertimeLimitRules::KEY_WEEKLY);
        (new OvertimeLimitRules($this->provider($parameters)))->forDate('2026-06-01');
    }

    /** @return array<string,PayrollRuleValue> */
    private function employmentThresholdParameters(): array
    {
        return CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::EmploymentThresholds, '2026-06-01')
            ->parameters;
    }

    /** @param array<string,PayrollRuleValue> $parameters */
    private function provider(array $parameters): PayrollRulesetProvider
    {
        ksort($parameters, SORT_STRING);
        $base = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::EmploymentThresholds, '2026-06-01');

        return new PayrollRulesetProvider([new PayrollRulesetVersion(
            'synthetic-overtime-ruleset',
            '2026.synthetic',
            PayrollRulesetDomain::EmploymentThresholds,
            '2026-01-01',
            '2026-12-31',
            $base->lifecycle,
            $base->capability,
            $base->sources,
            $parameters,
            new RulesetApproval(
                'synthetic-reviewer',
                '2026-01-02',
                'synthetic-approver',
                '2026-01-03',
                'Synthetic approval for a fail-closed overtime ruleset test.',
            ),
            $base->technicalReview,
        )]);
    }
}
