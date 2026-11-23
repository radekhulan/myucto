<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Primární pracovněprávní vztah (JMHZ 10495) za osobu v jednom podání.
 *
 * Datový slovník 1.4.1.6 i 1.5.0.3 vede 10495 jako příznak formuláře
 * (`hlavicka.primarniPpv`) a kontroly 255 a 260 ho posuzují „za OIČ v rámci
 * podání": v podání musí být právě jeden primární vztah osoby. Příznak tedy
 * rozlišuje SOUBĚŽNÉ vztahy vykázané v témže měsíci, ne pořadí dohod v čase.
 *
 * Vykazuje-li osoba v měsíci jediný vztah, je primární on, i když ho evidence
 * vede jako vedlejší. Typicky agentura s navazujícími DPP: nová dohoda vznikla
 * jako vedlejší, protože ta předchozí v evidenci ještě byla primární, a po jejím
 * skončení už podmínky opravit nejdou. Nerozhodnutý údaj (`null`) se nedoplňuje,
 * a u více vztahů rozhoduje jen evidence.
 *
 * Výjimkou je měsíc předání hlavní role: navazující dohoda, která převzala roli
 * po dohodě skončené před jejím nástupem
 * ({@see \MyInvoice\Repository\Payroll\PayrollEmploymentRepository::hasOverlappingPrimary()}),
 * má vlastní verzi podmínek s příznakem hlavního vztahu a verze skončené
 * dohody ho nese dál. V měsíci, kdy osoba vykazuje obě, by byly hlavní dva
 * vztahy a blokující kontrola 260 („nesmí existovat více než jedno primární
 * PPV za OIČ v rámci podání") by hlášení odmítla. Vztahy, které se časově
 * nepřekrývají, jsou postupným předáním role: hlavní je ten, který nastoupil
 * poslední (stejně jako v evidenci po předání). Překrývající se hlavní vztahy
 * jsou skutečný rozpor v evidenci a zůstávají k vyřešení.
 *
 * Jediné místo, odkud příznak berou příprava podání i sestavení formulářů.
 */
final class JmhzPrimaryEmployment
{
    /**
     * @param array<array-key,mixed> $employmentRows řádky s klíčem `employment`
     * @return array<array-key,?bool> účinný příznak podle klíče řádku
     */
    public static function flags(array $employmentRows): array
    {
        $flags = [];
        foreach ($employmentRows as $key => $row) {
            $employment = is_array($row) && is_array($row['employment'] ?? null)
                ? $row['employment']
                : [];
            $flag = $employment['is_primary'] ?? null;
            $flags[$key] = is_bool($flag) ? $flag : null;
        }
        if (count($flags) === 1 && reset($flags) === false) {
            $flags[array_key_first($flags)] = true;
        }
        $primary = array_keys(array_filter($flags, static fn (?bool $flag): bool => $flag === true));
        if (count($primary) > 1) {
            $latest = self::latestOfSequential(array_intersect_key($employmentRows, array_flip($primary)));
            if ($latest !== null) {
                foreach ($primary as $key) {
                    $flags[$key] = $key === $latest;
                }
            }
        }

        return $flags;
    }

    /**
     * Klíč vztahu, který nastoupil poslední, jsou-li vztahy po sobě jdoucí
     * (žádné dva se nepřekrývají); jinak `null`.
     *
     * @param array<array-key,mixed> $rows
     */
    private static function latestOfSequential(array $rows): int|string|null
    {
        $spans = [];
        foreach ($rows as $key => $row) {
            $employment = is_array($row) && is_array($row['employment'] ?? null) ? $row['employment'] : [];
            $start = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
            $end = $employment['end_date'] ?? null;
            if (!is_string($start) || $start === '' || ($end !== null && !is_string($end))) {
                return null;
            }
            $spans[] = ['key' => $key, 'start' => substr($start, 0, 10), 'end' => $end === null ? null : substr($end, 0, 10)];
        }
        usort($spans, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        for ($index = 1, $count = count($spans); $index < $count; $index++) {
            $previousEnd = $spans[$index - 1]['end'];
            if ($previousEnd === null || $previousEnd >= $spans[$index]['start']) {
                return null;
            }
        }

        return $spans[count($spans) - 1]['key'];
    }
}
