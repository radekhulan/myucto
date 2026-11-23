<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use DateTimeImmutable;

/**
 * Placeholdery v textech pravidelné fakturace (#108) — popisy položek a poznámky.
 *
 * Vyhodnocují se při generování faktury ze šablony vůči referenčnímu datu
 * (DUZP faktury; u proformy datum vystavení — stejná logika jako
 * {@see MonthSynchronizer}). Šablonová hodnota se nikdy nemění, do faktury
 * jde vyhodnocený snapshot.
 *
 * Podporované tokeny (STRIKTNĚ velká písmena; pro ref. datum 15. 5. 2026):
 *   {YYYY} {YY}            2026, 26          — rok; offset po letech: {YYYY+1} → 2027
 *                                              offset po měsících (rok k {MMMM±N}): {YYYY+8M} → 2027
 *   {M} {MM}             5, 05             — měsíc; offset po MĚSÍCÍCH vč. přetečení
 *                                              roku: {MM+8} → 01
 *   {MMMM}                 květen / May      — název měsíce dle jazyka faktury (cs/en),
 *                                              offset po měsících: {MMMM+1} → červen
 *   {Q}                    2                 — čtvrtletí 1-4; offset po čtvrtletích
 *   {D} {DD}               15, 15            — den; offset po dnech
 *   {DATE}                 15. 5. 2026       — celé ref. datum, formát dle jazyka
 *                                              (cs „j. n. Y", en „M j, Y" — stejné jako PDF)
 *   {DATE+1Y-1D}           14. 5. 2027       — datová aritmetika: kombinace ±N D/M/Y,
 *                                              vyhodnocuje se zleva doprava; posun po
 *                                              měsících/letech je CLAMPOVANÝ (31. 1. +1M
 *                                              → 28. 2., ne 3. 3. — jako MySQL DATE_ADD)
 *   {BOM} {EOM}            1. 5. 2026,       — začátek/konec měsíce ref. data (celé datum
 *                          31. 5. 2026         dle jazyka); offset po měsících: {EOM+1} →
 *                                              30. 6. 2026, {EOM-1} → 30. 4. 2026
 *
 * Nerozpoznané tokeny ({FOO}, {yyyy}, …) zůstávají netknuté → žádné escapování
 * není potřeba a stávající šablony fungují beze změny.
 */
final class DescriptionPlaceholders
{
    /** Měsíce česky — PHP format('F') je jen anglicky a intl nemusí být k dispozici. */
    private const MONTHS_CS = [
        1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
    ];

    /** Rozpoznané tokeny; sdílí ho {@see apply()} i {@see unknownTokens()}. */
    private const PATTERN = '/\{(YYYY|YY)([+-]\d{1,3})M\}|\{(YYYY|YY|MMMM|MM|M|Q|DD|D)([+-]\d{1,3})?\}|\{DATE((?:[+-]\d{1,3}[DMY])*)\}|\{(BOM|EOM)([+-]\d{1,3})?\}/';

    /**
     * Složené závorky, které {@see apply()} nevyhodnotí ({FOO}, {mm}, osamocená „{“).
     * Faktury je tiše nechávají v textu; šablona názvu opakování ostatní položky
     * je odmítne, ať se překlep neobjeví v názvu každého výskytu.
     *
     * @return list<string>
     */
    public static function unknownTokens(string $text): array
    {
        $rest = (string) preg_replace(self::PATTERN, '', $text);
        if (!str_contains($rest, '{') && !str_contains($rest, '}')) {
            return [];
        }
        preg_match_all('/\{[^{}]*\}|[{}]/u', $rest, $m);
        return array_values(array_unique($m[0]));
    }

    public static function apply(string $text, DateTimeImmutable $ref, string $lang = 'cs'): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }

        // Den v měsíci může při posunu po měsících přetéct (31. 5. +1M → 1. 7. v PHP);
        // pro tokeny roku/měsíce/čtvrtletí na dni nezáleží → kotvíme na 1. den měsíce.
        $monthAnchor = $ref->setDate((int) $ref->format('Y'), (int) $ref->format('n'), 1);

        $result = preg_replace_callback(
            self::PATTERN,
            static function (array $m) use ($ref, $monthAnchor, $lang): string {
                // {YYYY±NM}/{YY±NM} — rok měsíce posunutého o N měsíců, dvojice k {MMMM±N}:
                // „{MMMM+1} {YYYY+1M}" dá v prosinci „leden" následujícího roku.
                if (($m[1] ?? '') !== '') {
                    return $monthAnchor->modify(sprintf('%+d months', (int) $m[2]))->format($m[1] === 'YYYY' ? 'Y' : 'y');
                }
                $m = [$m[0], $m[3] ?? '', $m[4] ?? '', $m[5] ?? '', $m[6] ?? '', $m[7] ?? ''];
                // {BOM±N}/{EOM±N} větev — začátek/konec měsíce posunutého o N měsíců.
                if (($m[4] ?? '') !== '') {
                    $month = $monthAnchor->modify(sprintf('%+d months', (int) ($m[5] ?? 0)));
                    if ($m[4] === 'EOM') {
                        $month = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), (int) $month->format('t'));
                    }
                    return self::formatDate($month, $lang);
                }
                // {DATE…} větev — token group 1 je prázdný string.
                if (($m[1] ?? '') === '') {
                    return self::formatDate(self::shiftDate($ref, $m[3] ?? ''), $lang);
                }
                $offset = (int) ($m[2] ?? 0);
                return match ($m[1]) {
                    'YYYY' => $monthAnchor->modify(sprintf('%+d years', $offset))->format('Y'),
                    'YY'   => $monthAnchor->modify(sprintf('%+d years', $offset))->format('y'),
                    'MMMM' => self::monthName($monthAnchor->modify(sprintf('%+d months', $offset)), $lang),
                    'MM'   => $monthAnchor->modify(sprintf('%+d months', $offset))->format('m'),
                    'M'    => $monthAnchor->modify(sprintf('%+d months', $offset))->format('n'),
                    'Q'    => (string) (intdiv((int) $monthAnchor->modify(sprintf('%+d months', $offset * 3))->format('n') - 1, 3) + 1),
                    'DD'   => $ref->modify(sprintf('%+d days', $offset))->format('d'),
                    'D'    => $ref->modify(sprintf('%+d days', $offset))->format('j'),
                    default => $m[0],
                };
            },
            $text,
        );

        return $result ?? $text;
    }

    /**
     * Datová aritmetika pro {DATE…}: sekvence ±N[DMY] aplikovaná zleva doprava.
     * Posun po měsících/letech je clampovaný na poslední den cílového měsíce
     * (31. 1. +1M → 28. 2., 29. 2. +1Y → 28. 2.) — PHP modify() by přetekl
     * do dalšího měsíce (31. 1. +1M = 3. 3.), což je pro fakturační období
     * překvapivé; chováme se jako MySQL DATE_ADD. Dny zůstávají exaktní.
     */
    private static function shiftDate(DateTimeImmutable $ref, string $ops): DateTimeImmutable
    {
        if ($ops === '') {
            return $ref;
        }
        preg_match_all('/([+-]\d{1,3})([DMY])/', $ops, $all, PREG_SET_ORDER);
        foreach ($all as [, $n, $unit]) {
            $ref = match ($unit) {
                'D' => $ref->modify(sprintf('%+d days', (int) $n)),
                'M' => self::addMonthsClamped($ref, (int) $n),
                default => self::addMonthsClamped($ref, 12 * (int) $n),
            };
        }
        return $ref;
    }

    /** Posun o N měsíců se zachováním dne; přetečení se ořízne na poslední den cílového měsíce. */
    private static function addMonthsClamped(DateTimeImmutable $d, int $months): DateTimeImmutable
    {
        $total = ((int) $d->format('Y')) * 12 + ((int) $d->format('n') - 1) + $months;
        $year  = intdiv($total, 12);
        $month = ($total % 12) + 1;
        $lastDay = (int) $d->setDate($year, $month, 1)->format('t');
        return $d->setDate($year, $month, min((int) $d->format('j'), $lastDay));
    }

    private static function formatDate(DateTimeImmutable $d, string $lang): string
    {
        // Stejné formáty jako InvoicePdfRenderer / WorkReportPdfRenderer.
        return $d->format($lang === 'en' ? 'M j, Y' : 'j. n. Y');
    }

    private static function monthName(DateTimeImmutable $d, string $lang): string
    {
        $n = (int) $d->format('n');
        return $lang === 'en' ? $d->format('F') : self::MONTHS_CS[$n];
    }
}
