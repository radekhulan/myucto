<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\CzechBirthNumber;

/**
 * Rodné číslo z podkladů importu docházky.
 *
 * Excel ukládá rodné číslo bez lomítka jako číslo a zahodí úvodní nulu:
 * 0103011234 (narozen 1. 3. 2001) se v buňce stane 103011234 a devět číslic
 * by prošlo jako rodné číslo z roku 1910, které kontrolu modulo 11 nemá.
 * Číslo bez lomítka se sedmi až devíti číslicemi se proto zkusí doplnit nulami
 * na deset číslic; doplněné se použije, jen když projde kontrolní číslicí
 * i datem. Datum narození z podkladů rozhodne mezi oběma čteními a nesmí
 * rodnému číslu odporovat.
 */
final class AttendanceBirthNumber
{
    public const PADDED_WARNING = 'Rodné číslo bylo v podkladech zapsané jako číslo bez úvodní nuly; doplnilo se na 10 číslic '
        . '(kontrolní číslice i datum sedí). Ve zdrojovém sešitu nastavte sloupec s rodným číslem jako text.';

    public const NINE_DIGITS_WARNING = 'Rodné číslo má jen 9 číslic, což platí jen pro osoby narozené před rokem 1954. '
        . 'Je-li osoba mladší, chybí v podkladech úvodní nula (buňka uložená jako číslo); opravte ho a nahrajte podklady znovu.';

    /**
     * @return array{value:?string,warnings:list<string>,error:?string}
     *   `value` = kanonický tvar RRMMDD/XXXX, u neplatného čísla text z podkladů;
     *   `error` = rodné číslo je platné, ale k párování ani založení se nepoužije
     */
    public static function read(?string $raw, ?string $birthDate): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['value' => null, 'warnings' => [], 'error' => null];
        }
        $digits = (string) preg_replace('/\D/', '', $raw);
        $warnings = [];
        $value = self::tryNormalize($raw);
        if (!str_contains($raw, '/') && preg_match('/^\d{7,9}$/', $digits) === 1
            && preg_match('/^[\d\s.]+$/', $raw) === 1) {
            $padded = self::tryNormalize(str_pad($digits, 10, '0', STR_PAD_LEFT));
            $candidates = array_values(array_filter([$padded, $value], static fn (?string $item): bool => $item !== null));
            if ($birthDate !== null && $candidates !== []) {
                $matching = array_values(array_filter(
                    $candidates,
                    static fn (string $item): bool => CzechBirthNumber::birthDate($item) === $birthDate,
                ));
                if (count($matching) === 1) {
                    $candidates = $matching;
                }
            }
            if ($candidates === []) {
                return ['value' => $raw, 'warnings' => [], 'error' => null];
            }
            $value = $candidates[0];
            $warnings[] = $value === $padded ? self::PADDED_WARNING : self::NINE_DIGITS_WARNING;
        }
        if ($value === null) {
            return ['value' => $raw, 'warnings' => [], 'error' => null];
        }
        if ($birthDate !== null && CzechBirthNumber::birthDate($value) !== $birthDate) {
            return [
                'value' => $value,
                'warnings' => $warnings,
                'error' => 'Rodné číslo neodpovídá datu narození ' . self::czechDate($birthDate)
                    . ' v podkladech; k párování ani založení osoby se nepoužilo. Opravte jedno z nich.',
            ];
        }

        return ['value' => $value, 'warnings' => $warnings, 'error' => null];
    }

    /** Datum narození zapsané v platném rodném čísle (Y-m-d), jinak null. */
    public static function birthDateOf(?string $value): ?string
    {
        $normalized = $value === null ? null : self::tryNormalize($value);

        return $normalized === null ? null : CzechBirthNumber::birthDate($normalized);
    }

    private static function tryNormalize(string $value): ?string
    {
        try {
            return CzechBirthNumber::normalize($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function czechDate(string $date): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return "{$day}. {$month}. {$year}";
    }
}
