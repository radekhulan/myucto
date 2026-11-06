<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;

final class ProfitDistribution504Builder
{
    public const TARGET_ROLES = ['result.retained_profit', 'result.funds', 'result.own_equity'];

    public static function cells(array $rows): array
    {
        $cells = [];
        foreach ($rows as $row) {
            $dimensions = $row['dimensions'] ?? [];
            $splits = $row['dimension_splits'] ?? [];
            foreach ($splits as $type => $shares) {
                if (count($shares) === 1) {
                    $dimensions[$type] = (int) array_key_first($shares);
                    unset($splits[$type]);
                }
            }
            if (count($splits) > 1) {
                throw new ClosingException('system_dimension_allocation_ambiguous', 'Rozdělení více systémových dimenzí musí mít společné alokační řádky.', 422);
            }
            $cents = (int) round((float) $row['signed_amount'] * 100) * ($row['side'] === 'credit' ? 1 : -1);
            $pieces = [[$dimensions, $cents]];
            if ($splits !== []) {
                $type = array_key_first($splits);
                $shares = $splits[$type];
                ksort($shares);
                $amounts = DimensionStamper::distributeCents(abs($cents), array_values($shares));
                $pieces = [];
                foreach (array_keys($shares) as $index => $value) {
                    $pieces[] = [$dimensions + [$type => (int) $value], ($cents < 0 ? -1 : 1) * $amounts[$index]];
                }
            }
            foreach ($pieces as [$dims, $amount]) {
                ksort($dims);
                $metadata = ['account_code' => $row['account_code'], 'dimensions' => $dims,
                    'cost_center' => $row['cost_center'] ?? null, 'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null];
                $key = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR));
                $cells[$key] ??= $metadata + ['key' => $key, 'available_cents' => 0, 'source_line_ids' => []];
                $cells[$key]['available_cents'] += $amount;
                $cells[$key]['source_line_ids'][] = (int) $row['id'];
            }
        }
        ksort($cells);
        foreach ($cells as $key => &$cell) {
            if ($cell['available_cents'] === 0) { unset($cells[$key]); continue; }
            $cell['available'] = $cell['available_cents'] / 100;
            $cell['source_line_ids'] = array_values(array_unique($cell['source_line_ids']));
            sort($cell['source_line_ids']);
        }
        unset($cell);
        return array_values($cells);
    }

    public static function snapshot(array $cells): string
    {
        return hash('sha256', json_encode($cells, JSON_THROW_ON_ERROR));
    }

    public static function allocationCents(mixed $raw): int
    {
        $invalid = static fn (): ClosingException => new ClosingException('validation_failed', 'Částka přídělu musí být kladné desetinné číslo v celých haléřích a rozsahu účetního deníku.', 422);
        if (is_float($raw)) {
            if (!is_finite($raw)) throw $invalid();
            $text = sprintf('%.2F', $raw);
            if ((float) $text !== $raw) throw $invalid();
        } elseif (is_int($raw) || is_string($raw)) {
            $text = (string) $raw;
        } else {
            throw $invalid();
        }
        if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $text, $parts)) throw $invalid();
        $whole = ltrim($parts[1], '0');
        $fraction = rtrim($parts[2] ?? '', '0');
        if (strlen($whole) > 13 || strlen($fraction) > 2) throw $invalid();
        $cents = (int) (($whole === '' ? '0' : $whole) . str_pad($fraction, 2, '0'));
        if ($cents <= 0) throw $invalid();
        return $cents;
    }

    public static function resourceAccounts(array $cell, string $accountPrefix, array $resources): array
    {
        return array_values(array_filter($resources, static fn (array $resource): bool =>
            str_starts_with($resource['account_code'], $accountPrefix)
            && $resource['available_cents'] > 0
            && $resource['key'] === self::resourceKey($resource['account_code'], $cell)));
    }

    private static function resourceKey(string $accountCode, array $cell): string
    {
        return hash('sha256', json_encode(['account_code' => $accountCode]
            + array_intersect_key($cell, ['dimensions' => true, 'cost_center' => true, 'project_id' => true]), JSON_THROW_ON_ERROR));
    }

    public static function build(array $cells, array $body, AccountingRoleProfile $profile, array $resources = []): array
    {
        if ($profile->framework !== 'cz_504' || !is_array($body['allocations'] ?? null)) {
            throw new ClosingException('validation_failed', 'Neplatné podklady rozhodnutí o výsledku hospodaření.', 422);
        }
        if (!is_string($body['snapshot_hash'] ?? null) || !hash_equals(self::snapshot($cells), $body['snapshot_hash'])) {
            throw new ClosingException('result_decision_stale', 'Podklady rozhodnutí o výsledku se změnily. Načtěte náhled znovu.', 409);
        }
        $byKey = array_column($cells, null, 'key');
        $used = [];
        $resourceUsed = [];
        $resourceBalances = array_column($resources, 'available_cents', 'key');
        $lines = [];
        foreach ($body['allocations'] ?? [] as $allocation) {
            $key = (string) ($allocation['cell_key'] ?? '');
            $role = (string) ($allocation['role'] ?? '');
            $raw = $allocation['amount'] ?? null;
            if (!isset($byKey[$key]) || !in_array($role, self::TARGET_ROLES, true)) {
                throw new ClosingException('validation_failed', 'Neplatný příděl výsledku hospodaření.', 422);
            }
            $amount = self::allocationCents($raw);
            $cell = $byKey[$key];
            $used[$key] = ($used[$key] ?? 0) + $amount;
            $metadata = array_intersect_key($cell, ['dimensions' => true, 'cost_center' => true, 'project_id' => true]);
            foreach ($cell['dimensions'] as $type => $value) {
                $metadata['dimension_origins'][$type] = ['origin' => 'module', 'ref' => 'result_decision:' . substr(self::snapshot($cells), 0, 32)];
            }
            $profit = $cell['available_cents'] > 0;
            $defaultAccount = $profile->account($role);
            $account = $defaultAccount;
            if (array_key_exists('account_code', $allocation)) {
                if (!is_string($allocation['account_code']) || !str_starts_with($allocation['account_code'], $defaultAccount)) {
                    throw new ClosingException('validation_failed', 'Neplatný příděl výsledku hospodaření.', 422);
                }
                $account = $allocation['account_code'];
            } elseif (!$profit && $role !== 'result.retained_profit') {
                $availableAccounts = self::resourceAccounts($cell, $defaultAccount, $resources);
                if (count($availableAccounts) > 1) {
                    throw new ClosingException('result_decision_resource_required', 'Zdroj krytí ztráty má více analytických účtů. Vyberte konkrétní účet.', 422);
                }
                if ($availableAccounts !== []) $account = $availableAccounts[0]['account_code'];
            }
            if (!$profit && $role !== 'result.retained_profit') {
                $resourceKey = self::resourceKey($account, $cell);
                $resourceUsed[$resourceKey] = ($resourceUsed[$resourceKey] ?? 0) + $amount;
                if ($resourceUsed[$resourceKey] > max(0, $resourceBalances[$resourceKey] ?? 0)) {
                    throw new ClosingException('insufficient_distributable_resources', 'Zdroj krytí ztráty nemá dostatečný zůstatek ve zvolené kombinaci dimenzí.', 422);
                }
            }
            $lines[] = ['account_code' => $cell['account_code'], 'side' => $profit ? 'debit' : 'credit', 'amount' => $amount / 100] + $metadata;
            $lines[] = ['account_code' => $account, 'side' => $profit ? 'credit' : 'debit', 'amount' => $amount / 100] + $metadata;
        }
        foreach ($cells as $cell) {
            if (($used[$cell['key']] ?? 0) !== abs($cell['available_cents'])) {
                throw new ClosingException('distribution_mismatch', 'Příděly musí přesně vypořádat každou kombinaci dimenzí výsledku hospodaření.', 422);
            }
        }
        if ($lines === []) throw new ClosingException('distribution_mismatch', 'Není k dispozici výsledek hospodaření k vypořádání.', 422);
        return $lines;
    }
}
