<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationPlanBuilder;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use PHPUnit\Framework\TestCase;

final class JointAllocationPlanBuilderTest extends TestCase
{
    private const TYPES = ['activity' => 1, 'activity_kind' => 2, 'tax_regime' => 3];
    private const CELLS = [
        ['activity' => 11, 'activity_kind' => 21, 'tax_regime' => 31, 'weight' => '1'],
        ['activity' => 12, 'activity_kind' => 22, 'tax_regime' => 31, 'weight' => '1'],
    ];

    public function testTwoCentsMustNotChangeNonTargetSplitReport(): void
    {
        $source = self::source('0.02');
        $source['dimension_splits'] = [9 => [91 => '50.000000', 92 => '50.000000']];
        self::assertSame([1, 1], DimensionStamper::distributeCents(2, [50, 50]));
        $oneCent = DimensionStamper::distributeCents(1, [50, 50]);
        self::assertSame([0, 2], array_map(static fn (int $part): int => $part * 2, $oneCent));
        $this->expectException(JointAllocationException::class);
        JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES);
    }

    public function testIndependentForeignCentsAndUntargetedMetadataArePreserved(): void
    {
        $source = self::source('0.03');
        $source['amount_foreign'] = '0.01';
        $source['currency_code'] = 'EUR';
        $source['fx_rate'] = '24.123456';
        $source['cost_center'] = 'synthetic';
        $source['project_id'] = 70;
        $source['dimensions'] = [9 => 91, 1 => 19];
        $source['dimension_origins'] = [9 => ['origin' => 'unknown', 'ref' => null]];
        $before = $source;
        $result = JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES)['sources'][0];
        self::assertSame($before, $source);
        self::assertSame(['0.01', '0.02'], array_column($result['targets'], 'amount'));
        self::assertSame(['0.00', '0.01'], array_column($result['targets'], 'amount_foreign'));
        self::assertSame('credit', $result['compensation']['side']);
        foreach ($result['targets'] as $target) {
            self::assertSame(91, $target['dimensions'][9]);
            self::assertSame($source['dimension_origins'][9], $target['dimension_origins'][9]);
            self::assertSame('24.123456', $target['fx_rate']);
            self::assertSame('synthetic', $target['cost_center']);
            self::assertSame(70, $target['project_id']);
            self::assertArrayNotHasKey('id', $target);
        }
    }

    public function testRedStornoCompensationKeepsSideAndTargetsKeepRedSign(): void
    {
        $source = self::source('10.01');
        $source['is_red_storno'] = true;
        $result = JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES)['sources'][0];
        self::assertSame('debit', $result['compensation']['side']);
        self::assertFalse($result['compensation']['is_red_storno']);
        self::assertSame([true, true], array_column($result['targets'], 'is_red_storno'));
        self::assertSame(['5.00', '5.01'], array_column($result['targets'], 'amount'));
    }

    public function testForeignOnlyCellIsRetained(): void
    {
        $source = self::source('0.00');
        $source['amount_foreign'] = '0.01';
        $result = JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES)['sources'][0];
        self::assertCount(2, $result['targets']);
        self::assertSame(['0.00', '0.01'], array_column($result['targets'], 'amount_foreign'));
    }

    public function testTwoNonTargetFractionalAxesNeedExplicitJointProofEvenWhenMarginalsMatch(): void
    {
        $source = self::source('0.03');
        $source['dimension_splits'] = [9 => [91 => '50', 92 => '50'], 10 => [101 => '50', 102 => '50']];
        self::assertSame([1, 2], DimensionStamper::distributeCents(3, [50, 50]));
        self::assertSame([0, 1], DimensionStamper::distributeCents(1, [50, 50]));
        self::assertSame([1, 1], DimensionStamper::distributeCents(2, [50, 50]));
        $this->expectException(JointAllocationException::class);
        $this->expectExceptionMessage('společné kombinace');
        JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES);
    }

    public function testSingleNonTargetSplitStillRequiresDeclaredCorrelation(): void
    {
        $source = self::source('0.03');
        $source['dimension_splits'] = [9 => [91 => '50', 92 => '50']];
        $source['dimension_split_origins'] = [9 => [91 => ['origin' => 'unknown', 'ref' => null]]];
        $this->expectException(JointAllocationException::class);
        JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES);
    }

    public function testForeignProjectionDriftIsCheckedIndependentlyOfCzk(): void
    {
        $source = self::source('0.03');
        $source['amount_foreign'] = '0.02';
        $source['dimension_splits'] = [9 => [92 => 50.0, 91 => 50.0]];
        $this->expectException(JointAllocationException::class);
        JointAllocationPlanBuilder::build([$source], self::CELLS, self::TYPES);
    }

    public function testSourceOrderIsCanonicalAndPerAccountBalanceRemainsZero(): void
    {
        $first = self::source('3.07');
        $second = self::source('5.09');
        $second['id'] = 102;
        $second['account_id'] = 502;
        $second['side'] = 'credit';
        $second['is_red_storno'] = true;
        $plan = JointAllocationPlanBuilder::build([$second, $first], self::CELLS, self::TYPES);
        self::assertSame($plan, JointAllocationPlanBuilder::build([$first, $second], array_reverse(self::CELLS), self::TYPES));
        $balances = [];
        foreach ($plan['sources'] as $source) {
            foreach ([$source['compensation'], ...$source['targets']] as $line) {
                $negative = ($line['side'] === 'credit') !== !empty($line['is_red_storno']);
                $amount = $negative ? bcsub('0', $line['amount'], 2) : $line['amount'];
                $balances[$line['account_id']] = bcadd($balances[$line['account_id']] ?? '0.00', $amount, 2);
            }
        }
        self::assertSame([501 => '0.00', 502 => '0.00'], $balances);
    }

    public function testEntirelyEmptySourceDoesNotCreateMeaninglessJournalRows(): void
    {
        $this->expectException(JointAllocationException::class);
        JointAllocationPlanBuilder::build([self::source('0.00')], self::CELLS, self::TYPES);
    }

    private static function source(string $amount): array
    {
        return ['id' => 101, 'account_id' => 501, 'side' => 'debit', 'amount' => $amount, 'is_red_storno' => false];
    }
}
