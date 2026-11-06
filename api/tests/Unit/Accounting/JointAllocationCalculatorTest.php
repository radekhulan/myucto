<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JointAllocationCalculatorTest extends TestCase
{
    public function testReferenceTjJointCellsAndSignedMirror(): void
    {
        $cells = [self::cell(1, 11, 21, '60'), self::cell(1, 12, 21, '25'), self::cell(2, 13, 21, '15')];
        $result = JointAllocationCalculator::allocate('20123.45', $cells);
        self::assertSame(['12074.07', '5030.86', '3018.52'], array_column($result['cells'], 'amount'));
        self::assertSame('0.00', $result['residual']);
        self::assertSame(['-12074.07', '-5030.86', '-3018.52'], array_column(JointAllocationCalculator::allocate('-20123.45', $cells)['cells'], 'amount'));
    }

    #[DataProvider('centCases')]
    public function testResidualMatchesDeclaredPolicy(string $amount, array $weights, array $expected): void
    {
        $cells = array_map(static fn (int $i, string $weight): array => self::cell(1, $i + 1, 21, $weight), array_keys($weights), $weights);
        $result = JointAllocationCalculator::allocate($amount, $cells);
        self::assertSame($expected, array_column($result['cells'], 'amount'));
        $total = '0.00';
        foreach ($result['cells'] as $cell) {
            $total = bcadd($total, $cell['amount'], 2);
            self::assertSame($cell['amount'], bcadd($cell['rounded_amount'], $cell['adjustment'], 2));
        }
        self::assertSame($amount, $total);
    }

    public static function centCases(): iterable
    {
        yield 'positive residual' => ['0.01', ['1', '1', '1'], ['0.01', '0.00', '0.00']];
        yield 'negative total mirror' => ['-0.01', ['1', '1', '1'], ['-0.01', '0.00', '0.00']];
        yield 'rounded excess from largest first' => ['0.01', ['1', '1'], ['0.00', '0.01']];
        yield 'negative excess mirror' => ['-0.01', ['1', '1'], ['0.00', '-0.01']];
        yield 'no negative part' => ['0.02', ['1', '1', '1'], ['0.00', '0.01', '0.01']];
        yield 'unequal weights' => ['0.01', ['2', '3', '2'], ['0.00', '0.01', '0.00']];
        yield 'one cent many cells' => ['0.01', array_fill(0, 101, '1'), ['0.01', ...array_fill(0, 100, '0.00')]];
    }

    public function testCanonicalTupleOrderDoesNotDependOnRequestOrder(): void
    {
        $cells = [self::cell(2, 11, 22, '1'), self::cell(1, 12, 21, '1'), self::cell(1, 11, 23, '1')];
        $first = JointAllocationCalculator::allocate('0.01', $cells);
        self::assertSame($first, JointAllocationCalculator::allocate('0.01', array_reverse($cells)));
        self::assertSame([1, 1, 2], array_column($first['cells'], 'activity'));
        self::assertSame(['0.01', '0.00', '0.00'], array_column($first['cells'], 'amount'));
    }

    public function testLargeDecimalAmountsDoNotUseBinaryFloatProducts(): void
    {
        $result = JointAllocationCalculator::allocate('9999999999999.99', [self::cell(1, 1, 1, '1'), self::cell(1, 2, 1, '1')]);
        self::assertSame(['4999999999999.99', '5000000000000.00'], array_column($result['cells'], 'amount'));
    }

    public function testParityWithExistingSplitRoundingPolicyOnFiniteAmounts(): void
    {
        foreach ([1, 2, 3, 7] as $count) {
            $weights = array_map(static fn (int $i): int => $i % 3 + 1, range(0, $count - 1));
            $cells = array_map(static fn (int $i, int $weight): array => self::cell(1, $i + 1, 21, (string) $weight), array_keys($weights), $weights);
            foreach ([-101, -2, -1, 0, 1, 2, 101, 2012345] as $cents) {
                $actual = array_map(static fn (array $cell): int => (int) bcmul($cell['amount'], '100', 0), JointAllocationCalculator::allocate(bcdiv((string) $cents, '100', 2), $cells)['cells']);
                self::assertSame(DimensionStamper::distributeCents($cents, $weights), $actual);
            }
        }
    }

    #[DataProvider('invalidCells')]
    public function testRejectsAmbiguousOrInvalidJointInputs(array $cells): void
    {
        $this->expectException(JointAllocationException::class);
        JointAllocationCalculator::allocate('10.00', $cells);
    }

    public static function invalidCells(): iterable
    {
        yield 'empty' => [[]];
        yield 'independent axes' => [['activity' => [1 => '60', 2 => '40'], 'tax_regime' => [21 => '50', 22 => '50']]];
        yield 'missing axis' => [[['activity' => 1, 'activity_kind' => 11, 'weight' => '1']]];
        yield 'duplicate tuple' => [[self::cell(1, 11, 21, '1'), self::cell(1, 11, 21, '2')]];
        yield 'zero weight' => [[self::cell(1, 11, 21, '0')]];
        yield 'negative weight' => [[self::cell(1, 11, 21, '-1')]];
        yield 'weight precision' => [[self::cell(1, 11, 21, '0.0000001')]];
        yield 'float weight' => [[['activity' => 1, 'activity_kind' => 11, 'tax_regime' => 21, 'weight' => 0.1]]];
        yield 'invalid additional value' => [[self::cell(1, 11, 21, '1') + ['additional_dimensions' => [7 => 0]]]];
    }

    private static function cell(int $activity, int $kind, int $tax, string $weight): array
    {
        return ['activity' => $activity, 'activity_kind' => $kind, 'tax_regime' => $tax, 'weight' => $weight];
    }
}
