<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;
use OverflowException;

final class PayrollRounding
{
    public static function ceilToCzk(int $minorUnits): int
    {
        return self::ceilToMultiple($minorUnits, 100);
    }

    public static function ceilToHundredCzk(int $minorUnits): int
    {
        return self::ceilToMultiple($minorUnits, 10_000);
    }

    public static function healthMinimumTopUp(
        int $standardContribution,
        int $minimumContribution,
    ): int {
        if ($standardContribution < 0 || $minimumContribution < 0) {
            throw new InvalidArgumentException(
                'Health insurance contributions must be non-negative.',
            );
        }

        return max(0, $minimumContribution - $standardContribution);
    }

    public static function ceilToMultiple(int $value, int $multiple): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Payroll statutory rounding expects a non-negative amount.');
        }
        if ($multiple <= 0) {
            throw new InvalidArgumentException('Payroll rounding multiple must be positive.');
        }

        $remainder = $value % $multiple;
        if ($remainder === 0) {
            return $value;
        }

        $increment = $multiple - $remainder;
        if ($value > PHP_INT_MAX - $increment) {
            throw new OverflowException('Payroll rounding exceeds the integer range.');
        }

        return $value + $increment;
    }

    /**
     * @param non-empty-list<array{numerator:int,denominator:int}> $fractions
     */
    public static function ceilFractionSumToMultiple(array $fractions, int $multiple): int
    {
        if ($multiple <= 0) {
            throw new InvalidArgumentException('Payroll rounding multiple must be positive.');
        }

        $commonDenominator = 1;
        foreach ($fractions as $fraction) {
            if ($fraction['numerator'] < 0 || $fraction['denominator'] <= 0) {
                throw new InvalidArgumentException('Payroll tax fractions must be non-negative.');
            }
            $commonDenominator = self::leastCommonMultiple(
                $commonDenominator,
                $fraction['denominator'],
            );
        }

        $numerator = 0;
        foreach ($fractions as $fraction) {
            $factor = intdiv($commonDenominator, $fraction['denominator']);
            $scaled = self::multiplyExactly($fraction['numerator'], $factor);
            if ($numerator > PHP_INT_MAX - $scaled) {
                throw new OverflowException('Payroll fraction aggregation exceeds the integer range.');
            }
            $numerator += $scaled;
        }

        $roundingDenominator = self::multiplyExactly($commonDenominator, $multiple);
        $units = intdiv($numerator, $roundingDenominator);
        if ($numerator % $roundingDenominator !== 0) {
            if ($units === PHP_INT_MAX) {
                throw new OverflowException('Payroll fraction rounding exceeds the integer range.');
            }
            $units++;
        }

        return self::multiplyExactly($units, $multiple);
    }

    private static function leastCommonMultiple(int $left, int $right): int
    {
        $gcd = self::greatestCommonDivisor($left, $right);

        return self::multiplyExactly(intdiv($left, $gcd), $right);
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException('Payroll rounding multiplication expects non-negative integers.');
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OverflowException('Payroll rounding multiplication exceeds the integer range.');
        }

        return $left * $right;
    }
}
