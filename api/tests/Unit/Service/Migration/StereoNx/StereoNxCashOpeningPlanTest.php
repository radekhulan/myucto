<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxCashOpeningPlan;
use PHPUnit\Framework\TestCase;

final class StereoNxCashOpeningPlanTest extends TestCase
{
    public function testProvenOpeningPreservesRawMovementAndRemovesOnlyItsReview(): void
    {
        [$payments, $journal] = $this->plans();
        $result = StereoNxCashOpeningPlan::resolve($payments, $journal);
        self::assertSame([], $result['records']['reviews']);
        self::assertSame($payments['records']['cash_transactions'], $result['records']['cash_transactions']);
        self::assertSame([['movement' => $payments['records']['cash_transactions'][0], 'opening_cents' => 45678,
            'entry_keys' => ['["P","C","27","0","1"]'], 'account_code' => '211.100', 'date' => '2026-01-01']],
            $result['records']['cash_openings']);
        self::assertSame(1, $result['counts']['cash_openings']);
        self::assertSame(1, $result['counts']['cash_transactions']);
        self::assertSame(0, $result['counts']['requires_review']);
        self::assertSame(['cash_opening_balance'], array_column($result['warnings'], 'code'));
        self::assertSame($result, StereoNxCashOpeningPlan::resolve($result, $journal));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unprovenCases')]
    public function testUnprovenCashOpeningKeepsMovementAndReview(string $case): void
    {
        [$payments, $journal] = $this->plans();
        $entry = &$journal['accounting_plan']['entries'][0];
        $key = $payments['records']['cash_transactions'][0]['source_key'];
        switch ($case) {
            case 'red': $entry['is_red_storno'] = true; break;
            case 'amount': $entry['amount_cents']++; break;
            case 'date': $entry['date'] = '2026-01-02'; break;
            case 'missing_year': unset($entry['posting_year']); break;
            case 'no_cash': $entry['debit'] = '221'; break;
            case 'wrong_target': $entry['target_debit'] = '221.100'; break;
            case 'two_cash_sides': $entry['credit'] = '211'; break;
            case 'currency': $payments['records']['cash_transactions'][0]['currency'] = 'EUR'; break;
            case 'zero': $payments['records']['cash_transactions'][0]['amount'] = 0; break;
            case 'duplicate_movement': $payments['records']['cash_transactions'][] = $payments['records']['cash_transactions'][0]; break;
            case 'ordinary_entry': $journal['accounting_plan']['entries'][] = array_replace($entry, ['source_key' => '["P","C","27","0","2"]', 'is_opening' => false]); break;
            case 'duplicate_entry': $journal['accounting_plan']['entries'][] = $entry; break;
            case 'payment': $payments['records']['payments'][] = ['movement_type' => 'cash', 'movement_key' => $key]; break;
            case 'vat': $payments['records']['cash_transactions'][0]['review_codes'] = ['movement_vat_unverified']; break;
            case 'other_review': $payments['records']['reviews'][0]['review_codes'] = ['payment_agenda_not_imported']; break;
            case 'missing_raw_proof': $payments['non_vat_cash_candidates'] = []; break;
            case 'opposite_legs':
                $entry['amount_cents'] += 100;
                $journal['accounting_plan']['entries'][] = array_replace($entry, ['source_key' => '["P","C","27","0","2"]',
                    'debit' => '701', 'credit' => '211', 'target_debit' => '701', 'target_credit' => '211.100', 'amount_cents' => 100]);
                break;
        }
        unset($entry);
        $result = StereoNxCashOpeningPlan::resolve($payments, $journal);
        self::assertSame($payments['records'], $result['records']);
        self::assertContains('cash_opening_unverified', array_column($result['warnings'], 'code'));
        self::assertSame($result, StereoNxCashOpeningPlan::resolve($result, $journal));
    }

    public static function unprovenCases(): iterable
    {
        foreach (['red', 'amount', 'date', 'missing_year', 'no_cash', 'wrong_target', 'two_cash_sides', 'currency',
            'zero', 'duplicate_movement', 'ordinary_entry', 'duplicate_entry', 'payment', 'vat', 'other_review',
            'missing_raw_proof', 'opposite_legs'] as $case) yield $case => [$case];
    }

    public function testOpeningRawCandidateWithNullFieldsDoesNotRequireGeneralNonVatProof(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['cash_opening_candidates'] = $payments['non_vat_cash_candidates'];
        $payments['non_vat_cash_candidates'] = [];
        $result = StereoNxCashOpeningPlan::resolve($payments, $journal);
        self::assertSame(1, $result['counts']['cash_openings']);
        self::assertSame($payments['records']['cash_transactions'], $result['records']['cash_transactions']);
    }

    public function testOpeningDescriptionDoesNotProveOrdinaryCashPosting(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['records']['cash_transactions'][0]['description'] = 'Počáteční zůstatek';
        $journal['accounting_plan']['entries'][0]['is_opening'] = false;
        self::assertSame($payments, StereoNxCashOpeningPlan::resolve($payments, $journal));
    }

    public function testNegativeCashOpeningKeepsDirectionAndFiscalYear(): void
    {
        [$payments, $journal] = $this->plans();
        $payments['records']['cash_transactions'][0]['amount'] = -456.78;
        $payments['records']['cash_transactions'][0]['source_key'] = '["2025","C","27"]';
        $payments['records']['reviews'][0]['source_key'] = '["2025","C","27"]';
        $payments['non_vat_cash_candidates'] = ['["2025","C","27"]' => true];
        $journal['accounting_plan']['entries'][0] = array_replace($journal['accounting_plan']['entries'][0],
            ['posting_year' => 2025, 'debit' => '701', 'credit' => '211', 'target_debit' => '701', 'target_credit' => '211100']);
        $result = StereoNxCashOpeningPlan::resolve($payments, $journal);
        self::assertSame(-45678, $result['records']['cash_openings'][0]['opening_cents']);
        self::assertSame('211.100', $result['records']['cash_openings'][0]['account_code']);
        self::assertSame([], $result['records']['reviews']);
    }

    public function testOnlyOpeningReviewIsRemovedAndOtherCashRemainsUntouched(): void
    {
        [$payments, $journal] = $this->plans();
        $other = ['kind' => 'cash', 'source_key' => '["2026","C","28"]', 'review_codes' => ['pending_reconciliation']];
        $payments['records']['reviews'][] = $other;
        $payments['records']['cash_transactions'][] = array_replace($payments['records']['cash_transactions'][0],
            ['source_key' => $other['source_key']]);
        $payments['counts']['requires_review'] = 2;
        $payments['counts']['cash_transactions'] = 2;
        $result = StereoNxCashOpeningPlan::resolve($payments, $journal);
        self::assertSame([$other], $result['records']['reviews']);
        self::assertSame(1, $result['counts']['requires_review']);
        self::assertSame(2, $result['counts']['cash_transactions']);
        self::assertSame(1, $result['warnings'][0]['count']);
        self::assertSame($payments['records']['cash_transactions'], $result['records']['cash_transactions']);
    }

    private function plans(): array
    {
        $movement = ['source_key' => '["2026","C","27"]', 'amount' => 456.78, 'date' => '2026-01-01',
            'currency' => 'CZK', 'description' => 'Syntetický záznam', 'variable_symbol' => '', 'document_no' => 'C27',
            'requires_draft' => true, 'review_codes' => ['pending_reconciliation']];
        $payments = ['counts' => ['cash_transactions' => 1, 'requires_review' => 1],
            'warnings' => [['level' => 'warning', 'code' => 'pending_reconciliation', 'count' => 1]],
            'non_vat_cash_candidates' => [$movement['source_key'] => true],
            'records' => ['cash_transactions' => [$movement], 'payments' => [],
                'reviews' => [['kind' => 'cash', 'source_key' => $movement['source_key'], 'review_codes' => ['pending_reconciliation']]]]];
        $journal = ['accounting_plan' => ['entries' => [['source_key' => '["P","C","27","0","1"]',
            'date' => '2026-01-01', 'source_year' => 2026, 'posting_year' => 2026,
            'debit' => '211', 'credit' => '701', 'target_debit' => '211.100', 'target_credit' => '701',
            'amount_cents' => 45678, 'is_opening' => true, 'is_red_storno' => false]]]];
        return [$payments, $journal];
    }
}
