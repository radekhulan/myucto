<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Zásah svátku do segmentů absence — čistá logika bez databáze.
 *
 * Repozitář segmenty jen načte a doplní kalendářní podklady; rozhodnutí, který
 * segment kvůli svátku zmizí a který kvůli němu vznikne, žije tady, aby šlo
 * ověřit unit testem. Obě větve se musí potkat: den, který dovolená kvůli § 219
 * nespotřebuje, je týž den, za který § 192 v okně DPN platí náhradu.
 */
final class AbsenceHolidaySegments
{
    /**
     * § 219 odst. 1 ZP — směny připadající na svátek se z čerpání dovolené
     * vypouštějí.
     *
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     * @param array<string,mixed> $holidays datum => cokoliv (stačí přítomnost klíče)
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    public static function excludeFromLeave(array $segments, array $holidays): array
    {
        return array_values(array_filter(
            $segments,
            static fn (array $segment): bool => !array_key_exists($segment['local_date'], $holidays),
        ));
    }

    /**
     * § 192 odst. 1 ZP — svátek uvnitř okna DPN se proplácí i bez publikované
     * směny. Když směna publikovaná je, náhrada už ze segmentu plyne a druhý
     * segment se nepřidává, jinak by se svátek proplatil dvakrát.
     *
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     * @param array<string,int> $holidayPlannedMinutes datum => minuty, které by
     *        zaměstnanec ten den jinak odpracoval (0 = jinak by nepracoval)
     * @param array<string,int> $remainingByDate zbývající limit částečného dne
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    public static function compensateSickness(
        array $segments,
        array $holidayPlannedMinutes,
        array $remainingByDate = [],
    ): array {
        $covered = [];
        foreach ($segments as $segment) {
            $covered[$segment['local_date']] = true;
        }

        foreach ($holidayPlannedMinutes as $date => $minutes) {
            if ($minutes <= 0 || isset($covered[$date])) {
                continue;
            }
            $eligible = $minutes;
            if (array_key_exists($date, $remainingByDate)) {
                $eligible = min($eligible, $remainingByDate[$date]);
            }
            if ($eligible <= 0) {
                continue;
            }
            $segments[] = [
                'shift_id' => null,
                'local_date' => (string) $date,
                'planned_minutes' => $minutes,
                'eligible_minutes' => $eligible,
            ];
        }

        usort(
            $segments,
            static fn (array $a, array $b): int => [$a['local_date'], $a['shift_id'] ?? 0]
                <=> [$b['local_date'], $b['shift_id'] ?? 0],
        );

        return array_values($segments);
    }
}
