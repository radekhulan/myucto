<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator as Calculator;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationPlanBuilder as Planner;
use PHPUnit\Framework\TestCase;

final class JointAllocationFullTupleTest extends TestCase
{
    private const TYPES = ['activity' => 1, 'activity_kind' => 2, 'tax_regime' => 3];

    public function testMatchingMarginalsDoNotProveCorrelation(): void
    {
        $source = self::source('100.00');
        $source['dimension_splits'] = [9 => [91 => '50', 92 => '50']];
        try {
            Planner::build([$source], self::cells(), self::TYPES);
            self::fail('A=50/B=50 does not authorize inventing A1=25/A2=25/B1=25/B2=25.');
        } catch (JointAllocationException $e) {
            self::assertSame('allocation_non_target_joint_required', $e->errorCode);
        }
    }

    public function testDeclaredTwoAxisCorrelationMaterializesScalarTuples(): void
    {
        $source = self::source('0.02');
        $source['dimension_splits'] = [9 => [91 => '50', 92 => '50'], 10 => [101 => '50', 102 => '50']];
        $cells = self::cells();
        $cells[0]['additional_dimensions'] = [9 => 91, 10 => 102];
        $cells[1]['additional_dimensions'] = [9 => 92, 10 => 101];
        $plan = Planner::build([$source], $cells, self::TYPES);
        self::assertSame([91, 92], array_column(array_column($plan['sources'][0]['targets'], 'dimensions'), 9));
        self::assertSame([102, 101], array_column(array_column($plan['sources'][0]['targets'], 'dimensions'), 10));
        self::assertSame(['0.01', '0.01'], array_column($plan['sources'][0]['targets'], 'amount'));
        foreach ($plan['sources'][0]['targets'] as $target) self::assertSame([], $target['dimension_splits']);
        self::assertSame($source['dimension_splits'], $plan['sources'][0]['compensation']['dimension_splits']);
    }

    public function testSameSystemTupleCanHaveDifferentDeclaredAdditionalValues(): void
    {
        $cells = self::cells();
        $cells[1] = $cells[0];
        $cells[0]['additional_dimensions'] = [9 => 91];
        $cells[1]['additional_dimensions'] = [9 => 92];
        self::assertCount(2, Calculator::cells($cells));
    }

    public function testExplicitIndependentForeignCellsResolveRoundingAndPreserveRedSign(): void
    {
        $source = self::source('0.03');
        $source['amount_foreign'] = '0.02';
        $source['currency_code'] = 'EUR';
        $source['fx_rate'] = '1.500000';
        $source['is_red_storno'] = true;
        $source['dimension_splits'] = [9 => [91 => '50', 92 => '50']];
        $cells = self::cells();
        $cells[0]['additional_dimensions'] = [9 => 91];
        $cells[1]['additional_dimensions'] = [9 => 92];
        $exact = [['source_line_id' => 7, 'evidence' => 'Synthetic measured usage with independent booked currency margins.', 'cells' => [
            ['cell_id' => Calculator::cellId($cells[1]), 'amount' => '0.02', 'amount_foreign' => '0.01'],
            ['cell_id' => Calculator::cellId($cells[0]), 'amount' => '0.01', 'amount_foreign' => '0.01'],
        ]]];
        $plan = Planner::build([$source], $cells, self::TYPES, $exact, 'explicit_amounts');
        $targets = $plan['sources'][0]['targets'];
        self::assertSame(['0.01', '0.02'], array_column($targets, 'amount'));
        self::assertSame(['0.01', '0.01'], array_column($targets, 'amount_foreign'));
        self::assertSame([true, true], array_column($targets, 'is_red_storno'));
        self::assertSame(['EUR', 'EUR'], array_column($targets, 'currency_code'));
        self::assertSame(['1.500000', '1.500000'], array_column($targets, 'fx_rate'));
        $exact[0]['cells'][0]['amount_foreign'] = '0.02';
        $this->expectException(JointAllocationException::class);
        Planner::build([$source], $cells, self::TYPES, $exact, 'explicit_amounts');
    }

    public function testExactTotalsCannotHideWrongRetainedMargin(): void
    {
        $source = self::source('100.00');
        $source['dimension_splits'] = [9 => [91 => '50', 92 => '50']];
        $cells = self::cells();
        $cells[0]['additional_dimensions'] = [9 => 91];
        $cells[1]['additional_dimensions'] = [9 => 92];
        $exact = [['source_line_id' => 7, 'evidence' => 'Synthetic exact allocation', 'cells' => [
            ['cell_id' => Calculator::cellId($cells[0]), 'amount' => '40.00'],
            ['cell_id' => Calculator::cellId($cells[1]), 'amount' => '60.00'],
        ]]];
        try {
            Planner::build([$source], $cells, self::TYPES, $exact, 'explicit_amounts');
            self::fail('Matching totals hid changed retained margin.');
        } catch (JointAllocationException $e) {
            self::assertSame('allocation_non_target_rounding_drift', $e->errorCode);
            self::assertSame([91 => '50.00', 92 => '50.00'], $e->details['original']);
            self::assertSame([91 => '40.00', 92 => '60.00'], $e->details['preview']);
        }
    }

    private static function source(string $amount): array
    {
        return ['id' => 7, 'account_id' => 50, 'side' => 'debit', 'amount' => $amount, 'amount_foreign' => null,
            'is_red_storno' => false, 'dimensions' => [], 'dimension_splits' => []];
    }

    public function testExactRequestIdentityIsOrderIndependentAndCoversEvidenceAndForeignAmounts(): void
    {
        $exact = [['source_line_id' => 7, 'evidence' => 'Synthetic evidence', 'cells' => [
            ['cell_id' => str_repeat('a', 64), 'amount' => '1.00', 'amount_foreign' => '0.01'],
            ['cell_id' => str_repeat('b', 64), 'amount' => '2.00', 'amount_foreign' => '0.02'],
        ]]];
        $request = ['key_version_id' => 3, 'source_line_ids' => [7], 'target_date' => '2096-06-30', 'reason' => 'Synthetic allocation',
            'preview_hash' => str_repeat('c', 64), 'idempotency_key' => 'synthetic-exact', 'exact_cells' => $exact];
        $hash = \MyInvoice\Service\Accounting\Allocation\JointAllocationRequest::apply($request)['request_hash'];
        $request['exact_cells'][0]['cells'] = array_reverse($exact[0]['cells']);
        self::assertSame($hash, \MyInvoice\Service\Accounting\Allocation\JointAllocationRequest::apply($request)['request_hash']);
        $request['exact_cells'][0]['evidence'] = 'Changed evidence';
        self::assertNotSame($hash, \MyInvoice\Service\Accounting\Allocation\JointAllocationRequest::apply($request)['request_hash']);
        $request['exact_cells'] = $exact;
        $request['exact_cells'][0]['cells'][0]['amount_foreign'] = '0.02';
        self::assertNotSame($hash, \MyInvoice\Service\Accounting\Allocation\JointAllocationRequest::apply($request)['request_hash']);
    }

    public function testDeclaredAxisCannotChangeAnExistingScalarDimension(): void
    {
        $source = self::source('100.00');
        $source['dimensions'] = [9 => 91];
        $cells = self::cells();
        $cells[0]['additional_dimensions'] = [9 => 91];
        $cells[1]['additional_dimensions'] = [9 => 92];
        $this->expectException(JointAllocationException::class);
        Planner::build([$source], $cells, self::TYPES);
    }

    private static function cells(): array
    {
        return [['activity' => 11, 'activity_kind' => 21, 'tax_regime' => 31, 'weight' => '1'],
            ['activity' => 12, 'activity_kind' => 22, 'tax_regime' => 31, 'weight' => '1']];
    }
}
