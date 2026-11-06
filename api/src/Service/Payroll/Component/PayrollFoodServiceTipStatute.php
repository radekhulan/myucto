<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Osvobození spropitného ze stravovacích služeb — § 6 odst. 9 písm. g) a odst. 19
 * ZDP ve znění zákona č. 180/2026 Sb. (§ 30 body 7 a 9), od 1. 1. 2027.
 *
 * Doslovně: osvobozeno je „spropitné ze stravovacích služeb obdržené zaměstnancem
 * vykonávajícím činnost v provozovně stravovacích služeb, za kterou je poskytování
 * spropitného obvyklé, a to za kalendářní měsíc do výše odpovídající podílu 7 %
 * z měsíčních příjmů ze stravovacích služeb zaměstnavatele a počtu zaměstnanců
 * tohoto zaměstnavatele, kteří za daný kalendářní měsíc obdrželi spropitné ze
 * stravovacích služeb, nebo do výše určené zaměstnavatelem tak, aby úhrn
 * osvobozeného spropitného … nepřekročil 7 % z jeho měsíčních příjmů ze
 * stravovacích služeb". Plátcem spropitného je podle odst. 19 zaměstnavatel.
 *
 * Sedm procent je pevné číslo zákona, ne ročníkový parametr mezd; proto tady
 * a ne v rulesetu, stejně jako limity v {@see \MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableStatute}.
 *
 * Osvobozený příjem není vyměřovacím základem pojistného: § 5 odst. 1 zákona
 * č. 589/1992 Sb. i § 3 odst. 1 zákona č. 592/1992 Sb. berou jen příjmy, které
 * „jsou … předmětem daně … a nejsou od této daně osvobozeny". Nadlimitní část je
 * běžný zdanitelný příjem i vyměřovací základ.
 *
 * Za zdaňovací období 2026 a dřív se podle § 31 bodu 1 zák. č. 180/2026 Sb.
 * použije dosavadní znění, kde osvobození není — strop je tedy nula.
 */
final class PayrollFoodServiceTipStatute
{
    public const FIRST_YEAR = 2027;

    /** „7 % z měsíčních příjmů ze stravovacích služeb" — čitatel a jmenovatel. */
    public const SHARE_NUMERATOR = 7;
    public const SHARE_DENOMINATOR = 100;

    public const STATUTE = '§ 6 odst. 9 písm. g) ZDP';

    public static function isEffective(int $taxYear): bool
    {
        return $taxYear >= self::FIRST_YEAR;
    }

    /**
     * Úhrnný strop osvobozeného spropitného zaměstnavatele za měsíc: 7 % tržeb
     * ze stravovacích služeb, v haléřích dolů (strop se nesmí překročit).
     */
    public static function employerCapMinor(int $revenueMinor): int
    {
        if ($revenueMinor < 0) {
            throw new \InvalidArgumentException('Měsíční příjmy ze stravovacích služeb nesmí být záporné.');
        }

        return intdiv($revenueMinor * self::SHARE_NUMERATOR, self::SHARE_DENOMINATOR);
    }

    /**
     * Strop na jednoho zaměstnance při rovnoměrném dělení: podíl 7 % tržeb
     * a počtu zaměstnanců, kteří v měsíci spropitné obdrželi. Haléře dolů — tak
     * se úhrn všech podílů nikdy nedostane nad 7 %.
     */
    public static function perCapitaCapMinor(int $revenueMinor, int $recipients): int
    {
        if ($recipients < 1) {
            throw new \InvalidArgumentException('Počet zaměstnanců se spropitným musí být kladný.');
        }

        return intdiv($revenueMinor * self::SHARE_NUMERATOR, self::SHARE_DENOMINATOR * $recipients);
    }
}
