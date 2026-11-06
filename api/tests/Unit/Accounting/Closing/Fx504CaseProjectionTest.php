<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\{ClosingException, Fx504Builder, Fx504CaseProjection};
use PHPUnit\Framework\TestCase;

final class Fx504CaseProjectionTest extends TestCase
{
    public function testNativeSeparateCasesKeepGrossDifferences(): void
    {
        $rows = [self::row(1, '24000.00', '1000.00'), self::row(2, '26000.00', '1000.00')];
        $projected = Fx504CaseProjection::project($rows, [1 => [self::document(11)], 2 => [self::document(12)]]);
        self::assertTrue($projected['complete']);
        $preview = Fx504Builder::preview(Fx504Builder::positions($projected['rows']), ['EUR' => '25.000000'], [1, 2, 3]);
        self::assertSame(['loss' => '1000.00', 'gain' => '1000.00'], $preview['totals']);
        self::assertCount(4, Fx504Builder::build($preview, [], [1, 2, 3])['saldo']);
    }

    public function testExplicitBulkPaymentIsSplitExactlyAcrossDocumentCases(): void
    {
        $rows = [self::row(1, '24000.00', '1000.00'), self::row(2, '26000.00', '1000.00'), self::row(3, '24500.00', '1000.00', 'credit')];
        $origins = [1 => [self::document(11)], 2 => [self::document(12)], 3 => [self::document(11), self::document(12)]];
        $cells = array_values(Fx504CaseProjection::cells([$rows[2]]));
        $decisions = [
            ['origin' => self::document(11), 'reason' => 'Synthetic bulk settlement evidence', 'contributions' => [['cell_key' => $cells[0]['cell_key'], 'czk_signed' => '-12000.00', 'foreign_signed' => '-500.00']]],
            ['origin' => self::document(12), 'reason' => 'Synthetic bulk settlement evidence', 'contributions' => [['cell_key' => $cells[0]['cell_key'], 'czk_signed' => '-12500.00', 'foreign_signed' => '-500.00']]],
        ];
        $projected = Fx504CaseProjection::project($rows, $origins, $decisions);
        self::assertTrue($projected['complete']);
        $preview = Fx504Builder::preview(Fx504Builder::positions($projected['rows']), ['EUR' => '25.000000'], [1, 2, 3]);
        self::assertSame(['loss' => '1000.00', 'gain' => '500.00'], $preview['totals']);
    }

    public function testUnknownCasesAreVisibleEvenWhenTheirAggregateDifferenceIsZero(): void
    {
        $projection = Fx504CaseProjection::project([self::row(1, '24000.00', '1000.00'), self::row(2, '26000.00', '1000.00')], []);
        self::assertFalse($projection['complete']);
        self::assertCount(2, $projection['unresolved']);
        $preview = Fx504Builder::preview(Fx504Builder::positions($projection['rows']), ['EUR' => '25.000000'], [1, 2, 3]);
        self::assertFalse($preview['complete']);
        $this->expectException(ClosingException::class);
        Fx504Builder::build($preview, [], [1, 2, 3]);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['23999.99', '1000.00'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['24000.00', '999.99'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['-24000.00', '-1000.00'])]
    public function testContributionsMustCoverBothExactCurrencies(string $czk, string $foreign): void
    {
        $row = self::row(1, '24000.00', '1000.00');
        $key = array_key_first(Fx504CaseProjection::cells([$row]));
        $this->expectException(ClosingException::class);
        Fx504CaseProjection::project([$row], [], [['origin' => ['kind' => 'declared_title', 'origin_line_id' => 1, 'reference' => 'Synthetic loan contract'],
            'reason' => 'Synthetic opening evidence', 'contributions' => [['cell_key' => $key, 'czk_signed' => $czk, 'foreign_signed' => $foreign]]]]);
    }

    public function testUnprovenCashAccountCannotBypassCaseEvidence(): void
    {
        $row = self::row(1, '24000.00', '1000.00');
        $key = array_key_first(Fx504CaseProjection::cells([$row]));
        $this->expectException(ClosingException::class);
        Fx504CaseProjection::project([$row], [], [['origin' => ['kind' => 'cash_account', 'account_id' => 10, 'currency_code' => 'EUR'],
            'reason' => 'Unsupported classification', 'contributions' => [['cell_key' => $key, 'czk_signed' => '24000.00', 'foreign_signed' => '1000.00']]]]);
    }

    private static function document(int $id): array { return ['kind' => 'document', 'doc_type' => 'invoice', 'doc_id' => $id]; }

    private static function row(int $id, string $czk, string $foreign, string $side = 'debit'): array
    {
        return ['id' => $id, 'entry_id' => $id, 'entry_version' => 1, 'line_no' => 0, 'account_id' => 10, 'account_code' => '311',
            'currency_code' => 'EUR', 'side' => $side, 'amount' => $czk, 'amount_foreign' => $foreign, 'is_red_storno' => false, 'dimensions' => [1 => 11, 2 => 21, 3 => 31, 4 => 41]];
    }
}
