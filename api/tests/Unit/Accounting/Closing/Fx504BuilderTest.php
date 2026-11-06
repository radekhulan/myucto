<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\Fx504Builder;
use PHPUnit\Framework\TestCase;

final class Fx504BuilderTest extends TestCase
{
    public function testCurrentCarryingAmountAndJointCellsRemainSeparate(): void
    {
        $rows = [self::row(1, '24000.00', '1000.00', [1 => 11, 2 => 21, 3 => 31, 4 => 41]),
            self::row(2, '25000.00', '1000.00', [1 => 12, 2 => 22, 3 => 32, 4 => 42]),
            self::row(3, '500.00', '0.00', [1 => 11, 2 => 21, 3 => 31, 4 => 41])];
        $positions = Fx504Builder::positions($rows);
        $preview = Fx504Builder::preview($positions, ['EUR' => '26.000000'], [1, 2, 3]);
        self::assertCount(2, $preview['positions']);
        self::assertSame('2500.00', $preview['totals']['gain']);
        $entries = Fx504Builder::build($preview, [], [1, 2, 3]);
        self::assertCount(4, $entries['saldo']);
        self::assertEqualsCanonicalizing([11, 12], array_column(array_column(array_filter($entries['saldo'], static fn (array $line): bool => $line['account_code'] === '645'), 'dimensions'), 1));
        self::assertSame(['0.00', '0.00'], array_column(array_values(array_filter($entries['saldo'], static fn (array $line): bool => $line['account_code'] === '311')), 'amount_foreign'));
    }

    public function testExactReversalAndRedStornoDoNotLeavePhantomPosition(): void
    {
        $row = self::row(1, '24000.00', '1000.00', [1 => 11]);
        $reversal = array_replace($row, ['id' => 2, 'side' => 'credit']);
        self::assertSame([], Fx504Builder::positions([$row, $reversal]));
        self::assertSame([], Fx504Builder::positions([$row, array_replace($row, ['id' => 3, 'is_red_storno' => true])]));
    }

    public function testMissingProfitDimensionsRequireAnExplicitDecision(): void
    {
        $preview = Fx504Builder::preview(Fx504Builder::positions([self::row(1, '24000.00', '1000.00', [4 => 41])]), ['EUR' => '25.000000'], [1, 2, 3]);
        self::assertFalse($preview['complete']);
        $key = $preview['positions'][0]['key'];
        $entries = Fx504Builder::build($preview, [$key => ['reason' => 'Doložená příslušnost k činnosti.', 'cells' => [
            ['amount' => '600.00', 'dimensions' => [1 => 11, 2 => 21, 3 => 31, 4 => 41]],
            ['amount' => '400.00', 'dimensions' => [1 => 12, 2 => 22, 3 => 32, 4 => 41]],
        ]]], [1, 2, 3]);
        self::assertSame([4 => 41], $entries['saldo'][0]['dimensions']);
        self::assertSame('600.00', $entries['saldo'][1]['amount']);
        self::assertSame([1 => 12, 2 => 22, 3 => 32, 4 => 41], $entries['saldo'][3]['dimensions']);
    }

    public function testUnresolvedDecisionNeverDefaultsToMainOrSubject(): void
    {
        $preview = Fx504Builder::preview(Fx504Builder::positions([self::row(1, '24000.00', '1000.00', [])]), ['EUR' => '25.000000'], [1, 2, 3]);
        $this->expectException(ClosingException::class);
        Fx504Builder::build($preview, [], [1, 2, 3]);
    }

    public function testUnknownMonetaryTitleIsVisibleUntilEvidenced(): void
    {
        $row = array_replace(self::row(1, '24000.00', '1000.00', [1 => 11, 2 => 21, 3 => 31]), ['account_code' => '951.123']);
        $positions = Fx504Builder::positions([$row]);
        $pending = Fx504Builder::preview($positions, ['EUR' => '25.000000'], [1, 2, 3]);
        self::assertFalse($pending['complete']);
        self::assertSame('monetary_title_required', $pending['positions'][0]['finding']);
        $resolved = Fx504Builder::preview($positions, ['EUR' => '25.000000'], [1, 2, 3], ['951.123' => ['classification' => 'monetary', 'reason' => 'Úvěrová smlouva, splácení v EUR.']]);
        self::assertTrue($resolved['complete']);
        self::assertSame('1000.00', $resolved['totals']['gain']);
    }

    public function testIndependentFractionalAxesAreNotMultiplied(): void
    {
        $row = self::row(1, '24000.00', '1000.00', []);
        $row['dimension_splits'] = [1 => [11 => 50, 12 => 50], 4 => [41 => 50, 42 => 50]];
        $this->expectException(ClosingException::class);
        Fx504Builder::positions([$row]);
    }

    public function testExplicitDecisionCannotReplaceAnExistingSourceAxis(): void
    {
        $preview = Fx504Builder::preview(Fx504Builder::positions([self::row(1, '24000.00', '1000.00', [4 => 41])]), ['EUR' => '25.000000'], [1, 2, 3]);
        $key = $preview['positions'][0]['key'];
        $this->expectException(ClosingException::class);
        Fx504Builder::build($preview, [$key => ['reason' => 'Rozhodnutí.', 'cells' => [['amount' => '1000.00', 'dimensions' => [1 => 11, 2 => 21, 3 => 31, 4 => 42]]]]], [1, 2, 3]);
    }

    public function testMissingRateIsVisibleAndCannotAuthorizePosting(): void
    {
        $preview = Fx504Builder::preview(Fx504Builder::positions([self::row(1, '24000.00', '1000.00', [1 => 11, 2 => 21, 3 => 31])]), [], [1, 2, 3]);
        self::assertFalse($preview['complete']);
        self::assertSame('rate_required', $preview['positions'][0]['finding']);
        self::assertNull($preview['positions'][0]['diff']);
        $this->expectException(ClosingException::class);
        Fx504Builder::build($preview, [], [1, 2, 3]);
    }

    public function testSecuritiesCannotBeRoutedTo545645ByMonetaryReasonOnly(): void
    {
        $row = array_replace(self::row(1, '24000.00', '1000.00', [1 => 11, 2 => 21, 3 => 31]), ['account_code' => '063.100']);
        $preview = Fx504Builder::preview(Fx504Builder::positions([$row]), ['EUR' => '25.000000'], [1, 2, 3], ['063.100' => ['classification' => 'monetary', 'reason' => 'Synthetic unsupported claim.']]);
        self::assertFalse($preview['complete']);
        self::assertSame('securities_valuation_required', $preview['positions'][0]['finding']);
        self::assertNull($preview['positions'][0]['diff']);
        $this->expectException(ClosingException::class);
        Fx504Builder::build($preview, [], [1, 2, 3]);
    }

    public function testDistinctReceivablesWithSameTupleCannotNetOppositeExchangeDifferences(): void
    {
        $a = self::row(1, '24000.00', '1000.00', [1 => 11, 2 => 21, 3 => 31]) + ['case_origin' => ['kind' => 'document', 'doc_type' => 'invoice', 'doc_id' => 10]];
        $b = self::row(2, '26000.00', '1000.00', [1 => 11, 2 => 21, 3 => 31]) + ['case_origin' => ['kind' => 'document', 'doc_type' => 'invoice', 'doc_id' => 20]];
        $preview = Fx504Builder::preview(Fx504Builder::positions([$a, $b]), ['EUR' => '25.000000'], [1, 2, 3]);
        self::assertSame('1000.00', $preview['totals']['gain']);
        self::assertSame('1000.00', $preview['totals']['loss']);
    }

    private static function row(int $id, string $amount, string $foreign, array $dimensions): array
    {
        return ['id' => $id, 'account_id' => 10, 'account_code' => '311', 'account_type' => 'asset', 'currency_code' => 'EUR',
            'amount' => $amount, 'amount_foreign' => $foreign, 'side' => 'debit', 'is_red_storno' => false, 'dimensions' => $dimensions];
    }
}
