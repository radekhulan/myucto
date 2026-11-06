<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;

/**
 * Porovnání převzatých počátečních stavů s vypočtenými — ČISTÁ třída bez DB.
 *
 * Následující rok může mít otevírací zápis, který nevznikl otevřením roku v průvodci
 * (převod z jiného systému, ručně zadaná otevírací rozvaha). Otevření roku ho proto
 * nezakládá znovu, ale srovná účet po účtu s tím, co by samo zaúčtovalo
 * ({@see ClosingEntryBuilder::openingLines}). Při shodě převzatý zápis platí, při
 * rozdílu se nepokračuje bez výslovného rozhodnutí účetní.
 *
 * Klíč účtu: rozvažné účty 70x se vynechávají (v obou zápisech končí na nule, liší se
 * jen tím, jak kdo řádky párovat), 431 se srovnává za syntetiku — průvodce dává VH na
 * `431`, jiný systém ho často vede na analytice.
 */
final class OpeningTakeoverComparison
{
    public static function accountKey(string $code, ?AccountingRoleProfile $profile = null): ?string
    {
        $profile ??= new AccountingRoleProfile();
        if ($profile->isClosingAccount($code)) {
            return null;
        }
        return str_starts_with($code, $profile->account('result.pending_approval'))
            ? $profile->account('result.pending_approval')
            : $code;
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $expectedLines řádky vypočteného otevíracího zápisu
     * @param array<string,float> $existingBalances účet => signed netto převzatých zápisů (MD kladně)
     * @return array{diff: list<array{account_code:string, expected:float, existing:float, difference:float}>, accounts:int}
     */
    public static function compare(array $expectedLines, array $existingBalances, ?AccountingRoleProfile $profile = null): array
    {
        $expected = [];
        foreach ($expectedLines as $line) {
            $key = self::accountKey((string) $line['account_code'], $profile);
            if ($key === null) {
                continue;
            }
            $cents = self::cents((float) $line['amount']);
            $expected[$key] = ($expected[$key] ?? 0) + ($line['side'] === 'debit' ? $cents : -$cents);
        }

        $existing = [];
        foreach ($existingBalances as $code => $balance) {
            $key = self::accountKey((string) $code, $profile);
            if ($key !== null) {
                $existing[$key] = ($existing[$key] ?? 0) + self::cents((float) $balance);
            }
        }

        $keys = array_map('strval', array_unique(array_merge(array_keys($expected), array_keys($existing))));
        sort($keys, SORT_STRING);

        $diff = [];
        foreach ($keys as $key) {
            $mine = $expected[$key] ?? 0;
            $theirs = $existing[$key] ?? 0;
            if ($mine !== $theirs) {
                $diff[] = [
                    'account_code' => $key,
                    'expected' => $mine / 100,
                    'existing' => $theirs / 100,
                    'difference' => ($mine - $theirs) / 100,
                ];
            }
        }

        return [
            'diff' => $diff,
            'accounts' => count(array_filter($expected, static fn (int $c): bool => $c !== 0)),
        ];
    }

    public static function compareWithDimensions(array $expectedLines, array $existingBalances, array $linkedDimensionTypes = [], ?AccountingRoleProfile $profile = null): array
    {
        $existingLines = [];
        $totals = [];
        foreach ($existingBalances as $row) {
            $code = (string) $row['account_code'];
            $totals[$code] = ($totals[$code] ?? 0.0) + (float) $row['bal'];
            $existingLines[] = [
                'account_code' => $code,
                'side' => $row['bal'] >= 0 ? 'debit' : 'credit',
                'amount' => abs((float) $row['bal']),
            ] + array_intersect_key($row, ['dimensions' => true, 'dimension_splits' => true, 'cost_center' => true, 'project_id' => true]);
        }
        $comparison = self::compare($expectedLines, $totals, $profile);
        if ($comparison['diff'] !== []) {
            return $comparison;
        }
        $expectedLines = self::allocatedLines($expectedLines, $linkedDimensionTypes, $profile);
        $existingLines = self::allocatedLines($existingLines, $linkedDimensionTypes, $profile);
        $expected = self::dimensionalRepresentation($expectedLines);
        $existing = self::dimensionalRepresentation($existingLines);
        $originalExpected = $expected;
        $originalExisting = $existing;
        self::cancelSame($expected['cells'], $existing['cells'], 'balance');
        self::cancelSame($expected['profiles'], $existing['profiles'], 'units');
        self::refineProfiles($expected['profiles'], $existing['cells']);
        self::refineProfiles($existing['profiles'], $expected['cells']);
        $comparison['diff'] = self::dimensionalDifferences($expected, $existing, $originalExpected, $originalExisting);
        return $comparison;
    }

    private static function allocatedLines(array $lines, array $linkedDimensionTypes, ?AccountingRoleProfile $roleProfile): array
    {
        $allocated = [];
        foreach ($lines as $line) {
            $code = self::accountKey((string) $line['account_code'], $roleProfile);
            if ($code === null) {
                continue;
            }
            $amount = self::cents((float) $line['amount']);
            if ($amount === 0) {
                continue;
            }
            $profile = [];
            foreach ((array) ($line['dimensions'] ?? []) as $typeId => $valueId) {
                $profile[(int) $typeId] = [(int) $valueId => $amount];
            }
            foreach ((array) ($line['dimension_splits'] ?? []) as $typeId => $shares) {
                ksort($shares, SORT_NUMERIC);
                $parts = DimensionStamper::distributeCents($amount, array_values($shares));
                $values = array_filter(array_combine(array_keys($shares), $parts), static fn (int $part): bool => $part !== 0);
                $profile[(int) $typeId] = $values;
            }
            ksort($profile, SORT_NUMERIC);
            $costCenter = trim((string) ($line['cost_center'] ?? ''));
            $projectId = (int) ($line['project_id'] ?? 0);
            foreach (array_keys($profile) as $typeId) {
                if (($linkedDimensionTypes[$typeId] ?? null) === 'cost_center') {
                    $costCenter = '';
                }
                if (($linkedDimensionTypes[$typeId] ?? null) === 'project') {
                    $projectId = 0;
                }
            }
            $metadata = [
                'account_code' => $code,
                'dimension_types' => array_keys($profile),
                'cost_center' => $costCenter === '' ? null : $costCenter,
                'project_id' => $projectId > 0 ? $projectId : null,
            ];
            $allocated[] = [
                'context' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'metadata' => $metadata,
                'allocations' => $profile,
                'amount' => $amount,
                'sign' => $line['side'] === 'debit' ? 1 : -1,
            ];
        }
        return $allocated;
    }

    private static function dimensionalRepresentation(array $lines): array
    {
        $cells = [];
        $profiles = [];
        foreach ($lines as $line) {
            $splitTypes = count(array_filter($line['allocations'], static fn (array $parts): bool => count($parts) > 1));
            if ($splitTypes > 1) {
                $divisor = $line['amount'];
                foreach ($line['allocations'] as $parts) {
                    foreach ($parts as $part) {
                        $divisor = self::gcd($divisor, $part);
                    }
                }
                $allocations = [];
                foreach ($line['allocations'] as $typeId => $parts) {
                    foreach ($parts as $valueId => $part) {
                        $allocations[$typeId][$valueId] = intdiv($part, $divisor);
                    }
                }
                $profile = [
                    'context' => $line['context'],
                    'metadata' => $line['metadata'],
                    'allocations' => $allocations,
                    'amount' => intdiv($line['amount'], $divisor),
                ];
                $key = json_encode([
                    'context' => $profile['context'],
                    'allocations' => $profile['allocations'],
                    'amount' => $profile['amount'],
                ], JSON_THROW_ON_ERROR);
                $profiles[$key] ??= $profile + ['units' => 0];
                $profiles[$key]['units'] += $line['sign'] * $divisor;
                continue;
            }

            $fixed = [];
            $splitType = null;
            foreach ($line['allocations'] as $typeId => $parts) {
                if (count($parts) > 1) {
                    $splitType = $typeId;
                } else {
                    $fixed[$typeId] = array_key_first($parts);
                }
            }
            $lineCells = $splitType === null
                ? [['dimensions' => $fixed, 'amount' => $line['amount']]]
                : array_map(
                    static fn (int $amount, int $valueId): array => [
                        'dimensions' => $fixed + [$splitType => $valueId],
                        'amount' => $amount,
                    ],
                    $line['allocations'][$splitType],
                    array_keys($line['allocations'][$splitType]),
                );
            foreach ($lineCells as $cell) {
                ksort($cell['dimensions'], SORT_NUMERIC);
                $metadata = $line['metadata'] + ['dimensions' => $cell['dimensions']];
                $key = json_encode($metadata, JSON_THROW_ON_ERROR);
                $cells[$key] ??= $metadata + ['context' => $line['context'], 'balance' => 0];
                $cells[$key]['balance'] += $line['sign'] * $cell['amount'];
            }
        }
        return [
            'cells' => array_filter($cells, static fn (array $row): bool => $row['balance'] !== 0),
            'profiles' => array_filter($profiles, static fn (array $row): bool => $row['units'] !== 0),
        ];
    }

    private static function cancelSame(array &$expected, array &$existing, string $field): void
    {
        foreach (array_intersect(array_keys($expected), array_keys($existing)) as $key) {
            $mine = $expected[$key][$field];
            $theirs = $existing[$key][$field];
            if (($mine < 0) !== ($theirs < 0)) {
                continue;
            }
            $common = min(abs($mine), abs($theirs));
            $direction = $mine < 0 ? -1 : 1;
            $expected[$key][$field] -= $direction * $common;
            $existing[$key][$field] -= $direction * $common;
            if ($expected[$key][$field] === 0) {
                unset($expected[$key]);
            }
            if ($existing[$key][$field] === 0) {
                unset($existing[$key]);
            }
        }
    }

    private static function refineProfiles(array &$profiles, array &$cells): void
    {
        $profileGroups = [];
        foreach ($profiles as $key => $profile) {
            $profileGroups[$profile['context'] . '|' . ($profile['units'] < 0 ? '-' : '+')][] = $key;
        }
        $cellGroups = [];
        foreach ($cells as $key => $cell) {
            $cellGroups[$cell['context'] . '|' . ($cell['balance'] < 0 ? '-' : '+')][] = $key;
        }
        foreach ($profileGroups as $group => $profileKeys) {
            $cellKeys = $cellGroups[$group] ?? [];
            if ($cellKeys === []) {
                continue;
            }
            $assigned = [];
            $valid = true;
            foreach ($cellKeys as $cellKey) {
                $candidates = [];
                foreach ($profileKeys as $profileKey) {
                    if (self::cellFitsProfile($cells[$cellKey], $profiles[$profileKey])) {
                        $candidates[] = $profileKey;
                    }
                }
                if (count($candidates) !== 1) {
                    $valid = false;
                    break;
                }
                $assigned[$candidates[0]][] = $cellKey;
            }
            if (!$valid || count($assigned) !== count($profileKeys)) {
                continue;
            }
            foreach ($profileKeys as $profileKey) {
                if (!self::cellsMatchProfile($cells, $assigned[$profileKey] ?? [], $profiles[$profileKey])) {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                continue;
            }
            foreach ($profileKeys as $profileKey) {
                foreach ($assigned[$profileKey] as $cellKey) {
                    unset($cells[$cellKey]);
                }
                unset($profiles[$profileKey]);
            }
        }
    }

    private static function cellFitsProfile(array $cell, array $profile): bool
    {
        if (array_keys($cell['dimensions']) !== array_keys($profile['allocations'])) {
            return false;
        }
        foreach ($cell['dimensions'] as $typeId => $valueId) {
            if (!isset($profile['allocations'][$typeId][$valueId])) {
                return false;
            }
        }
        return true;
    }

    private static function cellsMatchProfile(array $cells, array $cellKeys, array $profile): bool
    {
        $units = abs($profile['units']);
        $total = 0;
        $marginals = [];
        foreach ($cellKeys as $cellKey) {
            $amount = abs($cells[$cellKey]['balance']);
            $total += $amount;
            foreach ($cells[$cellKey]['dimensions'] as $typeId => $valueId) {
                $marginals[$typeId][$valueId] = ($marginals[$typeId][$valueId] ?? 0) + $amount;
            }
        }
        $expected = [];
        foreach ($profile['allocations'] as $typeId => $parts) {
            foreach ($parts as $valueId => $part) {
                $expected[$typeId][$valueId] = $units * $part;
            }
        }
        foreach ($marginals as &$parts) {
            ksort($parts, SORT_NUMERIC);
        }
        unset($parts);
        ksort($marginals, SORT_NUMERIC);
        foreach ($expected as &$parts) {
            ksort($parts, SORT_NUMERIC);
        }
        unset($parts);
        ksort($expected, SORT_NUMERIC);
        return $total === $units * $profile['amount'] && $marginals === $expected;
    }

    private static function dimensionalDifferences(array $expected, array $existing, array $originalExpected, array $originalExisting): array
    {
        $balances = [];
        self::appendResiduals($balances, $expected, 'expected');
        self::appendResiduals($balances, $existing, 'existing');
        $originalBalances = [];
        self::appendResiduals($originalBalances, $originalExpected, 'expected');
        self::appendResiduals($originalBalances, $originalExisting, 'existing');
        ksort($balances, SORT_STRING);
        $diff = [];
        foreach ($balances as $key => $row) {
            $mine = $row['expected'] ?? 0;
            $theirs = $row['existing'] ?? 0;
            if ($mine === $theirs) {
                continue;
            }
            $mine = $originalBalances[$key]['expected'] ?? 0;
            $theirs = $originalBalances[$key]['existing'] ?? 0;
            unset($row['expected'], $row['existing']);
            $diff[] = $row + [
                'expected' => $mine / 100,
                'existing' => $theirs / 100,
                'difference' => ($mine - $theirs) / 100,
            ];
        }
        return $diff;
    }

    private static function appendResiduals(array &$balances, array $representation, string $side): void
    {
        foreach ($representation['cells'] as $cell) {
            $context = $cell;
            unset($context['context'], $context['balance']);
            $key = json_encode($context, JSON_THROW_ON_ERROR);
            $balances[$key] ??= $context;
            $balances[$key][$side] = ($balances[$key][$side] ?? 0) + $cell['balance'];
        }
        foreach ($representation['profiles'] as $profile) {
            $context = $profile['metadata'] + [
                'dimension_allocations' => $profile['allocations'],
                'allocation_amount' => $profile['amount'],
            ];
            $key = json_encode($context, JSON_THROW_ON_ERROR);
            $balances[$key] ??= $context;
            $balances[$key][$side] = ($balances[$key][$side] ?? 0) + $profile['units'] * $profile['amount'];
        }
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return abs($a);
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
