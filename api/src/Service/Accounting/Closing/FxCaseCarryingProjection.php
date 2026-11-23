<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class FxCaseCarryingProjection
{
    public static function findingMessage(string $finding): string
    {
        return match ($finding) {
            'case_document_lineage_unproven' => 'Vazba dobropisu na původní doklad nemá doloženou stejnou protistranu a časové pořadí.',
            'case_source_allocation_ambiguous' => 'Doklad má více saldokontních řádků pro stejný účet a měnu. Jejich přiřazení k účetnímu případu není jednoznačně doloženo.',
            'historical_source_tuple_missing' => 'Historický podklad přecenění neobsahuje důkaz původního střediska, projektu nebo dimenzí dokladu.',
            'historical_source_tuple_changed', 'historical_case_changed' => 'Původní účetní zápis nebo jeho dimenze nesouhlasí s uloženým podkladem přecenění.',
            'historical_rate_unproven', 'historical_rate_changed' => 'Kurzy v historickém rozpisu dokladů nesouhlasí s uloženým kurzovým podkladem.',
            'historical_amount_changed', 'historical_net_mismatch', 'historical_saldo_lines_mismatch' => 'Částky historického přecenění nesouhlasí s doloženými zůstatky dokladů a účetním zápisem.',
            'historical_settlement_unproven' => 'Historické úhrady a vypořádání dokladu nelze jednoznačně doložit.',
            'historical_opening_anchor_mismatch', 'historical_opening_currency_mismatch', 'historical_case_currency_mismatch', 'historical_manual_currency_unproven' => 'Počáteční zůstatek účtu nesouhlasí s uzavřeným deníkem a doloženým rozpisem účetních případů.',
            'historical_reversal_missing', 'historical_reversal_mismatch' => 'Storno historického přecenění není úplným zrcadlem původního účetního zápisu.',
            'historical_fx_entry_unattributed' => 'Účet obsahuje historické přecenění bez jednoznačné vazby na doklady a uložený podklad uzávěrky.',
            'historical_carry_chain_missing', 'historical_carry_chain_unproven' => 'Chybí úplná doložená návaznost historického přecenění, uzavření knih a otevření dalšího období.',
            'historical_case_disappeared', 'current_case_disappeared', 'historical_membership_unproven', 'historical_detail_incomplete' => 'Rozpis účetních případů není úplný vůči původním dokladům a dosud zaúčtovanému přecenění.',
            'historical_journal_unproven', 'historical_fx_foreign_changed', 'historical_fx_counterpart_unproven' => 'Účetní zápis historického přecenění nemá doložené období, měnu nebo správnou protistranu.',
            default => 'Historický podklad přecenění chybí nebo má neplatný formát. Bez úplného podkladu nelze určit přecenění jednotlivých účetních případů.',
        };
    }

    public static function key(array $item): string
    {
        return $item['doc_type'] . ':' . $item['doc_id'] . ':' . $item['account_code'] . ':' . $item['currency_code'];
    }

    public function project(array $snapshot, array $period): array
    {
        try {
            return $this->complete($snapshot, $period);
        } catch (\DomainException $e) {
            return ['complete' => false, 'findings' => [$e->getMessage()], 'carrying' => [], 'proof' => []];
        } catch (\InvalidArgumentException|\ValueError) {
            return ['complete' => false, 'findings' => ['historical_value_malformed'], 'carrying' => [], 'proof' => []];
        }
    }

    private function complete(array $snapshot, array $period): array
    {
        $carry = [];
        $proof = [];
        $claimed = [];
        $openingProjections = [];
        $entries = $snapshot['entries'];
        $relevant = [];
        foreach ([$snapshot['items'], ...array_column($snapshot['history'], 'items')] as $items) {
            foreach ($items as $projection) $relevant[$projection['item']['account_id'] . '|' . $projection['item']['currency_code']] = true;
        }
        foreach ($snapshot['history'] as $history) {
            $prior = $history['period'];
            $pid = (int) $prior['id'];
            $steps = $history['steps'];
            $payload = $steps['fx_revaluation']['payload'];
            $this->require(is_array($payload) && isset($payload['detail'], $payload['saldo_lines'], $payload['entry_ids']) && is_array($payload['detail']) && array_is_list($payload['detail']) && is_array($payload['saldo_lines']) && array_is_list($payload['saldo_lines']) && is_array($payload['entry_ids']), 'historical_detail_missing');
            if ($payload['detail'] === [] && $payload['saldo_lines'] === [] && empty($payload['entry_ids']['saldo']) && $this->clean($carry) === []) continue;
            $native = $this->index($history['items']);
            $seen = [];
            $increments = [];
            $net = [];
            $sourceLines = [];
            $rates = [];
            foreach ($payload['rate_info'] ?? [] as $rate) {
                $this->require(is_array($rate) && isset($rate['currency'], $rate['rate'], $rate['rate_date']) && is_string($rate['currency']) && is_numeric($rate['rate']) && is_string($rate['rate_date']) && !isset($rates[$rate['currency']]) && bccomp((string) $rate['rate'], '0', 6) > 0 && $rate['rate_date'] <= $prior['ends_on'], 'historical_rate_unproven');
                $rates[$rate['currency']] = $rate['rate'];
            }
            foreach ($payload['detail'] as $detail) {
                $this->require(is_array($detail) && isset($detail['doc_type'], $detail['doc_id'], $detail['account_code'], $detail['currency_code'], $detail['remaining_foreign'], $detail['booked_czk'], $detail['rate_cnb'], $detail['new_czk'], $detail['diff']), 'historical_detail_malformed');
                $key = self::key($detail);
                $this->require(!isset($seen[$key]) && isset($native[$key]), 'historical_membership_unproven');
                $seen[$key] = true;
                $item = $native[$key]['item'];
                $this->require(isset($rates[$detail['currency_code']]) && bccomp((string) $detail['rate_cnb'], (string) $rates[$detail['currency_code']], 6) === 0, 'historical_rate_changed');
                $evidence = $native[$key]['evidence'];
                $this->require($evidence['complete'], 'historical_settlement_unproven');
                $remaining = JournalLineAmount::cents($evidence['remaining_foreign']);
                $booked = JournalLineAmount::cents($evidence['booked_czk']);
                $new = JournalLineAmount::cents(bcmul((string) $evidence['remaining_foreign'], (string) $detail['rate_cnb'], 16));
                $this->require($remaining === JournalLineAmount::cents($detail['remaining_foreign']) && $booked === JournalLineAmount::cents($detail['booked_czk']) && $new === JournalLineAmount::cents($detail['new_czk']) && $new - $booked === JournalLineAmount::cents($detail['diff']), 'historical_amount_changed');
                $desired = ($detail['doc_type'] === 'invoice' ? 1 : -1) * ($new - $booked);
                $increment = $desired - ($carry[$key] ?? 0);
                if (isset($payload['case_projection_version'])) {
                    $this->require($payload['case_projection_version'] === 1 && isset($detail['incremental_signed'], $detail['carrying_before'], $detail['source_line_id']), 'historical_case_version_unproven');
                    $this->require(JournalLineAmount::cents($detail['incremental_signed']) === $increment && JournalLineAmount::cents($detail['carrying_before']) === ($carry[$key] ?? 0) && (int) $detail['source_line_id'] === (int) $item['source_line_id'], 'historical_case_changed');
                    $this->require(isset($detail['source_proof_hash'], $detail['source_tuple']) && is_string($detail['source_proof_hash']) && is_array($detail['source_tuple']) && hash_equals($item['source_proof_hash'], $detail['source_proof_hash']) && FxCaseTuple::hash($item['source_tuple']) === FxCaseTuple::hash($detail['source_tuple']), 'historical_source_tuple_changed');
                }
                if (!isset($payload['case_projection_version'])) $this->require(FxCaseTuple::hash($item['source_tuple']) === FxCaseTuple::hash([]), 'historical_source_tuple_missing');
                $increments[$key] = $increment;
                $accountKey = $item['account_id'] . '|' . $item['currency_code'];
                $net[$accountKey] = ($net[$accountKey] ?? 0) + $increment;
                $sourceLines[] = (int) $item['source_line_id'];
            }
            foreach ($native as $key => $projection) {
                if (!$projection['evidence']['complete']) $this->require(isset($seen[$key]), 'historical_settlement_unproven');
                if (JournalLineAmount::cents($projection['evidence']['remaining_foreign'] ?? 0) !== 0 || ($carry[$key] ?? 0) !== 0) $this->require(isset($seen[$key]), 'historical_detail_incomplete');
            }
            foreach ($carry as $key => $amount) if ($amount !== 0) $this->require(isset($seen[$key]), 'historical_case_disappeared');
            $entryId = (int) ($payload['entry_ids']['saldo'] ?? 0);
            $entry = $entryId ? ($entries[$entryId] ?? null) : null;
            $this->require(!$entryId || ($entry !== null && $entry['source_type'] === 'fx_revaluation' && (int) $entry['source_id'] === ClosingSourceId::fxSaldo($pid) && (int) $entry['period_id'] === $pid && $entry['entry_date'] === $prior['ends_on'] && $entry['posted_at'] !== null && $entry['reversed_by'] === null), 'historical_journal_unproven');
            $actual = $this->currencyTotals($entry['lines'] ?? []);
            $this->require($this->clean($net) === $this->clean($actual), 'historical_net_mismatch');
            $this->validateStoredLines($payload['saldo_lines'], $entry['lines'] ?? [], $rates);
            if ($entryId) $claimed[$entryId] = true;
            $open = $steps['open_next'] ?? null;
            $close = $steps['close_books'] ?? null;
            $this->require(($close['status'] ?? null) === 'done' && ($open['status'] ?? null) === 'done', 'historical_carry_chain_missing');
            $opening = FxOpeningCarryAnchor::fromSnapshot($snapshot, $prior, $open);
            $closingId = (int) ($close['payload']['entry_id'] ?? 0);
            $closing = $entries[$closingId] ?? null;
            $this->require($closing !== null && $closing['source_type'] === 'closing' && (int) $closing['source_id'] === $pid
                && (int) $closing['period_id'] === $pid && $closing['posted_at'] !== null && $closing['reversed_by'] === null
                && $closing['entry_date'] === $prior['ends_on'], 'historical_carry_chain_unproven');
            $caseBalances = [];
            $caseForeign = [];
            foreach ($native as $key => $projection) {
                $item = $projection['item'];
                $id = $item['account_id'] . '|' . $item['currency_code'] . '|' . FxCaseTuple::hash($item['source_tuple']);
                $base = JournalLineAmount::cents($projection['evidence']['booked_czk']);
                $caseBalances[$id] = ($caseBalances[$id] ?? 0) + ($item['doc_type'] === 'invoice' ? $base : -$base) + ($carry[$key] ?? 0) + ($increments[$key] ?? 0);
                $caseForeign[$id] = ($caseForeign[$id] ?? 0) + ($item['doc_type'] === 'invoice' ? 1 : -1) * JournalLineAmount::cents($projection['evidence']['remaining_foreign']);
            }
            $closedHomeLines = [];
            foreach ($history['items'] as $projection) if ($this->closedHomePosition($projection)) $closedHomeLines[(int) $projection['item']['source_line_id']] = true;
            $this->validateAnchor($snapshot, $prior, $closing, $opening, array_keys($net), $caseBalances, $caseForeign, $native, $openingProjections, $closedHomeLines);
            $reversalId = (int) ($open['payload']['fx_reversal_entry_id'] ?? 0);
            if ($reversalId) {
                $reversal = $entries[$reversalId] ?? null;
                $this->require($entry !== null && $reversal !== null && $reversal['posted_at'] !== null && $reversal['reversed_by'] === null && $reversal['source_type'] === 'fx_revaluation' && (int) $reversal['source_id'] === ClosingSourceId::fxReversal($pid) && (int) $reversal['period_id'] === $opening->periodId && $reversal['entry_date'] === $opening->startsOn, 'historical_reversal_missing');
                $this->require($this->fingerprints(array_map(JournalLineAmount::reversal(...), $entry['lines'])) === $this->fingerprints($reversal['lines']), 'historical_reversal_mismatch');
                $claimed[$reversalId] = true;
            }
            foreach ($increments as $key => $increment) if (!$reversalId) $carry[$key] = ($carry[$key] ?? 0) + $increment;
            foreach ($opening->entryIds() as $openingId) $openingProjections[$openingId] = ['anchor' => $opening, 'czk' => $caseBalances, 'foreign' => $caseForeign];
            $proof[] = ['period_id' => $pid, 'step_id' => (int) $steps['fx_revaluation']['id'], 'source_line_ids' => $sourceLines, 'closed_home_source_line_ids' => array_keys($closedHomeLines), 'fx_entry_id' => $entryId ?: null, 'closing_entry_id' => $closingId, 'opening_entry_id' => $opening->legacyEntryId, 'reversal_entry_id' => $reversalId ?: null]
                + ($opening->legacyEntryId === null ? ['opening_entry_ids' => $opening->entryIds()] : []);
        }
        foreach ($entries as $entry) {
            if ($entry['source_type'] !== 'fx_revaluation' || $entry['posted_at'] === null || (int) $entry['source_id'] === ClosingSourceId::fxSaldo((int) $period['id']) || (int) $entry['source_id'] % 10 === ClosingSourceId::SLOT_FX_BANK) continue;
            if (!array_any($entry['lines'], static fn (array $line): bool => isset($relevant[$line['account_id'] . '|' . ($line['currency_code'] ?? '')]))) continue;
            $this->require(isset($claimed[(int) $entry['id']]), 'historical_fx_entry_unattributed');
        }
        $current = $this->index($snapshot['items']);
        foreach ($carry as $key => $amount) if ($amount !== 0) $this->require(isset($current[$key]), 'current_case_disappeared');
        return ['complete' => true, 'findings' => [], 'carrying' => $carry, 'proof' => $proof];
    }

    private function index(array $projections): array
    {
        $out = [];
        foreach ($projections as $projection) {
            if (in_array(substr((string) $projection['item']['account_code'], 0, 3), ['314', '324'], true)) continue;
            if ($this->closedHomePosition($projection)) continue;
            if (array_key_exists('case_document_id', $projection['item'])) $this->require($projection['item']['case_document_id'] !== null, 'case_document_lineage_unproven');
            $key = self::key($projection['item']);
            $this->require(!isset($out[$key]), 'case_source_allocation_ambiguous');
            $out[$key] = $projection;
        }
        return $out;
    }

    private function closedHomePosition(array $projection): bool
    {
        $evidence = $projection['evidence'];
        return ($evidence['closed_home_position_proven'] ?? false) === true && $evidence['complete'] === true
            && isset($projection['item']['source_line_id']) && (int) $projection['item']['source_line_id'] > 0
            && $evidence['remaining_foreign'] === '0.00' && $evidence['booked_czk'] === '0.00';
    }

    private function validateStoredLines(array $stored, array $actual, array $rates): void
    {
        $expected = [];
        foreach ($stored as $line) {
            $this->require(isset($line['account_id'], $line['currency_code'], $line['direction'], $line['amount']) && in_array($line['direction'], ['gain', 'loss'], true), 'historical_saldo_lines_malformed');
            $key = $line['account_id'] . '|' . $line['currency_code'] . '|' . $line['direction'] . '|' . FxCaseTuple::hash($line['source_tuple'] ?? []);
            $expected[$key] = ($expected[$key] ?? 0) + JournalLineAmount::cents($line['amount']);
        }
        $observed = [];
        $balance = 0;
        foreach ($actual as $line) {
            $signed = $this->signed($line);
            $balance += $signed;
            if ($line['currency_code'] !== null) {
                $this->require($line['amount_foreign'] !== null && JournalLineAmount::cents($line['amount_foreign']) === 0, 'historical_fx_foreign_changed');
                $this->require($line['fx_rate'] !== null && isset($rates[$line['currency_code']]) && bccomp((string) $line['fx_rate'], (string) $rates[$line['currency_code']], 6) === 0, 'historical_rate_changed');
                $key = $line['account_id'] . '|' . $line['currency_code'] . '|' . ($signed >= 0 ? 'gain' : 'loss') . '|' . FxCaseTuple::hash($line['source_tuple'] ?? []);
                $observed[$key] = ($observed[$key] ?? 0) + abs($signed);
            } else {
                $this->require(in_array(substr((string) $line['account_code'], 0, 1), ['5', '6'], true), 'historical_fx_counterpart_unproven');
            }
        }
        $this->require($balance === 0 && $this->clean($expected) === $this->clean($observed), 'historical_saldo_lines_mismatch');
    }

    private function validateAnchor(array $snapshot, array $prior, array $closing, FxOpeningCarryAnchor $opening, array $keys, array $caseBalances, array $caseForeign, array $native, array $openingProjections, array $closedHomeLines): void
    {
        $accounts = [];
        foreach ($keys as $key) $accounts[(int) explode('|', $key)[0]] = true;
        $anchor = null;
        // Důkaz přenosu navazuje na konkrétní opening zápis, nikoli na periodické okno reportu LedgerReportRepository::openingAnchor().
        foreach ($snapshot['entries'] as $entry) {
            if ($entry['source_type'] !== 'opening' || $entry['posted_at'] === null || $entry['entry_date'] > $prior['ends_on']) continue;
            $known = $openingProjections[(int) $entry['id']]['anchor'] ?? null;
            if ($known !== null) $known->assertLedgerMembership($snapshot['ledger'], $accounts, $prior['ends_on']);
            $anchor = max($anchor ?? '', $known?->startsOn ?? $entry['entry_date']);
        }
        $before = [];
        $ledgerForeign = [];
        $foreignCzk = [];
        $foreignNominal = [];
        $projectedOpenings = [];
        $manualCurrencies = [];
        foreach ($native as $projection) foreach ($projection['evidence']['manual_members'] ?? [] as $member) {
            $id = (int) $member['line_id'];
            $currency = $projection['item']['currency_code'];
            $this->require(!isset($manualCurrencies[$id]) || $manualCurrencies[$id] === $currency, 'historical_manual_currency_unproven');
            $manualCurrencies[$id] = $currency;
        }
        foreach ($snapshot['ledger'] as $line) {
            if (!isset($accounts[(int) $line['account_id']]) || $line['posted_at'] === null || $line['entry_date'] > $prior['ends_on'] || ($anchor !== null && $line['entry_date'] < $anchor) || (int) $line['entry_id'] === (int) $closing['id']) continue;
            $id = (int) $line['account_id'];
            $tupleKey = $id . '|' . FxCaseTuple::hash($line['source_tuple']);
            $before[$tupleKey] = ($before[$tupleKey] ?? 0) + $this->signed($line);
            if ($line['currency_code'] !== null && $line['currency_code'] !== 'CZK') {
                $ledgerKey = $id . '|' . $line['currency_code'] . '|' . FxCaseTuple::hash($line['source_tuple']);
                $ledgerForeign[$ledgerKey] = ($ledgerForeign[$ledgerKey] ?? 0) + $this->signed($line, true);
            }
            if (isset($closedHomeLines[(int) $line['id']])) continue;
            $entryId = (int) $line['entry_id'];
            if ($line['source_type'] === 'opening' && isset($openingProjections[$entryId])) {
                $groupKey = $openingProjections[$entryId]['anchor']->key();
                if (!isset($projectedOpenings[$groupKey])) {
                    foreach ($openingProjections[$entryId]['czk'] as $key => $amount) $foreignCzk[$key] = ($foreignCzk[$key] ?? 0) + $amount;
                    foreach ($openingProjections[$entryId]['foreign'] as $key => $amount) $foreignNominal[$key] = ($foreignNominal[$key] ?? 0) + $amount;
                    $projectedOpenings[$groupKey] = true;
                }
                continue;
            }
            $currency = $line['currency_code'];
            if ($currency === null || $currency === 'CZK') $currency = $manualCurrencies[(int) $line['id']] ?? null;
            if ($currency !== null) {
                $this->require($line['currency_code'] === null || $line['amount_foreign'] !== null, 'historical_case_currency_mismatch');
                $key = $id . '|' . $currency . '|' . FxCaseTuple::hash($line['source_tuple']);
                $foreignCzk[$key] = ($foreignCzk[$key] ?? 0) + $this->signed($line);
                $foreignNominal[$key] = ($foreignNominal[$key] ?? 0) + $this->signed($line, true);
            }
        }
        $closed = [];
        $opened = [];
        $openedForeign = [];
        foreach ($closing['lines'] as $line) if (isset($accounts[(int) $line['account_id']])) {
            $tupleKey = $line['account_id'] . '|' . FxCaseTuple::hash($line['source_tuple']);
            $closed[$tupleKey] = ($closed[$tupleKey] ?? 0) - $this->signed($line);
        }
        foreach ($opening->entries as $openingEntry) foreach ($openingEntry['lines'] as $line) {
            if (!isset($accounts[(int) $line['account_id']])) continue;
            $id = (int) $line['account_id'];
            $tupleKey = $id . '|' . FxCaseTuple::hash($line['source_tuple']);
            $opened[$tupleKey] = ($opened[$tupleKey] ?? 0) + $this->signed($line);
            if ($line['currency_code'] !== null) {
                $key = $id . '|' . $line['currency_code'] . '|' . FxCaseTuple::hash($line['source_tuple']);
                $openedForeign[$key] = ($openedForeign[$key] ?? 0) + $this->signed($line, true);
            }
        }
        $filter = static fn (string $key): bool => isset($accounts[(int) explode('|', $key)[0]]);
        $caseBalances = array_filter($caseBalances, $filter, ARRAY_FILTER_USE_KEY);
        $caseForeign = array_filter($caseForeign, $filter, ARRAY_FILTER_USE_KEY);
        $foreignCzk = array_filter($foreignCzk, $filter, ARRAY_FILTER_USE_KEY);
        $foreignNominal = array_filter($foreignNominal, $filter, ARRAY_FILTER_USE_KEY);
        $this->require($this->clean($before) === $this->clean($closed) && $this->clean($before) === $this->clean($opened), 'historical_opening_anchor_mismatch');
        $this->require($this->clean($caseBalances) === $this->clean($foreignCzk) && $this->clean($caseForeign) === $this->clean($foreignNominal), 'historical_case_currency_mismatch');
        // Otevření nese buď cizí měnu doložených případů (převzaté počáteční stavy), nebo cizoměnový
        // zůstatek účtu z deníku, jak ho přenáší uzávěrka (ClosingRepository::bsBalances). Ten
        // zahrnuje i pozice uzavřené v korunách; buňku, kterou přenést nešlo, nese bez měny.
        if ($openedForeign !== []) $this->require($this->clean($caseForeign) === $this->clean($openedForeign)
            || $this->mirrorsLedgerForeign($this->clean($openedForeign), $ledgerForeign), 'historical_opening_currency_mismatch');
    }

    /**
     * @param array<string,int> $opened
     * @param array<string,int> $ledger
     */
    private function mirrorsLedgerForeign(array $opened, array $ledger): bool
    {
        foreach ($opened as $key => $cents) {
            if (($ledger[$key] ?? 0) !== $cents) return false;
        }
        return true;
    }

    private function currencyTotals(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) if ($line['currency_code'] !== null) {
            $key = $line['account_id'] . '|' . $line['currency_code'];
            $totals[$key] = ($totals[$key] ?? 0) + $this->signed($line);
        }
        return $totals;
    }

    private function fingerprints(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) $out[] = json_encode([(int) $line['account_id'], $line['side'], JournalLineAmount::decimal($line['amount']), (bool) ($line['is_red_storno'] ?? false), $line['currency_code'], $line['amount_foreign'] === null ? null : JournalLineAmount::decimal($line['amount_foreign']), $line['fx_rate'] === null ? null : bcadd((string) $line['fx_rate'], '0', 6), FxCaseTuple::hash($line['source_tuple'] ?? [])], JSON_THROW_ON_ERROR);
        sort($out, SORT_STRING);
        return $out;
    }

    private function signed(array $line, bool $foreign = false): int
    {
        if ($foreign) $line['amount'] = $line['amount_foreign'] ?? 0;
        return ($line['side'] === 'debit' ? 1 : -1) * JournalLineAmount::signedCents($line);
    }

    private function clean(array $values): array
    {
        $values = array_filter($values, static fn (int $value): bool => $value !== 0);
        ksort($values);
        return $values;
    }

    private function require(bool $condition, string $finding): void
    {
        if (!$condition) throw new \DomainException($finding);
    }
}
