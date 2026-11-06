<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Repository\SecuritiesValuation504ReadRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;

final class SecuritiesValuation504Evidence
{
    public static function preview(array $snapshot, array $accountDecisions, ?array $caseDecisions, ?array $evidence): array
    {
        try {
            $selected = SecuritiesValuation504ReadRepository::select($snapshot['securities_snapshot'], $evidence['inventory_account_ids'] ?? []);
        } catch (\DomainException $e) {
            $selected = SecuritiesValuation504ReadRepository::select($snapshot['securities_snapshot'], []);
            $selected['findings'][] = ['code' => $e->getMessage()];
        }
        $assessment = self::evaluate($selected, $evidence, $snapshot['required_types']);
        $selectedCells = array_column($selected['cells'], null, 'cell_key');
        $caseCells = Fx504CaseProjection::cells($snapshot['case_snapshot']['rows']);
        $inventoryOrigins = array_replace($selected['origins'], $snapshot['case_snapshot']['origins'], $selected['debt_lifecycle']['origins'] ?? []);
        $snapshot['case_snapshot']['origins'] = array_replace($snapshot['case_snapshot']['origins'], $selected['debt_lifecycle']['origins'] ?? []);
        $sourceRows = array_column($selected['all_source_rows'], null, 'id');
        foreach ($assessment['cells'] as &$cell) $cell['case_origins'] = $inventoryOrigins[$cell['line_id']] ?? [];
        unset($cell);
        $members = $assessment['monetary_members'];
        $monetary = $generated = $monetaryKeys = [];
        try {
            foreach ($members as $member) {
                $key = $member['source_cell_hash'];
                $cell = $selectedCells[$key];
                $origins = $inventoryOrigins[$cell['line_id']] ?? [];
                $origin = $member['case_origin'];
                $whole = $member['book_czk'] === $cell['book_czk'] && $member['book_foreign'] === $cell['book_foreign'];
                $nativeDebt = self::nativeDebtMember($selected, $member, $cell);
                self::need($origins === [] ? $origin['kind'] === 'declared_title' : (($whole || $nativeDebt) && in_array($origin, $origins, true)), 'securities_monetary_allocation_unproven');
                self::need($origins !== [] || in_array($sourceRows[$cell['line_id']]['source_type'] ?? null, ['manual', 'opening'], true), 'securities_monetary_allocation_unproven');
                try { Fx504CaseProjection::assertOriginForCell($cell, $selectedCells, $inventoryOrigins, $origin); }
                catch (ClosingException) { throw new \DomainException('securities_monetary_allocation_unproven'); }
                if ($cell['currency_code'] === 'CZK') continue;
                self::need(isset($caseCells[$key]), 'securities_monetary_allocation_unproven');
                $monetary[$key] ??= [0, 0];
                $monetary[$key][0] += self::money($member['book_czk']);
                $monetary[$key][1] += self::money($member['book_foreign']);
                $originKey=hash('sha256',json_encode($origin,JSON_THROW_ON_ERROR));
                $generated[$originKey] ??= ['origin'=>$origin,'reason'=>$member['contract_reference'],'contributions'=>[]];
                $generated[$originKey]['contributions'][]=['cell_key'=>$key,'czk_signed'=>$member['book_czk'],'foreign_signed'=>$member['book_foreign']];
                $monetaryKeys[$key] = true;
            }
        } catch (\DomainException $e) {
            $assessment['complete'] = false;
            $assessment['findings'][] = ['code' => $e->getMessage()];
            $monetary = $generated = $monetaryKeys = [];
        }
        $rows = [];
        foreach ($caseCells as $key => $cell) {
            if (isset($selectedCells[$key]) && !isset($monetary[$key])) continue;
            [$czk, $fx] = $monetary[$key] ?? [$cell['czk_cents'], $cell['foreign_cents']];
            $rows[] = ['id' => $cell['line_id'], 'entry_id' => $cell['entry_id'], 'line_no' => $cell['line_no'], 'entry_version' => $cell['entry_version'],
                'account_id' => $cell['account_id'], 'account_code' => $cell['account_code'], 'currency_code' => $cell['currency_code'],
                'dimensions' => $cell['dimensions'], 'cost_center' => $cell['cost_center'], 'project_id' => $cell['project_id'],
                'side' => 'debit', 'amount' => Fx504Builder::decimal($czk), 'amount_foreign' => Fx504Builder::decimal($fx), 'is_red_storno' => false];
        }
        $filter = static function (array $decisions) use ($selectedCells): array {
            $out = [];
            foreach ($decisions as $decision) {
                $decision = Fx504CaseProjection::normalizeDecision($decision);
                $decision['contributions'] = array_values(array_filter($decision['contributions'] ?? [], static fn (array $part): bool => !isset($selectedCells[$part['cell_key'] ?? ''])));
                if ($decision['contributions'] !== []) $out[] = $decision;
            }
            return $out;
        };
        $snapshot['case_snapshot']['rows'] = $rows;
        $snapshot['case_snapshot']['prior_decisions'] = $filter($snapshot['case_snapshot']['prior_decisions']);
        $decisions = [...$filter($caseDecisions ?? $snapshot['case_snapshot']['prior_decisions']), ...array_values($generated)];
        $codes = array_column($rows, 'account_code');
        $accountDecisions = array_intersect_key($accountDecisions, array_flip($codes));
        $preview = Fx504CaseProjection::preview($snapshot, $accountDecisions, $decisions, array_keys($monetaryKeys));
        $preview['securities'] = $assessment;
        $preview['complete'] = $assessment['complete'] && $preview['complete'];
        return $preview;
    }

    public static function evaluate(array $snapshot, ?array $evidence, array $requiredTypes): array
    {
        $result = ['complete' => false, 'findings' => $snapshot['findings'], 'cells' => $snapshot['cells'],
            'inventory_hash' => $snapshot['hash'], 'valuations' => [], 'evidence' => $evidence,
            'opening_entry_ids' => $snapshot['opening_entry_ids'], 'as_of' => $snapshot['as_of'],
            'profile_periods' => $snapshot['profile_snapshot']['periods'], 'profile_history' => $snapshot['profile_snapshot']['history'],
            'posted_entries' => self::postedEntries($snapshot), 'available_accounts' => $snapshot['available_accounts'], 'monetary_members' => [],
            'legal_basis' => ['valuation_rules' => 'cz_504_2024', 'category_rules' => 'act_316_2025_II_2', 'period_start_from' => '2024-01-01', 'period_end_from' => '2024-12-31', 'period_end_to' => '2026-12-31']];
        if (isset($evidence['debt_basis'])) {
            $periods = array_values(array_filter($snapshot['profile_snapshot']['periods'], static fn (array $period): bool => $period['ends_on'] === $snapshot['as_of']));
            if (count($periods) !== 1 || !is_array($evidence['debt_basis'])) $result['findings'][] = ['code' => 'debt_period_invalid'];
            else {
                $result['debt_basis'] = SecuritiesDebt504BasisReader::inspect($snapshot, $evidence['debt_basis'], $periods[0]['starts_on']);
                array_push($result['findings'], ...$result['debt_basis']['findings']);
            }
        }
        $nativeDebt = $snapshot['debt_lifecycle'] ?? ['lots'=>[], 'findings'=>[]];
        array_push($result['findings'], ...$nativeDebt['findings']);
        $result['debt_lifecycle'] = $nativeDebt;
        $result['debt_treatments'] = [];
        if ($evidence === null && $nativeDebt['lots'] !== []) $evidence = ['valuations'=>[]];
        if ($snapshot['cells'] === [] && ($evidence === null || (($evidence['valuations'] ?? []) === [] && ($evidence['non_security_members'] ?? []) === []))) {
            $result['complete'] = $result['findings'] === [];
            return $result;
        }
        if ($evidence === null) {
            $result['findings'][] = ['code' => 'securities_evidence_required'];
            return $result;
        }
        try {
            $valuations = $evidence['valuations'] ?? null;
            self::need(is_array($valuations) && array_is_list($valuations), 'securities_evidence_required');
            $profile = $valuations === [] ? null : self::profile($snapshot, $evidence['profile'] ?? null);
            $fair = $profile !== null && self::fairValueEligible($snapshot, $profile);
            $cells = array_column($snapshot['cells'], null, 'cell_key');
            $used = $proofUsed = $claims = [];
            $identities = [];
            $participationMethods = [];
            foreach ($valuations as $index => $valuation) {
                self::need(is_array($valuation), 'securities_evidence_invalid');
                $instrument = $valuation['instrument'] ?? null;
                $facts = $valuation['evidence'] ?? null;
                self::need(is_array($instrument) && in_array($instrument['kind'] ?? null, ['equity', 'debt'], true)
                    && in_array($instrument['classification'] ?? null, ['trading', 'available_for_sale', 'held_to_maturity', 'participation'], true)
                    && self::text($instrument['identity'] ?? null) && self::text($instrument['reference'] ?? null)
                    && is_array($facts) && self::text($facts['reference'] ?? null)
                    && ($valuation['as_of'] ?? null) === $snapshot['as_of'] && self::date($facts['valuation_date'] ?? null)
                    && $facts['valuation_date'] <= $snapshot['as_of'], 'securities_instrument_required');
                self::need($instrument['kind'] === 'equity' ? $instrument['classification'] !== 'held_to_maturity' : $instrument['classification'] !== 'participation', 'securities_instrument_required');
                self::need(self::text($instrument['classification_reference'] ?? null) && self::date($instrument['designated_on'] ?? null)
                    && $instrument['designated_on'] <= $snapshot['as_of'], 'securities_classification_required');
                if ($instrument['classification'] === 'participation') self::need(in_array($instrument['influence'] ?? null, ['control', 'significant'], true)
                    && self::text($instrument['influence_reference'] ?? null), 'securities_method_ineligible');
                $members = $valuation['members'] ?? null;
                self::need(is_array($members) && array_is_list($members) && $members !== [], 'securities_inventory_incomplete');
                $base = $foreign = $acquisitionCzk = $acquisitionForeign = 0;
                $tuple = $root = null;
                foreach ($members as $member) {
                    self::need(is_array($member) && is_string($member['source_cell_hash'] ?? null) && isset($cells[$member['source_cell_hash']]), 'securities_inventory_changed');
                    $cell = $cells[$member['source_cell_hash']];
                    $key = $cell['cell_key'];
                    $currentTuple = self::tuple($cell);
                    if ($tuple === null) { $tuple = $currentTuple; $root = $cell; }
                    self::need($tuple === $currentTuple, 'securities_tuple_changed');
                    $czk = self::money($member['book_czk'] ?? null);
                    $fx = self::money($member['book_foreign'] ?? null);
                    self::portion($czk, $cell['czk_cents']);
                    self::portion($fx, $cell['foreign_cents']);
                    $used[$key] ??= [0, 0];
                    $used[$key][0] += $czk;
                    $used[$key][1] += $fx;
                    $base += $czk;
                    $foreign += $fx;
                    if ($cell['entry_date'] === ($facts['acquisition_date'] ?? null)) {
                        $acquisitionCzk += $czk;
                        $acquisitionForeign += $fx;
                    }
                }
                $identity = $instrument['identity'] . '|' . $tuple;
                self::need(!isset($identities[$identity]), 'securities_duplicate_instrument');
                $identities[$identity] = true;
                $rate = $snapshot['rates'][$root['currency_code']]['rate'] ?? null;
                self::need(is_string($rate) && bccomp($rate, '0', 6) > 0, 'securities_rate_required');
                if (($instrument['method'] ?? null) === 'cost_equity') {
                    $acquired = self::decimal($facts['acquired_quantity'] ?? null);
                    $unitCost = self::decimal($facts['unit_cost_foreign'] ?? null);
                    $acquiredValue = JournalLineAmount::cents(bcmul($acquired, $unitCost, 16));
                    self::need(self::money($facts['acquisition_cost_czk'] ?? null) === $acquisitionCzk
                        && $acquiredValue === ($root['currency_code'] === 'CZK' ? $acquisitionCzk : $acquisitionForeign), 'securities_cost_basis_mismatch');
                }
                [$target, $components] = self::target($instrument, $facts, $profile, $fair, $base, $foreign, $rate, $snapshot['as_of'], $root['currency_code']);
                self::need(self::money($valuation['target_czk'] ?? null) === $target, 'securities_target_mismatch');
                if ($instrument['classification'] === 'participation') $participationMethods[$instrument['method']] = true;
                $posted = self::posted($valuation['posted_proof'] ?? [], $snapshot, $root, $components, $requiredTypes, $claims, $proofUsed);
                self::need($posted === $target - $base, 'securities_posting_required');
                $result['valuations'][] = ['identity' => $instrument['identity'], 'method' => $instrument['method'],
                    'book_czk' => Fx504Builder::decimal($base), 'target_czk' => Fx504Builder::decimal($target),
                    'change_czk' => Fx504Builder::decimal($target - $base), 'components' => array_map(static fn (array $component): array => ['kind' => $component['kind'], 'route' => $component['route'], 'amount_czk' => Fx504Builder::decimal($component['amount'])], $components)];
            }
            foreach ($nativeDebt['lots'] as $lot) {
                $fairDebt = $lot['terms']['classification'] === 'trading' && self::fairValueEligible($snapshot, $evidence['profile'] ?? []);
                $result['debt_treatments'][] = ['lot_id'=>$lot['id'],'fair_value'=>$fairDebt];
                if ($fairDebt) self::need($lot['valued_on'] === $snapshot['as_of'] || $lot['principal_balance'] === '0.00', 'debt_valuation_required');
                foreach ($lot['lines'] as $lineId => $part) {
                    $matches = array_values(array_filter($cells, static fn ($cell) => $cell['line_id'] === (int) $lineId));
                    self::need(count($matches) === 1, 'debt_inventory_changed'); $cell = $matches[0]; $key = $cell['cell_key'];
                    $czk = self::money(bcadd($part['principal_czk'], $part['interest_czk'], 2));
                    $native = self::money(bcadd($part['principal_native'], $part['interest_native'], 2));
                    self::need($czk === $cell['czk_cents'] && ($lot['currency'] === 'CZK' || $native === $cell['foreign_cents']), 'debt_inventory_changed');
                    $used[$key] ??= [0, 0]; $used[$key][0] += $cell['czk_cents']; $used[$key][1] += $cell['foreign_cents'];
                    $monetaryCzk = $fairDebt ? $part['interest_czk'] : Fx504Builder::decimal($cell['czk_cents']);
                    $monetaryForeign = $fairDebt ? $part['interest_native'] : Fx504Builder::decimal($cell['foreign_cents']);
                    if ($lot['currency'] !== 'CZK' && ($monetaryCzk !== '0.00' || $monetaryForeign !== '0.00')) $result['monetary_members'][] = [
                        'source_cell_hash'=>$key, 'book_czk'=>$monetaryCzk, 'book_foreign'=>$monetaryForeign,
                        'native_debt_lot_id'=>$lot['id'], 'native_debt_fair'=>$fairDebt, 'nature'=>'monetary',
                        'contract_reference'=>$lot['terms']['contract_reference'], 'case_origin'=>$lot['origin']];
                }
            }
            $monetaryMembers = $evidence['non_security_members'] ?? [];
            self::need(is_array($monetaryMembers) && array_is_list($monetaryMembers), 'securities_monetary_evidence_invalid');
            foreach ($monetaryMembers as $member) {
                self::need(is_array($member) && is_string($member['source_cell_hash'] ?? null) && isset($cells[$member['source_cell_hash']])
                    && ($member['nature'] ?? null) === 'monetary' && self::text($member['contract_reference'] ?? null)
                    && is_array($member['case_origin'] ?? null), 'securities_monetary_evidence_invalid');
                $key = $member['source_cell_hash'];
                $cell = $cells[$key];
                self::need(!str_starts_with($cell['account_code'], '255'), 'securities_own_instrument_unsupported');
                try { $origin = Fx504CaseProjection::origin($member['case_origin']); }
                catch (ClosingException) { throw new \DomainException('securities_monetary_evidence_invalid'); }
                $czk = self::money($member['book_czk'] ?? null);
                $fx = self::money($member['book_foreign'] ?? null);
                self::portion($czk, $cell['czk_cents']);
                self::portion($fx, $cell['foreign_cents']);
                self::need($czk !== 0 || $fx !== 0, 'securities_monetary_evidence_invalid');
                $used[$key] ??= [0, 0];
                $used[$key][0] += $czk;
                $used[$key][1] += $fx;
                $result['monetary_members'][] = array_replace($member, ['case_origin' => $origin]);
            }
            self::need(!isset($participationMethods['equity_method']) || count($participationMethods) === 1, 'securities_equity_policy_inconsistent');
            foreach ($cells as $key => $cell) {
                $total = [($used[$key][0] ?? 0) + ($proofUsed[$key][0] ?? 0), ($used[$key][1] ?? 0) + ($proofUsed[$key][1] ?? 0)];
                self::need($total === [$cell['czk_cents'], $cell['foreign_cents']], 'securities_inventory_incomplete');
            }
            foreach ($claims as $entryId => $lineClaims) {
                foreach ($snapshot['entries'][$entryId]['lines'] as $line) {
                    $signed = self::signed($line);
                    self::need(($lineClaims[(int) $line['id']] ?? 0) === $signed, 'securities_posting_allocation_incomplete');
                }
            }
            $result['complete'] = $result['findings'] === [];
        } catch (\DomainException $e) {
            $result['findings'][] = ['code' => $e->getMessage(), 'valuation_index' => isset($index) ? $index : null];
        }
        return $result;
    }

    private static function activeAt(array $entry, array $snapshot): bool
    {
        if ($entry['posted_at'] === null) return false;
        if ($entry['reversed_by'] === null) return true;
        $reverse = $snapshot['entries'][(int) $entry['reversed_by']] ?? null;
        if (!$reverse || $reverse['posted_at'] === null || $reverse['entry_date'] <= $snapshot['as_of'] || count($reverse['lines']) !== count($entry['lines'])) return false;
        foreach ($entry['lines'] as $index => $line) if (!\MyInvoice\Repository\Fx504CaseReadRepository::same($reverse['lines'][$index], JournalLineAmount::reversal($line))) return false;
        return true;
    }

    private static function postedEntries(array $snapshot): array
    {
        $entries = [];
        foreach ($snapshot['entries'] as $entry) {
            if ($entry['source_type'] !== 'manual' || !self::activeAt($entry, $snapshot) || $entry['entry_date'] !== $snapshot['as_of']) continue;
            $lines = [];
            foreach ($entry['lines'] as $line) $lines[] = ['id' => (int) $line['id'], 'account_code' => $line['account_code'],
                'signed_czk' => Fx504Builder::decimal(self::signed($line)), 'currency_code' => $line['currency_code'] ?? 'CZK',
                'dimensions' => $line['dimensions'], 'cost_center' => $line['cost_center'], 'project_id' => isset($line['project_id']) ? (int) $line['project_id'] : null];
            $entries[] = ['id' => (int) $entry['id'], 'document_no' => $entry['document_no'], 'entry_date' => $entry['entry_date'], 'lines' => $lines];
        }
        return $entries;
    }

    private static function nativeDebtMember(array $snapshot,array $member,array $cell): bool
    {
        if (!isset($member['native_debt_lot_id'])) return false;
        foreach ($snapshot['debt_lifecycle']['lots'] ?? [] as $lot) {
            if ($lot['id'] !== $member['native_debt_lot_id'] || !isset($lot['lines'][$cell['line_id']])) continue;
            $part = $lot['lines'][$cell['line_id']];
            $czk = !empty($member['native_debt_fair']) ? $part['interest_czk'] : bcadd($part['principal_czk'],$part['interest_czk'],2);
            $native = !empty($member['native_debt_fair']) ? $part['interest_native'] : bcadd($part['principal_native'],$part['interest_native'],2);
            return $member['book_czk'] === $czk && $member['book_foreign'] === $native
                && $member['case_origin'] === $lot['origin'];
        }
        return false;
    }

    public static function fairValueEligible(array $snapshot, array $profile): bool
    {
        $profile = self::profile($snapshot, $profile);
        return $profile['scope'] === 'full' && ($profile['category'] !== 'micro' || $profile['micro_exception'] !== 'none');
    }

    private static function profile(array $snapshot, mixed $profile): array
    {
        $exceptions = ['none', 'securities_dealer', 'foreign_securities_dealer_branch', 'payment_institution', 'foreign_payment_institution_branch',
            'electronic_money_institution', 'foreign_electronic_money_institution_branch', 'investment_company', 'investment_fund', 'pension_fund'];
        self::need(is_array($profile) && in_array($profile['scope'] ?? null, ['full', 'simplified'], true)
            && in_array($profile['category'] ?? null, ['micro', 'small', 'medium', 'large'], true)
            && in_array($profile['micro_exception'] ?? null, $exceptions, true)
            && ($profile['as_of'] ?? null) === $snapshot['as_of'] && self::text($profile['reference'] ?? null)
            && self::text($profile['scope_reference'] ?? null) && self::text($profile['inception_reference'] ?? null), 'securities_profile_required');
        self::need($snapshot['as_of'] >= '2024-01-01' && $snapshot['as_of'] <= '2026-12-31', 'securities_legal_interval_unsupported');
        if ($profile['micro_exception'] !== 'none') {
            self::need(self::text($profile['registration_reference'] ?? null) && self::date($profile['registration_from'] ?? null)
                && $profile['registration_from'] <= $snapshot['as_of']
                && (($profile['registration_to'] ?? null) === null || (self::date($profile['registration_to']) && $profile['registration_to'] >= $snapshot['as_of'])), 'securities_profile_required');
        }
        $facts = $profile['category_facts'] ?? null;
        $stored = $snapshot['profile_snapshot'];
        self::need(is_array($facts) && array_is_list($facts) && count($facts) === count($stored['periods']) && $facts !== []
            && ($profile['first_period_start'] ?? null) === $stored['periods'][0]['starts_on'], 'securities_category_history_required');
        $history = array_column($stored['history'], null, 'period_id');
        $raws = [];
        $previousEnd = null;
        foreach ($stored['periods'] as $index => $period) {
            $fact = $facts[$index];
            self::need(is_array($fact) && ($fact['period_id'] ?? null) === (int) $period['id']
                && ($fact['starts_on'] ?? null) === $period['starts_on'] && ($fact['ends_on'] ?? null) === $period['ends_on']
                && self::text($fact['statement_reference'] ?? null) && self::text($fact['employees_reference'] ?? null), 'securities_category_history_required');
            self::need($previousEnd === null || $period['starts_on'] === (new \DateTimeImmutable($previousEnd))->modify('+1 day')->format('Y-m-d'), 'securities_category_history_required');
            $previousEnd = $period['ends_on'];
            $assets = self::money($fact['assets_net'] ?? null);
            $turnover = self::money($fact['net_turnover'] ?? null);
            $employees = self::decimal($fact['average_employees'] ?? null);
            self::need($assets >= 0, 'securities_category_facts_invalid');
            $year = (int) substr($period['ends_on'], 0, 4);
            $threshold = $stored['thresholds'][$year] ?? null;
            self::need(is_array($threshold) && (int) $threshold['year'] === $year && is_array($threshold['limits']), 'securities_category_limits_unavailable');
            self::need($period['starts_on'] >= '2024-01-01' && $period['ends_on'] >= '2024-12-31' && $period['ends_on'] <= '2026-12-31', 'securities_category_legal_interval_unsupported');
            $verifiedLimits = \MyInvoice\Service\Tax\TaxConstants::forYear(2026)['entity_category_thresholds'];
            self::need($threshold['limits'] == $verifiedLimits, 'securities_category_limits_unverified');
            $raw = EntityCategoryService::classifyCriteria(['assets_net' => Fx504Builder::decimal($assets), 'net_turnover' => Fx504Builder::decimal($turnover), 'employees' => $employees], $threshold['limits'], \MyInvoice\Service\Accounting\Reports\EntityCategoryPeriodPolicy::commencedMonths($period['starts_on'], $period['ends_on']));
            $frozen = $history[$period['id']] ?? null;
            if ($frozen !== null) self::need(JournalLineAmount::cents($frozen['assets_net']) === $assets && JournalLineAmount::cents($frozen['net_turnover']) === $turnover
                && bccomp((string) $frozen['avg_employees'], $employees, 6) === 0 && $frozen['raw_category'] === $raw, 'securities_category_history_changed');
            $raws[] = $raw;
        }
        $current = array_pop($raws);
        self::need(count(array_unique($raws)) <= 1, 'securities_category_transition_review_required');
        self::need(EntityCategoryService::categoryFromHistory($current, $raws) === $profile['category'], 'securities_category_mismatch');
        return $profile;
    }

    public static function assertMarketBasis(array $facts,string $asOf,string $currency): void
    {
        self::need(self::text($facts['market'] ?? null) && self::text($facts['market_reference'] ?? null)
            && ($facts['market_type'] ?? null) === 'regulated' && ($facts['quote_currency'] ?? null) === $currency
            && self::text($facts['market_calendar_reference'] ?? null) && ($facts['market_calendar_as_of'] ?? null) === $asOf
            && self::date($facts['last_trading_date'] ?? null) && $facts['last_trading_date'] === $facts['valuation_date'], 'securities_market_basis_required');
        self::need($facts['valuation_date'] <= $asOf, 'securities_market_basis_required');
    }

    private static function target(array $instrument, array $facts, array $profile, bool $fair, int $base, int $foreign, string $rate, string $asOf, string $currency): array
    {
        $method = $instrument['method'] ?? null;
        $quantity = self::decimal($facts['quantity'] ?? null);
        self::need(bccomp($quantity, '0', 6) >= 0 && $base >= 0 && $foreign >= 0, 'securities_short_position_unsupported');
        $classification = $instrument['classification'];
        $kind = $instrument['kind'];
        if ($method === 'cost_equity') {
            self::need($kind === 'equity' && ($classification === 'participation' || !$fair), 'securities_method_ineligible');
            $cost = self::decimal($facts['unit_cost_foreign'] ?? null);
            self::need(self::date($facts['acquisition_date'] ?? null) && $facts['acquisition_date'] <= $asOf && self::money($facts['acquisition_cost_czk'] ?? null) >= 0, 'securities_cost_basis_required');
            $nominal = JournalLineAmount::cents(bcmul($quantity, $cost, 16));
            self::need($nominal === ($currency === 'CZK' ? $base : $foreign), 'securities_nominal_mismatch');
            self::need(self::text($facts['recoverability_reference'] ?? null) && ($facts['recoverability_date'] ?? null) === $asOf, 'securities_impairment_assessment_required');
            $recoverable = JournalLineAmount::cents(bcmul($quantity, self::decimal($facts['recoverable_unit_value_foreign'] ?? null), 16));
            self::need($recoverable >= $nominal, 'securities_impairment_assessment_required');
            $target = JournalLineAmount::cents(bcmul(bcdiv((string) $nominal, '100', 2), $rate, 16));
            return [$target, [['kind' => 'fx', 'route' => 'equity', 'amount' => $target - $base]]];
        }
        if ($method === 'equity_method') {
            self::need($fair && $kind === 'equity' && $classification === 'participation'
                && in_array($instrument['influence'] ?? null, ['control', 'significant'], true)
                && self::text($instrument['influence_reference'] ?? null), 'securities_method_ineligible');
            $share = self::decimal($facts['participation_fraction'] ?? null);
            $equity = self::decimal($facts['issuer_equity_foreign'] ?? null, true);
            self::need(bccomp($share, '0', 6) > 0 && bccomp($share, '1', 6) <= 0 && self::text($facts['issuer_statement_reference'] ?? null), 'securities_equity_basis_required');
            $value = bcmul($equity, $share, 16);
            $target = bccomp($value, '0', 16) < 0 ? 0 : JournalLineAmount::cents(bcmul($value, $rate, 16));
            return [$target, [['kind' => 'equity', 'route' => 'equity', 'amount' => $target - $base]]];
        }
        if ($method === 'fair_value_market') {
            self::need($fair && (($kind === 'equity' && in_array($classification, ['trading', 'available_for_sale'], true)) || ($kind === 'debt' && $classification === 'trading')), 'securities_method_ineligible');
            self::assertMarketBasis($facts, $asOf, $currency);
            $price = self::decimal($facts['unit_price_foreign'] ?? null);
            $market = JournalLineAmount::cents(bcmul(bcmul($quantity, $price, 16), $rate, 16));
            if ($kind === 'debt') self::need(false, ($instrument['debt_type'] ?? null) === 'coupon' ? 'securities_coupon_basis_unsupported' : 'securities_discounted_trading_unsupported');
            if ($classification === 'available_for_sale') self::need($market >= self::money($facts['acquisition_cost_czk'] ?? null), 'securities_impairment_assessment_required');
            return [$market, [['kind' => 'price_fx', 'route' => $classification === 'trading' ? 'other' : 'equity', 'amount' => $market - $base]]];
        }
        self::need(false, 'securities_method_unsupported');
    }

    private static function posted(mixed $proof, array $snapshot, array $root, array $components, array $requiredTypes, array &$claims, array &$proofUsed): int
    {
        self::need(is_array($proof) && array_is_list($proof), 'securities_posting_required');
        $expected = [];
        foreach ($components as $component) if ($component['amount'] !== 0) $expected[$component['kind']] = $component;
        $seen = [];
        $total = 0;
        foreach ($proof as $allocation) {
            self::need(is_array($allocation) && is_int($allocation['entry_id'] ?? null) && is_int($allocation['asset_line_id'] ?? null)
                && is_int($allocation['counterpart_line_id'] ?? null) && isset($expected[$allocation['component'] ?? '']), 'securities_posting_invalid');
            $entry = $snapshot['entries'][$allocation['entry_id']] ?? null;
            self::need($entry !== null && $entry['source_type'] === 'manual' && self::activeAt($entry, $snapshot) && $entry['entry_date'] === $snapshot['as_of'], 'securities_posting_current_required');
            $lines = array_column($entry['lines'], null, 'id');
            $asset = $lines[$allocation['asset_line_id']] ?? null;
            $counterpart = $lines[$allocation['counterpart_line_id']] ?? null;
            self::need($asset !== null && $counterpart !== null && $asset['id'] !== $counterpart['id'], 'securities_posting_invalid');
            $assetCells = Fx504Builder::sourceCells($asset);
            self::need(count($assetCells) === 1 && self::tuple($assetCells[0]) === self::tuple($root), 'securities_tuple_changed');
            $amount = self::money($allocation['amount_czk'] ?? null);
            self::need($amount !== 0, 'securities_posting_invalid');
            self::portion($amount, self::signed($asset));
            self::portion(-$amount, self::signed($counterpart));
            $component = $expected[$allocation['component']];
            self::portion($amount, $component['amount']);
            $route = $component['route'];
            $code = $counterpart['account_code'];
            $allowed = match ($route) { 'equity' => str_starts_with($code, '921'), 'interest' => str_starts_with($code, '644'), default => str_starts_with($code, $amount > 0 ? '649' : '549') };
            self::need($allowed && $counterpart['account_type'] === ($route === 'equity' ? 'equity' : ($route === 'interest' || $amount > 0 ? 'revenue' : 'expense')), 'securities_posting_route_invalid');
            self::need(($counterpart['cost_center'] ?? null) === $root['cost_center'] && (isset($counterpart['project_id']) ? (int) $counterpart['project_id'] : null) === $root['project_id']
                && ($counterpart['dimension_splits'] ?? []) === [] && array_diff_assoc($root['dimensions'], $counterpart['dimensions']) === [], 'securities_tuple_changed');
            if ($route !== 'equity') self::need(count($requiredTypes) === 3 && array_diff($requiredTypes, array_keys($counterpart['dimensions'])) === [], 'securities_profit_dimensions_required');
            else self::need($counterpart['dimensions'] === $root['dimensions'], 'securities_tuple_changed');
            self::need($assetCells[0]['foreign_cents'] === 0 && JournalLineAmount::cents($counterpart['amount_foreign'] ?? '0.00') === 0, 'securities_posting_nominal_changed');
            $claims[$allocation['entry_id']] ??= [];
            foreach ([$asset['id'] => $amount, $counterpart['id'] => -$amount] as $id => $value) $claims[$allocation['entry_id']][(int) $id] = ($claims[$allocation['entry_id']][(int) $id] ?? 0) + $value;
            $cell = SecuritiesValuation504ReadRepository::cells([$asset]);
            $key = array_key_first($cell);
            $proofUsed[$key] ??= [0, 0];
            $proofUsed[$key][0] += $amount;
            $seen[$allocation['component']] = ($seen[$allocation['component']] ?? 0) + $amount;
            $total += $amount;
        }
        foreach ($expected as $kind => $component) self::need(($seen[$kind] ?? 0) === $component['amount'], 'securities_posting_required');
        return $total;
    }

    private static function tuple(array $cell): string
    {
        return json_encode([$cell['account_id'], $cell['currency_code'], $cell['dimensions'], $cell['cost_center'], $cell['project_id']], JSON_THROW_ON_ERROR);
    }

    private static function signed(array $line): int
    {
        return ($line['side'] === 'credit' ? -1 : 1) * JournalLineAmount::signedCents($line);
    }

    private static function money(mixed $value): int
    {
        self::need(is_string($value) && preg_match('/^-?\d{1,12}\.\d{2}$/D', $value) === 1, 'securities_amount_invalid');
        return JournalLineAmount::cents($value);
    }

    private static function decimal(mixed $value, bool $signed = false): string
    {
        self::need(is_string($value) && preg_match($signed ? '/^-?\d{1,12}(?:\.\d{1,6})?$/D' : '/^\d{1,12}(?:\.\d{1,6})?$/D', $value) === 1, 'securities_amount_invalid');
        return $value;
    }

    private static function portion(int $value, int $whole): void
    {
        self::need(($value === 0 || ($value < 0) === ($whole < 0)) && abs($value) <= abs($whole), 'securities_allocation_invalid');
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 4000;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function need(bool $condition, string $finding): void
    {
        if (!$condition) throw new \DomainException($finding);
    }
}
