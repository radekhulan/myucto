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

    /**
     * Cena z DECIMAL(16,6) pro API a formuláře: dvě místa, víc jen když je cena
     * skutečně má. „12.500000" → „12.50", „0.010800" → „0.0108". Nečíselná
     * hodnota a NULL projdou beze změny.
     */
    public static function toDecimalString(mixed $value): mixed
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $value;
        }
        $text = is_string($value) ? trim($value) : number_format((float) $value, self::DECIMALS, '.', '');
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $text, $m)) {
            $text = number_format((float) $value, self::DECIMALS, '.', '');
            preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $text, $m);
        }
        $fraction = rtrim(substr($m[3] ?? '', 0, self::DECIMALS), '0');
        $fraction = str_pad($fraction, self::MIN_DISPLAY_DECIMALS, '0');
        $integer = ltrim($m[2], '0');
        $result = ($integer === '' ? '0' : $integer) . '.' . $fraction;
        return ($m[1] === '-' && trim($result, '0.') !== '') ? '-' . $result : $result;
    }

    /**
     * Cena VYPOČTENÁ (přirážka, marže, procentní sleva, přepočet kurzem): na haléře
     * jako dřív, pokud tím neztratí víc než 1 % své hodnoty, jinak na šest míst.
     * Běžná cena 133,333 tak zůstane 133,33, levná položka 0,0108 se nezkreslí
     * na 0,01 a 0,004 se nevynuluje. Ručně zadaná cena tudy nejde, drží šest míst.
     */
    public static function roundDerived(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return '0.00';
        }
        $negative = str_starts_with($value, '-');
        $abs = $negative ? substr($value, 1) : $value;
        $cents = self::roundHalfUp($abs, self::MIN_DISPLAY_DECIMALS);
        $exact = self::roundHalfUp($abs, self::DECIMALS);
        $loss = bcsub($exact, $cents, self::DECIMALS);
        if (str_starts_with($loss, '-')) {
            $loss = substr($loss, 1);
        }
        $result = bccomp(bcmul($loss, '100', self::DECIMALS), $exact, self::DECIMALS) > 0 ? $exact : $cents;
        $formatted = (string) self::toDecimalString($result);
        return $negative && bccomp($result, '0', self::DECIMALS) !== 0 ? '-' . $formatted : $formatted;
    }

    private static function roundHalfUp(string $abs, int $scale): string
    {
        return bcadd($abs, '0.' . str_repeat('0', $scale) . '5', $scale);
    }

    /** Vstup ceny má nejvýš šest desetinných míst (víc by databáze tiše zaokrouhlila). */
    public static function isWithinPrecision(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $text = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;
        if (preg_match('/^-?\d+(?:\.(\d+))?$/', $text, $m) !== 1) {
            return is_numeric($value) && self::normalize((float) $value) === (float) $value;
        }
        return strlen(rtrim($m[1] ?? '', '0')) <= self::DECIMALS;
    }
}
