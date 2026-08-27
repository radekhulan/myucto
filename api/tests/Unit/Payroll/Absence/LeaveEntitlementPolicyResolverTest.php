<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\LeaveEntitlementPolicyResolver;
use PHPUnit\Framework\TestCase;

final class LeaveEntitlementPolicyResolverTest extends TestCase
{
    public function testCompanyAllowanceAndAgreedWeeklyTimeAreResolvedForWholePeriod(): void
    {
        $result = (new LeaveEntitlementPolicyResolver())->resolve(
            '2026-01-01',
            '2026-12-31',
            'employment',
            [[
                'id' => 10,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'weekly_hours' => '37.50',
                'leave_entitlement_weeks_override' => null,
                'row_version' => 2,
            ]],
            [[
                'id' => 20,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'leave_entitlement_weeks' => 5,
                'row_version' => 3,
            ]],
            1_200,
        );

        self::assertSame(2_250, $result['weekly_minutes']);
        self::assertSame(5, $result['entitlement_weeks']);
        self::assertSame('company_policy', $result['allowance_source']);
        self::assertSame([], $result['blockers']);
    }

    public function testEmploymentOverrideWinsAndAgreementUsesStatutoryFiction(): void
    {
        $result = (new LeaveEntitlementPolicyResolver())->resolve(
            '2026-01-01',
            '2026-12-31',
            'dpc',
            [[
                'id' => 10,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'weekly_hours' => null,
                'leave_entitlement_weeks_override' => 6,
                'row_version' => 1,
            ]],
            [],
            1_200,
        );

        self::assertSame(1_200, $result['weekly_minutes']);
        self::assertSame(6, $result['entitlement_weeks']);
        self::assertSame('employment_override', $result['allowance_source']);
        self::assertSame([], $result['blockers']);
    }

    public function testMissingCoverageAndMidyearValueChangeFailClosed(): void
    {
        $result = (new LeaveEntitlementPolicyResolver())->resolve(
            '2026-01-01',
            '2026-12-31',
            'employment',
            [[
                'id' => 10,
                'effective_from' => '2026-02-01',
                'effective_to' => null,
                'weekly_hours' => '40.00',
                'leave_entitlement_weeks_override' => null,
                'row_version' => 1,
            ]],
            [[
                'id' => 20,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-06-30',
                'leave_entitlement_weeks' => 4,
                'row_version' => 1,
            ], [
                'id' => 21,
                'valid_from' => '2026-07-01',
                'valid_to' => null,
                'leave_entitlement_weeks' => 5,
                'row_version' => 1,
            ]],
            1_200,
        );

        self::assertContains('employment_terms_missing', $result['blockers']);
        self::assertContains('leave_allowance_changed', $result['blockers']);
    }

    public function testCompanyBenefitKeepsItsHistoricalEffectiveDate(): void
    {
        $resolver = new LeaveEntitlementPolicyResolver();
        $terms = [[
            'id' => 10,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'weekly_hours' => '40.00',
            'leave_entitlement_weeks_override' => null,
        ]];
        $policies = [[
            'id' => 20,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-06-30',
            'leave_entitlement_weeks' => 4,
        ], [
            'id' => 21,
            'valid_from' => '2026-07-01',
            'valid_to' => null,
            'leave_entitlement_weeks' => 5,
        ]];

        $beforeBenefit = $resolver->resolve(
            '2026-01-01', '2026-06-30', 'employment', $terms, $policies, 1_200,
        );
        $afterBenefit = $resolver->resolve(
            '2026-07-01', '2026-12-31', 'employment', $terms, $policies, 1_200,
        );

        self::assertSame(4, $beforeBenefit['entitlement_weeks']);
        self::assertSame([20], $beforeBenefit['policy_ids']);
        self::assertSame([], $beforeBenefit['blockers']);
        self::assertSame(5, $afterBenefit['entitlement_weeks']);
        self::assertSame([21], $afterBenefit['policy_ids']);
        self::assertSame([], $afterBenefit['blockers']);
    }
}
