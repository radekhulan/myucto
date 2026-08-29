<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Porovnání dvou průmětů hlásitelných údajů.
 *
 * Čistá doména: žádná databáze, žádné dešifrování, žádné hodiny. Právě proto
 * se dá otestovat, že změna úvazku nebo mzdy nevyrobí nic — v porovnávaném
 * průmětu takový údaj vůbec neexistuje a katalog ho odmítne.
 *
 * ## Co znamená „rozešlo se"
 *
 * Porovnávají se jen cesty, které zná OBĚ strany. Cesta, kterou výchozí stav
 * nemá, se přeskočí: znamená to, že jsme o tom údaji při posledním podání nic
 * neposlali, takže se nemohl „změnit oproti nahlášenému". Hlásit takový údaj
 * jako změnu by u každého staršího snapshotu spustilo lavinu podání, která
 * nemají co opravovat.
 *
 * ## Proč se příslušnost k cizím předpisům chová jinak
 *
 * Kód státu při trvající příslušnosti je běžná změna údaje (A3), ale VZNIK
 * a SKONČENÍ příslušnosti mají vlastní akce A6 a A7. Detektor proto u
 * `foreign_legislation.applies` podle směru vrátí 6 nebo 7 — poslat je jako
 * A3 by znamenalo podat správnou skutečnost špatnou akcí.
 */
final class PayrollRegistrationChangeDetector
{
    /** @return list<PayrollRegistrationChangeFinding> */
    public function compare(
        PayrollRegistrationReportableProfile $baseline,
        PayrollRegistrationReportableProfile $current,
    ): array {
        $findings = [];
        foreach (PayrollRegistrationReportableCatalog::paths() as $path) {
            if (!$baseline->has($path) || !$current->has($path)) {
                continue;
            }
            $from = $baseline->get($path);
            $to = $current->get($path);
            if ($from === $to) {
                continue;
            }
            $definition = PayrollRegistrationReportableCatalog::definition($path);
            $findings[] = new PayrollRegistrationChangeFinding(
                $path,
                $definition['group'],
                $this->actionCode($path, $definition['action'], $from, $to),
                $definition['sensitive'],
                $from,
                $to,
            );
        }

        return $findings;
    }

    private function actionCode(
        string $path,
        int $catalogAction,
        ?string $from,
        ?string $to,
    ): int {
        if ($path !== 'foreign_legislation.applies') {
            return $catalogAction;
        }

        // '1' → cokoliv jiného je skončení příslušnosti (A7), opačný směr
        // její vznik (A6). Katalog drží 6 jako výchozí hodnotu; směr zná až
        // porovnání, protože z jednoho údaje ho vyčíst nelze.
        return $from === '1'
            ? PayrollRegistrationReportableCatalog::ACTION_FOREIGN_LEGISLATION_END
            : PayrollRegistrationReportableCatalog::ACTION_FOREIGN_LEGISLATION_START;
    }
}
