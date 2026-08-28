<?php

declare(strict_types=1);

/**
 * Invarianty mzdového jádra — vrstva L3, mzdová obdoba `check-invariants.php`.
 *
 * Použití:
 *   php api/bin/check-payroll-invariants.php
 *   php api/bin/check-payroll-invariants.php --quiet     (jen porušení a souhrn)
 *
 * Spustí tvrzení, která platí VŽDY, nezávisle na datech (vyváženost účetní dávky,
 * strop platebních závazků, celistvost řetězu revizí, jediná platná schválená
 * revize, zůstatek dohody o srážce, kumulativní stavy a otisky zmrazených
 * snapshotů) — viz {@see PayrollInvariantService}.
 *
 * Skript je READ-ONLY: samé SELECTy, žádná transakce, žádný zápis. Lze ho proto
 * bez rizika pustit i proti produkční databázi.
 *
 * EXIT KÓD:
 *   0 = žádné porušení
 *   1 = nalezeno porušení (CI brána tím padá)
 *   2 = invarianty nebylo na čem měřit (prázdný mzdový modul / nevyhodnotil se
 *       ani jeden). Vakuózně zelený běh nad prázdnou databází je horší než
 *       červený: tvrdil by, že je hlídáno něco, co hlídané není.
 *
 * Cílovou databázi lze přepnout přes MYINVOICE_DB_NAME.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollInvariantService;

$quiet = in_array('--quiet', $argv, true);

$container = Bootstrap::buildApp()->getContainer();
$db = $container->get(Connection::class);
$service = $container->get(PayrollInvariantService::class);

$dbName = (string) $db->pdo()->query('SELECT DATABASE()')->fetchColumn();
if (!$quiet) {
    echo "Invarianty mzdového jádra — databáze: {$dbName}\n\n";
}

if ($service->payrollIsEmpty()) {
    fwrite(STDERR, "CHYBA: mzdový modul je prázdný — invarianty nemají na čem měřit.\n"
        . "Zelený běh nad prázdnou databází nic nedokazuje. Zvol databázi s obsahem\n"
        . "(MYINVOICE_DB_NAME=<db>).\n");
    exit(2);
}

$results = $service->checkAll();

$violationCount = 0;
$checkedCount = 0;
foreach ($results as $r) {
    if ($r['checked']) {
        $checkedCount++;
    }
    $violationCount += count($r['violations']);

    if ($r['violations'] !== []) {
        printf("[PORUŠENO] %s — %s (%s), nálezů: %d\n", $r['code'], $r['rule'], $r['source'], count($r['violations']));
        foreach ($r['violations'] as $v) {
            echo "    {$v}\n";
        }
        echo "\n";
        continue;
    }
    if (!$quiet) {
        printf(
            "[%s] %s — %s (%s)%s\n",
            $r['checked'] ? ' OK ' : 'SKIP',
            $r['code'],
            $r['rule'],
            $r['source'],
            $r['checked'] ? '' : ' — ' . (string) $r['skipped_reason'],
        );
    }
}

if ($checkedCount === 0) {
    fwrite(STDERR, "\nCHYBA: nevyhodnotil se ANI JEDEN invariant — vrstva by tvrdila, že hlídá.\n");
    exit(2);
}

printf(
    "\nVyhodnoceno %d z %d invariantů, porušení: %d\n",
    $checkedCount,
    count($results),
    $violationCount,
);

exit($violationCount > 0 ? 1 : 0);
