<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

/**
 * Plán rozpouštění položky časového rozlišení podle metody. Čistý výpočet bez databáze:
 * částky bere z {@see AccrualScheduleCalculator}, takže položka agendy se rozpouští stejným
 * poměrem dnů jako odklad uzávěrky a průběžný výkaz.
 *
 * Metody:
 *  - `monthly_days`: řádek za každý kalendářní měsíc (první a poslední poměrně), částka podle
 *    dnů jako rozdíl kumulativně uznané části. Haléřové zaokrouhlení tak telescopuje a součet
 *    řádků je přesně základ (shodně s POHODOU, která rozpouští stejně).
 *  - `monthly_equal`: řádek za každý měsíc období, každý měsíc stejným dílem bez ohledu na dny
 *    (kumulativně zaokrouhleno, zbytek v posledním řádku).
 *  - `yearly`: řádek za každý kalendářní rok k 31. 12., poslední ke konci období.
 *  - `manual`: bez plánu, rozpouští se ručními zápisy a vazbami.
 *
 * Řádek nese `due_on` (datum zápisu = konec úseku), `from_on`/`to_on` a `days` (pokryté dny).
 * Když plán pokračuje za převzatým nebo zaúčtovaným úsekem (`$coveredThrough`), začíná dnem po
 * něm a cílová částka `$targetTotal` (zbývající zůstatek) se vyrovná v posledním řádku.
 */
final class AccrualPlanBuilder
{
    public const METHODS = ['monthly_days', 'monthly_equal', 'yearly', 'manual'];

    /**
     * @return list<array{kind:string, due_on:string, from_on:string, to_on:string, days:int, amount_planned:float}>
     */
    public static function build(string $method, float $basis, string $from, string $to, ?string $coveredThrough = null, ?float $targetTotal = null): array
    {
        if ($method === 'manual' || AccrualScheduleCalculator::daysInclusive($from, $to) <= 0) {
            return [];
        }
        $segments = $method === 'yearly' ? self::yearSegments($from, $to) : self::monthSegments($from, $to);
        $n = count($segments);
        $rows = [];
        foreach ($segments as $k => [$segFrom, $segTo]) {
            $after = self::dayBefore($segFrom);
            if ($method === 'monthly_equal') {
                $amount = round(round($basis * ($k + 1) / $n, 2) - round($basis * $k / $n, 2), 2);
            } else {
                $amount = AccrualScheduleCalculator::recognizedBetween($basis, $from, $to, $after, $segTo);
            }
            $rows[] = [
                'kind' => 'release',
                'due_on' => $segTo,
                'from_on' => $segFrom,
                'to_on' => $segTo,
                'days' => AccrualScheduleCalculator::daysInclusive($segFrom, $segTo),
                'amount_planned' => $amount,
            ];
        }
        if ($coveredThrough !== null) {
            $rows = self::after($rows, $coveredThrough, $method, $basis, $from, $to);
        }
        if ($targetTotal !== null && $rows !== []) {
            $rows = self::settleLast($rows, $targetTotal);
        }
        return array_values(array_filter($rows, static fn (array $r): bool => (int) round($r['amount_planned'] * 100) !== 0));
    }

    /**
     * Rozpuštění k datu `$through` za dny po `$coveredThrough` (předčasné ukončení, průběžný
     * výkaz). Měsíční metody i roční počítají poměr dnů, `monthly_equal` po celých měsících
     * a poměrně v rozpracovaném měsíci.
     */
    public static function releaseThrough(string $method, float $basis, string $from, string $to, string $coveredThrough, string $through): float
    {
        if ($method === 'manual' || $through <= $coveredThrough) {
            return 0.0;
        }
        if ($method !== 'monthly_equal') {
            return AccrualScheduleCalculator::recognizedBetween($basis, $from, $to, $coveredThrough, $through);
        }
        return round(self::equalCumulative($basis, $from, $to, $through) - self::equalCumulative($basis, $from, $to, $coveredThrough), 2);
    }

    /** Kumulativně uznaná část metody `monthly_equal` k danému dni (rozpracovaný měsíc podle dnů). */
    private static function equalCumulative(float $basis, string $from, string $to, string $through): float
    {
        if ($through < $from) {
            return 0.0;
        }
        if ($through >= $to) {
            return $basis;
        }
        $segments = self::monthSegments($from, $to);
        $n = count($segments);
        foreach ($segments as $k => [$segFrom, $segTo]) {
            if ($through <= $segTo) {
                $before = round($basis * $k / $n, 2);
                $full = round($basis * ($k + 1) / $n, 2);
                $part = AccrualScheduleCalculator::daysInclusive($segFrom, $through) / AccrualScheduleCalculator::daysInclusive($segFrom, $segTo);
                return round($before + ($full - $before) * $part, 2);
            }
        }
        return $basis;
    }

    /**
     * Řádky za dny po `$coveredThrough`: úsek, který přes hranici přesahuje, se zkrátí
     * a jeho částka přepočte za zbývající dny.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function after(array $rows, string $coveredThrough, string $method, float $basis, string $from, string $to): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['to_on'] <= $coveredThrough) {
                continue;
            }
            if ($row['from_on'] <= $coveredThrough) {
                $row['from_on'] = self::dayAfter($coveredThrough);
                $row['days'] = AccrualScheduleCalculator::daysInclusive($row['from_on'], $row['to_on']);
                $row['amount_planned'] = self::releaseThrough($method, $basis, $from, $to, $coveredThrough, $row['to_on']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function settleLast(array $rows, float $targetTotal): array
    {
        $last = count($rows) - 1;
        $others = 0.0;
        foreach ($rows as $i => $row) {
            if ($i !== $last) {
                $others = round($others + (float) $row['amount_planned'], 2);
            }
        }
        $rows[$last]['amount_planned'] = round($targetTotal - $others, 2);
        return $rows;
    }

    /** @return list<array{0:string,1:string}> */
    private static function monthSegments(string $from, string $to): array
    {
        $out = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $monthEnd = $cursor->modify('last day of this month');
            $segTo = $monthEnd < $end ? $monthEnd : $end;
            $out[] = [$cursor->format('Y-m-d'), $segTo->format('Y-m-d')];
            $cursor = $segTo->modify('+1 day');
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    private static function yearSegments(string $from, string $to): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            $segFrom = max($from, $y . '-01-01');
            $segTo = min($to, $y . '-12-31');
            $out[] = [$segFrom, $segTo];
        }
        return $out;
    }

    public static function dayBefore(string $date): string
    {
        return (new \DateTimeImmutable(substr($date, 0, 10)))->modify('-1 day')->format('Y-m-d');
    }

    public static function dayAfter(string $date): string
    {
        return (new \DateTimeImmutable(substr($date, 0, 10)))->modify('+1 day')->format('Y-m-d');
    }
}
