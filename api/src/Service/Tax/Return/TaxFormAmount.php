<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Částka na řádku přiznání k dani z příjmů v celých korunách.
 *
 * Pokyny k přiznání DPPO (25 5404) i DPFO (25 5405) chtějí částky v celých korunách
 * a XSD (dppdp9, dpfdp7) má u řádků `fractionDigits=0`. Součtové řádky (DPPO ř. 70,
 * 170, 200, 250, 270; DPFO ř. 41, 42, 45, 54, 55, Příloha 1 ř. 104 a 113, Příloha 2
 * ř. 203) EPO kontroluje jako součet UVEDENÝCH řádků. Proto se každý řádek zaokrouhlí
 * jednou z haléřové hodnoty a mezisoučty se skládají až ze zaokrouhlených řádků:
 * součet v haléřích zaokrouhlený až na konci se od součtu řádků může lišit o korunu
 * a EPO pak řádek odmítne.
 *
 * Zaokrouhluje se matematicky (polovina od nuly). Pokyny směr neurčují; pokyny k DPFO
 * výslovně zakazují postupné zaokrouhlování ve více stupních, takže se zaokrouhluje
 * vždy přímo haléřová hodnota, nikdy už jednou zaokrouhlené číslo.
 */
final class TaxFormAmount
{
    public static function kc(float $amount): float
    {
        $rounded = round($amount, 0);

        return $rounded == 0.0 ? 0.0 : $rounded;
    }

    /**
     * Zaokrouhlení na celé koruny NAHORU (pojistné a vyměřovací základy OSVČ, daň § 16,
     * zálohy). Součin částky a sazby nese ve float chybu řádu 1e-11: 400 000 × 0,55 dá
     * 220000.00000000003 a holé `ceil()` z něj udělá vyměřovací základ 220 001. Proto se
     * před `ceil()` vrátí na 6 desetinných míst; součin celých korun a sazby se třemi
     * desetinnými místy se tím nezmění, odstraní se jen šum.
     */
    public static function ceilKc(float $amount): float
    {
        return ceil(round($amount, 6));
    }

    /** Nahoru na celé násobky $step (zálohy na daň zaokrouhlené na stokoruny). */
    public static function ceilTo(float $amount, int $step): float
    {
        if ($step <= 0) {
            return self::ceilKc($amount);
        }

        return ceil(round($amount / $step, 6)) * $step;
    }
}
