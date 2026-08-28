<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Deadline;

use MyInvoice\Service\Payroll\Deadline\PayrollChecklistDeadlinePolicy;
use PHPUnit\Framework\TestCase;

final class PayrollChecklistDeadlinePolicyTest extends TestCase
{
    private PayrollChecklistDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PayrollChecklistDeadlinePolicy();
    }

    public function testHealthRegistrationGetsEightDaysFromStart(): void
    {
        $deadline = $this->policy->forItem(
            'health_insurance_registration',
            '2026-08-01',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertSame('2026-08-09', $deadline->dueOn);
        self::assertSame('statute_verified', $deadline->sourceStatus);
        self::assertSame('§ 10 zákona č. 48/1997 Sb.', $deadline->source);
    }

    public function testAgreementsUseTheMonthlyHealthInsuranceException(): void
    {
        foreach (['dpp', 'dpc'] as $relationType) {
            $deadline = $this->policy->forItem(
                'health_insurance_registration',
                '2026-08-01',
                $relationType,
            );

            self::assertNotNull($deadline);
            self::assertSame('2026-09-20', $deadline->dueOn);
        }
    }

    public function testTaxDeclarationIsThirtyDaysAfterStart(): void
    {
        $deadline = $this->policy->forItem(
            'tax_declaration',
            '2026-08-01',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertSame('2026-08-31', $deadline->dueOn);
    }

    public function testEmploymentRegistrationIsDueOnTheStartDay(): void
    {
        $deadline = $this->policy->forItem(
            'social_jmhz_registration',
            '2026-08-01',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertSame('2026-08-01', $deadline->dueOn);
    }

    public function testEmploymentRegistrationBeforeJuly2026HasNoDerivedDeadline(): void
    {
        $deadline = $this->policy->forItem(
            'social_jmhz_registration',
            '2026-05-04',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertNull($deadline->dueOn);
        self::assertSame('not_derived', $deadline->sourceStatus);
    }

    public function testEldpIsNotSeededWhenCsszAssemblesItFromMonthlyReport(): void
    {
        self::assertNull($this->policy->forItem(
            'eldp_submission',
            '2026-08-31',
            'employment',
        ));
    }

    public function testEldpIsSeededForTerminationsInsideTheTransitionalWindow(): void
    {
        $deadline = $this->policy->forItem(
            'eldp_submission',
            '2026-02-28',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertNotNull($deadline->dueOn);
        self::assertLessThanOrEqual('2027-01-31', $deadline->dueOn);
        self::assertGreaterThan('2026-02-28', $deadline->dueOn);
    }

    public function testTaxableIncomeConfirmationHasNoDerivedDeadline(): void
    {
        $deadline = $this->policy->forItem(
            'taxable_income_confirmation',
            '2026-08-31',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertNull($deadline->dueOn);
        self::assertSame('not_derived', $deadline->sourceStatus);
        self::assertNotNull($deadline->note);
    }

    public function testInternalReviewsGetNoInventedDeadline(): void
    {
        foreach ([
            'enforcement_insolvency_review',
            'later_income_review',
            'contract_amendment',
        ] as $itemKey) {
            $deadline = $this->policy->forItem(
                $itemKey,
                '2026-08-31',
                'employment',
            );

            self::assertNotNull($deadline);
            self::assertNull($deadline->dueOn, $itemKey);
        }
    }

    public function testDeregistrationFollowsTheEightDayRegzecWindow(): void
    {
        $deadline = $this->policy->forItem(
            'social_jmhz_deregistration',
            '2026-08-31',
            'employment',
        );

        self::assertNotNull($deadline);
        self::assertSame('2026-09-08', $deadline->dueOn);
    }
}
