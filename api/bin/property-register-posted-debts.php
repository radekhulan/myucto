<?php

declare(strict_types=1);

/**
 * Doplnění dluhů k předpisům SVJ zaúčtovaným před zavedením hromadné registrace.
 *
 * Předpis zaúčtovaný bez registrovaného dluhu zastaví první úhradu jednotky na
 * `debt_inventory_incomplete`. Skript projde zaúčtované kladné předpisy, jejichž kladný řádek
 * nemá dluh, a zaregistruje jim dluh stejnou cestou jako zaúčtování dávky
 * ({@see \MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar}). Předpis, jehož část
 * řádků už má ručně registrovaný dluh, zůstává výjimkou: jen se vypíše a doregistruje se ručně.
 *
 * Běží po blocích (výchozí 500 předpisů), každý blok ve vlastní transakci. Opakované spuštění
 * nic nového nezapíše, protože předpis s dluhem už kandidátem není.
 *
 * Použití:
 *   php api/bin/property-register-posted-debts.php --dry-run           # jen počty, nic nezapíše
 *   php api/bin/property-register-posted-debts.php                     # všechny firmy
 *   php api/bin/property-register-posted-debts.php --supplier=1        # jedna firma
 *
 * Argumenty:
 *   --supplier=<id>   (volitelné) jen tahle firma
 *   --dry-run         vypsat počty bez zápisu
 *   --chunk=<n>       (volitelné) velikost bloku, výchozí 500
 *
 * Návratový kód: 0 = hotovo, 1 = zbývají předpisy s částečně ručně registrovanými dluhy,
 * 2 = chyba argumentů, 3 = chyba při registraci.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PostedChargeDebtBackfill;

/** @return string|null hodnota --key=value nebo null */
function argValue(array $argv, string $key): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$key}=")) {
            return substr($a, strlen($key) + 3);
        }
    }
    return null;
}

$supplierArg = argValue($argv, 'supplier');
$chunkArg = argValue($argv, 'chunk');
$dryRun = in_array('--dry-run', $argv, true);
foreach ([$supplierArg, $chunkArg] as $value) {
    if ($value !== null && !preg_match('/^[1-9][0-9]*$/D', $value)) {
        fwrite(STDERR, "Neplatná hodnota argumentu (čekám kladné celé číslo).\n");
        exit(2);
    }
}

$container = Bootstrap::buildApp()->getContainer();
$backfill = new PostedChargeDebtBackfill($container->get(Connection::class));
$suppliers = $supplierArg === null ? $backfill->suppliers() : [(int) $supplierArg];
$chunk = $chunkArg === null ? PostedChargeDebtBackfill::CHUNK : (int) $chunkArg;

echo ($dryRun ? '[DRY-RUN] ' : '') . 'Doplnění dluhů zaúčtovaných předpisů, firem: ' . count($suppliers) . "\n";
$total = ['candidates' => 0, 'registered' => 0, 'partial' => 0];
foreach ($suppliers as $supplier) {
    try {
        $result = $backfill->run($supplier, $dryRun, $chunk);
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("  #%d chyba: %s\n", $supplier, $e->getMessage()));
        exit(3);
    }
    foreach ($total as $key => $value) $total[$key] = $value + $result[$key];
    if ($result['candidates'] === 0) continue;
    printf("  #%-6d předpisů bez dluhu: %d, %s: %d, částečně ručně: %d\n", $supplier, $result['candidates'],
        $dryRun ? 'k registraci' : 'registrováno', $result['registered'], $result['partial']);
}
printf("Celkem předpisů bez dluhu: %d, %s: %d, částečně ručně (k ruční registraci): %d\n", $total['candidates'],
    $dryRun ? 'k registraci' : 'registrováno', $total['registered'], $total['partial']);
if ($dryRun) echo "(nic nebylo zapsáno; bez --dry-run se dluhy zaregistrují)\n";

exit($total['partial'] > 0 ? 1 : 0);
