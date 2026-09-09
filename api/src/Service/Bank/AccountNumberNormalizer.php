<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Normalizace bankovního účtu pro porovnávání mezi:
 *  - GPC výpisem (zero-padded 16 cifer, např. `0000000123456789`)
 *  - currencies.account_number (uložené bez padding, např. `123456789`)
 *  - CZ účty s prefixem (`19-2000145399` → `192000145399`)
 *  - e-mailovými avízy s maskovaným číslem (Moneta Info Servis: `238***891`)
 *
 * Strip non-digits + ltrim '0'. Po normalize se dva různé zápisy stejného účtu
 * shodují.
 *
 * Pozn.: ztrácíme tím rozlišení účtů, které se liší pouze prefixem (např.
 * `19-1000000005` vs. `1000000005` budou normalizované shodné). To je v praxi
 * OK — žádný důstojný účet nemá takovou kolizi.
 */
final class AccountNumberNormalizer
{
    public static function normalize(string $accountNumber): string
    {
        $digitsOnly = preg_replace('/\D/', '', $accountNumber) ?? '';
        return ltrim($digitsOnly, '0');
    }

    /**
     * True pokud dvě account number stringy odkazují na stejný účet.
     *
     * Nejdřív přes normalize (GPC padding / pomlčky). Když nesedí, zkusí
     * maskovanou shodu — Moneta v avízu posílá `238***891` místo plného čísla,
     * a mapování e-mailových notifikací by jinak spadlo na match_failed.
     */
    public static function equals(string $a, string $b): bool
    {
        if (str_contains($a, '*') || str_contains($b, '*')) {
            return self::matchesMasked($a, $b) || self::matchesMasked($b, $a);
        }
        $ibanA = self::czechSlovakIbanAccountPart($a);
        $ibanB = self::czechSlovakIbanAccountPart($b);
        if ($ibanA !== null || $ibanB !== null) {
            $banks = [];
            foreach ([$a, $b] as $value) {
                $compact = strtoupper((string) preg_replace('/\s+/', '', $value));
                $banks[] = preg_match('/^(?:CZ|SK)\d{2}(\d{4})\d{16}$/D', $compact, $m)
                    || preg_match('#/(\d{4})$#D', $compact, $m) ? $m[1] : null;
            }
            if ($banks[0] !== null && $banks[1] !== null && $banks[0] !== $banks[1]) return false;
            return self::equalsCzech($ibanA ?? $a, $ibanB ?? $b);
        }
        if (self::normalize($a) === self::normalize($b)) {
            return true;
        }
        return false;
    }

    /**
     * Maskovaný zápis (číslice + `*`) vs. plné číslo stejné délky.
     *
     * Každá `*` zastupuje právě jednu číslici. Pomlčky a kód banky za `/`
     * se ignorují. Obě strany s `*` → false (dvě masky se neporovnávají).
     *
     * Příklad: `238***891` sedí na `238456891` i `238456891/0600`,
     * ale ne na `239456891` (jiný prefix) ani `2384567891` (jiná délka).
     */
    private static function matchesMasked(string $masked, string $full): bool
    {
        $maskPart = explode('/', trim($masked), 2)[0];
        if ($maskPart === '' || !str_contains($maskPart, '*')) {
            return false;
        }
        if (str_contains($full, '*')) {
            return false;
        }

        $maskPattern = preg_replace('/[^0-9*]/', '', $maskPart) ?? '';
        $fullDigits = preg_replace('/\D/', '', explode('/', trim($full), 2)[0]) ?? '';
        if ($maskPattern === '' || preg_match('/[0-9]/', $maskPattern) !== 1 || $fullDigits === '' || strlen($maskPattern) !== strlen($fullDigits)) {
            return false;
        }

        $len = strlen($maskPattern);
        for ($i = 0; $i < $len; $i++) {
            if ($maskPattern[$i] === '*') {
                continue;
            }
            if ($maskPattern[$i] !== $fullDigits[$i]) {
                return false;
            }
        }
        return true;
    }

    public static function equalsCzech(string $a, string $b): bool
    {
        $identities = [];
        foreach ([$a, $b] as $raw) {
            $compact = preg_replace('/\s+/', '', $raw) ?? '';
            $national = self::czechIbanAccountPart($compact)
                ?? preg_replace('#/\d{4}$#', '', $compact);
            if (!is_string($national) || preg_match('/^(?:(?:\d{1,6}-)?\d{1,10}|\d{16})$/D', $national) !== 1) {
                return false;
            }
            $base = self::czechAccountBase($national);
            if ($base === null) return false;
            $identities[] = [self::czechAccountPrefix($national), $base];
        }
        return $identities[0] === $identities[1];
    }

    /**
     * Domácí část (předčíslí+číslo, 16 cifer) z českého IBANu — porovnatelná
     * s GPC account_number. Vrací NULL, pokud vstup není validně tvarovaný CZ IBAN.
     *
     * CZ IBAN: CZkk BBBB PPPPPP NNNNNNNNNN (kontrolní 2, banka 4, předčíslí 6, číslo 10).
     * Pozn.: kontrolní číslice neověřujeme — vstup je vlastní uložený účet, ne user input.
     */
    public static function czechIbanAccountPart(string $iban): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (preg_match('/^CZ\d{2}\d{4}(\d{6}\d{10})$/', $compact, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public static function czechSlovakIbanAccountPart(string $iban): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
        return preg_match('/^(?:CZ|SK)\d{2}\d{4}(\d{16})$/D', $compact, $m) === 1 ? $m[1] : null;
    }

    /** Předčíslí českého účtu z národního, GPC nebo IBAN zápisu. */
    public static function czechAccountPrefix(string $raw): ?string
    {
        $value = trim($raw);
        if (preg_match('/^(\d{1,6})-\d+(?:\/\d{4})?$/', $value, $m) === 1) {
            $prefix = ltrim($m[1], '0');
            return $prefix === '' ? null : $prefix;
        }
        $ibanPart = self::czechIbanAccountPart($value);
        if ($ibanPart !== null) {
            $prefix = ltrim(substr($ibanPart, 0, 6), '0');
            return $prefix === '' ? null : $prefix;
        }
        $national = preg_replace('#/\d{4}$#', '', $value) ?? $value;
        $digits = preg_replace('/\D/', '', $national) ?? '';
        if (strlen($digits) === 16) {
            $prefix = ltrim(substr($digits, 0, 6), '0');
            return $prefix === '' ? null : $prefix;
        }
        return null;
    }

    /**
     * Základ českého účtu (číslo BEZ předčíslí) z národního, GPC nebo IBAN zápisu.
     *
     * Doplněk k {@see czechAccountPrefix()}. `normalize()` se sem nehodí: u účtu
     * s předčíslím slepí předčíslí s číslem, takže `21012-7928311` a jeho
     * nulami vycpaná GPC podoba `0210120007928311` normalizují různě. Základ je
     * naopak v obou tvarech stejný (`7928311`) — a u účtu bez předčíslí (VZP
     * `1111006311` vs. `0000001111006311`) taky.
     */
    public static function czechAccountBase(string $raw): ?string
    {
        $value = trim($raw);
        if (preg_match('/^\d{1,6}-(\d+)(?:\/\d{4})?$/', $value, $m) === 1) {
            $base = ltrim($m[1], '0');
            return $base === '' ? null : $base;
        }
        $ibanPart = self::czechIbanAccountPart($value);
        if ($ibanPart !== null) {
            $base = ltrim(substr($ibanPart, 6), '0');
            return $base === '' ? null : $base;
        }
        $national = preg_replace('#/\d{4}$#', '', $value) ?? $value;
        $digits = preg_replace('/\D/', '', $national) ?? '';
        if ($digits === '') {
            return null;
        }
        $base = ltrim(strlen($digits) === 16 ? substr($digits, 6) : $digits, '0');
        return $base === '' ? null : $base;
    }

    /** Kód banky (4 cifry) z českého IBANu, NULL pokud vstup není CZ IBAN. */
    public static function czechIbanBankCode(string $iban): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (preg_match('/^CZ\d{2}(\d{4})\d{16}$/', $compact, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * True pokud účet z výpisu odpovídá uloženému účtu — buď přes `account_number`,
     * nebo přes domácí část `iban` (issue #109: EUR účty bývají evidované jen IBANem,
     * GPC ale nese domácí číslo účtu → bez tohohle se EUR výpis nikdy nespároval).
     */
    public static function matchesAny(string $statementAccount, ?string $accountNumber, ?string $iban = null): bool
    {
        if (is_string($accountNumber) && trim($accountNumber) !== '') {
            if (self::equalsCzech($accountNumber, $statementAccount) || self::equals($accountNumber, $statementAccount)) {
                return true;
            }
            // Defenzivně: IBAN vepsaný do pole account_number porovnej přes domácí část.
            $part = self::czechSlovakIbanAccountPart($accountNumber);
            if ($part !== null && self::equals($part, $statementAccount)) {
                return true;
            }
        }
        $ibanPart = is_string($iban) ? self::czechSlovakIbanAccountPart($iban) : null;
        return $ibanPart !== null && self::equals($ibanPart, $statementAccount);
    }

    /**
     * Kanonický klíč vlastního účtu: domácí část CZ IBANu nebo národní číslo
     * bez vodicích nul. Nečeský IBAN bez národního čísla nelze kanonizovat.
     */
    public static function canonical(?string $accountNumber, ?string $iban = null): ?string
    {
        $number = trim((string) $accountNumber);
        if ($number !== '') {
            $ibanPart = self::czechIbanAccountPart($number);
            $compact = strtoupper((string) preg_replace('/\s+/', '', $number));
            if ($ibanPart !== null) {
                return self::normalize($ibanPart) ?: null;
            }
            if (preg_match('/^[A-Z]{2}[A-Z0-9]+$/', $compact) !== 1) {
                $national = preg_replace('#/\d{4}$#', '', $number) ?? $number;
                $canonical = self::normalize($national);
                if ($canonical !== '') {
                    return $canonical;
                }
            }
        }

        $ibanPart = self::czechIbanAccountPart(trim((string) $iban));
        if ($ibanPart === null) {
            return null;
        }
        $canonical = self::normalize($ibanPart);
        return $canonical === '' ? null : $canonical;
    }

    /** Kód banky z explicitní hodnoty, případně z českého IBANu. */
    public static function canonicalBankCode(?string $bankCode, ?string $iban = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $bankCode) ?? '';
        if ($digits !== '') {
            return str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);
        }
        return self::czechIbanBankCode(trim((string) $iban));
    }
}
