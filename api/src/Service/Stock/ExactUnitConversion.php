<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

final class ExactUnitConversion
{
    public static function reduce(int $numerator, int $denominator): array
    {
        if ($numerator <= 0 || $denominator <= 0 || $numerator > 1_000_000 || $denominator > 1_000_000) {
            throw new StockException('invalid_unit_ratio', 'Převod jednotky musí být kladný přesný poměr do 1 000 000.', 422);
        }
        $a = $numerator; $b = $denominator;
        while ($b !== 0) { [$a, $b] = [$b, $a % $b]; }
        return [intdiv($numerator, $a), intdiv($denominator, $a)];
    }

    public static function toBaseT(string $quantity, int $numerator, int $denominator): int
    {
        if (!preg_match('/^([0-9]{1,11})(?:\.([0-9]{1,3}))?$/D', trim($quantity), $m)) {
            throw new StockException('invalid_quantity', 'Množství musí být nezáporné číslo s nejvýše třemi desetinnými místy.', 422);
        }
        $scale = str_pad($m[2] ?? '', 3, '0');
        $inputT = ((int) $m[1] * 1000) + (int) $scale;
        if ($inputT > intdiv(PHP_INT_MAX, $numerator)) {
            throw new StockException('invalid_quantity', 'Množství je příliš vysoké.', 422);
        }
        $product = $inputT * $numerator;
        if ($product % $denominator !== 0) {
            throw new StockException('inexact_unit_conversion', 'Množství nelze převést přesně na tisíciny základní jednotky.', 422);
        }
        return intdiv($product, $denominator);
    }
}
