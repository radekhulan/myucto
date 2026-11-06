<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Sleva za umístění dítěte (§ 35bd ZDP) — co stojí v zákoně, ne v rulesetu.
 *
 * Slevu zavedl zákon č. 180/2026 Sb. (část druhá, § 30 body 15, 18, 23, 24, 27,
 * 28 a 40) s účinností od 1. 1. 2027; podle § 31 bodu 1 téhož zákona se za
 * zdaňovací období započaté dřív použije dosavadní znění, kde sleva není.
 *
 * Strop je „minimální mzda" (§ 35bd odst. 1 věta druhá), tedy podle § 21g
 * odst. 1 měsíční minimální mzda účinná k prvnímu dni zdaňovacího období.
 * Částka sama je ročníkový parametr a čte se z rulesetu
 * ({@see AnnualTaxRates::$minimumWageMinorUnits}); tady je jen to, co se
 * nemění s rokem.
 *
 * Pořadí ve výpočtu: § 35ba odst. 1 písm. g) ji řadí mezi „slevy na dani pro
 * poplatníky daně z příjmů fyzických osob". Podle § 35d odst. 7 se jimi daň
 * sníží nejdřív, až potom se počítá daňové zvýhodnění podle § 35c, a § 35c
 * odst. 2 a 3 měří slevu na dítě i bonus z daně snížené „o slevy na dani podle
 * § 35 a 35ba". Sleva za umístění dítěte tedy jde do úhrnu § 35ba (nejvýš do
 * výše daně), před daňové zvýhodnění.
 */
final class AnnualPreschoolCreditStatute
{
    /** První zdaňovací období, za které sleva existuje. */
    public const FIRST_YEAR = 2027;

    public const SOURCE = 'zdp-35bd';

    public static function isEffective(int $taxYear): bool
    {
        return $taxYear >= self::FIRST_YEAR;
    }

    /** § 35bd odst. 2 věta třetí: „zaokrouhluje na celé koruny dolů". */
    public static function floorToCrowns(int $minorUnits): int
    {
        return intdiv(max(0, $minorUnits), 100) * 100;
    }
}
