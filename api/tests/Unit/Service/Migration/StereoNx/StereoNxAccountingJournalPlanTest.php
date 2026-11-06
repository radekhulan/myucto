<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingJournalPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StereoNxAccountingJournalPlanTest extends TestCase
{
    private static function row(array $changes = []): array
    {
        return $changes + ['Agenda' => 'B', 'DoklRada' => 'TEST', 'DoklCislo' => '1', 'Klic' => 1, 'Poradi' => 1,
            'KdyUcPripad' => '2026-02-03', 'Rok' => 2026, 'Mesic' => 2,
            'UcetMD' => '221test', 'UcetD' => '321', 'Celkem' => 1250.50, 'DPH' => 0.0];
    }

    private static function plan(array $rows): array
    {
        return StereoNxAccountingJournalPlan::build($rows, [['Ucet' => '221test'], ['Ucet' => '321']]);
    }

    public function testPreservesSourceIdentityAccountsAndSignedAmounts(): void
    {
        $plan = self::plan([self::row(), self::row(['Klic' => 2, 'Celkem' => -50.25])]);
        self::assertTrue($plan['ok']);
        self::assertSame('221test', $plan['entries'][0]['debit']);
        self::assertSame(2026, $plan['entries'][0]['source_year']);
        self::assertSame(120025, $plan['summary']['debit_cents']);
        self::assertSame(120025, $plan['summary']['credit_cents']);
        self::assertSame(-5025, $plan['entries'][1]['amount_cents']);
    }

    public function testNormalizationCollisionBlocksImportInsteadOfMergingSourceAccounts(): void
    {
        $plan = StereoNxAccountingJournalPlan::build([], [['Ucet' => '321001'], ['Ucet' => '321.001']]);
        self::assertFalse($plan['ok']);
        self::assertContains('chart_account_normalization_collision', array_column($plan['blockers'], 'code'));
    }

    public function testLengthLimitIncludesInsertedAnalyticSeparator(): void
    {
        $plan = StereoNxAccountingJournalPlan::build([], [['Ucet' => '3211234567']]);
        self::assertFalse($plan['ok']);
        self::assertContains('target_account_code_too_long', array_column($plan['blockers'], 'code'));
    }

    public function testPostingDateDeterminesYearAndSourceYearRemainsVisible(): void
    {
        $plan = self::plan([self::row(['Rok' => 2025])]);
        self::assertTrue($plan['ok']);
        self::assertSame([], $plan['blockers']);
        self::assertSame(['journal_year_mismatch'], array_column($plan['warnings'], 'code'));
        self::assertSame(2025, $plan['entries'][0]['source_year']);
        self::assertSame(2026, $plan['entries'][0]['posting_year']);
        self::assertSame('2026-02-03', $plan['entries'][0]['date']);
    }

    public function testUnusedNativeNullTaxBreakdownPreservesExactAccountingPosting(): void
    {
        $withZero = self::plan([self::row()]);
        $withNull = self::plan([self::row(['DPH' => null])]);
        self::assertTrue($withNull['ok'], json_encode($withNull['blockers']));
        self::assertSame($withZero['entries'], $withNull['entries']);
        self::assertSame($withZero['summary'], $withNull['summary']);
    }

    public function testMissingTaxFieldDoesNotMasqueradeAsAnUnusedNativeNull(): void
    {
        $row = self::row();
        unset($row['DPH']);
        $plan = self::plan([$row]);
        self::assertFalse($plan['ok']);
        self::assertContains('journal_tax_amount_invalid', array_column($plan['blockers'], 'code'));
    }

    public function testSplitPostingUsesOrderAsPartOfIdentity(): void
    {
        $plan = self::plan([self::row(), self::row(['Poradi' => 2, 'Celkem' => 100.0])]);
        self::assertTrue($plan['ok']);
        self::assertCount(2, $plan['entries']);
    }

    public function testUserIdDoesNotIdentifyJournalRows(): void
    {
        $plan = self::plan([
            self::row(['DoklCislo' => '1', 'UID' => 0]),
            self::row(['DoklCislo' => '2', 'UID' => 0]),
        ]);
        self::assertTrue($plan['ok']);
        self::assertCount(2, $plan['entries']);
        self::assertNotSame($plan['entries'][0]['source_key'], $plan['entries'][1]['source_key']);
    }

    #[DataProvider('invalidRows')]
    public function testRejectsInvalidSourceFields(array $changes, string $code): void
    {
        $plan = self::plan([self::row($changes)]);
        self::assertFalse($plan['ok']);
        self::assertContains($code, array_column($plan['blockers'], 'code'));
    }

    public static function invalidRows(): iterable
    {
        yield 'missing series' => [['DoklRada' => ''], 'journal_identity_missing'];
        yield 'missing key' => [['Klic' => null], 'journal_identity_missing'];
        yield 'impossible date' => [['KdyUcPripad' => '2026-02-30'], 'journal_date_invalid'];
        yield 'missing year' => [['Rok' => null], 'journal_year_invalid'];
        yield 'wrong month' => [['Mesic' => 1], 'journal_month_mismatch'];
        yield 'invalid month' => [['Mesic' => 13], 'journal_month_invalid'];
        yield 'missing account' => [['UcetMD' => ''], 'journal_account_missing'];
        yield 'unknown account' => [['UcetD' => '999'], 'journal_account_not_in_chart'];
        yield 'long account' => [['UcetMD' => '22112345678'], 'target_account_code_too_long'];
        yield 'null amount' => [['Celkem' => null], 'journal_amount_invalid'];
        yield 'nan amount' => [['Celkem' => NAN], 'journal_amount_invalid'];
        yield 'infinite amount' => [['Celkem' => INF], 'journal_amount_invalid'];
        yield 'out of range' => [['Celkem' => 1e14], 'journal_amount_out_of_range'];
        yield 'unknown tax decomposition' => [['DPH' => 21.0], 'journal_tax_amount_unverified'];
    }

    public function testFiscalCompanyAssignsCompanyPeriodLabelAndAcceptsMatchingSourceYear(): void
    {
        $plan = StereoNxAccountingJournalPlan::build([
            self::row(['KdyUcPripad' => '2025-08-01', 'Rok' => 2025, 'Mesic' => 8]),
            self::row(['Klic' => 2, 'KdyUcPripad' => '2026-07-31', 'Rok' => 2025, 'Mesic' => 7]),
        ], [['Ucet' => '221test'], ['Ucet' => '321']], new FiscalCalendar(8));
        self::assertTrue($plan['ok'], json_encode($plan['blockers']));
        self::assertSame([], $plan['warnings']);
        self::assertSame([2025, 2025], array_column($plan['entries'], 'posting_year'));
    }

    public function testCalendarCompanySplitsFiscalSourceByDateAndOnlyWarnsAboutSourceYear(): void
    {
        $plan = StereoNxAccountingJournalPlan::build([
            self::row(['KdyUcPripad' => '2025-08-01', 'Rok' => 2025, 'Mesic' => 8]),
            self::row(['Klic' => 2, 'KdyUcPripad' => '2026-02-03', 'Rok' => 2025, 'Mesic' => 2]),
        ], [['Ucet' => '221test'], ['Ucet' => '321']], FiscalCalendar::calendar());
        self::assertTrue($plan['ok'], 'shodu účetního roku zdroje kontroluje writer podle počátečních stavů');
        self::assertSame(['journal_year_mismatch'], array_column($plan['warnings'], 'code'));
        self::assertSame([2025, 2026], array_column($plan['entries'], 'posting_year'));
    }

    public function testRejectsDuplicateRowsAndChartAccounts(): void
    {
        $plan = StereoNxAccountingJournalPlan::build([self::row(), self::row()],
            [['Ucet' => '221test'], ['Ucet' => '321'], ['Ucet' => '321']]);
        self::assertSame(['chart_account_duplicate', 'journal_identity_duplicate'], array_column($plan['blockers'], 'code'));
        self::assertCount(1, $plan['entries']);
    }
}
