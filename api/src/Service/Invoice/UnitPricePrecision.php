<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Přesnost jednotkové ceny řádku dokladu (vydaná a přijatá faktura, šablona
 * pravidelné fakturace). Cena se ukládá na šest desetinných míst, aby levné
 * položky odebírané po statisících kusů (0,004 EUR/ks) ani hodinové sazby
 * neztratily hodnotu. Součty řádků, DPH a dokladu zůstávají v haléřích.
 *
 * Výstupy (PDF, ISDOC, XML exporty) ukazují víc než dvě místa jen u ceny,
 * která je skutečně má, takže doklady s cenou v haléřích vypadají beze změny.
 */
final class UnitPricePrecision
{
    public const DECIMALS = 6;
    public const MIN_DISPLAY_DECIMALS = 2;

    public static function normalize(float $price): float
    {
        return round($price, self::DECIMALS);
    }

    public static function hasSubCentPrecision(float $price): bool
    {
        return abs($price - round($price, self::MIN_DISPLAY_DECIMALS)) > 0.0000001;
    }

    public static function decimalPlaces(float $price): int
    {
        $fraction = rtrim(substr(number_format(abs($price), self::DECIMALS, '.', ''), -self::DECIMALS), '0');
        return max(self::MIN_DISPLAY_DECIMALS, strlen($fraction));
    }
}
