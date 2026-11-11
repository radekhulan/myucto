<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog;

/**
 * Mzdové vstupy v celých korunách pro měsíční hlášení (JMHZ).
 *
 * Hlášení nese výdělek vztahu po atributech 10328 až 10331 (a úhrn 10286)
 * v celých korunách; částky se tam sčítají ze vstupů složek se zařazením
 * `jmhz_treatment = included` ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder}).
 * Haléř v takovém vstupu končí až při přípravě hlášení nálezem
 * `jmhz_scenario1_whole_czk_required` — tedy po schválení mzdy. Mzda, odměna
 * z dohody i náhrada se podle § 142 odst. 2 a § 144 zákoníku práce
 * zaokrouhlují na celé koruny nahoru, proto se tu zaokrouhluje vždy nahoru
 * (ve prospěch zaměstnance) a celočíselně v haléřích.
 *
 * Jediné místo, kde se rozhoduje, kterých vstupů se pravidlo týká a jak se
 * zaokrouhlují: kontrola mzdového běhu, hromadná oprava vstupů i import docházky.
 */
final class PayrollWholeCrowns
{
    /** Zaokrouhlení nahoru (k +∞) na celé koruny, v haléřích. */
    public static function roundUpMinor(int $minor): int
    {
        $remainder = $minor % 100;
        if ($remainder === 0) {
            return $minor;
        }

        return $remainder > 0 ? $minor - $remainder + 100 : $minor - $remainder;
    }

    public static function hasHalere(int $minor): bool
    {
        return $minor % 100 !== 0;
    }

    /**
     * Vstupy, které hlášení zablokují: haléřové vstupy skupin (vztah + složka),
     * jejichž součet nevyjde na celé koruny. Výdělek se v hlášení sčítá po
     * složkách, takže dva vstupy téže složky po 0,50 Kč nevadí.
     *
     * @param array<array-key,array{group:string,amount_minor:int}> $inputs
     * @return list<array-key> klíče vstupů k zaokrouhlení
     */
    public static function blockingInputs(array $inputs): array
    {
        $sums = [];
        foreach ($inputs as $input) {
            $sums[$input['group']] = ($sums[$input['group']] ?? 0) + $input['amount_minor'];
        }
        $blocking = [];
        foreach ($inputs as $key => $input) {
            if (self::hasHalere($sums[$input['group']]) && self::hasHalere($input['amount_minor'])) {
                $blocking[] = $key;
            }
        }

        return $blocking;
    }

    /** Vstup složky s tímto zařazením se vykazuje ve výdělku hlášení. */
    public static function reportedInJmhz(mixed $jmhzTreatment): bool
    {
        return $jmhzTreatment === 'included';
    }

    /** Hlídat celé koruny má smysl jen za měsíc, který se vykazuje přes JMHZ. */
    public static function jmhzPeriod(string $periodStart): bool
    {
        return PayrollStatutoryAgendaCatalog::jmhzApplies($periodStart);
    }
}
