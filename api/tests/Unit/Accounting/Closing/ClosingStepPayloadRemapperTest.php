<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\ClosingStepPayloadRemapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ClosingStepPayloadRemapperTest extends TestCase
{
    private const OFFSETS = ['journal_entries' => 1000, 'invoices' => 2000, 'accounting_periods' => 3000, 'purchase_invoices' => 4000, 'users' => 5000];

    /** Staré id → nové id = staré + posun tabulky; id 999 v přenášených datech není. */
    private static function resolve(string $table, mixed $old): ?int
    {
        return (int) $old === 999 ? null : (int) $old + self::OFFSETS[$table];
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function untouchedPayloads(): iterable
    {
        yield 'null' => ['deferrals', null];
        yield 'prázdný záznam' => ['deferrals', []];
        yield 'seznamy null' => ['estimates', ['entries' => null, 'reversed' => null]];
        yield 'asistovaný bez zápisu' => ['estimates', ['entries' => [['entry_id' => null, 'amount' => 1.0]]]];
        yield 'jiný krok' => ['fx_revaluation', ['entry_ids' => [5, 999], 'entries' => [['entry_id' => 999]]]];
        yield 'reversed rezerv' => ['provisions', ['reversed' => [['entry_id' => 999, 'reversal_entry_id' => 999]]]];
        yield 'rozpad odkladu' => ['deferrals', [
            'deferred_revenue_accrual' => ['entry_id' => 999, 'items' => [['item_id' => 999, 'doc_id' => 999]]],
            'prepaid_expense_accrual' => ['entry_id' => 5, 'item_costs' => ['999' => 1.0]],
        ]];
        yield 'otevření bez zápisů' => ['open_next', ['entry_id' => null, 'fx_reversal_entry_id' => null, 'next_period_id' => null, 'document_no' => 'X']];
        yield 'důkaz přenosu § 504' => ['close_books', ['carry' => ['close_books' => ['entry_id' => 999, 'target_period_id' => 999, 'source_line_ids' => [999]]]]];
        yield 'rozdělení výsledku § 504' => ['open_next', ['result_decision' => ['entry_id' => 999, 'target_period_id' => 999, 'cells' => [['account' => '431']]]]];
        // Zápisy zrušení přecenění a rozpuštění převod nepřijímá.
        yield 'zápisy mimo převod' => ['open_next', ['fx_reversal_entry_id' => 999, 'stock_release_entry_id' => 999, 'small_asset_release_entry_id' => 999,
            'prepaid_expense_release_entry_id' => 999, 'deferred_revenue_release_entry_id' => 999]];
    }

    #[DataProvider('untouchedPayloads')]
    public function testPayloadWithoutReferencesStaysUnchanged(string $step, mixed $payload): void
    {
        self::assertSame([$payload, []], ClosingStepPayloadRemapper::remap($step, $payload, self::resolve(...)));
    }

    #[DataProvider('assistedSteps')]
    public function testAssistedEntriesAreRemapped(string $step): void
    {
        [$payload, $warnings] = ClosingStepPayloadRemapper::remap($step, [
            'entries' => [['entry_id' => 1, 'rule_key' => 'accrual.accrued.expense']],
            'reversed' => [['entry_id' => 2, 'reversal_entry_id' => 3]],
            'accounts' => ['518' => 1.0],
        ], self::resolve(...));

        self::assertSame([], $warnings);
        self::assertSame([
            'entries' => [['entry_id' => 1001, 'rule_key' => 'accrual.accrued.expense']],
            'reversed' => [['entry_id' => 1002, 'reversal_entry_id' => 1003]],
            'accounts' => ['518' => 1.0],
        ], $payload);
    }

    /** @return iterable<string, array{string}> */
    public static function assistedSteps(): iterable
    {
        yield 'Dohadné položky' => ['estimates'];
        yield 'Časové rozlišení' => ['deferrals'];
    }

    public function testMissingAssistedReferencesAreDroppedWithWarning(): void
    {
        [$payload, $warnings] = ClosingStepPayloadRemapper::remap('deferrals', [
            'entries' => [['entry_id' => 999], ['entry_id' => 1]],
            'reversed' => [['entry_id' => 2, 'reversal_entry_id' => 999], ['entry_id' => 999, 'reversal_entry_id' => 3]],
        ], self::resolve(...));

        self::assertSame([['entry_id' => 1001]], $payload['entries']);
        // Storno zůstává v historii i bez stornovaného zápisu.
        self::assertSame([['entry_id' => 1002, 'reversal_entry_id' => null], ['entry_id' => null, 'reversal_entry_id' => 1003]], $payload['reversed']);
        self::assertSame([
            'Záznam kroku uzávěrky deferrals.entries.entry_id: journal_entries #999 v přenášených datech není, odkaz se nepřenesl.',
            'Záznam kroku uzávěrky deferrals.reversed.reversal_entry_id: journal_entries #999 v přenášených datech není, odkaz se nepřenesl.',
            'Záznam kroku uzávěrky deferrals.reversed.entry_id: journal_entries #999 v přenášených datech není, odkaz se nepřenesl.',
        ], $warnings);
    }

    public function testOpenNextRemapsEntriesPeriodAndTakenOverEntries(): void
    {
        $carry = ['open_next' => ['entry_id' => 1, 'target_period_id' => 7, 'entry_version' => 2]];
        [$payload, $warnings] = ClosingStepPayloadRemapper::remap('open_next', [
            'entry_id' => 1,
            'next_period_id' => 7,
            'document_no' => 'OT-1',
            'opening_source' => 'taken_over',
            'taken_over_entry_ids' => [8, 999, 9],
            'carry' => $carry,
        ], self::resolve(...));

        self::assertSame([
            'entry_id' => 1001,
            'next_period_id' => 3007,
            'document_no' => 'OT-1',
            'opening_source' => 'taken_over',
            'taken_over_entry_ids' => [1008, 1009],
            'carry' => $carry,
        ], $payload);
        self::assertSame(['Záznam kroku uzávěrky open_next.taken_over_entry_ids: journal_entries #999 v přenášených datech není, odkaz se nepřenesl.'], $warnings);
    }

    public function testCloseBooksRemapsEntryTakenOverEntriesAndUnpostedOverride(): void
    {
        [$payload, $warnings] = ClosingStepPayloadRemapper::remap('close_books', [
            'entry_id' => 999, 'profit' => 10.0, 'closing_taken_over' => true, 'taken_over_entry_ids' => [4],
            'next_period_id' => 7,
            'unposted_override' => ['count' => 3, 'invoice_ids' => [5, 999], 'purchase_ids' => [6], 'reason' => 'Doklady po uzávěrce', 'overridden_by' => 8],
        ], self::resolve(...));

        // next_period_id záznam Uzavření knih nenese; kdyby ho nesl, nepřemapuje se.
        self::assertSame(['entry_id' => null, 'profit' => 10.0, 'closing_taken_over' => true, 'taken_over_entry_ids' => [1004], 'next_period_id' => 7,
            'unposted_override' => ['count' => 3, 'invoice_ids' => [2005], 'purchase_ids' => [4006], 'reason' => 'Doklady po uzávěrce', 'overridden_by' => 5008]], $payload);
        self::assertSame([
            'Záznam kroku uzávěrky close_books.entry_id: journal_entries #999 v přenášených datech není, odkaz se nepřenesl.',
            'Záznam kroku uzávěrky close_books.unposted_override.invoice_ids: invoices #999 v přenášených datech není, odkaz se nepřenesl.',
        ], $warnings);
    }

    public function testProvisionEntriesRemapAndMissingReferenceStops(): void
    {
        [$payload] = ClosingStepPayloadRemapper::remap('provisions', ['entries' => [['invoice_id' => 1, 'entry_id' => 2, 'legal_decision' => ['source_entry_id' => 3]]]], self::resolve(...));
        self::assertSame(['entries' => [['invoice_id' => 2001, 'entry_id' => 1002, 'legal_decision' => ['source_entry_id' => 3]]]], $payload);

        $this->expectException(UnexpectedValueException::class);
        ClosingStepPayloadRemapper::remap('provisions', ['entries' => [['invoice_id' => 999, 'entry_id' => 2]]], self::resolve(...));
    }
}
