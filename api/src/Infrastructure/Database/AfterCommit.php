<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

/**
 * Vedlejší přepočet (statistiky, kontrola přílohy, fronta AI) po commitu transakce,
 * ve které se doklad uložil. Mimo transakci běží hned, rollback ho zahodí.
 *
 * Krok zapisuje odvozená data, která dopočte i příští přepočet. Jeho chyba proto
 * nesmí změnit odpověď už uložené akce ani odvolat transakci volajícího: souběžný
 * zápis týchž řádků (1020/1213) by jinak shodil uložení dokladu nebo náhled zápisu.
 * Odvolaný krok se zopakuje, jiná chyba se jen zaloguje.
 */
final class AfterCommit
{
    public static function run(PDO $pdo, string $key, \Closure $step): void
    {
        $guarded = static function () use ($pdo, $key, $step): void {
            try {
                TransactionFailure::retrying($pdo, $step);
            } catch (\Throwable $e) {
                error_log('Krok po commitu ' . $key . ' selhal: ' . $e->getMessage());
            }
        };
        if ($pdo instanceof LoggingPdo) {
            $pdo->afterCommit($key, $guarded);
        } elseif (!$pdo->inTransaction()) {
            $guarded();
        }
    }
}
