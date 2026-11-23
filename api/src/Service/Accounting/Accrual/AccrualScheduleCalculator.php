<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

/**
 * Jediný výpočet rovnoměrného rozpouštění časového rozlišení podle dnů včetně obou krajů.
 *
 * Volají ho krok uzávěrky „Časové rozlišení" a rozpuštění v otevření roku
 * ({@see \MyInvoice\Service\Accounting\Closing\ClosingService}), výběr a rozpad odkladu
 * ({@see \MyInvoice\Service\Accounting\Closing\AccrualDeferralService}), průběžné časové
 * rozlišení ve výkazech ({@see \MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment})
 * a náhledy dokladů (členský předpis NNO). Vlastní verzi poměru dnů nikde jinde nepočítej:
 * odklad a jeho rozpouštění se pak rozejdou o haléře.
 *
 * Kotvou je kumulativně uznaná část k danému dni ({@see cumRecognized()}). Tranše mezi dvěma
 * dny je rozdíl dvou kumulativních hodnot, takže se tranše přes všechna období sečtou přesně
 * na základ, bez haléřového zbytku.
 */
final class AccrualScheduleCalculator
{
    /** Počet dnů intervalu VČETNĚ obou krajů (rozvahový den se počítá). */
    public static function daysInclusive(string $from, string $to): int
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10));
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10));
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }
        return (int) $a->diff($b)->days + 1;
    }

    /**
     * EP-15: kumulativně uznaný náklad či výnos z rovnoměrného rozlišení [from..to] DO dne
     * $through včetně (na haléře). $through < from → 0; $through >= to → celý $netCzk. Kotva
     * pro konzistentní odklad (net − cumRecognized(konec N)) i víceleté rozpouštění: rozdíl
     * dvou kumulativních hodnot na hranicích období telescopuje přesně na odloženou částku,
     * takže 381/384 se rozpustí do haléře bez zbytku napříč N+1, N+2, …
     */
    public static function cumRecognized(float $netCzk, string $from, string $to, string $through): float
    {
        $from = substr($from, 0, 10);
        $to = substr($to, 0, 10);
        $through = substr($through, 0, 10);
        if ($through < $from) {
            return 0.0;
        }
        $totalDays = self::daysInclusive($from, $to);
        if ($totalDays <= 0) {
            return 0.0;
        }
        $end = $through >= $to ? $to : $through;
        $days = self::daysInclusive($from, $end);
        return round($netCzk * min(1.0, $days / $totalDays), 2);
    }

    /**
     * Část základu řádku s obdobím [from..to], která k rozvahovému dni `asOf` připadá na dny
     * za ním a odkládá se na 381/384: základ − kumulativně uznaná část k `asOf` včetně.
     * Období celé za `asOf` = celý základ, období končící do `asOf` = 0. Neplatné období
     * (konec před začátkem) se nerozlišuje.
     */
    public static function deferredAmount(float $netCzk, string $from, string $to, string $asOf): float
    {
        if (self::daysInclusive($from, $to) <= 0) {
            return 0.0;
        }
        return round($netCzk - self::cumRecognized($netCzk, $from, $to, $asOf), 2);
    }

    /**
     * Tranše uznaná za dny PO `$after` až do `$through` včetně: kumulativně uznáno k
     * `$through` − kumulativně uznáno k `$after`. Konec období rozlišení omezuje horní hranici
     * sám ({@see cumRecognized()}).
     */
    public static function recognizedBetween(float $netCzk, string $from, string $to, string $after, string $through): float
    {
        return round(
            self::cumRecognized($netCzk, $from, $to, $through) - self::cumRecognized($netCzk, $from, $to, $after),
            2,
        );
    }

    /**
     * EP-15: harmonogram rozpouštění po kalendářních rocích od `$firstYear` do roku konce
     * období: kolik se uzná v každém roce. Roky s nulovou tranší se vynechají. Σ tranší =
     * základ − kumulativně uznáno k 31. 12. roku před `$firstYear` (telescopuje).
     *
     * @return list<array{fiscal_year:int, amount:float}>
     */
    public static function fiscalYearTranches(float $netCzk, string $from, string $to, int $firstYear): array
    {
        $schedule = [];
        $toYear = (int) substr($to, 0, 4);
        for ($fy = $firstYear; $fy <= $toYear; $fy++) {
            $rel = self::recognizedBetween($netCzk, $from, $to, ($fy - 1) . '-12-31', $fy . '-12-31');
            if ((int) round($rel * 100) !== 0) {
                $schedule[] = ['fiscal_year' => $fy, 'amount' => $rel];
            }
        }
        return $schedule;
    }
}
