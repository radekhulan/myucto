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
 * Kód státu při trvající příslušnosti je běžná změna údaje (A3), ale přechod
 * mezi cizími a českými předpisy má vlastní akce (Zásady REGZEC 1.4.6, kódy
 * akce 6 a 7): začne-li zaměstnanec podléhat cizím předpisům, skončila
 * příslušnost k českým (A7); přestane-li jim podléhat, vznikla příslušnost
 * k českým (A6). Detektor proto u `foreign_legislation.applies` podle směru
 * vrátí 7 nebo 6 — poslat je jako A3 by znamenalo podat správnou skutečnost
 * špatnou akcí. Byla-li cizí příslušnost přihlášená už v A1, A6 odmítne až
 * schválení události (Specifický postup č. 1: A2 a nová A1).
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

        // '1' → cokoliv jiného: cizí předpisy skončily, vznikla příslušnost
        // k českým (A6); opačný směr je skončení příslušnosti k českým (A7).
        // Směr zná až porovnání, z jednoho údaje ho vyčíst nelze.
        return $from === '1'
            ? PayrollRegistrationReportableCatalog::ACTION_CZECH_LEGISLATION_START
            : PayrollRegistrationReportableCatalog::ACTION_CZECH_LEGISLATION_END;
    }
}
