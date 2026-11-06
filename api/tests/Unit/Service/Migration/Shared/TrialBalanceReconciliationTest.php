<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;
use PHPUnit\Framework\TestCase;

final class TrialBalanceReconciliationTest extends TestCase
{
    public function testToleranceIsHalfCent(): void
    {
        self::assertTrue(ReconciliationTolerance::sameCent(100.0, 100.004));
        self::assertFalse(ReconciliationTolerance::sameCent(100.0, 100.01));
        self::assertTrue(ReconciliationTolerance::isZeroCent(-0.0049));
        self::assertFalse(ReconciliationTolerance::isZeroCent(0.01));
        self::assertSame(1.0, ReconciliationTolerance::FILING_ROUNDING);
    }

    public function testSyntheticSumsAnalyticsAndSkips(): void
    {
        $rows = [
            ['account_code' => '221.001', 'ps_md' => 100, 'ps_d' => 0, 'turnover_md' => 50.004, 'turnover_d' => 20, 'ks_md' => 130, 'ks_d' => 0],
            ['account_code' => '221.002', 'ps_md' => 0, 'ps_d' => 10, 'turnover_md' => 0, 'turnover_d' => 0, 'ks_md' => 0, 'ks_d' => 10],
            ['account_code' => '701', 'ps_md' => 0, 'ps_d' => 90, 'turnover_md' => 0, 'turnover_d' => 0, 'ks_md' => 0, 'ks_d' => 90],
        ];
        self::assertSame(['221' => [90.0, 30.0, 120.0], '701' => [-90.0, 0.0, -90.0]], TrialBalanceReconciliation::synthetic($rows));
        self::assertSame(['221' => [90.0, 30.0, 120.0]], TrialBalanceReconciliation::synthetic($rows, static fn (string $s): bool => $s[0] === '7'));
    }

    public function testCompareListsOnlyDifferingAccountsSorted(): void
    {
        $diffs = TrialBalanceReconciliation::compare(
            ['311' => [1.0, 2.0, 3.0], '211' => [0.0, 5.0, 5.0], '221' => [1.0, 1.0, 2.0]],
            ['311' => [1.0, 2.004, 3.0], '211' => [0.0, 5.0, 5.01], '602' => [0.0, -1.0, -1.0]],
        );
        self::assertSame(['211', '221', '602'], array_column($diffs, 'account'));
        self::assertSame([0.0, 0.0, 0.0], $diffs[1]['money']);
        self::assertSame([0.0, 0.0, 0.0], $diffs[2]['myucto']);
    }

    public function testChecksAndBalanceSheet(): void
    {
        $tb = ['checks' => ['turnover_balanced' => true, 'matches_journal' => 1, 'opening_balanced' => true], 'draft_count' => 0];
        $checks = TrialBalanceReconciliation::checks($tb, 'pohoda_journal', [], 12);
        self::assertSame(['turnover_balanced', 'matches_journal', 'opening_balanced', 'no_drafts', 'pohoda_journal'], array_column($checks, 'key'));
        self::assertSame(12, $checks[4]['accounts']);
        self::assertTrue(TrialBalanceReconciliation::allOk($checks));
        self::assertFalse(TrialBalanceReconciliation::allOk(TrialBalanceReconciliation::checks($tb, 'x', [['account' => '211']], 1)));

        $bs = TrialBalanceReconciliation::balanceSheet(['checks' => ['balanced' => true, 'unmapped_accounts' => [['account_code' => '395', 'name' => 'Vnitřní zúčtování', 'balance' => '10.004']]]]);
        self::assertSame(['key' => 'balance_sheet_balanced', 'ok' => false], $bs['check']);
        self::assertSame([['account' => '395', 'name' => 'Vnitřní zúčtování', 'balance' => 10.0]], $bs['unmapped']);
        self::assertTrue(TrialBalanceReconciliation::balanceSheet(['checks' => ['balanced' => true]])['check']['ok']);
    }

    public function testNegativeNetRowsAreAWarningNotAFailedCheck(): void
    {
        $bs = TrialBalanceReconciliation::balanceSheet(['checks' => [
            'balanced' => true,
            'negative_net_rows' => [
                ['row_code' => 'C.II.2.1.', 'label' => 'Pohledávky z obchodních vztahů', 'column' => 'current', 'gross' => 7000.0, 'correction' => 25000.0, 'net' => -18000.004],
            ],
        ]]);
        self::assertTrue($bs['check']['ok'], 'Záporné netto nerozbije kontrolu rozvahy, je to varování.');
        self::assertSame([['row_code' => 'C.II.2.1.', 'column' => 'current', 'net' => -18000.0]], $bs['negative_net_rows']);

        $text = TrialBalanceReconciliation::negativeNetWarning(2091, $bs['negative_net_rows']);
        self::assertNotNull($text);
        self::assertStringContainsString('Rok 2091', $text);
        self::assertStringContainsString('C.II.2.1. -18 000,00 Kč', $text);
        self::assertNull(TrialBalanceReconciliation::negativeNetWarning(2091, []));
        self::assertSame([], TrialBalanceReconciliation::balanceSheet(['checks' => ['balanced' => true]])['negative_net_rows']);
    }

    public function test504ConversionBalanceDoesNotPretendToHave500Diagnostics(): void
    {
        $this->expectException(\MyInvoice\Service\Accounting\Reports\ReportException::class);
        $this->expectExceptionMessage('Převod účetních výkazů podle vyhlášky 504');
        TrialBalanceReconciliation::balanceSheet(['accounting_framework' => 'cz_504',
            'checks' => ['balanced' => true, 'profit_matches' => true]]);
    }

    public function testDocumentRow(): void
    {
        self::assertSame(
            ['key' => 'bank', 'documents' => 10.0, 'journal' => 10.004, 'ok' => true, 'other_accounts' => 0],
            TrialBalanceReconciliation::documentRow('bank', 10.0, 10.004, 0),
        );
        self::assertFalse(TrialBalanceReconciliation::documentRow('cash', 10.0, 10.01, 2)['ok']);
    }

    public function testExplainedBySourceNeedsEveryDocumentAndWholeDifference(): void
    {
        $mine = [['document_no' => 'FV1', 'difference' => 100.0], ['document_no' => '2024', 'difference' => -20.0]];
        $source = ['FV1' => 100.0, 2024 => -20.004];
        self::assertTrue(TrialBalanceReconciliation::explainedBySource($mine, $source, 580.0, 500.0));
        self::assertFalse(TrialBalanceReconciliation::explainedBySource($mine, $source, 590.0, 500.0), 'rozdíl kontroly je větší než vysvětlený');
        self::assertFalse(TrialBalanceReconciliation::explainedBySource($mine, ['FV1' => 100.0], 580.0, 500.0), 'doklad bez rozdílu ve zdroji');
        self::assertFalse(TrialBalanceReconciliation::explainedBySource($mine, ['FV1' => 100.0, '2024' => 0.0], 580.0, 500.0), 'nulový rozdíl ve zdroji nic nevysvětlí');
        self::assertFalse(TrialBalanceReconciliation::explainedBySource([], [], 1.0, 0.0));
    }
}
