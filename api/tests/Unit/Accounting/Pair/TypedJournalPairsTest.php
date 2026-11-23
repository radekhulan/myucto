<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Pair;

use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\Pair\TypedJournalPairs;
use PHPUnit\Framework\TestCase;

/**
 * Pravidlo dvojic typovaných zapisovačů (SVJ, kroky uzávěrky 504) a porovnání plánu
 * se zapsaným zápisem v důkazech.
 */
final class TypedJournalPairsTest extends TestCase
{
    public function testConsecutivePairsAreOnlyNumberedInPlace(): void
    {
        $lines = [
            self::line('315.130', 'debit', '1080.00'), self::line('955.100', 'credit', '1080.00'),
            self::line('324.200', 'credit', '6.67'), self::line('315.120', 'debit', '6.67'),
        ];
        $result = TypedJournalPairs::pair($lines);
        self::assertSame(JournalPairer::STATUS_PAIRED, $result['status']);
        self::assertSame([1, 1, 2, 2], array_column($result['lines'], 'pair_no'));
        self::assertSame(array_column($lines, 'account_code'), array_column($result['lines'], 'account_code'), 'Pořadí řádků zůstává.');
    }

    public function testMovementRunsSplitTheHeadAgainstItsOwnCounterLines(): void
    {
        $lines = [
            self::line('324.110', 'debit', '132.00'), self::line('315.210', 'credit', '80.00'), self::line('315.210', 'credit', '52.00'),
            self::line('324.110', 'debit', '10.00'), self::line('379.910', 'credit', '10.00'),
        ];
        $result = TypedJournalPairs::pair($lines);
        self::assertSame(JournalPairer::STATUS_PAIRED, $result['status']);
        self::assertSame(
            [['324.110', '80.00', 1], ['315.210', '80.00', 1], ['324.110', '52.00', 2], ['315.210', '52.00', 2], ['324.110', '10.00', 3], ['379.910', '10.00', 3]],
            array_map(static fn (array $l): array => [$l['account_code'], $l['amount'], $l['pair_no']], $result['lines']),
        );
    }

    public function testConsecutiveModeRefusesToSplitLines(): void
    {
        $lines = [self::line('221', 'debit', '100.00'), self::line('379.900', 'credit', '60.00'), self::line('379.900', 'credit', '40.00')];
        $result = TypedJournalPairs::pair($lines, TypedJournalPairs::CONSECUTIVE);
        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, $result['status']);
        self::assertSame(TypedJournalPairs::REASON_NOT_CONSECUTIVE, $result['reason']);
        self::assertSame(JournalPairer::STATUS_PAIRED, TypedJournalPairs::pair($lines)['status']);
    }

    public function testCarriedPairNumbersAreDropped(): void
    {
        $lines = [self::line('221', 'debit', '50.00') + ['pair_no' => 7, 'line_text' => 'banka'], self::line('379.900', 'credit', '50.00')];
        self::assertSame([1, 1], array_column(TypedJournalPairs::pair($lines)['lines'], 'pair_no'));
        self::assertArrayNotHasKey('line_text', TypedJournalPairs::withoutCarriedPairs($lines)[0]);
    }

    public function testSameAccountCellMoveStaysUnpaired(): void
    {
        $result = TypedJournalPairs::pair([self::line('379.900', 'debit', '10.00'), self::line('379.900', 'credit', '10.00')]);
        self::assertSame(JournalPairer::STATUS_UNPAIRABLE, $result['status']);
    }

    public function testMatchesAcceptsTheWrittenSplitAndItsReversalMirrorButNotADifferentLedger(): void
    {
        $plan = [self::line('221', 'debit', '100.00'), self::line('379.900', 'credit', '60.00'), self::line('379.910', 'credit', '40.00')];
        $written = TypedJournalPairs::pair($plan)['lines'];
        $canonical = static fn (array $lines): string => json_encode(array_map(static fn (array $l): array => [$l['account_code'], $l['side'], $l['amount']], $lines));
        self::assertTrue(TypedJournalPairs::matches($plan, $written, $canonical));
        self::assertTrue(TypedJournalPairs::matches($written, $plan, $canonical), 'Pořadí argumentů nerozhoduje.');
        self::assertTrue(TypedJournalPairs::matches($plan, $plan, $canonical), 'Bez dvojic řádek po řádku.');

        $mirror = array_map(static fn (array $l): array => array_replace($l, JournalLineAmount::reversal($l)), $written);
        $reversedPlan = array_map(static fn (array $l): array => array_replace($l, JournalLineAmount::reversal($l)), $plan);
        self::assertTrue(TypedJournalPairs::matches($reversedPlan, $mirror, $canonical), 'Zrcadlo storna zdroje po dvojicích.');
        self::assertSame($canonical($mirror), $canonical(TypedJournalPairs::expectedFor($reversedPlan, $mirror)));

        $tampered = $written;
        $tampered[1]['account_code'] = '379.920';
        self::assertFalse(TypedJournalPairs::matches($plan, $tampered, $canonical));
        $composite = $plan;
        $composite[0]['amount'] = '99.00';
        self::assertFalse(TypedJournalPairs::matches($plan, $composite, $canonical));
    }

    /** @return array<string,mixed> */
    private static function line(string $account, string $side, string $amount): array
    {
        return ['account_code' => $account, 'side' => $side, 'amount' => $amount];
    }
}
