<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Charges;

final class ExactFraction
{
    public static function normalize(array $fraction, bool $signed = false): array
    {
        if (count($fraction) !== 2 || !isset($fraction['num'], $fraction['den'])) throw new \InvalidArgumentException('A fraction requires numerator and denominator.');
        $pattern = $signed ? '/^-?(0|[1-9][0-9]*)$/D' : '/^(0|[1-9][0-9]*)$/D';
        if ((!is_string($fraction['num']) && !is_int($fraction['num'])) || !preg_match($pattern, (string) $fraction['num'])
            || (!is_string($fraction['den']) && !is_int($fraction['den'])) || !preg_match('/^[1-9][0-9]*$/D', (string) $fraction['den'])) throw new \InvalidArgumentException('A fraction requires an integer numerator and positive denominator.');
        return self::reduce((string) $fraction['num'], (string) $fraction['den']);
    }

    public static function reduce(string $num, string $den): array
    {
        $a = ltrim($num, '-'); $b = $den;
        while ($b !== '0') [$a, $b] = [$b, bcmod($a, $b, 0)];
        return ['num' => bcdiv($num, $a, 0), 'den' => bcdiv($den, $a, 0)];
    }
    public static function add(array $a, array $b): array
    {
        return self::reduce(bcadd(bcmul($a['num'], $b['den'], 0), bcmul($b['num'], $a['den'], 0), 0), bcmul($a['den'], $b['den'], 0));
    }
    public static function multiply(array $a, array $b): array
    {
        return self::reduce(bcmul($a['num'], $b['num'], 0), bcmul($a['den'], $b['den'], 0));
    }
    public static function decimalCents(mixed $value): array
    {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $value)) {
            throw new \InvalidArgumentException('Amount requires an exact decimal string.');
        }
        $parts = explode('.', $value);
        $den = '1' . str_repeat('0', strlen($parts[1] ?? ''));
        return self::reduce(bcmul($parts[0] . ($parts[1] ?? ''), '100', 0), $den);
    }
    public static function round(array $a): string
    {
        $negative = str_starts_with($a['num'], '-');
        $num = ltrim($a['num'], '-');
        $floor = bcdiv($num, $a['den'], 0);
        if (bccomp(bcmul(bcmod($num, $a['den'], 0), '2', 0), $a['den'], 0) >= 0) $floor = bcadd($floor, '1', 0);
        return $negative && $floor !== '0' ? '-' . $floor : $floor;
    }
}
