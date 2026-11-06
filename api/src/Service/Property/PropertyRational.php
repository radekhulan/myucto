<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyRational
{
    public static function normalize(array $input): array
    {
        if (count($input) !== 2 || !isset($input['num'], $input['den'])) self::invalid();
        foreach ($input as $value) {
            if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]{0,17})$/D', (string) $value)) self::invalid();
        }
        if ((string) $input['den'] === '0') self::invalid();
        return self::reduce((string) $input['num'], (string) $input['den']);
    }

    public static function sum(array $values): array
    {
        $sum = ['num' => '0', 'den' => '1'];
        foreach ($values as $value) {
            $value = self::normalize($value);
            $sum = self::reduce(bcadd(bcmul($sum['num'], $value['den'], 0), bcmul($value['num'], $sum['den'], 0), 0), bcmul($sum['den'], $value['den'], 0));
        }
        return $sum;
    }

    public static function compare(array $a, array $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        return bccomp(bcmul($a['num'], $b['den'], 0), bcmul($b['num'], $a['den'], 0), 0);
    }

    private static function reduce(string $num, string $den): array
    {
        $a = $num;
        $b = $den;
        while ($b !== '0') [$a, $b] = [$b, bcmod($a, $b, 0)];
        return ['num' => bcdiv($num, $a, 0), 'den' => bcdiv($den, $a, 0)];
    }

    private static function invalid(): never
    {
        throw new PropertyException('property_invalid_fraction', 'Podíl vyžaduje nezáporný celočíselný čitatel a kladný jmenovatel.');
    }
}
