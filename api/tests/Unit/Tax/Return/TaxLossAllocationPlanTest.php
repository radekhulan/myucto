<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\TaxLossAllocationPlan;
use PHPUnit\Framework\TestCase;

final class TaxLossAllocationPlanTest extends TestCase
{
    public function testDatabaseDecimalsRetainAllCentsWithoutFloatRoundTrip(): void
    {
        self::assertSame('900719925474099111', TaxLossAllocationPlan::cents('9007199254740991.11'));
        self::assertSame('9007199254740991.11', TaxLossAllocationPlan::amount('900719925474099111'));
        $plan = TaxLossAllocationPlan::build([['id' => 1, 'origin_year' => 2024, 'amount' => '9007199254740991.11', 'applied' => '9007199254740990.10', 'carried_back' => '0.00']], 2025, 1.01, 5, 2, 30000000);
        self::assertSame('101', $plan['available_cents']);
        self::assertSame('101', $plan['allocated_cents']);
        self::assertSame('0', $plan['unallocated_cents']);
    }

    public function testPartialCarrybackLimitIsPerSourceAndForwardPrecedesFutureLoss(): void
    {
        $plan = TaxLossAllocationPlan::build([
            ['id' => 2, 'origin_year' => 2026, 'amount' => '50000000.00', 'applied' => '25000000.00', 'carried_back' => '25000000.00'],
            ['id' => 1, 'origin_year' => 2024, 'amount' => '100000.00', 'applied' => '0.00', 'carried_back' => '0.00'],
        ], 2025, 10000000, 5, 2, 30000000);
        self::assertSame([1, 2], array_column($plan['chunks'], 'loss_id'));
        self::assertSame('510000000', $plan['allocated_cents']);
        self::assertSame('490000000', $plan['unallocated_cents']);
        self::assertSame('10000000', $plan['chunks'][0]['amount_cents']);
        self::assertSame('500000000', $plan['chunks'][1]['amount_cents']);
    }
}
