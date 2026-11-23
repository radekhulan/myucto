<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Pair;

use MyInvoice\Service\Accounting\JournalLineAmount;
use PHPUnit\Framework\TestCase;

final class JournalPairMirrorLinesTest extends TestCase
{
    public function testMirrorOfCompositeLineKeepsTodaysShape(): void
    {
        $mirror = JournalLineAmount::mirrorLines(
            [['id' => 5, 'account_id' => '311', 'side' => 'debit', 'amount' => '121.00', 'is_red_storno' => 0,
                'currency_code' => 'EUR', 'fx_rate' => '25.000000', 'amount_foreign' => '4.84',
                'cost_center' => 'S1', 'project_id' => '9', 'pair_no' => null, 'line_text' => null]],
            [5 => [4 => 12]],
            [5 => [5 => [13 => '0.40']]],
            [5 => [4 => 'rule']],
            [],
        );

        self::assertSame([[
            'account_id' => 311, 'side' => 'credit', 'amount' => '121.00', 'is_red_storno' => false,
            'currency_code' => 'EUR', 'fx_rate' => '25.000000', 'amount_foreign' => '4.84',
            'cost_center' => 'S1', 'project_id' => 9,
            'dimensions' => [4 => 12], 'dimension_splits' => [5 => [13 => '0.40']],
            'dimension_origins' => [4 => 'rule'], 'dimension_split_origins' => [],
        ]], $mirror);
    }

    public function testMirrorOfPairCarriesPairNumberAndTextAndCancelsRedStorno(): void
    {
        $mirror = JournalLineAmount::mirrorLines([
            ['id' => 1, 'account_id' => 568, 'side' => 'debit', 'amount' => '15.00', 'is_red_storno' => 1,
                'pair_no' => '3', 'line_text' => 'Poplatek'],
        ]);

        self::assertSame('debit', $mirror[0]['side']);
        self::assertFalse($mirror[0]['is_red_storno']);
        self::assertSame(3, $mirror[0]['pair_no']);
        self::assertSame('Poplatek', $mirror[0]['line_text']);
    }
}
