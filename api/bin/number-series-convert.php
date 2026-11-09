<?php

declare(strict_types=1);

/**
 * Převod stávajících číselných řad všech firem do číselníku (`number_series`, migrace 2265).
 * Čísla dokladů ani počítadla se nemění, převedená řada jen dostane záznam v číselníku.
 * Logika: MyInvoice\Service\NumberSeries\NumberSeriesCatalog.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill
 * v api/bin/migrate.php, když najde firmu bez převedených řad.
 *
 * Použití:
 *   php api/bin/number-series-convert.php           # dry-run (jen vypíše počet firem)
 *   php api/bin/number-series-convert.php --apply   # skutečně převede
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$catalog = $app->getContainer()->get(\MyInvoice\Service\NumberSeries\NumberSeriesCatalog::class);

$pending = $catalog->pending();
if (!$apply) {
    echo "[DRY-RUN] Firem bez převedených číselných řad: {$pending}. Spusť znovu s --apply.\n";
    exit(0);
}
$result = $catalog->syncAll();
echo "Hotovo. Firem: {$result['suppliers']}, nově převedených řad: {$result['series']}\n";
