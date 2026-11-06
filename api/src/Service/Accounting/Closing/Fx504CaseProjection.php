<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

final class Fx504CaseProjection
{
    public static function preview(array $snapshot, array $accountDecisions, ?array $caseDecisions = null, array $monetaryInventoryCells = []): array
    {
        $source = $snapshot['case_snapshot'];
        $caseDecisions ??= $source['prior_decisions'];
        $locked = [];
        foreach ($source['prior_decisions'] as $prior) {
            foreach ($prior['contributions'] as $part) {
                $locked[$part['cell_key']] = true;
                $found = false;
                foreach ($caseDecisions as $decision) {
                    if (($decision['origin'] ?? null) === $prior['origin'] && ($decision['reason'] ?? null) === $prior['reason'] && in_array($part, $decision['contributions'] ?? [], true)) { $found = true; break; }
                }
                if (!$found) throw self::invalid();
            }
        }
        $sourceCells = self::cells($source['rows']);
        foreach ($sourceCells as &$cell) {
            $cell['czk_signed'] = Fx504Builder::decimal($cell['czk_cents']);
            $cell['foreign_signed'] = Fx504Builder::decimal($cell['foreign_cents']);
            $cell['candidates'] = $source['origins'][$cell['line_id']] ?? [];
        }
        unset($cell);
        $projection = self::project($source['rows'], $source['origins'], $caseDecisions);
        $preview = Fx504Builder::preview(Fx504Builder::positions($projection['rows']), $snapshot['rates'], $snapshot['required_types'], $accountDecisions, $monetaryInventoryCells);
        if ($source['findings'] !== []) $preview['complete'] = false;
        $requiredCases = array_column(array_filter($preview['positions'], static fn (array $position): bool => $position['finding'] === 'case_evidence_required'), 'case_cell_key');
        $unresolved = array_values(array_filter($projection['unresolved'], static fn (array $cell): bool => in_array($cell['cell_key'], $requiredCases, true)));
        return $preview + ['case_decisions' => $caseDecisions, 'case_unresolved' => $unresolved, 'case_findings' => $source['findings'],
            'case_source_hash' => $source['hash'], 'case_source_cells' => array_values($sourceCells), 'case_locked_cell_keys' => array_keys($locked)];
    }

    public static function cells(array $rows): array
    {
        $cells = [];
        foreach ($rows as $row) {
            foreach (Fx504Builder::positions([$row]) as $position) {
                $key = hash('sha256', json_encode([(int) $row['id'], $position['account_id'], $position['currency_code'], $position['dimensions'], $position['cost_center'], $position['project_id']], JSON_THROW_ON_ERROR));
                if (isset($cells[$key])) throw self::invalid();
                $cells[$key] = $position + ['cell_key' => $key, 'line_id' => (int) $row['id'], 'entry_id' => (int) $row['entry_id'],
                    'line_no' => (int) $row['line_no'], 'entry_version' => (int) $row['entry_version']];
            }
        }
        ksort($cells);
        return $cells;
    }

    public static function project(array $rows, array $origins, array $decisions = []): array
    {
        $cells = self::cells($rows);
        $pieces = [];
        $used = [];
        $declared = [];
        foreach ($decisions as $decision) {
            $decision = self::normalizeDecision($decision);
            $origin = $decision['origin'];
            $root = null;
            if ($origin['kind'] === 'declared_title') {
                foreach ($cells as $cell) if ($cell['line_id'] === $origin['origin_line_id']) { $root = $cell; break; }
                if ($root === null) throw self::invalid();
                $identity = 'line:' . $origin['origin_line_id'];
                if (isset($declared[$identity]) && $declared[$identity] !== $origin) throw self::invalid();
                $declared[$identity] = $origin;
            }
            foreach ($decision['contributions'] as $contribution) {
                $key = $contribution['cell_key'] ?? null;
                if (!is_string($key) || !isset($cells[$key])) throw self::invalid();
                $cell = $cells[$key];
                self::assertOriginForCell($cell, $cells, $origins, $origin);
                $czk = self::cents($contribution['czk_signed'] ?? null);
                $fx = self::cents($contribution['foreign_signed'] ?? null);
                self::portion($czk, $cell['czk_cents']);
                self::portion($fx, $cell['foreign_cents']);
                if ($czk === 0 && $fx === 0) throw self::invalid();
                $used[$key] ??= [0, 0];
                $used[$key][0] += $czk;
                $used[$key][1] += $fx;
                $pieces[] = self::piece($cell, $czk, $fx, $origin);
            }
        }
        $unresolved = [];
        foreach ($cells as $key => $cell) {
            if (isset($used[$key])) {
                if ($used[$key] !== [$cell['czk_cents'], $cell['foreign_cents']]) throw self::invalid();
                continue;
            }
            $candidates = $origins[$cell['line_id']] ?? [];
            if (count($candidates) === 1) $pieces[] = self::piece($cell, $cell['czk_cents'], $cell['foreign_cents'], self::origin($candidates[0]));
            else {
                $unresolved[] = $cell + ['candidates' => $candidates, 'czk_signed' => Fx504Builder::decimal($cell['czk_cents']), 'foreign_signed' => Fx504Builder::decimal($cell['foreign_cents'])];
                $pieces[] = self::piece($cell, $cell['czk_cents'], $cell['foreign_cents'], null);
            }
        }
        return ['rows' => $pieces, 'unresolved' => $unresolved, 'complete' => $unresolved === []];
    }

    public static function assertOriginForCell(array $cell, array $cells, array $origins, array $origin): void
    {
        $origin = self::origin($origin);
        $root = null;
        if ($origin['kind'] === 'declared_title') {
            foreach ($cells as $sourceCell) if ($sourceCell['line_id'] === $origin['origin_line_id']) { $root = $sourceCell; break; }
            if ($root === null) throw self::invalid();
        }
        $candidates = $origins[$cell['line_id']] ?? [];
        if ($candidates !== [] && !in_array($origin, $candidates, true)) throw self::invalid();
        if ($origin['kind'] === 'cash_account' && !in_array($origin, $candidates, true)) throw self::invalid();
        if ($root !== null && ($cell['account_id'] !== $root['account_id'] || $cell['currency_code'] !== $root['currency_code'])) throw self::invalid();
        if ($origin['kind'] === 'document' && $candidates === []) {
            $evidenced = false;
            foreach ($cells as $sourceCell) {
                if ($sourceCell['account_id'] === $cell['account_id'] && $sourceCell['currency_code'] === $cell['currency_code']
                    && in_array($origin, $origins[$sourceCell['line_id']] ?? [], true)) { $evidenced = true; break; }
            }
            if (!$evidenced) throw self::invalid();
        }
    }

    public static function normalizeDecision(mixed $decision): array
    {
        if (!is_array($decision) || !is_array($decision['origin'] ?? null) || !is_string($decision['reason'] ?? null) || trim($decision['reason']) === '' || strlen($decision['reason']) > 8000
            || array_diff(array_keys($decision), ['origin', 'reason', 'contributions']) !== []
            || !is_array($decision['contributions'] ?? null) || $decision['contributions'] === []) throw self::invalid();
        $decision['origin'] = self::origin($decision['origin']);
        foreach ($decision['contributions'] as $contribution) {
            if (!is_array($contribution) || array_diff(array_keys($contribution), ['cell_key', 'czk_signed', 'foreign_signed']) !== [] || !is_string($contribution['cell_key'] ?? null)) throw self::invalid();
            self::cents($contribution['czk_signed'] ?? null);
            self::cents($contribution['foreign_signed'] ?? null);
        }
        return $decision;
    }

    private static function piece(array $cell, int $czk, int $fx, ?array $origin): array
    {
        return ['id' => $cell['line_id'], 'account_id' => $cell['account_id'], 'account_code' => $cell['account_code'],
            'currency_code' => $cell['currency_code'], 'dimensions' => $cell['dimensions'], 'cost_center' => $cell['cost_center'], 'project_id' => $cell['project_id'],
            'side' => 'debit', 'amount' => Fx504Builder::decimal($czk), 'amount_foreign' => Fx504Builder::decimal($fx), 'is_red_storno' => false,
            'case_origin' => $origin, 'case_cell_key' => $cell['cell_key'], 'case_finding' => $origin === null ? 'case_evidence_required' : null];
    }

    public static function origin(array $origin): array
    {
        $kind = $origin['kind'] ?? null;
        $allowed = match ($kind) { 'document' => ['kind', 'doc_type', 'doc_id'], 'declared_title' => ['kind', 'origin_line_id', 'reference'], 'cash_account' => ['kind', 'account_id', 'currency_code'], default => [] };
        if ($allowed === [] || array_diff(array_keys($origin), $allowed) !== []) throw self::invalid();
        if ($kind === 'document' && in_array($origin['doc_type'] ?? null, ['invoice', 'purchase_invoice'], true) && self::id($origin['doc_id'] ?? null)) {
            return ['kind' => 'document', 'doc_type' => $origin['doc_type'], 'doc_id' => (int) $origin['doc_id']];
        }
        if ($kind === 'declared_title' && self::id($origin['origin_line_id'] ?? null) && is_string($origin['reference'] ?? null) && trim($origin['reference']) !== '') {
            return ['kind' => 'declared_title', 'origin_line_id' => (int) $origin['origin_line_id'], 'reference' => trim($origin['reference'])];
        }
        if ($kind === 'cash_account' && self::id($origin['account_id'] ?? null) && is_string($origin['currency_code'] ?? null) && preg_match('/^[A-Z]{3}$/D', $origin['currency_code'])) {
            return ['kind' => 'cash_account', 'account_id' => (int) $origin['account_id'], 'currency_code' => $origin['currency_code']];
        }
        throw self::invalid();
    }

    private static function id(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && ctype_digit((string) $value) && (int) $value > 0;
    }

    private static function cents(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/D', $value) !== 1) throw self::invalid();
        return \MyInvoice\Service\Accounting\JournalLineAmount::cents($value);
    }

    private static function portion(int $value, int $whole): void
    {
        if (($whole === 0 && $value !== 0) || ($value !== 0 && ($value < 0) !== ($whole < 0)) || abs($value) > abs($whole)) throw self::invalid();
    }

    private static function invalid(): ClosingException
    {
        return new ClosingException('fx_case_evidence_invalid', 'Rozpad účetních případů musí přesně pokrýt aktuální zdrojové buňky v Kč i cizí měně a doložit jejich původ.', 422);
    }
}
