<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

/**
 * Označení dvojic u builderů, které řádky skládají už po dvojicích předkontací
 * (uzávěrka, otevření, kurzové rozdíly, zásoby, rozpouštění časového rozlišení),
 * ale výsledek je jako celek M : N (účet 702, 710, 701 nebo 563/663 na obou
 * stranách), takže by ho {@see JournalPairer} sám nerozdělil.
 *
 * Výsledek je jen vstup pro párovač: klíč `pair_group` firma se složeným zápisem
 * nikdy nevidí (zaúčtování ho zahodí), a řádky, částky ani pořadí se nemění.
 */
final class PairGroups
{
    /**
     * Každé dva po sobě jdoucí řádky jsou jedna dvojice. Platí-li to pro VŠECHNY
     * řádky (sudý počet, každá dvojice je platná podle {@see JournalPairInvariant}),
     * dostane dvojice `pair_group` = `<prefix>:<n>`. Jinak se vstup vrátí beze změny
     * a o rozdělení rozhodne párovač (1 : N, shodné částky). Řádky, které už nesou
     * `pair_group` nebo `pair_no`, se nepřepisují.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public static function consecutive(array $lines, string $prefix): array
    {
        $lines = array_values($lines);
        if ($lines === [] || count($lines) % 2 !== 0) {
            return $lines;
        }
        foreach ($lines as $line) {
            if (isset($line['pair_group']) || isset($line['pair_no'])) {
                return $lines;
            }
        }
        for ($i = 0; $i < count($lines); $i += 2) {
            if (JournalPairInvariant::pairViolation($lines[$i], $lines[$i + 1]) !== null) {
                return $lines;
            }
        }
        foreach ($lines as $i => $line) {
            $lines[$i]['pair_group'] = $prefix . ':' . (intdiv($i, 2) + 1);
        }
        return $lines;
    }

    /**
     * Všem řádkům nastaví stejnou skupinu (např. jedna úhrada s haléřovým zbytkem).
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public static function same(array $lines, string $group): array
    {
        return array_map(static function (array $line) use ($group): array {
            $line['pair_group'] = $group;
            return $line;
        }, array_values($lines));
    }
}
