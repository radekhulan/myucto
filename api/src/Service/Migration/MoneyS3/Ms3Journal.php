<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Pravidla čtení účetního deníku Money (`UcDenik.DAT`) na jednom místě — používá je
 * náhled agendy, import deníku i rekonciliace. Kdyby si každý z nich určoval rok
 * nebo zdroj dokladu po svém, rekonciliace by kontrolovala sama sebe.
 */
final class Ms3Journal
{
    /** Zdroj řádku deníku, kterým Money označuje počáteční stavy. */
    public const OPENING_SOURCE = 'XP';

    /** @param array<string,mixed> $row */
    public static function isOpening(array $row): bool
    {
        return trim((string) ($row['Zdroj'] ?? '')) === self::OPENING_SOURCE;
    }

    /**
     * Klíč dokladu v deníku: skupina řádků se stejným zdrojem, číslem a datem.
     * Počáteční stavy roku tvoří jediný otevírací zápis.
     *
     * @param array<string,mixed> $row
     */
    public static function groupKey(array $row): string
    {
        if (self::isOpening($row)) {
            return self::OPENING_SOURCE;
        }
        return trim((string) ($row['Zdroj'] ?? '')) . '|' . trim((string) ($row['Doklad'] ?? '')) . '|' . (string) ($row['Datum'] ?? '');
    }

    /** Zdroj dokladu v Money → `journal_entries.source_type`. */
    public static function sourceType(string $moneySource): string
    {
        return match (strtoupper(trim($moneySource))) {
            'BK' => 'bank',
            'PK' => 'cash',
            'FP' => 'purchase_invoice',
            'FV' => 'invoice',
            default => 'manual', // ID interní doklady, KZ závazky, KP pohledávky, ostatní
        };
    }

    /**
     * Účetní rok adresáře ROK.nnn. Nejčastější rok mezi reálnými zápisy, ne první
     * nalezený: jediný netypický řádek by jinak posunul celé období. Rok, který má jen
     * počáteční stavy, se vezme z jejich popisu („Počáteční stav roku 2026").
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function fiscalYear(array $rows): ?int
    {
        $years = [];
        $fromText = null;
        foreach ($rows as $r) {
            $date = (string) ($r['Datum'] ?? '');
            if (!self::isOpening($r) && $date !== '') {
                $y = (int) substr($date, 0, 4);
                if ($y >= 1990 && $y <= 2100) {
                    $years[$y] = ($years[$y] ?? 0) + 1;
                }
            }
            if ($fromText === null && preg_match('/\b(19|20)(\d{2})\b/', (string) ($r['Popis'] ?? ''), $m) === 1 && self::isOpening($r)) {
                $fromText = (int) ($m[1] . $m[2]);
            }
        }
        if ($years !== []) {
            arsort($years);
            return (int) array_key_first($years);
        }
        if ($fromText !== null) {
            return $fromText;
        }
        foreach ($rows as $r) {
            $date = (string) ($r['Datum'] ?? '');
            if ($date !== '') {
                return (int) substr($date, 0, 4);
            }
        }
        return null;
    }

    /** Podíl zápisů mimo kalendářní rok, nad kterým agenda vede hospodářský rok. */
    private const NON_CALENDAR_SHARE = 0.1;

    /**
     * Vede agenda kalendářní účetní rok? Převod jiný nezná (období jsou 1. 1. – 31. 12.).
     * Pár zápisů mimo rok je běžná chyba dokladu a převod je ohlásí po jednom; hospodářský
     * rok (třeba červenec–červen) má mimo kalendářní rok velkou část deníku.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function isCalendarYear(array $rows, int $year): bool
    {
        $total = 0;
        $outside = 0;
        foreach ($rows as $r) {
            $date = (string) ($r['Datum'] ?? '');
            if (self::isOpening($r) || $date === '') {
                continue;
            }
            $total++;
            $outside += (int) substr($date, 0, 4) !== $year ? 1 : 0;
        }
        return $total === 0 || $outside / $total <= self::NON_CALENDAR_SHARE;
    }

    /**
     * Čistý účetní účinek řádku deníku: kladná částka jde na MD `UcMD` a D `UcD`,
     * záporná obráceně. `null` = řádek nemá účinek (nulová částka, chybějící účet
     * nebo stejný účet na obou stranách — Money je v počátečních stavech běžně má).
     *
     * @param array<string,mixed> $row
     * @return array{debit:string,credit:string,amount:float}|null
     */
    public static function effect(array $row): ?array
    {
        $amount = round((float) ($row['Castka'] ?? 0), 2);
        $md = trim((string) ($row['UcMD'] ?? ''));
        $d = trim((string) ($row['UcD'] ?? ''));
        if ($amount === 0.0 || $md === '' || $d === '' || $md === $d) {
            return null;
        }
        if ($amount < 0) {
            return ['debit' => $d, 'credit' => $md, 'amount' => -$amount];
        }
        return ['debit' => $md, 'credit' => $d, 'amount' => $amount];
    }
}
