<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\{AccountingFrameworkService, AccountingRoleProfile, FrameworkOperationPolicy};

final class LegalClosing504Builder
{
    public static function withDecisionOrigins(array $lines, array $decision): array
    {
        $reference = 'legal_decision:' . hash('sha256', json_encode($decision, JSON_THROW_ON_ERROR));
        foreach ($lines as &$line) {
            foreach ($line['dimensions'] ?? [] as $type => $value) $line['dimension_origins'][$type] = ['origin' => 'explicit', 'ref' => $reference];
        }
        unset($line);
        return $lines;
    }

    public static function reserve(AccountingRoleProfile $profile, array $body, ?array $remaining = null): array
    {
        if ($profile->framework !== AccountingFrameworkService::CZ_504) throw new \InvalidArgumentException('A 504 profile is required.');
        $rule = $body['rule_key'] ?? '';
        if (!in_array($rule, ['reserve.repairs.create', 'reserve.repairs.release'], true)) throw self::evidenceError();
        $decision = self::decision($profile, 'reserve.legal', $body['legal_decision'] ?? null, $body['amount'] ?? null);
        $release = $rule === 'reserve.repairs.release';
        if ($release) {
            if ($remaining === null || (int) ($decision['source_entry_id'] ?? 0) <= 0) {
                throw new ClosingException('legal_closing_source_required', 'Pro rozpuštění vyberte původní doloženou rezervu.', 422);
            }
            self::assertAvailable($decision['allocations'], $remaining);
        }
        $expense = $profile->account('reserve.legal.expense');
        $balance = $profile->account('reserve.legal.balance');
        return ['decision' => $decision, 'lines' => self::pairs($decision['allocations'], $release ? $balance : $expense, $release ? $expense : $balance)];
    }

    public static function decision(AccountingRoleProfile $profile, string $operation, mixed $raw, mixed $amount, bool $allowZero = false): array
    {
        if (!is_array($raw) || !FrameworkOperationPolicy::allows($profile, $operation, $raw)
            || ($raw['conditions_confirmed'] ?? null) !== true) throw self::evidenceError();
        $out = ['version' => '504-legal-v1', 'taxable_activity' => true, 'conditions_confirmed' => true];
        foreach (['reference', 'legal_basis', 'evidence'] as $key) {
            if (!is_string($raw[$key] ?? null) || trim($raw[$key]) === '' || mb_strlen($raw[$key]) > 2000) throw self::evidenceError();
            $out[$key] = trim($raw[$key]);
        }
        if (isset($raw['source_entry_id'])) $out['source_entry_id'] = (int) $raw['source_entry_id'];
        if (isset($raw['reserve_source'])) $out['reserve_source'] = AssetReserveSourceDecision::normalize($raw['reserve_source']);
        if (isset($raw['allocation_basis'])) {
            if (!in_array($raw['allocation_basis'], ['source_joint', 'new_decision'], true)) throw self::evidenceError();
            $out['allocation_basis'] = $raw['allocation_basis'];
            if ($raw['allocation_basis'] === 'source_joint') {
                if (($raw['payment_allocation'] ?? null) !== 'proportional') throw self::evidenceError();
                $out['payment_allocation'] = 'proportional';
            }
        }
        $total = $allowZero ? self::targetCents($amount) : ProfitDistribution504Builder::allocationCents($amount);
        $out['allocations'] = $total === 0 && ($raw['allocations'] ?? null) === [] ? [] : self::allocations($raw['allocations'] ?? null, $total);
        return $out;
    }

    public static function targetCents(mixed $amount): int
    {
        if ($amount === 0 || $amount === 0.0 || (is_string($amount) && preg_match('/^0+(?:\.0+)?$/D', $amount))) return 0;
        return ProfitDistribution504Builder::allocationCents($amount);
    }

    public static function provisionMovements(array $decision, array $current): array
    {
        $targets = [];
        foreach ($decision['allocations'] as $cell) $targets[self::cellKey($cell)] = $cell;
        $movements = [];
        foreach (array_unique(array_merge(array_keys($current), array_keys($targets))) as $key) {
            $target = isset($targets[$key]) ? ProfitDistribution504Builder::allocationCents($targets[$key]['amount']) : 0;
            $delta = $target - ($current[$key]['available_cents'] ?? 0);
            if ($delta === 0) continue;
            $cell = $targets[$key] ?? $current[$key];
            unset($cell['available_cents']);
            $cell['amount'] = abs($delta) / 100;
            $cell['direction'] = $delta > 0 ? 'create' : 'release';
            $movements[] = $cell;
        }
        return $movements;
    }

    public static function provisionMovementLines(AccountingRoleProfile $profile, array $movements): array
    {
        $lines = [];
        foreach ($movements as $cell) {
            if (!in_array($cell['direction'] ?? null, ['create', 'release'], true)) throw self::evidenceError();
            $normalized = self::allocations([$cell], ProfitDistribution504Builder::allocationCents($cell['amount'] ?? null));
            $expense = $profile->account('provision.legal.expense');
            $balance = $profile->account('provision.legal.balance');
            array_push($lines, ...self::pairs($normalized, $cell['direction'] === 'create' ? $expense : $balance, $cell['direction'] === 'create' ? $balance : $expense));
        }
        return $lines;
    }

    public static function allocations(mixed $raw, int $total): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 200) {
            throw new ClosingException('legal_closing_allocation_required', 'Doplňte společné rozdělení částky do činností a dalších povinných dimenzí.', 422);
        }
        $sum = 0;
        $out = [];
        foreach ($raw as $cell) {
            if (!is_array($cell) || !is_array($cell['dimensions'] ?? null) || $cell['dimensions'] === [] || !empty($cell['dimension_splits'])) {
                throw new ClosingException('legal_closing_allocation_required', 'Doplňte společné rozdělení částky do činností a dalších povinných dimenzí.', 422);
            }
            $cents = ProfitDistribution504Builder::allocationCents($cell['amount'] ?? null);
            $dimensions = [];
            foreach ($cell['dimensions'] as $type => $value) {
                if (!ctype_digit((string) $type) || !ctype_digit((string) $value) || (int) $type <= 0 || (int) $value <= 0) throw self::evidenceError();
                $dimensions[(int) $type] = (int) $value;
            }
            ksort($dimensions);
            $normalized = ['amount' => $cents / 100, 'dimensions' => $dimensions];
            if (isset($cell['cost_center'])) $normalized['cost_center'] = (string) $cell['cost_center'];
            if (isset($cell['project_id'])) $normalized['project_id'] = (int) $cell['project_id'];
            $key = self::cellKey($normalized);
            if (isset($out[$key])) throw new ClosingException('validation_failed', 'Společná alokační buňka je v rozhodnutí uvedena vícekrát.', 422);
            $out[$key] = $normalized;
            $sum += $cents;
        }
        if ($sum !== $total) throw new ClosingException('legal_closing_allocation_mismatch', 'Součet společných alokací neodpovídá potvrzené částce.', 422);
        return array_values($out);
    }

    public static function pairs(array $allocations, string $debit, string $credit): array
    {
        $lines = [];
        foreach ($allocations as $cell) {
            $metadata = array_intersect_key($cell, array_flip(['amount', 'dimensions', 'cost_center', 'project_id']));
            $lines[] = ['account_code' => $debit, 'side' => 'debit'] + $metadata;
            $lines[] = ['account_code' => $credit, 'side' => 'credit'] + $metadata;
        }
        return $lines;
    }

    public static function cellKey(array $cell): string
    {
        $dimensions = $cell['dimensions'] ?? [];
        ksort($dimensions);
        return hash('sha256', json_encode([$dimensions, $cell['cost_center'] ?? null, isset($cell['project_id']) ? (int) $cell['project_id'] : null], JSON_THROW_ON_ERROR));
    }

    public static function assertAvailable(array $allocations, array $remaining): void
    {
        foreach ($allocations as $cell) {
            if (ProfitDistribution504Builder::allocationCents($cell['amount']) > ($remaining[self::cellKey($cell)] ?? 0)) {
                throw new ClosingException('legal_closing_source_exceeded', 'Částka přesahuje doložený zůstatek zdroje v dané společné alokaci.', 422);
            }
        }
    }

    private static function evidenceError(): ClosingException
    {
        return new ClosingException('legal_closing_evidence_required', 'Pro tento účetní rámec doložte zákonný titul, splnění podmínek a vazbu na zdanitelnou činnost.', 422);
    }
}
