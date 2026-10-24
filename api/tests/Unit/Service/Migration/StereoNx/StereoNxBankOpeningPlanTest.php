<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxBankOpeningPlan;
use PHPUnit\Framework\TestCase;

final class StereoNxBankOpeningPlanTest extends TestCase
{
    public function testProvenOpeningBecomesPreviousDayBalanceWithoutChangingSourceRecords(): void
    {
        [$payments, $journal] = $this->plans();
        $resolved = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame([], $resolved['records']['bank_transactions']);
        self::assertSame([['movement' => $payments['records']['bank_transactions'][0],
            'statement_key' => '["2026","B","17"]', 'opening_cents' => 123456,
            'entry_keys' => ['["B","B","17","4","1"]'], 'account_code' => '221.100',
            'date' => '2026-01-01', 'balance_date' => '2025-12-31']], $resolved['records']['bank_openings']);
        self::assertSame($payments['records']['bank_statements'], $resolved['records']['bank_statements']);
        self::assertSame($payments['records']['bank_accounts'], $resolved['records']['bank_accounts']);
        self::assertSame(0, $resolved['counts']['bank_transactions']);
        self::assertSame(1, $resolved['counts']['bank_openings']);
        self::assertContains('bank_opening_balance', array_column($resolved['warnings'], 'code'));
        self::assertSame($resolved, StereoNxBankOpeningPlan::resolve($resolved, $journal));
    }

    public function testNegativeOpeningKeepsItsSignAndMappedAccount(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['records']['bank_transactions'][0]['amount'] = -1234.56;
        $journal['accounting_plan']['entries'][0] = array_replace($journal['accounting_plan']['entries'][0],
            ['debit' => '701', 'credit' => '221', 'target_debit' => '701', 'target_credit' => '221100']);
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame(-123456, $result['records']['bank_openings'][0]['opening_cents']);
        self::assertSame('221.100', $result['records']['bank_openings'][0]['account_code']);
    }

    public function testFiscalPostingYearMayDifferFromCalendarYear(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['records']['bank_transactions'][0]['source_key'] = '["2025","B","17","4"]';
        $payments['records']['bank_transactions'][0]['statement_key'] = '["2025","B","17"]';
        $payments['records']['bank_statements'][0]['source_key'] = '["2025","B","17"]';
        $journal['accounting_plan']['entries'][0]['posting_year'] = 2025;
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame(1, $result['counts']['bank_openings']);
        self::assertSame('2025-12-31', $result['records']['bank_openings'][0]['balance_date']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unprovenCases')]
    public function testUnprovenOpeningRemainsUnchanged(string $case): void
    {
        [$payments, $journal] = $this->plans();
        $entry = &$journal['accounting_plan']['entries'][0];
        switch ($case) {
            case 'red': $entry['is_red_storno'] = true; break;
            case 'amount': $entry['amount_cents']++; break;
            case 'date': $entry['date'] = '2026-01-02'; break;
            case 'missing_year': unset($entry['posting_year']); break;
            case 'analytic': $entry['target_debit'] = '221.101'; break;
            case 'no_bank': $entry['debit'] = '211'; break;
            case 'two_bank_sides': $entry['credit'] = '221'; break;
            case 'currency': $payments['records']['bank_transactions'][0]['currency'] = 'EUR'; break;
            case 'zero': $payments['records']['bank_transactions'][0]['amount'] = 0; break;
            case 'skipped': $payments['counts']['skipped_bank_transactions'] = 1; break;
            case 'mixed_statement': $payments['records']['bank_transactions'][] = array_replace($payments['records']['bank_transactions'][0], ['source_key' => '["2026","B","17","5"]']); break;
            case 'ordinary_entry': $journal['accounting_plan']['entries'][] = array_replace($entry, ['source_key' => '["B","B","17","4","2"]', 'is_opening' => false]); break;
            case 'duplicate_entry': $journal['accounting_plan']['entries'][] = $entry; break;
            case 'payment': $payments['records']['payments'][] = ['movement_type' => 'bank', 'movement_key' => $payments['records']['bank_transactions'][0]['source_key']]; break;
            case 'vat': $payments['records']['reviews'][] = ['kind' => 'bank', 'source_key' => $payments['records']['bank_transactions'][0]['source_key'], 'review_codes' => ['movement_vat_unverified']]; break;
        }
        unset($entry);
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame($payments['records'], $result['records']);
        self::assertContains('bank_opening_unverified', array_column($result['warnings'], 'code'));
        self::assertSame($result, StereoNxBankOpeningPlan::resolve($result, $journal));
    }

    public static function unprovenCases(): iterable
    {
        foreach (['red', 'amount', 'date', 'missing_year', 'analytic', 'no_bank', 'two_bank_sides', 'currency',
            'zero', 'skipped', 'mixed_statement', 'ordinary_entry', 'duplicate_entry', 'payment', 'vat'] as $case) yield $case => [$case];
    }

    public function testOpeningDescriptionAloneDoesNotClassifyOrdinaryMovement(): void
    {
        [$payments, $journal] = $this->plans();
        $journal['accounting_plan']['entries'][0]['is_opening'] = false;
        $payments['records']['bank_transactions'][0]['description'] = 'Počáteční zůstatek';
        self::assertSame($payments, StereoNxBankOpeningPlan::resolve($payments, $journal));
    }

    public function testEarlierMovementOfSamePhysicalBankThroughAliasBlocksOpening(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['records']['bank_accounts'][] = array_replace($payments['records']['bank_accounts'][0], ['source_key' => 'ALIAS']);
        $payments['records']['bank_statements'][] = ['source_key' => '["2026","ALIAS","16"]', 'account_key' => 'ALIAS', 'date' => '2025-12-31', 'currency' => 'CZK'];
        $payments['records']['bank_transactions'][] = array_replace($payments['records']['bank_transactions'][0],
            ['source_key' => '["2026","ALIAS","16","1"]', 'statement_key' => '["2026","ALIAS","16"]', 'date' => '2025-12-31']);
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame($payments['records'], $result['records']);
        self::assertContains('bank_opening_unverified', array_column($result['warnings'], 'code'));
    }

    public function testSameDayOrdinaryMovementOnDifferentStatementDoesNotBlockPreviousDayAnchor(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['records']['bank_statements'][] = array_replace($payments['records']['bank_statements'][0], ['source_key' => '["2026","B","18"]']);
        $ordinary = array_replace($payments['records']['bank_transactions'][0],
            ['source_key' => '["2026","B","18","1"]', 'statement_key' => '["2026","B","18"]', 'amount' => 50]);
        $payments['records']['bank_transactions'][] = $ordinary;
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame([$ordinary], $result['records']['bank_transactions']);
        self::assertSame(1, $result['counts']['bank_openings']);
        self::assertSame('2025-12-31', $result['records']['bank_openings'][0]['balance_date']);
    }

    public function testSplitOpeningEntriesHaveOneAccountAndCompleteEntryKeys(): void
    {
        [$payments, $journal] = $this->plans();
        $journal['accounting_plan']['entries'][0]['amount_cents'] = 100000;
        $journal['accounting_plan']['entries'][] = array_replace($journal['accounting_plan']['entries'][0],
            ['source_key' => '["B","B","17","4","2"]', 'amount_cents' => 23456]);
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame(123456, $result['records']['bank_openings'][0]['opening_cents']);
        self::assertSame(array_column($journal['accounting_plan']['entries'], 'source_key'), $result['records']['bank_openings'][0]['entry_keys']);
    }

    public function testOnlyReviewsOfProvenOpeningAreRemoved(): void
    {
        [$payments, $journal] = $this->plans();
        $other = ['kind' => 'cash', 'source_key' => 'other', 'review_codes' => ['movement_vat_unverified']];
        $payments['records']['reviews'] = [['kind' => 'bank', 'source_key' => $payments['records']['bank_transactions'][0]['source_key'],
            'review_codes' => ['movement_journal_unverified']], $other];
        $payments['counts']['requires_review'] = 2;
        $payments['warnings'][] = ['code' => 'pending_reconciliation', 'count' => 2];
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame([$other], $result['records']['reviews']);
        self::assertSame(1, $result['counts']['requires_review']);
        self::assertSame(1, $result['warnings'][0]['count']);
    }

    public function testOppositeOpeningLegsAreNotSilentlyNettedIntoOneBalance(): void
    {
        [$payments, $journal] = $this->plans();
        $journal['accounting_plan']['entries'][0]['amount_cents'] = 123556;
        $journal['accounting_plan']['entries'][] = array_replace($journal['accounting_plan']['entries'][0],
            ['source_key' => '["B","B","17","4","2"]', 'amount_cents' => 100,
                'debit' => '701', 'credit' => '221', 'target_debit' => '701', 'target_credit' => '221.100']);
        $result = StereoNxBankOpeningPlan::resolve($payments, $journal);
        self::assertSame($payments['records'], $result['records']);
        self::assertContains('bank_opening_unverified', array_column($result['warnings'], 'code'));
    }

    private function plans(): array
    {
        $statement = '["2026","B","17"]';
        $movement = ['source_key' => '["2026","B","17","4"]', 'statement_key' => $statement,
            'amount' => 1234.56, 'date' => '2026-01-01', 'currency' => 'CZK',
            'description' => 'Syntetický záznam bez návodného názvu', 'variable_symbol' => '', 'document_no' => 'B17'];
        $payments = ['counts' => ['bank_transactions' => 1, 'requires_review' => 0], 'warnings' => [], 'errors' => [],
            'records' => ['bank_accounts' => [['source_key' => 'B', 'account_number' => '1000000005',
                'bank_code' => '0100', 'currency' => 'CZK', 'target_analytic_suffix' => '100']],
                'bank_statements' => [['source_key' => $statement, 'account_key' => 'B', 'date' => '2026-01-01', 'currency' => 'CZK']],
                'bank_transactions' => [$movement], 'payments' => [], 'reviews' => []]];
        $journal = ['accounting_plan' => ['entries' => [['source_key' => '["B","B","17","4","1"]',
            'date' => '2026-01-01', 'source_year' => 2026, 'posting_year' => 2026,
            'debit' => '221', 'credit' => '701', 'target_debit' => '221.100', 'target_credit' => '701',
            'amount_cents' => 123456, 'is_opening' => true, 'is_red_storno' => false]]]];
        return [$payments, $journal];
    }
}
