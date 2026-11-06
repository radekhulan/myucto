<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Repository\SecuritiesValuation504ReadRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;

final class SecuritiesDebt504BasisReader
{
    public function __construct(private readonly SecuritiesValuation504ReadRepository $inventory) {}

    public function read(int $supplierId, array $period, array $request, bool $currentRead = false): array
    {
        $snapshot = $this->inventory->snapshot($supplierId, $period, $currentRead);
        $snapshot = SecuritiesValuation504ReadRepository::select($snapshot, $request['inventory_account_ids'] ?? []);
        return self::inspect($snapshot, $request, $period['starts_on']);
    }

    public static function inspect(array $snapshot, array $request, string $periodStart): array
    {
        $result = ['complete' => false, 'financial_authorization' => false, 'ledger_complete' => false,
            'inventory_hash' => $snapshot['hash'], 'as_of' => $snapshot['as_of'], 'findings' => $snapshot['findings'],
            'opening_entry_ids' => $snapshot['opening_entry_ids'], 'cancelled_entry_ids' => $snapshot['cancelled_entry_ids'],
            'lots' => [], 'claims' => [], 'legal_reference' => SecuritiesDebt504Policy::LEGAL_REFERENCE];
        try {
            SecuritiesDebt504Policy::date($periodStart);
            SecuritiesDebt504Policy::date($snapshot['as_of']);
            self::need($periodStart <= $snapshot['as_of'], 'debt_period_invalid');
            self::need(($request['inventory_hash'] ?? null) === $snapshot['hash'], 'debt_inventory_changed');
            self::need(is_array($request['lots'] ?? null) && array_is_list($request['lots']), 'debt_lots_required');
            $cells = array_column($snapshot['cells'], null, 'cell_key');
            $entryCells = [];
            foreach ($snapshot['entries'] as $entryId => $entry) {
                if ($entry['posted_at'] === null || $entry['entry_date'] > $snapshot['as_of'] || in_array((int) $entryId, $snapshot['cancelled_entry_ids'], true)
                    || in_array($entry['source_type'], ['closing', 'opening'], true)) continue;
                $entryCells[(int) $entryId] = SecuritiesValuation504ReadRepository::cells($entry['lines']);
            }
            $used = $identities = $claimedEventEntries = [];
            foreach ($request['lots'] as $lot) {
                self::need(is_array($lot) && SecuritiesDebt504Policy::text($lot['lot_id'] ?? null) && SecuritiesDebt504Policy::text($lot['instrument_identity'] ?? null), 'debt_identity_required');
                self::need(!isset($identities[$lot['lot_id']]), 'debt_duplicate_lot');
                $identities[$lot['lot_id']] = true;
                self::need(in_array($lot['debt_type'] ?? null, ['coupon', 'discount'], true), 'debt_type_required');
                self::need(in_array($lot['classification'] ?? null, ['trading', 'held_to_maturity'], true), 'debt_classification_review_required');
                SecuritiesDebt504Policy::date($lot['acquired_on'] ?? null);
                SecuritiesDebt504Policy::date($lot['maturity_on'] ?? null);
                self::need($lot['acquired_on'] <= $snapshot['as_of'] && $lot['acquired_on'] < $lot['maturity_on'], 'debt_acquisition_date_invalid');
                SecuritiesDebt504Policy::decimal($lot['nominal'] ?? null);
                self::need(bccomp($lot['nominal'], '0', 12) > 0, 'debt_nominal_required');
                self::need(is_array($lot['acquisition'] ?? null) && array_is_list($lot['acquisition']) && $lot['acquisition'] !== [], 'debt_acquisition_required');
                $tuple = null;
                $clean = $acquired = [0, 0];
                foreach ($lot['acquisition'] as $part) {
                    $cell = self::claim($part, $cells, $used);
                    self::need(!str_starts_with($cell['account_code'], '255'), 'debt_own_issuer_position');
                    self::need($cell['entry_date'] === $lot['acquired_on'] && $part['czk'] >= 0 && $part['foreign'] >= 0, 'debt_acquisition_date_invalid');
                    self::need(in_array($part['component'] ?? null, ['clean', 'acquired_interest'], true), 'debt_partition_required');
                    $tuple ??= self::tuple($cell);
                    self::need($tuple === self::tuple($cell), 'debt_tuple_changed');
                    $amounts = [self::cents($part['czk']), self::cents($part['foreign'])];
                    if ($part['component'] === 'clean') { $clean[0] += $amounts[0]; $clean[1] += $amounts[1]; }
                    else { $acquired[0] += $amounts[0]; $acquired[1] += $amounts[1]; }
                    $root = $cell;
                }
                $currencyIndex = $root['currency_code'] === 'CZK' ? 0 : 1;
                $income = $earlierIncome = $receipts = $redemptions = $amortised = [0, 0];
                self::need(is_array($lot['events'] ?? null) && array_is_list($lot['events']), 'debt_events_required');
                $eventResults = [];
                foreach ($lot['events'] as $event) {
                    self::need(is_array($event) && is_int($event['entry_id'] ?? null) && isset($entryCells[$event['entry_id']]), 'debt_event_not_current');
                    $entry = $snapshot['entries'][$event['entry_id']];
                    $claimedEventEntries[$event['entry_id']] = true;
                    $nativeLines = array_column($entry['lines'], null, 'id');
                    self::need($entry['entry_date'] >= $lot['acquired_on'], 'debt_event_date_invalid');
                    self::need(in_array($event['kind'] ?? null, ['accrual', 'coupon_receipt', 'principal_redemption', 'amortisation'], true), 'debt_event_kind_invalid');
                    self::need(is_array($event['allocations'] ?? null) && array_is_list($event['allocations']) && count($event['allocations']) >= 2, 'debt_event_allocation_required');
                    $asset = $counterpart = [0, 0];
                    foreach ($event['allocations'] as $part) {
                        $cell = self::claim($part, $entryCells[$event['entry_id']], $used);
                        $amount = [self::cents($part['czk']), self::cents($part['foreign'])];
                        self::need(self::axes($cell) === self::axes($root), 'debt_tuple_changed');
                        if (($part['component'] ?? null) === 'asset') {
                            self::need(self::tuple($cell) === $tuple, 'debt_tuple_changed');
                            $asset[0] += $amount[0]; $asset[1] += $amount[1];
                        } else {
                            self::need(($part['component'] ?? null) === 'counterpart', 'debt_event_allocation_required');
                            $code = substr($cell['account_code'], 0, 3);
                            if (in_array($event['kind'], ['accrual', 'amortisation'], true)) {
                                $route = SecuritiesDebt504Policy::interestAccount(Fx504Builder::decimal(-$amount[0]));
                                self::need($code === $route && ($nativeLines[$cell['line_id']]['account_type'] ?? null) === ($route === '644' ? 'revenue' : 'expense'), 'debt_interest_route_invalid');
                            } else self::need(in_array($code, ['211', '221'], true) && ($nativeLines[$cell['line_id']]['account_type'] ?? null) === 'asset', 'debt_payment_counterpart_required');
                            $counterpart[0] += $amount[0]; $counterpart[1] += $amount[1];
                        }
                    }
                    self::need($asset !== [0, 0] && $asset[0] === -$counterpart[0], 'debt_event_unbalanced');
                    self::need($asset[1] === -$counterpart[1], 'debt_foreign_allocation_unbalanced');
                    if ($event['kind'] === 'accrual') {
                        self::need($asset[$currencyIndex] > 0, 'debt_interest_route_invalid');
                        if ($entry['entry_date'] < $periodStart) { $earlierIncome[0] += $asset[0]; $earlierIncome[1] += $asset[1]; }
                        else { $income[0] += $asset[0]; $income[1] += $asset[1]; }
                    } elseif ($event['kind'] === 'amortisation') { $amortised[0] += $asset[0]; $amortised[1] += $asset[1]; }
                    else {
                        self::need($asset[$currencyIndex] < 0, 'debt_payment_direction_invalid');
                        if ($event['kind'] === 'coupon_receipt') { $receipts[0] -= $asset[0]; $receipts[1] -= $asset[1]; }
                        else { $redemptions[0] -= $asset[0]; $redemptions[1] -= $asset[1]; }
                        $result['findings'][] = ['code' => 'debt_payment_lineage_review_required', 'lot_id' => $lot['lot_id'], 'entry_id' => $event['entry_id']];
                    }
                    $eventResults[] = ['kind' => $event['kind'], 'entry_id' => $event['entry_id'], 'source_type' => $entry['source_type'],
                        'origins' => array_intersect_key($snapshot['origins'], array_flip(array_column($entry['lines'], 'id'))),
                        'asset_czk' => Fx504Builder::decimal($asset[0]), 'asset_foreign' => Fx504Builder::decimal($asset[1])];
                }
                $expectedAcquired = $expectedCurrent = $expectedEarlier = '0.00';
                if ($lot['debt_type'] === 'coupon') {
                    self::need(is_array($lot['coupon_period'] ?? null), 'debt_coupon_period_required');
                    $coupon = $lot['coupon_period'];
                    SecuritiesDebt504Policy::date($coupon['to_exclusive'] ?? null);
                    $end = min(SecuritiesDebt504Policy::dayAfter($snapshot['as_of']), $coupon['to_exclusive']);
                    $boundary = min(max($periodStart, $lot['acquired_on']), $end);
                    $expectedAcquired = SecuritiesDebt504Policy::coupon($coupon, $lot['nominal'], $coupon['from'] ?? '', $lot['acquired_on']);
                    $expectedEarlier = SecuritiesDebt504Policy::coupon($coupon, $lot['nominal'], $lot['acquired_on'], $boundary);
                    $expectedCurrent = SecuritiesDebt504Policy::coupon($coupon, $lot['nominal'], $boundary, $end);
                }
                self::need($acquired[$currencyIndex] === self::cents($expectedAcquired), 'debt_acquired_interest_mismatch');
                self::need($earlierIncome[$currencyIndex] === self::cents($expectedEarlier), 'debt_earlier_accrual_mismatch');
                self::need($income[$currencyIndex] === self::cents($expectedCurrent), 'debt_current_accrual_mismatch');
                $expectedAmortisation = null;
                if ($lot['classification'] === 'held_to_maturity') {
                    self::need(is_array($lot['amortisation'] ?? null), 'debt_amortisation_review_required');
                    $schedule = $lot['amortisation'] + ['acquired_on' => $lot['acquired_on'], 'maturity_on' => $lot['maturity_on']];
                    self::need($schedule['acquired_on'] === $lot['acquired_on'] && $schedule['maturity_on'] === $lot['maturity_on'], 'debt_amortisation_period_invalid');
                    $expectedAmortisation = SecuritiesDebt504Policy::amortisation($schedule, Fx504Builder::decimal($clean[$currencyIndex]), $lot['nominal'], $lot['acquired_on'], min(SecuritiesDebt504Policy::dayAfter($snapshot['as_of']), $lot['maturity_on']));
                    self::need($amortised[$currencyIndex] === self::cents($expectedAmortisation), 'debt_amortisation_posting_mismatch');
                } else self::need($amortised === [0, 0], 'debt_trading_amortisation_review_required');
                $outstanding = $acquired[$currencyIndex] + $earlierIncome[$currencyIndex] + $income[$currencyIndex] - $receipts[$currencyIndex];
                self::need($outstanding >= 0, 'debt_coupon_overpaid');
                $result['lots'][] = ['lot_id' => $lot['lot_id'], 'instrument_identity' => $lot['instrument_identity'], 'currency_code' => $root['currency_code'],
                    'tuple' => json_decode($tuple, true, 512, JSON_THROW_ON_ERROR), 'clean_czk' => Fx504Builder::decimal($clean[0]), 'clean_foreign' => Fx504Builder::decimal($clean[1]),
                    'acquired_interest' => $expectedAcquired, 'earlier_earned' => $expectedEarlier, 'current_earned' => $expectedCurrent,
                    'coupon_receipts_ledger' => Fx504Builder::decimal($receipts[$currencyIndex]), 'principal_redemptions_ledger' => Fx504Builder::decimal($redemptions[$currencyIndex]),
                    'unpaid_interest_candidate' => Fx504Builder::decimal($outstanding), 'verified_unpaid_interest' => null,
                    'amortisation' => $expectedAmortisation, 'events' => $eventResults];
            }
            foreach ($cells as $key => $cell) self::need(($used[$key] ?? [0, 0]) === [$cell['czk_cents'], $cell['foreign_cents']], 'debt_source_allocation_incomplete');
            foreach ($entryCells as $entryId => $group) foreach ($group as $key => $cell) if (isset($used[$key]) || isset($claimedEventEntries[$entryId])) self::need(($used[$key] ?? [0, 0]) === [$cell['czk_cents'], $cell['foreign_cents']], 'debt_event_allocation_incomplete');
            $result['ledger_complete'] = $result['findings'] === [];
            $result['claims'] = $used;
            $result['findings'][] = ['code' => 'debt_instrument_event_membership_review_required'];
        } catch (\DomainException $e) {
            $result['findings'][] = ['code' => $e->getMessage()];
        }
        return $result;
    }

    private static function claim(mixed $part, array $cells, array &$used): array
    {
        self::need(is_array($part), 'debt_source_allocation_invalid');
        self::need(is_string($part['cell_key'] ?? null) && isset($cells[$part['cell_key']]), 'debt_source_not_current');
        $cell = $cells[$part['cell_key']];
        self::need(($part['source_hash'] ?? null) === $cell['source_hash'], 'debt_source_changed');
        $amounts = [self::cents($part['czk'] ?? null), self::cents($part['foreign'] ?? null)];
        self::need($amounts !== [0, 0], 'debt_empty_allocation');
        $used[$cell['cell_key']] ??= [0, 0];
        foreach ([$cell['czk_cents'], $cell['foreign_cents']] as $index => $whole) {
            self::need(($amounts[$index] === 0 || ($amounts[$index] < 0) === ($whole < 0)) && abs($amounts[$index]) <= abs($whole), 'debt_source_allocation_invalid');
            $used[$cell['cell_key']][$index] += $amounts[$index];
            self::need(abs($used[$cell['cell_key']][$index]) <= abs($whole), 'debt_source_used_twice');
        }
        return $cell;
    }

    private static function axes(array $cell): array
    {
        return [$cell['currency_code'], $cell['dimensions'], $cell['cost_center'], $cell['project_id']];
    }

    private static function tuple(array $cell): string
    {
        return json_encode([$cell['account_id'], ...self::axes($cell)], JSON_THROW_ON_ERROR);
    }

    private static function cents(mixed $value): int
    {
        self::need(is_string($value) && preg_match('/^-?\d{1,12}\.\d{2}$/D', $value) === 1, 'debt_amount_invalid');
        return JournalLineAmount::cents($value);
    }

    private static function need(bool $condition, string $code): void
    {
        if (!$condition) throw new \DomainException($code);
    }
}
