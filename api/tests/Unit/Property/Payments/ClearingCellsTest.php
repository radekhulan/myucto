<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Payments;

use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\Workflow\ClearingCells;
use PHPUnit\Framework\TestCase;

final class ClearingCellsTest extends TestCase
{
    public function testExplicitJointCellsKeepUnknownRemainderAndDoNotMultiplyAxes(): void
    {
        $cells = ClearingCells::normalize('10000', [
            ['key' => 'unit-b-water', 'amount_cents' => '3000', 'dimensions' => [8 => 82, 7 => 72]],
            ['key' => 'unresolved', 'amount_cents' => '4000', 'dimensions' => []],
            ['key' => 'unit-a-heating', 'amount_cents' => '3000', 'dimensions' => [7 => 71, 8 => 81]],
        ]);
        self::assertCount(3, $cells);
        self::assertSame(['unit-a-heating', 'unit-b-water', 'unresolved'], array_column($cells, 'key'));
        self::assertSame([7 => 71, 8 => 81], $cells[0]['dimensions']);
        self::assertSame([], $cells[2]['dimensions']);
        self::assertSame('10000', array_reduce($cells, static fn ($sum, $cell) => bcadd($sum, $cell['amount_cents'], 0), '0'));
    }

    public function testOneCentAndLargeExactCapacityArePreserved(): void
    {
        $cells = ClearingCells::normalize('9007199254740993', [
            ['key' => 'small', 'amount_cents' => '1', 'dimensions' => []],
            ['key' => 'large', 'amount_cents' => '9007199254740992', 'dimensions' => [7 => 71]],
        ]);
        $used = ClearingCells::consume($cells, [], '9007199254740993', [
            ['key' => 'small', 'amount_cents' => '1'],
            ['key' => 'large', 'amount_cents' => '9007199254740992'],
        ]);
        self::assertSame('9007199254740992', $used[0]['amount_cents']);
        self::assertSame('1', $used[1]['amount_cents']);
    }

    public function testMatchingAggregateCannotConcealOverusedCell(): void
    {
        $cells = ClearingCells::normalize('10000', [
            ['key' => 'a', 'amount_cents' => '6000', 'dimensions' => [7 => 71]],
            ['key' => 'b', 'amount_cents' => '4000', 'dimensions' => [7 => 72]],
        ]);
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('kapacitu');
        ClearingCells::consume($cells, ['a' => '6000'], '1000', [['key' => 'a', 'amount_cents' => '1000']]);
    }

    public function testMultipleCellsRequireExplicitSelectionEvenWithEnoughTotalCapacity(): void
    {
        $cells = ClearingCells::normalize('2', [
            ['key' => 'a', 'amount_cents' => '1', 'dimensions' => [7 => 71]],
            ['key' => 'b', 'amount_cents' => '1', 'dimensions' => [7 => 72]],
        ]);
        $this->expectException(PaymentException::class);
        ClearingCells::consume($cells, [], '1', null);
    }

    public function testSingleCellSelectionHasOneProvableMeaning(): void
    {
        $cells = ClearingCells::normalize('100', [['key' => 'only', 'amount_cents' => '100', 'dimensions' => []]]);
        self::assertSame([['key' => 'only', 'amount_cents' => '40']], ClearingCells::consume($cells, ['only' => '60'], '40', null));
    }

    public function testUndeclaredCapacityCannotBeHiddenOutsideSelectedCells(): void
    {
        $cells = ClearingCells::normalize('100', [['key' => 'only', 'amount_cents' => '100', 'dimensions' => []]]);
        $this->expectException(PaymentException::class);
        ClearingCells::consume($cells, ['missing' => '10'], '40', null);
    }

    public function testIndependentFractionalAxisInputIsNotACompleteJointCell(): void
    {
        $this->expectException(PaymentException::class);
        ClearingCells::normalize('100', [['key' => 'a', 'amount_cents' => '100', 'dimensions' => [], 'dimension_splits' => [7 => [71 => '0.5', 72 => '0.5']]]]);
    }

    public function testIncompleteMoneyMarginIsRejected(): void
    {
        $this->expectException(PaymentException::class);
        ClearingCells::normalize('100', [['key' => 'a', 'amount_cents' => '99', 'dimensions' => []]]);
    }

    public function testPinnedNativePartSurvivesEarlierReversalAndFinalPartConsumesExactResidual(): void
    {
        $cells = [['key' => 'fx', 'amount_cents' => '2467', 'line' => [
            'currency_code' => 'EUR', 'amount_foreign' => '1.00', 'fx_rate' => '24.666000',
        ]]];
        $remaining = ClearingCells::consume($cells, [], '1234', [['key' => 'fx', 'amount_cents' => '1234', 'native_minor' => '50']], [], true);
        self::assertSame('50', $remaining[0]['native_minor']);
        $replacement = ClearingCells::consume($cells, ['fx' => '1234'], '1233', [['key' => 'fx', 'amount_cents' => '1233']], ['fx' => '50']);
        self::assertSame('50', $replacement[0]['native_minor']);
        self::assertSame('100', bcadd($remaining[0]['native_minor'], $replacement[0]['native_minor'], 0));
    }
}
