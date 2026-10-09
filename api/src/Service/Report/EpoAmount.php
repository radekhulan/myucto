<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Zaokrouhlení haléřových součtů na celé koruny pro výkazy DPH (DPHDP3, DPHSHV).
 *
 * Vstupem jsou součty částek v haléřích sečtené ve float. Takový součet nese chybu
 * řádu 1e-12: 842,86 + 219,06 + 858,84 + 907,32 + 206,92 dá 3035.0000000000005 a
 * 6 349,68 + … + 1 882,55 dá 31249.499999999996. `ceil()` a `round()` pak sáhnou o korunu
 * vedle. Proto se nejdřív vrátí na haléře a teprve potom zaokrouhlí na koruny.
 */
final class EpoAmount
{
    /** Matematické zaokrouhlení na celé Kč (polovina od nuly), DPHDP3. */
    public static function wholeCzk(float $amount): int
    {
        return (int) round(round($amount, 2));
    }

    /**
     * Zaokrouhlení na celé Kč nahoru, DPHSHV `pln_hodnota`. Záporná hodnota jde od nuly,
     * aby se opravovaná částka nezmenšila.
     */
    public static function wholeCzkUp(float $amount): int
    {
        $cents = round($amount, 2);
        return (int) ($cents < 0 ? floor($cents) : ceil($cents));
    }
}
