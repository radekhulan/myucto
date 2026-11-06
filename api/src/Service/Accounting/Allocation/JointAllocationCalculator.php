<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

final class JointAllocationCalculator
{
    public const ROUNDING_POLICY = 'half_up_largest_weight_v1';
    public const AXES = ['activity', 'activity_kind', 'tax_regime'];
    public const MAX_CELLS = 200;

    public static function allocate(string $amount, array $cells): array
    {
        $amount = self::amount($amount);
        $cells = self::cells($cells);
        $allocation = self::apportion($amount, array_column($cells, 'weight'));
        foreach ($cells as $index => &$cell) {
            $cell += $allocation['parts'][$index];
        }
        unset($cell);
        unset($allocation['parts']);
        return $allocation + ['cells' => $cells];
    }

    public static function apportion(string $amount, array $weights): array
    {
        $amount = self::amount($amount);
        if (!array_is_list($weights) || $weights === []) {
            throw new JointAllocationException('allocation_invalid_weight', 'Zadejte neprázdný seznam vah v rozhodném pořadí.');
        }
        $weights = array_map(self::weight(...), $weights);
        $negative = bccomp($amount, '0', 2) < 0;
        $units = bcmul(ltrim($amount, '-'), '100', 0);
        $sum = '0.000000';
        foreach ($weights as $weight) {
            $sum = bcadd($sum, $weight, 6);
        }
        $assigned = '0';
        $parts = [];
        foreach ($weights as $weight) {
            $part = bcadd(bcdiv(bcmul($units, $weight, 6), $sum, 1), '0.5', 0);
            $parts[] = $part;
            $assigned = bcadd($assigned, $part, 0);
        }
        $rounded = $parts;
        $residual = bcsub($units, $assigned, 0);
        $priority = array_keys($weights);
        usort($priority, static fn (int $a, int $b): int => bccomp($weights[$b], $weights[$a], 6) ?: $a <=> $b);
        if (bccomp($residual, '0', 0) > 0) {
            $parts[$priority[0]] = bcadd($parts[$priority[0]], $residual, 0);
        } elseif (bccomp($residual, '0', 0) < 0) {
            $remaining = bcsub('0', $residual, 0);
            foreach ($priority as $index) {
                $deduction = bccomp($parts[$index], $remaining, 0) < 0 ? $parts[$index] : $remaining;
                $parts[$index] = bcsub($parts[$index], $deduction, 0);
                $remaining = bcsub($remaining, $deduction, 0);
                if ($remaining === '0') {
                    break;
                }
            }
        }
        $result = [];
        foreach ($parts as $index => $part) {
            $result[] = [
                'amount' => self::fromUnits($part, $negative),
                'rounded_amount' => self::fromUnits($rounded[$index], $negative),
                'adjustment' => self::fromUnits(bcsub($part, $rounded[$index], 0), $negative),
            ];
        }
        return [
            'amount' => $amount,
            'weight_total' => $sum,
            'rounding_policy' => self::ROUNDING_POLICY,
            'residual' => self::fromUnits($residual, $negative),
            'parts' => $result,
        ];
    }

    public static function amount(string $amount): string
    {
        if (!preg_match('/^-?(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/D', $amount)) {
            throw new JointAllocationException('allocation_invalid_amount', 'Částka musí být desetinné číslo nejvýše na dvě místa.');
        }
        return bcadd($amount, '0', 2);
    }

    public static function cells(mixed $cells): array
    {
        if (!is_array($cells) || !array_is_list($cells) || $cells === [] || count($cells) > self::MAX_CELLS) {
            throw new JointAllocationException('allocation_invalid_cells', 'Zadejte neprázdný seznam společných alokačních kombinací.');
        }
        $out = [];
        $seen = [];
        $additionalTypes = null;
        foreach ($cells as $index => $cell) {
            if (!is_array($cell)) {
                throw new JointAllocationException('allocation_invalid_cells', 'Alokační kombinace musí obsahovat všechny tři osy.');
            }
            $normalized = [];
            foreach (self::AXES as $axis) {
                if (!isset($cell[$axis]) || !is_int($cell[$axis]) || $cell[$axis] <= 0) {
                    throw new JointAllocationException('allocation_invalid_cells', 'Alokační kombinace musí obsahovat všechny tři osy.', details: ['cell' => $index, 'axis' => $axis]);
                }
                $normalized[$axis] = $cell[$axis];
            }
            $additional = $cell['additional_dimensions'] ?? [];
            if (!is_array($additional) || count($additional) > 20) {
                throw new JointAllocationException('allocation_invalid_cells', 'Další osy musí být mapou typů a hodnot.');
            }
            foreach ($additional as $typeId => $valueId) {
                if (!is_int($typeId) || $typeId <= 0 || !is_int($valueId) || $valueId <= 0) {
                    throw new JointAllocationException('allocation_invalid_cells', 'Typ a hodnota další osy musí být kladné identifikátory.');
                }
            }
            ksort($additional, SORT_NUMERIC);
            $additionalTypes ??= array_keys($additional);
            if ($additionalTypes !== array_keys($additional)) {
                throw new JointAllocationException('allocation_invalid_cells', 'Každá buňka musí obsahovat stejné úplné osy.');
            }
            if ($additional !== []) $normalized['additional_dimensions'] = $additional;
            $tuple = self::cellId($normalized);
            if (isset($seen[$tuple])) {
                throw new JointAllocationException('allocation_duplicate_cell', 'Společná alokační kombinace se opakuje.', details: ['cell' => $index]);
            }
            $seen[$tuple] = true;
            $weight = $cell['weight'] ?? null;
            $normalized['weight'] = self::weight($weight);
            $out[] = $normalized;
        }
        usort($out, static function (array $a, array $b): int {
            foreach (self::AXES as $axis) {
                if ($a[$axis] !== $b[$axis]) {
                    return $a[$axis] <=> $b[$axis];
                }
            }
            return array_values($a['additional_dimensions'] ?? []) <=> array_values($b['additional_dimensions'] ?? []);
        });
        return $out;
    }

    public static function cellId(array $cell): string
    {
        $tuple = array_intersect_key($cell, array_flip(self::AXES));
        $additional = $cell['additional_dimensions'] ?? [];
        ksort($additional, SORT_NUMERIC);
        $tuple['additional_dimensions'] = $additional;
        return JointAllocationKeyVersion::hash($tuple);
    }

    private static function fromUnits(string $units, bool $negative): string
    {
        return bcdiv($negative ? bcsub('0', $units, 0) : $units, '100', 2);
    }

    private static function weight(mixed $weight): string
    {
        if (!is_string($weight) || !preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D', $weight) || bccomp($weight, '0', 6) <= 0) {
            throw new JointAllocationException('allocation_invalid_weight', 'Váha musí být kladné desetinné číslo nejvýše na šest míst.');
        }
        return bcadd($weight, '0', 6);
    }
}
