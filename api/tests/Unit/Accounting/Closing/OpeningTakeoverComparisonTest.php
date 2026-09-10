<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\OpeningTakeoverComparison;
use PHPUnit\Framework\TestCase;

final class OpeningTakeoverComparisonTest extends TestCase
{
    public function testMatchingBalancesGiveNoDiff(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [
                ['account_code' => '311', 'side' => 'debit', 'amount' => 7100.00],
                ['account_code' => '701', 'side' => 'credit', 'amount' => 7100.00],
                ['account_code' => '701', 'side' => 'debit', 'amount' => 7100.00],
                ['account_code' => '431', 'side' => 'credit', 'amount' => 7100.00],
            ],
            ['311' => 7100.00, '431' => -7100.00, '701' => 0.00],
        );

        self::assertSame([], $result['diff']);
        self::assertSame(2, $result['accounts']);
    }

    public function testRetainedResultComparedAtSyntheticLevelAndClearingAccountsSkipped(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 500.00],
                ['account_code' => '701', 'side' => 'credit', 'amount' => 500.00],
                ['account_code' => '701', 'side' => 'debit', 'amount' => 500.00],
                ['account_code' => '431', 'side' => 'credit', 'amount' => 500.00],
            ],
            ['221' => 500.00, '431100' => -300.00, '431200' => -200.00, '701' => 999.00, '702' => -1.00],
        );

        self::assertSame([], $result['diff'], 'Analytiky 431 se sčítají na syntetiku, 70x se nesrovnávají.');
    }

    public function testDiffListsAccountsSortedWithSignedAmounts(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
                ['account_code' => '311', 'side' => 'debit', 'amount' => 100.00],
            ],
            ['311' => 90.00, '221' => 10.00, '321' => -100.00],
        );

        self::assertSame([
            ['account_code' => '221', 'expected' => 0, 'existing' => 10, 'difference' => -10],
            ['account_code' => '311', 'expected' => 100, 'existing' => 90, 'difference' => 10],
        ], $result['diff']);
    }

    public function testHalfPennyNoiseIsNotADifference(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [['account_code' => '311', 'side' => 'debit', 'amount' => 0.1 + 0.2]],
            ['311' => 0.30],
        );

        self::assertSame([], $result['diff']);
    }
}
