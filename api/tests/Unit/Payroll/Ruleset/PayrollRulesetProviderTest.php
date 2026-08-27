<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOrigin;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use MyInvoice\Service\Payroll\Ruleset\RulesetSource;
use MyInvoice\Service\Payroll\Ruleset\RulesetTechnicalReview;
use PHPUnit\Framework\TestCase;

final class PayrollRulesetProviderTest extends TestCase
{
    public function testItSelectsTheExactlyEffective2026Ruleset(): void
    {
        $provider = CzechPayrollRulesets2026::provider();

        $january = $provider->forDate(PayrollRulesetDomain::IncomeTax, '2026-01-01');
        $december = $provider->forDate(PayrollRulesetDomain::IncomeTax, '2026-12-31');

        self::assertSame($january->id, $december->id);
        self::assertSame(14_690_100, $january->parameter('advance.high_threshold.monthly')->value);
        self::assertSame('0.15', $january->parameter('advance.low_rate')->value);
        self::assertSame(1_200_000, $january->parameter('dpp.withholding.threshold')->value);
    }

    public function testSelectionFailsClosedOutsideSupportedPeriod(): void
    {
        $provider = CzechPayrollRulesets2026::provider();

        foreach (['2025-12-31', '2027-01-01'] as $date) {
            try {
                $provider->forDate(PayrollRulesetDomain::IncomeTax, $date);
                self::fail("Ruleset lookup for {$date} must fail closed.");
            } catch (PayrollRulesetException $exception) {
                self::assertStringContainsString('found 0', $exception->getMessage());
            }
        }
    }

    /**
     * Dodaná sada počítá hned po instalaci a ZÁKAZNÍK ji neschvaluje — za hodnoty
     * ručí provozovatel instalace, doložené jsou zdrojem a technickou kontrolou.
     * Dřív tenhle test tvrdil opak (`is not active`) a byl to jediný důvod, proč si
     * zákazník mzdy bez proklikání schvalování nespočítal.
     *
     * Podpis provozovatele ({@see VendorRulesetApprover}) na tom nic nemění: účinnost
     * dodané sady stojí na otisku obsahu proti manifestu, ne na tom, že tam nějaké
     * schválení je. Kdyby stála na podpisu, stačilo by ho přidat do uloženého
     * overridu a zákazníkova změna sazby by se stala účinnou.
     */
    public function testDeliveredRulesetCalculatesWithoutCustomerApproval(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        $inspection = $provider->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-03');

        self::assertSame(PayrollRulesetLifecycle::Active, $inspection->lifecycle);
        self::assertSame(PayrollRulesetOrigin::Vendor, $inspection->origin);
        self::assertNotNull($inspection->approval);
        self::assertNotNull($inspection->technicalReview);
        self::assertNotSame([], $inspection->sources);

        self::assertSame(
            $inspection,
            $provider->forCalculation(PayrollRulesetDomain::IncomeTax, '2026-08-03'),
        );
    }

    public function testDeadlineDomainExposesTheEffectiveJmhzRule(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::Deadlines, '2026-08-03');

        self::assertSame(PayrollRulesetCapability::Supported, $ruleset->capability);
        self::assertSame(
            'following_month_day_window',
            $ruleset->parameter('jmhz.deadline.rule')->value,
        );
        self::assertSame(20, $ruleset->parameter('jmhz.deadline.due_day')->value);
    }

    /**
     * MZ-14-W11: nezabavitelné částky se do registry přestěhovaly z konstant
     * v kódu, takže musí být čitelné jako běžné parametry a doména nesmí zůstat
     * zablokovaná na ruční kontrole.
     */
    public function testEnforcementDeductionsAreReadableParameters(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::EnforcementDeductions, '2026-08-03');

        self::assertSame(PayrollRulesetCapability::Supported, $ruleset->capability);
        self::assertSame(486_000, $ruleset->parameter('life_minimum.monthly')->value);
        self::assertSame(943_000, $ruleset->parameter('normative_rent.monthly')->value);
        self::assertSame(
            1_410_150,
            $ruleset->parameter('protected_amount.debtor_base.monthly')->value,
        );
        self::assertSame(
            CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH,
            $ruleset->contentHash,
        );
    }

    public function testManualReviewParameterFailsIndependentlyOfSupportedDomain(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::SocialInsurance, '2026-06-30');

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('requires manual review');
        $ruleset->parameter('employee.discount.agriculture_dpp');
    }

    public function testMissingRequiredParameterFailsClosed(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::HealthInsurance, '2026-06-30');

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('does not define required parameter');
        $ruleset->parameter('not.present');
    }

    public function testOverlappingActiveVersionsAreRejected(): void
    {
        $first = $this->version('test.tax.v1', '2026-01-01', '2026-06-30');
        $second = $this->version('test.tax.v2', '2026-06-30', '2026-12-31');

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('overlap');
        new PayrollRulesetProvider([$first, $second]);
    }

    public function testReviewedVersionAndItsApprovedSuccessorCannotBothBeSelectable(): void
    {
        $technicalReview = new RulesetTechnicalReview(
            'technical-check',
            '2026-08-01',
            'Synthetic technical check.',
        );
        $reviewed = new PayrollRulesetVersion(
            'test.tax.reviewed',
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Reviewed,
            PayrollRulesetCapability::Supported,
            [
                new RulesetSource(
                    'test-source',
                    'Synthetic primary source',
                    'https://example.invalid/source',
                    '2026-08-03',
                ),
            ],
            ['rate' => PayrollRuleValue::rate('0.15')],
            null,
            $technicalReview,
        );
        $approved = $reviewed->transition(
            PayrollRulesetLifecycle::Approved,
            'test.tax.approved',
            '1.1.0',
            new RulesetApproval(
                'external-reviewer',
                '2026-08-02',
                'external-approver',
                '2026-08-03',
                'External professional approval reference.',
            ),
        );

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('make lookup ambiguous');
        new PayrollRulesetProvider([$reviewed, $approved]);
    }

    public function testSupersededAuditVersionDoesNotCompeteWithCurrentHead(): void
    {
        $technicalReview = new RulesetTechnicalReview(
            'technical-check',
            '2026-08-01',
            'Synthetic technical check.',
        );
        $approval = new RulesetApproval(
            'external-reviewer',
            '2026-08-02',
            'external-approver',
            '2026-08-03',
            'External professional approval reference.',
        );
        $approved = new PayrollRulesetVersion(
            'test.tax.approved',
            '1.1.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Approved,
            PayrollRulesetCapability::Supported,
            [
                new RulesetSource(
                    'test-source',
                    'Synthetic primary source',
                    'https://example.invalid/source',
                    '2026-08-03',
                ),
            ],
            ['rate' => PayrollRuleValue::rate('0.15')],
            $approval,
            $technicalReview,
        );
        $superseded = new PayrollRulesetVersion(
            'test.tax.superseded',
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Superseded,
            PayrollRulesetCapability::Supported,
            $approved->sources,
            $approved->parameters,
            $approval,
            $technicalReview,
        );

        $provider = new PayrollRulesetProvider([$superseded, $approved]);

        self::assertSame('test.tax.approved', $provider->forDate(
            PayrollRulesetDomain::IncomeTax,
            '2026-06-30',
        )->id);
    }

    public function testGapIsDetectedByLookupAndCoverageValidation(): void
    {
        $provider = new PayrollRulesetProvider([
            $this->version('test.tax.v1', '2026-01-01', '2026-06-29'),
            $this->version('test.tax.v2', '2026-07-01', '2026-12-31'),
        ]);

        try {
            $provider->forDate(PayrollRulesetDomain::IncomeTax, '2026-06-30');
            self::fail('An effective-date gap must fail lookup.');
        } catch (PayrollRulesetException $exception) {
            self::assertStringContainsString('found 0', $exception->getMessage());
        }

        $this->expectException(PayrollRulesetException::class);
        $provider->assertContinuousCoverage(
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
        );
    }

    public function testEveryFixtureDomainCoversTheWholeSupportedYear(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        foreach (PayrollRulesetDomain::cases() as $domain) {
            $provider->assertContinuousCoverage($domain, '2026-01-01', '2026-12-31');
            self::addToAssertionCount(1);
        }
    }

    private function version(string $id, string $from, string $to): PayrollRulesetVersion
    {
        return new PayrollRulesetVersion(
            $id,
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            $from,
            $to,
            PayrollRulesetLifecycle::Active,
            PayrollRulesetCapability::Supported,
            [
                new RulesetSource(
                    'test-source',
                    'Synthetic primary source',
                    'https://example.invalid/source',
                    '2026-08-03',
                ),
            ],
            ['rate' => PayrollRuleValue::rate('0.15')],
            new RulesetApproval(
                'reviewer',
                '2026-08-02',
                'approver',
                '2026-08-03',
                'Synthetic test approval.',
            ),
            new RulesetTechnicalReview(
                'technical-check',
                '2026-08-01',
                'Synthetic technical check.',
            ),
        );
    }
}
