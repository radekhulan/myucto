<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;

/** Nezáporná částka v haléřích; mezi úseky nároku se nezaokrouhluje. */
final readonly class PayrollExactFraction
{
    public int $numerator;
    public int $denominator;

    public function __construct(int $numerator, int $denominator = 1)
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new \InvalidArgumentException('Zlomek nároku musí být nezáporný s kladným jmenovatelem.');
        }
        $gcd = self::gcd($numerator, $denominator);
        $this->numerator = intdiv($numerator, $gcd);
        $this->denominator = intdiv($denominator, $gcd);
    }

    /** @param list<int> $numerators @param list<int> $denominators */
    public static function product(array $numerators, array $denominators): self
    {
        foreach ($numerators as $value) {
            if ($value < 0) throw new \InvalidArgumentException('Činitel nároku nesmí být záporný.');
        }
        foreach ($denominators as $value) {
            if ($value <= 0) throw new \InvalidArgumentException('Dělitel nároku musí být kladný.');
        }
        foreach ($numerators as &$numerator) {
            foreach ($denominators as &$denominator) {
                $gcd = self::gcd($numerator, $denominator);
                $numerator = intdiv($numerator, $gcd);
                $denominator = intdiv($denominator, $gcd);
            }
            unset($denominator);
        }
        unset($numerator);
        $n = $d = 1;
        foreach ($numerators as $value) $n = self::multiply($n, $value);
        foreach ($denominators as $value) $d = self::multiply($d, $value);
        return new self($n, $d);
    }

    public function plus(self $other): self
    {
        $gcd = self::gcd($this->denominator, $other->denominator);
        $left = self::multiply($this->numerator, intdiv($other->denominator, $gcd));
        $right = self::multiply($other->numerator, intdiv($this->denominator, $gcd));
        if ($left > PHP_INT_MAX - $right) throw new \OverflowException('Součet nároků překročil celočíselný rozsah.');
        return new self($left + $right, self::multiply($this->denominator, intdiv($other->denominator, $gcd)));
    }

    public function minus(self $other): self
    {
        $gcd = self::gcd($this->denominator, $other->denominator);
        $left = self::multiply($this->numerator, intdiv($other->denominator, $gcd));
        $right = self::multiply($other->numerator, intdiv($this->denominator, $gcd));
        if ($left < $right) throw new \InvalidArgumentException('Odpočet převyšuje nárok.');
        return new self($left - $right, self::multiply($this->denominator, intdiv($other->denominator, $gcd)));
    }

    public function compare(self $other): int
    {
        // Rozdělení na celou část a zbytek omezuje velikost násobenců.
        $whole = intdiv($this->numerator, $this->denominator) <=> intdiv($other->numerator, $other->denominator);
        if ($whole !== 0) return $whole;
        $gcd = self::gcd($this->denominator, $other->denominator);
        return self::multiply($this->numerator % $this->denominator, intdiv($other->denominator, $gcd))
            <=> self::multiply($other->numerator % $other->denominator, intdiv($this->denominator, $gcd));
    }

    public function analyticalMinor(): int
    {
        return RoundingMode::HalfUp->roundFraction($this->numerator, $this->denominator);
    }

    public function payableMinor(PayrollWageRounding $rounding): int
    {
        $czk = self::product([$this->numerator], [$this->denominator, 100]);
        return self::multiply($rounding->mode()->roundFraction($czk->numerator, $czk->denominator), 100);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) [$a, $b] = [$b, $a % $b];
        return $a;
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) throw new \OverflowException('Nárok překročil celočíselný rozsah.');
        return $a * $b;
    }
}
