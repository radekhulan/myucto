<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use MyInvoice\Service\Accounting\JournalLineAmount;

final class Fx504Builder
{
    public static function positions(array $rows): array
    {
        $positions = [];
        foreach ($rows as $row) {
            if (($row['currency_code'] ?? 'CZK') === 'CZK') continue;
            foreach (self::sourceCells($row) as $cell) {
                $local = $cell['czk_cents'];
                $fx = $cell['foreign_cents'];
                $metadata = array_diff_key($cell, array_flip(['czk_cents', 'foreign_cents']));
                if (array_key_exists('case_origin', $row)) {
                    $metadata['case_origin'] = $row['case_origin'];
                    $metadata['case_finding'] = $row['case_finding'] ?? null;
                    if ($row['case_origin'] === null) $metadata['case_cell_key'] = $row['case_cell_key'];
                }
                $key = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR));
                $positions[$key] ??= $metadata + ['key' => $key, 'czk_cents' => 0, 'foreign_cents' => 0, 'source_line_ids' => []];
                $positions[$key]['czk_cents'] += $local;
                $positions[$key]['foreign_cents'] += $fx;
                $positions[$key]['source_line_ids'][] = (int) $row['id'];
            }
        }
        ksort($positions);
        foreach ($positions as $key => &$position) {
            if ($position['czk_cents'] === 0 && $position['foreign_cents'] === 0) { unset($positions[$key]); continue; }
            $position['source_line_ids'] = array_values(array_unique($position['source_line_ids']));
            sort($position['source_line_ids'], SORT_NUMERIC);
        }
        unset($position);
        return array_values($positions);
    }

    public static function sourceCells(array $row): array
    {
        $currency = $row['currency_code'] ?? 'CZK';
        if ($currency !== 'CZK' && ($row['amount_foreign'] ?? null) === null) throw self::invalid('fx_trace_incomplete', 'Cizoměnový řádek nemá doloženou částku v cizí měně.');
        $dimensions = self::dimensions($row['dimensions'] ?? []);
        $splits = $row['dimension_splits'] ?? [];
        foreach ($splits as $type => $shares) {
            if (count($shares) === 1) {
                $dimensions[$type] = (int) array_key_first($shares);
                unset($splits[$type]);
            }
        }
        if (count($splits) > 1) throw self::invalid('fx_joint_source_required', 'Zdrojové rozdělení více dimenzí vyžaduje doložené společné alokační buňky.');
        $sign = $row['side'] === 'credit' ? -1 : 1;
        $czk = $sign * JournalLineAmount::signedCents($row);
        $foreign = $currency === 'CZK' ? 0 : $sign * JournalLineAmount::signedCents(['amount' => $row['amount_foreign'], 'is_red_storno' => $row['is_red_storno'] ?? false]);
        $pieces = [[$dimensions, $czk, $foreign]];
        if ($splits !== []) {
            $type = array_key_first($splits);
            $shares = $splits[$type];
            ksort($shares, SORT_NUMERIC);
            if ($shares === [] || min($shares) <= 0 || abs(array_sum($shares) - 100) > 0.000001) throw self::invalid('fx_joint_source_required', 'Zdrojové rozdělení dimenzí není úplné.');
            $czkParts = DimensionStamper::distributeCents($czk, array_values($shares));
            $foreignParts = DimensionStamper::distributeCents($foreign, array_values($shares));
            $pieces = [];
            foreach (array_keys($shares) as $index => $value) $pieces[] = [$dimensions + [$type => (int) $value], $czkParts[$index], $foreignParts[$index]];
        }
        $out = [];
        foreach ($pieces as [$dims, $local, $fx]) {
            ksort($dims, SORT_NUMERIC);
            $out[] = ['account_id' => (int) $row['account_id'], 'account_code' => (string) $row['account_code'],
                'currency_code' => $currency, 'dimensions' => $dims, 'cost_center' => $row['cost_center'] ?? null,
                'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
                'czk_cents' => $local, 'foreign_cents' => $fx];
        }
        return $out;
    }

    public static function preview(array $positions, array $rates, array $requiredTypes, array $accountDecisions = [], array $monetaryInventoryCells = []): array
    {
        $loss = $gain = 0;
        $complete = $positions === [] || count($requiredTypes) === 3;
        if (array_diff(array_keys($accountDecisions), array_column($positions, 'account_code')) !== []) throw self::invalid('fx_decision_stale', 'Posouzení účtu odkazuje na neaktuální kurzovou pozici.');
        foreach ($positions as &$position) {
            $code = $position['account_code'];
            $decision = $accountDecisions[$code] ?? null;
            $known = in_array(substr($code, 0, 3), ['211', '221', '311', '321', '378', '379'], true);
            $inventoryMonetary = $monetaryInventoryCells !== [] && array_all($position['source_line_ids'], static fn (int $id): bool => in_array(hash('sha256', json_encode([$id, $position['account_id'], $position['currency_code'], $position['dimensions'], $position['cost_center'], $position['project_id']], JSON_THROW_ON_ERROR)), $monetaryInventoryCells, true));
            $classification = $known || $inventoryMonetary ? 'monetary' : null;
            if ($decision !== null) {
                if (!is_array($decision) || !in_array($decision['classification'] ?? null, ['monetary', 'nonmonetary'], true) || (!is_string($decision['reason'] ?? null) || trim($decision['reason']) === '')) throw self::invalid('fx_title_decision_invalid', 'Posouzení peněžní povahy účtu vyžaduje doložený důvod.');
                $classification = $decision['classification'];
            }
            if ($inventoryMonetary) $classification = 'monetary';
            $position['classification'] = $classification;
            $position['title_decision'] = $decision;
            $position['booked_czk'] = self::decimal($position['czk_cents']);
            $position['foreign_balance'] = self::decimal($position['foreign_cents']);
            $position['slot'] = in_array(substr($code, 0, 2), ['21', '22', '26'], true) ? 'bank' : 'saldo';
            $position['finding'] = null;
            $position['diff'] = null;
            $position['diff_cents'] = null;
            $position['rate'] = null;
            $position['new_czk'] = null;
            if (!$inventoryMonetary && \MyInvoice\Repository\SecuritiesValuation504ReadRepository::candidate($code)) {
                $position['classification'] = 'securities';
                $position['finding'] = 'securities_valuation_required';
                $complete = false;
                continue;
            }
            if ($classification === 'nonmonetary') continue;
            if (($position['case_finding'] ?? null) !== null) {
                $position['finding'] = $position['case_finding'];
                $complete = false;
                continue;
            }
            if ($classification === null) {
                $position['finding'] = 'monetary_title_required';
                $complete = false;
                continue;
            }
            if ($classification === 'nonmonetary') continue;
            $rate = $rates[$position['currency_code']] ?? null;
            if ($rate === null || !is_numeric($rate) || (float) $rate <= 0) {
                $position['finding'] = 'rate_required';
                $complete = false;
                continue;
            }
            $newCents = (int) bcround(bcmul((string) $position['foreign_cents'], (string) $rate, 12), 0);
            $diff = $newCents - $position['czk_cents'];
            $position['rate'] = (string) $rate;
            $position['new_czk'] = self::decimal($newCents);
            $position['diff'] = self::decimal($diff);
            $position['diff_cents'] = $diff;
            if ($diff >= 0) $gain += $diff; else $loss -= $diff;
            if ($diff !== 0 && (count($requiredTypes) !== 3 || array_diff($requiredTypes, array_keys($position['dimensions'])) !== [])) {
                $position['finding'] = 'profit_dimensions_required';
                $complete = false;
            }
        }
        unset($position);
        return ['accounting_decree' => '504', 'positions' => $positions, 'complete' => $complete,
            'totals' => ['loss' => self::decimal($loss), 'gain' => self::decimal($gain)]];
    }

    public static function build(array $preview, array $decisions, array $requiredTypes): array
    {
        if (isset($preview['securities']) && !$preview['securities']['complete']) throw self::invalid('fx_securities_valuation_required', 'Nejprve dokončete doložené ocenění všech cenných papírů a podílů.');
        if (($preview['case_findings'] ?? []) !== []) throw self::invalid('fx_case_source_changed', 'Historické podklady kurzových případů nesouhlasí s aktuálními účetními zápisy nebo počátečním stavem.');
        if ($preview['positions'] !== [] && count($requiredTypes) !== 3) throw self::invalid('fx_dimensions_required', 'Nejsou dostupné systémové dimenze činnosti a daňového režimu.');
        $profile = new AccountingRoleProfile('cz_504');
        $entries = ['saldo' => [], 'bank' => []];
        $caseMaterialization = ['saldo' => [], 'bank' => []];
        $used = [];
        foreach ($preview['positions'] as $position) {
            if ($position['finding'] === 'case_evidence_required') throw self::invalid('fx_case_evidence_required', 'Nejprve doložte rozpad aktuálních cizoměnových zůstatků podle účetních případů.');
            if ($position['finding'] === 'securities_valuation_required') throw self::invalid('fx_securities_valuation_required', 'Cenné papíry a podíly vyžadují samostatné doložené ocenění podle § 42 odst. 3 vyhlášky č. 504/2002 Sb.');
            if ($position['finding'] === 'rate_required') throw self::invalid('fx_rate_unavailable', 'Pro přecenění není k dispozici platný kurz.');
            if ($position['classification'] === null) throw self::invalid('fx_title_decision_required', 'Nejprve doložte peněžní povahu cizoměnového titulu.');
            if ($position['classification'] === 'nonmonetary' || $position['diff_cents'] === 0) continue;
            $key = $position['key'];
            $decision = $decisions[$key] ?? null;
            $amount = abs($position['diff_cents']);
            if ($decision === null) {
                if (array_diff($requiredTypes, array_keys($position['dimensions'])) !== []) throw self::invalid('fx_dimensions_required', 'Kurzový rozdíl vyžaduje doložené společné přiřazení výsledkových dimenzí.');
                $cells = [['amount' => self::decimal($amount), 'dimensions' => $position['dimensions']]];
            } else {
                if ((!is_string($decision['reason'] ?? null) || trim($decision['reason']) === '') || !is_array($decision['cells'] ?? null) || $decision['cells'] === []) throw self::invalid('fx_decision_invalid', 'Rozhodnutí o přiřazení kurzového rozdílu není úplné.');
                $cells = $decision['cells'];
                $used[$key] = true;
            }
            $assigned = 0;
            foreach ($cells as $cell) {
                $part = ProfitDistribution504Builder::allocationCents($cell['amount'] ?? null);
                $dims = self::dimensions($cell['dimensions'] ?? []);
                if (array_diff($requiredTypes, array_keys($dims)) !== [] || array_diff_assoc($position['dimensions'], $dims) !== []) throw self::invalid('fx_decision_invalid', 'Výsledková buňka musí zachovat doložené zdrojové dimenze a doplnit povinné osy.');
                $assigned += $part;
                $metadata = ['cost_center' => $position['cost_center'], 'project_id' => $position['project_id']];
                $gain = $position['diff_cents'] > 0;
                $entries[$position['slot']][] = ['account_code' => $position['account_code'], 'side' => $gain ? 'debit' : 'credit',
                    'amount' => self::decimal($part), 'currency_code' => $position['currency_code'], 'fx_rate' => $position['rate'],
                    'amount_foreign' => '0.00', 'dimensions' => $position['dimensions']] + $metadata;
                $entries[$position['slot']][] = ['account_code' => $profile->account($gain ? 'fx.gain' : 'fx.loss'),
                    'side' => $gain ? 'credit' : 'debit', 'amount' => self::decimal($part), 'dimensions' => $dims] + $metadata;
                $lineNo = count($entries[$position['slot']]) - 2;
                $caseMaterialization[$position['slot']][$lineNo] = ['origin' => $position['case_origin'] ?? null, 'line' => $entries[$position['slot']][$lineNo]];
            }
            if ($assigned !== $amount) throw self::invalid('fx_decision_mismatch', 'Částky společných buněk musí přesně odpovídat kurzovému rozdílu.');
        }
        if (array_diff(array_keys($decisions), array_keys($used)) !== []) throw self::invalid('fx_decision_stale', 'Rozhodnutí odkazuje na neaktuální kurzovou pozici.');
        return $entries + ['case_materialization' => $caseMaterialization];
    }

    private static function dimensions(array $dimensions): array
    {
        $result = [];
        foreach ($dimensions as $type => $value) {
            if (!ctype_digit((string) $type) || !ctype_digit((string) $value) || (int) $type <= 0 || (int) $value <= 0) throw self::invalid('fx_decision_invalid', 'Neplatná společná dimenzní buňka.');
            $result[(int) $type] = (int) $value;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function invalid(string $code, string $message): ClosingException
    {
        return new ClosingException($code, $message, 422);
    }
}
