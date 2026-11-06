<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use MyInvoice\Tests\Support\LegacyJ6FundingCapacityOracle;
use MyInvoice\Tests\Support\NonprofitResourceCaseFixture;
use MyInvoice\Tests\Support\ResourceReadBearerFixture;
use PHPUnit\Framework\TestCase;

/**
 * Převod J6FundingCapacity na J6FundingProjection nesmí změnit legacy výstup:
 * výsledek se porovnává s doslovnou kopií původní implementace na legacy fixtuře.
 */
final class J6FundingCapacityLegacyParityTest extends TestCase
{
    use ResourceReadBearerFixture;
    use NonprofitResourceCaseFixture;

    public function testLegacyCostClaimsAndEnvelopeStayIdentical(): void
    {
        $this->fundingSource(true);
        $tax = $this->container->get(NonprofitTaxAssessmentService::class);
        $assessment = $this->assessment;
        $assessment['funding'] = [['source_id' => 'line:' . $this->income, 'cost_id' => 'line:' . $this->expense,
            'amount_cents' => 60000, 'evidence' => self::PRIVATE_SENTINEL]];
        $assessment['funding_costs'] = [['anchor' => 'line:' . $this->expense, 'members' => ['line:' . $this->expense],
            'evidence' => self::PRIVATE_SENTINEL]];
        $snapshot = $tax->read($this->supplier, $this->period)['snapshot'];
        $tax->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], self::PRIVATE_SENTINEL,
            'legacy-cost-pool', $this->actor, $this->taxReceipt['id']);

        $capacity = $this->container->get(J6FundingCapacity::class);
        $oracle = $this->container->get(LegacyJ6FundingCapacityOracle::class);
        $claims = $oracle->costClaims($this->supplier, $this->expense);
        $envelope = $oracle->costEnvelope($this->supplier, $this->expense);
        self::assertCount(1, $claims);
        self::assertNotNull($envelope);
        self::assertSame($claims, $capacity->costClaims($this->supplier, $this->expense));
        self::assertSame($envelope, $capacity->costEnvelope($this->supplier, $this->expense));
        self::assertSame($oracle->costClaims($this->supplier, $this->expense, $this->period),
            $capacity->costClaims($this->supplier, $this->expense, $this->period));
        self::assertSame($oracle->costEnvelope($this->supplier, $this->expense, $this->period),
            $capacity->costEnvelope($this->supplier, $this->expense, $this->period));
    }

    public function testLegacyAssessmentCoverageDecisionStaysIdentical(): void
    {
        $this->fundingSource(true);
        $tax = $this->container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $tax->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment;
        $assessment['funding'] = [['source_id' => 'line:' . $this->income, 'cost_id' => 'line:' . $this->expense,
            'amount_cents' => 60000, 'evidence' => self::PRIVATE_SENTINEL]];
        $result = NonprofitTaxCalculator::forConnection($this->db)->calculate($snapshot, $assessment);
        $capacity = $this->container->get(J6FundingCapacity::class);
        $oracle = $this->container->get(LegacyJ6FundingCapacityOracle::class);

        self::assertSame($this->outcome(fn () => $oracle->assertAssessment($this->supplier, $snapshot, $assessment, $result)),
            $this->outcome(fn () => $capacity->assertAssessment($this->supplier, $snapshot, $assessment, $result)));
        self::assertSame('accepted', $this->outcome(fn () => $capacity->assertAssessment($this->supplier, $snapshot, $assessment, $result)));

        $assessment['funding'][0]['amount_cents'] = 85000;
        self::assertSame($this->outcome(fn () => $oracle->assertAssessment($this->supplier, $snapshot, $assessment, $result)),
            $this->outcome(fn () => $capacity->assertAssessment($this->supplier, $snapshot, $assessment, $result)));
        self::assertSame('nonprofit_funding_cost_overcovered',
            $this->outcome(fn () => $capacity->assertAssessment($this->supplier, $snapshot, $assessment, $result)));
    }

    private function outcome(callable $check): string
    {
        try {
            $check();
            return 'accepted';
        } catch (NonprofitTaxAssessmentException $failure) {
            return $failure->errorCode;
        }
    }
}
