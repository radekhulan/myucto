<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Repository\SecuritiesValuation504ReadRepository;
use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504BasisReader;
use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504Policy;
use MyInvoice\Service\Accounting\Closing\SecuritiesValuation504Evidence;
use PHPUnit\Framework\TestCase;

final class SecuritiesDebt504BasisTest extends TestCase
{
    public function testPurchasedCouponIsSeparatedFromCurrentIncome(): void
    {
        [$snapshot, $request] = self::fixture();
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01');
        self::assertTrue($result['ledger_complete']);
        self::assertFalse($result['complete']);
        self::assertFalse($result['financial_authorization']);
        self::assertSame('10.00', $result['lots'][0]['acquired_interest']);
        self::assertSame('10.00', $result['lots'][0]['current_earned']);
        self::assertSame('20.00', $result['lots'][0]['unpaid_interest_candidate']);
        self::assertNull($result['lots'][0]['verified_unpaid_interest']);
    }

    public function testWholeCouponCannotBePostedAsNewIncome(): void
    {
        [$snapshot, $request] = self::fixture('20.00');
        self::assertContains('debt_current_accrual_mismatch', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testDirtyPriceMustBePartitionedExactly(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['lots'][0]['acquisition'][0]['czk'] = '1001.00';
        self::assertContains('debt_source_used_twice', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testAcquiredInterestCannotBeRelabelledPrincipal(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['lots'][0]['acquisition'][0]['czk'] = '1010.00';
        array_pop($request['lots'][0]['acquisition']);
        self::assertContains('debt_acquired_interest_mismatch', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testPartialCouponPaymentIsNotPrincipalOrNewIncome(): void
    {
        [$snapshot, $request] = self::fixture();
        self::addEvent($snapshot, $request, 3, 'coupon_receipt', '7.00', '2026-01-20', 'credit', '221');
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01');
        self::assertSame('13.00', $result['lots'][0]['unpaid_interest_candidate']);
        self::assertSame('10.00', $result['lots'][0]['current_earned']);
        self::assertSame('0.00', $result['lots'][0]['principal_redemptions_ledger']);
        self::assertContains('debt_payment_lineage_review_required', self::codes($result));
        self::assertNull($result['lots'][0]['verified_unpaid_interest']);
    }

    public function testPrincipalReceiptDoesNotPayCoupon(): void
    {
        [$snapshot, $request] = self::fixture();
        self::addEvent($snapshot, $request, 3, 'principal_redemption', '100.00', '2026-01-20', 'credit', '221');
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01');
        self::assertSame('20.00', $result['lots'][0]['unpaid_interest_candidate']);
        self::assertSame('100.00', $result['lots'][0]['principal_redemptions_ledger']);
    }

    public function testCancelledAndFutureEventsCannotBeReused(): void
    {
        foreach (['cancelled', 'future'] as $mode) {
            [$snapshot, $request] = self::fixture();
            self::addEvent($snapshot, $request, 3, 'coupon_receipt', '7.00', $mode === 'future' ? '2026-01-21' : '2026-01-20', 'credit', '221');
            if ($mode === 'cancelled') $snapshot['cancelled_entry_ids'] = [3, 4];
            self::assertContains('debt_event_not_current', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
        }
    }

    public function testEarlierEarnedInterestRemainsOutsideCurrentIncome(): void
    {
        [$snapshot, $request] = self::fixture();
        $snapshot['opening_entry_ids'] = [100];
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-21');
        self::assertContains('debt_period_invalid', self::codes($result));
        $snapshot['as_of'] = '2027-01-01';
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2027-01-01');
        self::assertSame('10.00', $result['lots'][0]['earlier_earned']);
        self::assertSame('0.00', $result['lots'][0]['current_earned']);
        self::assertSame('20.00', $result['lots'][0]['unpaid_interest_candidate']);
        self::assertSame([100], $result['opening_entry_ids']);
    }

    public function testOffsettingPhysicalPositionsAreNotDropped(): void
    {
        [$snapshot, $request] = self::fixture();
        $positive = self::line(90, 9, '5.00', 'debit', '253');
        $negative = self::line(91, 9, '5.00', 'credit', '253');
        $snapshot['cells'] = [...$snapshot['cells'], ...array_values(SecuritiesValuation504ReadRepository::cells([$positive, $negative]))];
        self::assertContains('debt_source_allocation_incomplete', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testJointTupleAndForeignAmountsAreIndependent(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['lots'][0]['acquisition'][0]['foreign'] = '1.00';
        self::assertContains('debt_source_allocation_invalid', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
        [$snapshot, $request] = self::fixture();
        $snapshot['entries'][2]['lines'][1]['project_id'] = 99;
        $cells = SecuritiesValuation504ReadRepository::cells($snapshot['entries'][2]['lines']);
        $contra = array_values($cells)[array_values($cells)[0]['account_code'] === '644' ? 0 : 1];
        $request['lots'][0]['events'][0]['allocations'][1] = self::allocation($contra, 'counterpart', '-10.00');
        self::assertContains('debt_tuple_changed', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testDuplicateEventClaimFails(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['lots'][0]['events'][] = $request['lots'][0]['events'][0];
        self::assertContains('debt_source_used_twice', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testStaleSnapshotAndSourceHashFail(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['inventory_hash'] = 'old';
        self::assertContains('debt_inventory_changed', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
        $request['inventory_hash'] = $snapshot['hash'];
        $request['lots'][0]['acquisition'][0]['source_hash'] = 'old';
        self::assertContains('debt_source_changed', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testOwnIssuerBondCannotBecomeInvestorLot(): void
    {
        [$snapshot, $request] = self::fixture();
        foreach ($snapshot['cells'] as &$cell) $cell['account_code'] = '255';
        unset($cell);
        self::assertContains('debt_own_issuer_position', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testUnsupportedDayCountIsReviewWithoutZero(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['lots'][0]['coupon_period']['day_count'] = 'ACT/ACT';
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01');
        self::assertContains('debt_day_count_review_required', self::codes($result));
        self::assertSame([], $result['lots']);
    }

    public function testExplicitDayCountAndInclusiveClosingBoundary(): void
    {
        $period = self::coupon();
        self::assertSame('20.00', SecuritiesDebt504Policy::coupon($period, '1000.00', '2026-01-01', '2026-01-21'));
        $period['day_count'] = 'ACT/360';
        self::assertSame('20.28', SecuritiesDebt504Policy::coupon($period, '1000.00', '2026-01-01', '2026-01-21'));
        $period['from'] = '2024-02-28'; $period['to_exclusive'] = '2024-03-02';
        self::assertSame('3.04', SecuritiesDebt504Policy::coupon($period, '1000.00', '2024-02-28', '2024-03-02'));
        self::assertSame('2024-03-01', SecuritiesDebt504Policy::dayAfter('2024-02-29'));
    }

    public function testCumulativeRoundingDoesNotDuplicateAcrossPeriods(): void
    {
        $period = self::coupon(); $period['annual_rate_fraction'] = '0.05';
        $a = SecuritiesDebt504Policy::coupon($period, '1000', '2026-01-01', '2026-01-11');
        $b = SecuritiesDebt504Policy::coupon($period, '1000', '2026-01-11', '2026-01-21');
        self::assertSame(SecuritiesDebt504Policy::coupon($period, '1000', '2026-01-01', '2026-01-21'), bcadd($a, $b, 2));
    }

    public function testExplicitHtmAmortisationPremiumAndDiscount(): void
    {
        $schedule = ['method' => 'actual_days_linear', 'policy_reference' => 'Synthetic policy', 'rounding' => 'half_away_from_zero_2_cumulative', 'acquired_on' => '2026-01-01', 'maturity_on' => '2026-01-21'];
        self::assertSame('50.00', SecuritiesDebt504Policy::amortisation($schedule, '900', '1000', '2026-01-01', '2026-01-11'));
        self::assertSame('-50.00', SecuritiesDebt504Policy::amortisation($schedule, '1100', '1000', '2026-01-01', '2026-01-11'));
        self::assertSame('100.00', SecuritiesDebt504Policy::amortisation($schedule, '900', '1000', '2026-01-01', '2026-01-21'));
        self::assertSame('544', SecuritiesDebt504Policy::interestAccount('-50.00'));
        self::assertSame('644', SecuritiesDebt504Policy::interestAccount('50.00'));
    }

    public function testDebtPreviewCannotUnlockValuationEvidence(): void
    {
        [$snapshot, $request] = self::fixture();
        $result = SecuritiesValuation504Evidence::evaluate($snapshot, ['valuations' => [], 'debt_basis' => $request], [1, 2, 3]);
        self::assertFalse($result['complete']);
        self::assertSame('10.00', $result['debt_basis']['lots'][0]['current_earned']);
        self::assertContains('debt_instrument_event_membership_review_required', self::codes($result));
    }

    public function testDiscountTradingMovementUsesInterestAlsoWhenNegative(): void
    {
        self::assertSame('-50.00', SecuritiesDebt504Policy::discountTradingChange('900', '950')['amount']);
        self::assertSame('644', SecuritiesDebt504Policy::discountTradingChange('900', '950')['account']);
        self::assertSame('644', SecuritiesDebt504Policy::discountTradingChange('1000', '950')['account']);
    }

    public function testMalformedAllocationIsExplicitReview(): void
    {
        [$snapshot, $request] = self::fixture();
        $request['lots'][0]['acquisition'][0] = 'invalid';
        self::assertContains('debt_source_allocation_invalid', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public function testHtmAndIneligibleMicroCannotUseFairValue(): void
    {
        $target = new \ReflectionMethod(SecuritiesValuation504Evidence::class, 'target');
        foreach ([['held_to_maturity', true], ['trading', false]] as [$classification, $fair]) {
            try {
                $target->invoke(null, ['kind' => 'debt', 'classification' => $classification, 'method' => 'fair_value_market'], ['quantity' => '1'], [], $fair, 100000, 0, '1', '2026-12-31', 'CZK');
                self::fail('Ineligible fair value accepted.');
            } catch (\DomainException $e) {
                self::assertSame('securities_method_ineligible', $e->getMessage());
            }
        }
    }

    public function testForeignAcquisitionUsesSeparatePhysicalAmounts(): void
    {
        [$snapshot, $request] = self::fixture();
        $acquisition = self::line(10, 1, '25250.00', 'debit', '253', '2026-01-11');
        $acquisition['currency_code'] = 'EUR'; $acquisition['amount_foreign'] = '1010.00';
        $snapshot['cells'] = array_values(SecuritiesValuation504ReadRepository::cells([$acquisition]));
        $root = $snapshot['cells'][0];
        foreach ($request['lots'][0]['acquisition'] as &$part) {
            $part['cell_key'] = $root['cell_key']; $part['source_hash'] = $root['source_hash'];
            $part['foreign'] = $part['czk']; $part['czk'] = bcmul($part['czk'], '25', 2);
        }
        unset($part);
        foreach ($snapshot['entries'][2]['lines'] as &$line) { $line['currency_code'] = 'EUR'; $line['amount_foreign'] = '10.00'; $line['amount'] = '251.00'; }
        unset($line);
        $parts = [];
        foreach (SecuritiesValuation504ReadRepository::cells($snapshot['entries'][2]['lines']) as $cell) {
            $asset = $cell['account_code'] === '253';
            if ($asset) $snapshot['cells'][] = $cell;
            $parts[] = ['cell_key' => $cell['cell_key'], 'source_hash' => $cell['source_hash'], 'component' => $asset ? 'asset' : 'counterpart', 'czk' => $cell['book_czk'], 'foreign' => $cell['book_foreign']];
        }
        $request['lots'][0]['events'][0]['allocations'] = $parts;
        $result = SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01');
        self::assertTrue($result['ledger_complete']);
        self::assertSame('25000.00', $result['lots'][0]['clean_czk']);
        self::assertSame('1000.00', $result['lots'][0]['clean_foreign']);
        self::assertSame('20.00', $result['lots'][0]['unpaid_interest_candidate']);
        $request['lots'][0]['acquisition'][0]['foreign'] = '999.00';
        self::assertContains('debt_source_allocation_incomplete', self::codes(SecuritiesDebt504BasisReader::inspect($snapshot, $request, '2026-01-01')));
    }

    public static function fixture(string $income = '10.00'): array
    {
        $acquisition = self::line(10, 1, '1010.00', 'debit', '253', '2026-01-11');
        $cells = SecuritiesValuation504ReadRepository::cells([$acquisition]); $cell = reset($cells);
        $snapshot = ['hash' => 'current-native-snapshot', 'as_of' => '2026-01-20', 'findings' => [], 'cells' => array_values($cells), 'entries' => [], 'origins' => [], 'opening_entry_ids' => [], 'cancelled_entry_ids' => [], 'profile_snapshot' => ['periods' => [['starts_on' => '2026-01-01', 'ends_on' => '2026-01-20']], 'history' => []], 'available_accounts' => []];
        $request = ['inventory_hash' => $snapshot['hash'], 'lots' => [['lot_id' => 'synthetic-lot', 'instrument_identity' => 'synthetic-bond', 'classification' => 'trading', 'debt_type' => 'coupon', 'acquired_on' => '2026-01-11', 'maturity_on' => '2027-01-01', 'nominal' => '1000.00', 'coupon_period' => self::coupon(), 'acquisition' => [self::allocation($cell, 'clean', '1000.00'), self::allocation($cell, 'acquired_interest', '10.00')], 'events' => []]]];
        self::addEvent($snapshot, $request, 2, 'accrual', $income, '2026-01-20', 'debit', '644');
        return [$snapshot, $request];
    }

    public static function addEvent(array &$snapshot, array &$request, int $id, string $kind, string $amount, string $date, string $side, string $counter): void
    {
        $rows = [self::line($id * 10, $id, $amount, $side, '253', $date), self::line($id * 10 + 1, $id, $amount, $side === 'debit' ? 'credit' : 'debit', $counter, $date)];
        $cells = array_values(SecuritiesValuation504ReadRepository::cells($rows));
        $asset = array_values(array_filter($cells, static fn ($cell) => $cell['account_code'] === '253'))[0];
        $contra = array_values(array_filter($cells, static fn ($cell) => $cell['account_code'] !== '253'))[0];
        $snapshot['entries'][$id] = ['id' => $id, 'entry_date' => $date, 'source_type' => 'manual', 'source_id' => null, 'posted_at' => $date, 'reversed_by' => null, 'document_no' => 'SYNTHETIC', 'lines' => $rows];
        $snapshot['cells'][] = $asset;
        $signed = $side === 'debit' ? $amount : '-' . $amount;
        $request['lots'][0]['events'][] = ['entry_id' => $id, 'kind' => $kind, 'allocations' => [self::allocation($asset, 'asset', $signed), self::allocation($contra, 'counterpart', bcsub('0', $signed, 2))]];
    }

    public static function line(int $id, int $entry, string $amount, string $side, string $code, string $date = '2026-01-20'): array
    {
        return ['id' => $id, 'entry_id' => $entry, 'line_no' => $id, 'entry_version' => 1, 'entry_date' => $date, 'account_id' => (int) $code, 'account_code' => $code, 'account_type' => $code === '644' ? 'revenue' : 'asset', 'currency_code' => 'CZK', 'dimensions' => [1 => 11, 2 => 22, 3 => 33], 'dimension_splits' => [], 'cost_center' => 'SYN', 'project_id' => 7, 'amount' => $amount, 'amount_foreign' => null, 'side' => $side, 'is_red_storno' => false];
    }

    private static function allocation(array $cell, string $component, string $amount): array
    {
        return ['cell_key' => $cell['cell_key'], 'source_hash' => $cell['source_hash'], 'component' => $component, 'czk' => $amount, 'foreign' => '0.00'];
    }

    private static function coupon(): array
    {
        return ['from' => '2026-01-01', 'to_exclusive' => '2026-01-21', 'annual_rate_fraction' => '0.365', 'day_count' => 'ACT/365F', 'rounding' => 'half_away_from_zero_2_cumulative', 'terms_reference' => 'Synthetic terms'];
    }

    private static function codes(array $result): array
    {
        return array_column($result['findings'], 'code');
    }
}
