<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Pair;

use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use PHPUnit\Framework\TestCase;

final class JournalPairerTest extends TestCase
{
    private const A311 = 311;
    private const A602 = 602;
    private const A604 = 604;
    private const A343 = 343;
    private const A321 = 321;
    private const A221 = 221;
    private const A518 = 518;
    private const A568 = 568;
    private const A548 = 548;

    /** @param array<string,mixed> $extra */
    private static function line(int $account, string $side, string $amount, array $extra = []): array
    {
        return array_replace(['account_id' => $account, 'side' => $side, 'amount' => $amount, 'is_red_storno' => false,
            'cost_center' => null, 'project_id' => null], $extra);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array{int,int,string,int}> [MD účet, Dal účet, částka, pair_no]
     */
    private static function pairsOf(array $lines): array
    {
        $out = [];
        for ($i = 0; $i < count($lines); $i += 2) {
            $debit = $lines[$i];
            $credit = $lines[$i + 1];
            self::assertSame($debit['pair_no'], $credit['pair_no']);
            self::assertSame('debit', JournalPairInvariant::effectiveSide($debit));
            self::assertSame('credit', JournalPairInvariant::effectiveSide($credit));
            $out[] = [$debit['account_id'], $credit['account_id'], $debit['amount'], $debit['pair_no']];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $lines @return array<string,int> */
    private static function totalsByAccountSide(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) {
            $key = $line['account_id'] . ':' . $line['side'] . ':' . (int) !empty($line['is_red_storno']);
            $totals[$key] = ($totals[$key] ?? 0) + JournalLineAmount::cents($line['amount']);
        }
        ksort($totals);
        return $totals;
    }

    public function testIssuedInvoiceWithTwoRatesSplitsReceivableHubIntoFourPairs(): void
    {
        $input = [
            self::line(self::A311, 'debit', '13220.00'),
            self::line(self::A602, 'credit', '10000.00'),
            self::line(self::A343, 'credit', '2100.00'),
            self::line(self::A604, 'credit', '1000.00'),
            self::line(self::A343, 'credit', '120.00'),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame(JournalPairer::STATUS_PAIRED, $result['status']);
        self::assertSame([
            [self::A311, self::A602, '10000.00', 1],
            [self::A311, self::A343, '2100.00', 2],
            [self::A311, self::A604, '1000.00', 3],
            [self::A311, self::A343, '120.00', 4],
        ], self::pairsOf($result['lines']));
        self::assertSame(self::totalsByAccountSide($input), self::totalsByAccountSide($result['lines']));
        JournalPairInvariant::assert(['entry_date' => '2026-01-15'], $result['lines'], EntryForm::Single);
    }

    public function testBulkBankPaymentSplitsBankHubPerCounterLineAndKeepsPairLink(): void
    {
        $link = ['doc_type' => 'purchase_invoice', 'doc_id' => 7];
        $input = [
            self::line(self::A321, 'debit', '24200.00', ['pair_link' => $link]),
            self::line(self::A321, 'debit', '6050.00'),
            self::line(self::A568, 'debit', '15.00'),
            self::line(self::A221, 'credit', '30265.00'),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame([
            [self::A321, self::A221, '24200.00', 1],
            [self::A321, self::A221, '6050.00', 2],
            [self::A568, self::A221, '15.00', 3],
        ], self::pairsOf($result['lines']));
        self::assertSame($link, $result['lines'][0]['pair_link']);
        self::assertArrayNotHasKey('pair_link', $result['lines'][2]);
    }

    public function testEqualAmountsArePairedInOrderBeforeHubRule(): void
    {
        $input = [
            self::line(self::A518, 'debit', '100.00'),
            self::line(self::A343, 'debit', '21.00'),
            self::line(self::A321, 'credit', '100.00'),
            self::line(self::A321, 'credit', '21.00'),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame([
            [self::A518, self::A321, '100.00', 1],
            [self::A343, self::A321, '21.00', 2],
        ], self::pairsOf($result['lines']));
    }

    public function testManyToManyWithoutRuleIsUnpairableAndReturnsInputUnchanged(): void
    {
        $input = [
            self::line(self::A311, 'debit', '300.00'),
            self::line(self::A518, 'debit', '100.00'),
            self::line(self::A602, 'credit', '100.00'),
            self::line(self::A604, 'credit', '200.00'),
            self::line(self::A343, 'credit', '100.00'),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, $result['status']);
        self::assertSame(JournalPairer::REASON_MANY_TO_MANY, $result['reason']);
        self::assertSame($input, $result['lines']);
    }

    public function testPairGroupKeepsGroupsApartAndIsDroppedFromOutput(): void
    {
        // Bez skupin by šlo o M : N; skupiny ho rozloží na dvě 1 : N.
        $input = [
            self::line(self::A311, 'debit', '1210.00', ['pair_group' => 'sale']),
            self::line(self::A602, 'credit', '1000.00', ['pair_group' => 'sale']),
            self::line(self::A343, 'credit', '210.00', ['pair_group' => 'sale']),
            self::line(self::A343, 'debit', '50.00', ['pair_group' => 'advance']),
            self::line(self::A518, 'debit', '50.00', ['pair_group' => 'advance']),
            self::line(self::A321, 'credit', '100.00', ['pair_group' => 'advance']),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame(JournalPairer::STATUS_PAIRED, $result['status']);
        self::assertSame([
            [self::A311, self::A602, '1000.00', 1],
            [self::A311, self::A343, '210.00', 2],
            [self::A343, self::A321, '50.00', 3],
            [self::A518, self::A321, '50.00', 4],
        ], self::pairsOf($result['lines']));
        foreach ($result['lines'] as $line) {
            self::assertArrayNotHasKey('pair_group', $line);
            self::assertArrayNotHasKey('pair_role', $line);
        }

        $flat = array_map(static function (array $l): array {
            unset($l['pair_group']);
            return $l;
        }, $input);
        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, JournalPairer::pair($flat)['status']);
    }

    public function testRoundingLineOnHubSideIsPairedNorthWestWithBigLineFirst(): void
    {
        $input = [
            self::line(self::A311, 'debit', '121.00'),
            self::line(self::A548, 'debit', '0.20', ['pair_role' => JournalPairer::ROLE_ROUNDING]),
            self::line(self::A602, 'credit', '100.00'),
            self::line(self::A343, 'credit', '21.20'),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame(JournalPairer::STATUS_PAIRED, $result['status']);
        self::assertSame([
            [self::A311, self::A602, '100.00', 1],
            [self::A311, self::A343, '21.00', 2],
            [self::A548, self::A343, '0.20', 3],
        ], self::pairsOf($result['lines']));
        self::assertSame(self::totalsByAccountSide($input), self::totalsByAccountSide($result['lines']));
    }

    public function testForeignAmountIsSplitProportionallyWithRemainderOnLastPiece(): void
    {
        $input = [
            self::line(self::A311, 'debit', '100.00', ['currency_code' => 'EUR', 'fx_rate' => '25.000000', 'amount_foreign' => '4.01']),
            self::line(self::A602, 'credit', '33.33'),
            self::line(self::A604, 'credit', '33.33'),
            self::line(self::A343, 'credit', '33.34'),
        ];

        $lines = JournalPairer::pair($input)['lines'];
        $hubPieces = array_values(array_filter($lines, static fn (array $l): bool => $l['account_id'] === self::A311));

        self::assertCount(3, $hubPieces);
        self::assertSame(['1.34', '1.34', '1.33'], array_column($hubPieces, 'amount_foreign'));
        self::assertSame(['EUR', 'EUR', 'EUR'], array_column($hubPieces, 'currency_code'));
        self::assertSame(['25.000000', '25.000000', '25.000000'], array_column($hubPieces, 'fx_rate'));
        self::assertArrayNotHasKey('amount_foreign', $lines[1]);
    }

    public function testHubPiecesInheritDimensionsSplitsOriginsProjectAndCostCenter(): void
    {
        $dims = ['dimensions' => [4 => 12], 'dimension_splits' => [5 => [13 => '0.40', 14 => '0.60']],
            'dimension_origins' => [4 => 'rule'], 'cost_center' => 'S1', 'project_id' => 9];
        $input = [
            self::line(self::A221, 'credit', '300.00', $dims),
            self::line(self::A518, 'debit', '100.00'),
            self::line(self::A568, 'debit', '200.00'),
        ];

        $lines = JournalPairer::pair($input)['lines'];

        foreach ($lines as $line) {
            if ($line['account_id'] !== self::A221) {
                continue;
            }
            foreach ($dims as $key => $value) {
                self::assertSame($value, $line[$key], $key);
            }
        }
    }

    public function testExistingPairsAreValidatedAndNewPairsAreNumberedAfterThem(): void
    {
        $input = [
            self::line(self::A568, 'debit', '15.00', ['pair_no' => 1, 'line_text' => 'Poplatek']),
            self::line(self::A221, 'credit', '15.00', ['pair_no' => 1]),
            self::line(self::A311, 'debit', '50.00'),
            self::line(self::A602, 'credit', '50.00'),
        ];

        $lines = JournalPairer::pair($input)['lines'];

        self::assertSame([
            [self::A568, self::A221, '15.00', 1],
            [self::A311, self::A602, '50.00', 2],
        ], self::pairsOf($lines));
        self::assertSame('Poplatek', $lines[1]['line_text']);

        $continued = JournalPairer::pair([$input[2], $input[3]], 5)['lines'];
        self::assertSame([5, 5], array_column($continued, 'pair_no'));
    }

    public function testInvalidExistingPairIsRejected(): void
    {
        $this->expectException(JournalPairException::class);
        $this->expectExceptionMessage(JournalPairInvariant::message(JournalPairInvariant::SAME_ACCOUNT));

        JournalPairer::pair([
            self::line(self::A221, 'debit', '10.00', ['pair_no' => 1]),
            self::line(self::A221, 'credit', '10.00', ['pair_no' => 1]),
        ]);
    }

    public function testHubWithSameAccountAsCounterLineIsUnpairable(): void
    {
        $result = JournalPairer::pair([
            self::line(self::A311, 'debit', '150.00'),
            self::line(self::A311, 'credit', '50.00'),
            self::line(self::A602, 'credit', '100.00'),
        ]);

        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, $result['status']);
        self::assertSame(JournalPairer::REASON_SAME_ACCOUNT, $result['reason']);
    }

    public function testRedStornoUsesEffectiveSideAndKeepsStornoFlagOnBothLines(): void
    {
        // Červené storno faktury: 311 a 602/343 na původních stranách se zápornou částkou.
        $input = [
            self::line(self::A311, 'debit', '1210.00', ['is_red_storno' => true]),
            self::line(self::A602, 'credit', '1000.00', ['is_red_storno' => true]),
            self::line(self::A343, 'credit', '210.00', ['is_red_storno' => true]),
        ];

        $result = JournalPairer::pair($input);

        self::assertSame(JournalPairer::STATUS_PAIRED, $result['status']);
        self::assertSame([
            [self::A602, self::A311, '1000.00', 1],
            [self::A343, self::A311, '210.00', 2],
        ], self::pairsOf($result['lines']));
        foreach ($result['lines'] as $line) {
            self::assertTrue($line['is_red_storno']);
        }
        self::assertSame(self::totalsByAccountSide($input), self::totalsByAccountSide($result['lines']));
        JournalPairInvariant::assert([], $result['lines'], EntryForm::Single);
    }

    public function testMixedRedStornoAcrossPairIsUnpairable(): void
    {
        $result = JournalPairer::pair([
            self::line(self::A311, 'debit', '100.00'),
            self::line(self::A602, 'debit', '100.00', ['is_red_storno' => true]),
        ]);

        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, $result['status']);
    }

    public function testLineNumbersAreRenumberedContiguouslyWhenInputCarriesThem(): void
    {
        $lines = JournalPairer::pair([
            self::line(self::A311, 'debit', '1210.00', ['line_no' => 3]),
            self::line(self::A602, 'credit', '1000.00', ['line_no' => 4]),
            self::line(self::A343, 'credit', '210.00', ['line_no' => 5]),
        ])['lines'];

        self::assertSame([3, 4, 5, 6], array_column($lines, 'line_no'));

        $plain = JournalPairer::pair([
            self::line(self::A311, 'debit', '1210.00'),
            self::line(self::A602, 'credit', '1210.00'),
        ])['lines'];
        self::assertArrayNotHasKey('line_no', $plain[0]);
    }

    public function testCounterLineTextBecomesTextOfWholeSplitPair(): void
    {
        $lines = JournalPairer::pair([
            self::line(self::A221, 'credit', '30.00', ['line_text' => 'Výpis 7']),
            self::line(self::A568, 'debit', '10.00', ['line_text' => 'Poplatek']),
            self::line(self::A518, 'debit', '20.00'),
        ])['lines'];

        self::assertSame(['Poplatek', 'Poplatek', 'Výpis 7', 'Výpis 7'], array_column($lines, 'line_text'));
    }

    public function testUnbalancedGroupIsUnpairable(): void
    {
        $result = JournalPairer::pair([
            self::line(self::A311, 'debit', '100.00', ['pair_group' => 'x']),
            self::line(self::A602, 'credit', '100.00'),
        ]);

        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, $result['status']);
        self::assertSame(JournalPairer::REASON_GROUP_UNBALANCED, $result['reason']);
    }

    public function testPairingIsDeterministic(): void
    {
        $input = [
            self::line(self::A311, 'debit', '121.00', ['amount_foreign' => '4.84', 'currency_code' => 'EUR']),
            self::line(self::A548, 'debit', '0.20', ['pair_role' => JournalPairer::ROLE_ROUNDING]),
            self::line(self::A602, 'credit', '100.00'),
            self::line(self::A343, 'credit', '21.20'),
        ];

        self::assertSame(JournalPairer::pair($input), JournalPairer::pair($input));
    }

    public function testEmptyInputAndStripPairKeys(): void
    {
        self::assertSame(['lines' => [], 'status' => JournalPairer::STATUS_PAIRED], JournalPairer::pair([]));
        self::assertSame(
            [['account_id' => 1, 'side' => 'debit', 'amount' => '1.00']],
            JournalPairer::stripPairKeys([['account_id' => 1, 'side' => 'debit', 'amount' => '1.00',
                'pair_no' => 1, 'line_text' => 'x', 'pair_group' => 'g', 'pair_role' => 'rounding']]),
        );
    }

    public function testShareAnalyticsFillsOnlyMissingAnalyticsOnPartnerLine(): void
    {
        $lines = [
            ['account_id' => 1, 'side' => 'debit', 'amount' => '10.00', 'pair_no' => 1,
                'cost_center' => 'A', 'project_id' => 7, 'dimensions' => [3 => 30, 1 => 10],
                'dimension_origins' => [3 => ['source' => 'item']], 'dimension_splits' => [5 => [50 => 1.0]]],
            ['account_id' => 2, 'side' => 'credit', 'amount' => '10.00', 'pair_no' => 1, 'dimensions' => [1 => 11]],
            ['account_id' => 3, 'side' => 'debit', 'amount' => '5.00', 'line_no' => 9],
        ];

        $out = JournalPairer::shareAnalytics($lines);

        self::assertSame($lines[0], $out[0], 'Řádek, který nic nepostrádá, se nemění.');
        self::assertSame('A', $out[1]['cost_center']);
        self::assertSame(7, $out[1]['project_id']);
        self::assertSame([1 => 11, 3 => 30], $out[1]['dimensions'], 'Vlastní hodnota typu zůstává, chybějící typ se doplní.');
        self::assertSame([3 => ['source' => 'item']], $out[1]['dimension_origins']);
        self::assertSame([5 => [50 => 1.0]], $out[1]['dimension_splits']);
        self::assertSame($lines[2], $out[2], 'Řádek bez dvojice zůstává beze změny.');
    }
}
