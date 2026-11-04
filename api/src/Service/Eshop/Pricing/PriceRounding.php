<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Service\Invoice\UnitPricePrecision;

/**
 * Zaokrouhlování prodejní ceny (Epic ESHOP, cenotvorba).
 *
 * Money-safe: pracuje v celých jednotkách (žádný float). Vstup i výstup je
 * string s cenou bez DPH.
 *
 * Módy (sloupec stock_item_prices.rounding):
 *  - none      : jen normalizace na přesnost jednotkové ceny (half-up na šest míst),
 *                výstup ve tvaru {@see UnitPricePrecision::toDecimalString} — dvě místa,
 *                víc jen u ceny pod haléř
 *  - 0.01      : na halíř
 *  - 0.10      : na desetihalíř
 *  - 0.50      : na půlkorunu
 *  - 1         : na celou korunu
 *  - 9_ending  : psychologická cena — celé číslo končící na 9 (…9 Kč), .00 halířů
 */
final class PriceRounding
{
    /** Zaokrouhlí cenu $value dle módu $mode; explicitní módy vrací string s 2 des. místy. */
    public static function apply(string $value, string $mode): string
    {
        if ($mode === 'none') {
            return self::toUnitPrecision($value);
        }
        $cents = self::toCents($value);
        if ($cents <= 0) {
            return '0.00';
        }

        $cents = match ($mode) {
            '0.01'         => $cents,
            '0.10'         => self::roundToStep($cents, 10),
            '0.50'         => self::roundToStep($cents, 50),
            '1'            => self::roundToStep($cents, 100),
            '9_ending'     => self::nineEnding($cents),
            default        => $cents,
        };

        // cents → "X.YY"
        $whole = intdiv($cents, 100);
        $frac = $cents % 100;
        return $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }

    /** Cena na šest desetinných míst (half-up); nekladná cena = „0.00" jako u ostatních módů. */
    private static function toUnitPrecision(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return '0.00';
        }
        $scale = bcpow('10', (string) UnitPricePrecision::DECIMALS);
        $micro = bcadd(bcmul($value, $scale, 8), '0.5', 0);
        if (bccomp($micro, '0', 0) <= 0) {
            return '0.00';
        }
        return (string) UnitPricePrecision::toDecimalString(bcdiv($micro, $scale, UnitPricePrecision::DECIMALS));
    }

    /** Cena (string, libovolná přesnost) → halíře s half-up zaokrouhlením. */
    private static function toCents(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
            return 0;
        }
        $value = str_replace(',', '.', $value);
        // bcmath: *100, pak +0.5 a truncate na scale 0 = round half-up (pro kladná).
        $scaled = bcmul($value, '100', 6);
        if (str_starts_with($scaled, '-')) {
            return (int) bcsub($scaled, '0.5', 0);
        }
        return (int) bcadd($scaled, '0.5', 0);
    }

    /** Nejbližší násobek $step halířů (half-up). */
    private static function roundToStep(int $cents, int $step): int
    {
        $r = $cents % $step;
        if ($r * 2 >= $step) {
            return $cents + ($step - $r);
        }
        return $cents - $r;
    }

    /**
     * Psychologická cena: celé číslo (Kč) končící na 9. Zaokrouhlí na celou
     * korunu, pak vybere nejbližší …9 (při shodě nahoru — vstřícné k prodejci).
     */
    private static function nineEnding(int $cents): int
    {
        $whole = intdiv(self::roundToStep($cents, 100), 100);
        $mod = (($whole - 9) % 10 + 10) % 10; // vzdálenost nad nejbližší …9
        $lower = $whole - $mod;
        $upper = $lower + 10;
        $chosen = ($whole - $lower) < ($upper - $whole) ? $lower : $upper;
        if ($chosen < 9) {
            $chosen = 9; // nejmenší smysluplná …9 cena
        }
        return $chosen * 100;
    }
}
