<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Benchmark;

use InvalidArgumentException;
use MyInvoice\Benchmark\PropertyBenchmarkAdoption;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkAdoption.php';

final class PropertyBenchmarkAdoptionPlanTest extends TestCase
{
    public function testPilotKeepsEachSourceMonthAsOneNativeClaim(): void
    {
        $plan = PropertyBenchmarkAdoption::plan(12, 2, '2025-01');

        self::assertSame(12, $plan['units']);
        self::assertSame(2, $plan['source_months']);
        self::assertSame(2, $plan['claims_per_unit']);
        self::assertSame(24, $plan['physical_claims']);
        self::assertSame('monthly', $plan['source_granularity']);
        self::assertTrue($plan['monthly_claim_granularity']);
        self::assertSame('2025-01', $plan['source_first']);
        self::assertSame('2025-02', $plan['source_last']);
        self::assertSame([
            ['occurrence_ref' => '2025-01', 'period_from' => '2025-01-01', 'period_to' => '2025-01-31', 'source_month_count' => 1, 'amount_cents' => '180000'],
            ['occurrence_ref' => '2025-02', 'period_from' => '2025-02-01', 'period_to' => '2025-02-28', 'source_month_count' => 1, 'amount_cents' => '180000'],
        ], $plan['buckets']);
    }

    public function testHeavyAdoptionUsesTenExplicitAnnualRollupsWithoutChangingNativeLimit(): void
    {
        $plan = PropertyBenchmarkAdoption::plan(400, 120, '2016-01');

        self::assertSame(120, $plan['source_months']);
        self::assertSame(10, $plan['claims_per_unit']);
        self::assertSame(4000, $plan['physical_claims']);
        self::assertSame(48000, $plan['conceptual_unit_months']);
        self::assertSame('annual_rollup', $plan['source_granularity']);
        self::assertFalse($plan['monthly_claim_granularity']);
        self::assertSame('2016-01', $plan['source_first']);
        self::assertSame('2025-12', $plan['source_last']);
        self::assertSame('2016-01-01', $plan['buckets'][0]['period_from']);
        self::assertSame('2016-12-31', $plan['buckets'][0]['period_to']);
        self::assertSame(12, $plan['buckets'][0]['source_month_count']);
        self::assertSame('2160000', $plan['buckets'][0]['amount_cents']);
        self::assertSame('2025-01-01', $plan['buckets'][9]['period_from']);
        self::assertSame('2025-12-31', $plan['buckets'][9]['period_to']);
        self::assertLessThanOrEqual(4800, $plan['physical_claims']);
        self::assertSame('8640000000', $plan['total_cents']);
    }

    public function testUnsupportedUnboundedHistoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PropertyBenchmarkAdoption::plan(400, 121, '2016-01');
    }
}
