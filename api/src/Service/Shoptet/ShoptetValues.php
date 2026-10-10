<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Support\LocaleNumber;

/**
 * Převod textových hodnot z exportů Shoptetu na čísla a data.
 *
 * Šablony exportu si obchodník skládá sám, takže tvar čísla není pevný: v CSV
 * běží české formátování („1 234,50"), v XML tečka, u sazby DPH se objevuje
 * i znak procenta. Parser proto čte oboje a nečitelnou hodnotu vrací jako `null`,
 * nikdy jako nulu. Nula u ceny nebo sazby je tvrzení, prázdno je mlčení.
 */
final class ShoptetValues
{
    public static function decimal(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        return LocaleNumber::toFloat(str_replace('%', '', $raw));
    }

    /** Datum a čas objednávky na 'Y-m-d H:i:s'; Shoptet píše `y-M-d H:m:s` i české `d.m.Y`. */
    public static function dateTime(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$h, $mi, $s] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        } elseif (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$h, $mi, $s] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s);
    }

    public static function bool(?string $raw): ?bool
    {
        $value = mb_strtolower(trim((string) $raw));
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['1', 'true', 'ano', 'yes', 'y', 'zaplaceno', 'paid'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'ne', 'no', 'n', 'nezaplaceno', 'unpaid'], true)) {
            return false;
        }

        return null;
    }

    public static function text(?string $raw, int $max = 255): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /** Dvoupísmenný kód země; jiný tvar (lokalizovaný název) vrací `null`. */
    public static function country(?string $raw): ?string
    {
        $value = strtoupper(trim((string) $raw));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    /** Klíč pro porovnání názvů sloupců a elementů: bez diakritiky, mezer a velikosti písmen. */
    public static function key(string $raw): string
    {
        $value = mb_strtolower(trim($raw, " \t\n\r\0\x0B#\u{FEFF}"));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }

    /** Druh řádku objednávky podle typů položek Shoptetu. */
    public static function itemKind(?string $type): string
    {
        $value = mb_strtolower(trim((string) $type));

        return match (true) {
            $value === '' => 'product',
            in_array($value, ['product', 'bazar', 'service', 'set', 'product-set', 'gift'], true) => 'product',
            in_array($value, ['shipping', 'doprava'], true) => 'shipping',
            in_array($value, ['billing', 'platba', 'payment'], true) => 'billing',
            str_contains($value, 'discount') || str_contains($value, 'sleva') || str_contains($value, 'coupon') => 'discount',
            default => 'other',
        };
    }
}
