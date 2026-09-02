<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Normalizace a kontrola českého rodného čísla.
 *
 * Vytaženo z PayrollPersonProfileValidator, aby stejné pravidlo mohla zavolat
 * i evidence vyživovaných osob (MZ-04-W05) — privátní helper uvnitř jedné
 * validace se okopíruje rychleji, než kdyby neexistoval.
 */
final class CzechBirthNumber
{
    /**
     * Jen číslice, bez lomítka — tvar, který chtějí úřední schémata.
     *
     * PROČ ZVLÁŠŤ: {@see normalize()} vrací kanonický ČESKÝ tvar `RRMMDD/XXXX`,
     * protože tak se rodné číslo píše a tak ho účetní čte. Schémata ČSSZ
     * (`client/@bno`, `t:simpleNNType`) ale berou 9 až 10 číslic a lomítko
     * odmítnou. Bez téhle konverze spadl na správně uloženém rodném čísle
     * KAŽDÝ český zaměstnanec — hláška pak tvrdila, že je údaj neplatný,
     * a účetní ho neměla jak „opravit", protože byl od začátku v pořádku.
     */
    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', self::normalize($value));
    }

    /** @return string kanonický tvar RRMMDD/XXXX */
    public static function normalize(string $value): string
    {
        self::rejectMaskPlaceholder($value);
        $digits = (string) preg_replace('/\D/', '', $value);
        if (!preg_match('/^\d{9,10}$/', $digits)) {
            throw new InvalidArgumentException('Rodné číslo musí mít 9 nebo 10 číslic.');
        }

        $yearPart = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        foreach ([70, 50, 20] as $offset) {
            if ($month > $offset) {
                $month -= $offset;
                break;
            }
        }
        $year = strlen($digits) === 9 || $yearPart >= 54
            ? 1900 + $yearPart
            : 2000 + $yearPart;
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Rodné číslo neobsahuje platné datum narození.');
        }
        $birthDate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        if ($birthDate > new DateTimeImmutable('today')) {
            throw new InvalidArgumentException('Rodné číslo nesmí obsahovat budoucí datum narození.');
        }
        if (strlen($digits) === 9) {
            if ($year >= 1954) {
                throw new InvalidArgumentException('Devítimístné rodné číslo je přípustné jen před rokem 1954.');
            }
        } else {
            $number = (int) $digits;
            $legacyException = $year < 1985
                && ((int) substr($digits, 0, 9)) % 11 === 10
                && (int) substr($digits, -1) === 0;
            if ($number % 11 !== 0 && !$legacyException) {
                throw new InvalidArgumentException('Rodné číslo neprošlo kontrolou modulo 11.');
            }
        }

        return substr($digits, 0, 6) . '/' . substr($digits, 6);
    }

    /** @return string datum narození ve tvaru Y-m-d odvozené z rodného čísla */
    public static function birthDate(string $normalized): string
    {
        $digits = (string) preg_replace('/\D/', '', $normalized);
        $yearPart = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        foreach ([70, 50, 20] as $offset) {
            if ($month > $offset) {
                $month -= $offset;
                break;
            }
        }
        $year = strlen($digits) === 9 || $yearPart >= 54
            ? 1900 + $yearPart
            : 2000 + $yearPart;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Pohlaví zapsané v rodném čísle.
     *
     * Měsíc navýšený o 50 (a od roku 2004 i o 70 u vyčerpané kapacity dne) nese
     * ženu, navýšení o 20 je mužská alternativa. Registrace zaměstnance u ČSSZ
     * pohlaví vyžaduje, ale karta osoby ho po účetní chtěla vyplnit ručně,
     * přestože ho rodné číslo jednoznačně určuje — a chybějící údaj se poznal
     * až na obrazovce podání.
     *
     * @return 'female'|'male'|null null = tvar, ze kterého se to odvodit nedá
     */
    public static function sex(string $normalized): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $normalized);
        if (strlen($digits) < 6) {
            return null;
        }
        $month = (int) substr($digits, 2, 2);

        return match (true) {
            $month >= 1 && $month <= 12 => 'male',
            $month >= 21 && $month <= 32 => 'male',
            $month >= 51 && $month <= 62 => 'female',
            $month >= 71 && $month <= 82 => 'female',
            default => null,
        };
    }

    public static function rejectMaskPlaceholder(string $value): void
    {
        if (str_contains($value, '•') || preg_match('/\*{3,}/u', $value) === 1) {
            throw new InvalidArgumentException('Hodnota nesmí obsahovat maskovaný údaj.');
        }
    }
}
