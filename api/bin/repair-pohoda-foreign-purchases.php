<?php

declare(strict_types=1);

/**
 * Přijaté doklady v cizí měně se samovyměřením DPH, které převod z POHODY převzal v Kč
 * (poznámka „doklad v EUR, převzat v Kč (samovyměření DPH - …)"), převede na měnu a kurz
 * dokladu podle původního exportu z POHODY. DPH, KH ani deník se nemění. Pravidla výběru
 * a pojistky jsou v {@see \MyInvoice\Service\Migration\Pohoda\PohodaForeignPurchaseRepair}.
 *
 * Měnu, kurz a částku v měně databáze nezná, proto oprava potřebuje export firmy z POHODY
 * (ZIP nebo rozbalený adresář, týž jako pro převod; přiřazuje se podle IČO). Bez --export
 * jen vypíše dotčené doklady (stejně jako Kontrola dat po převodu).
 *
 * Výchozí je zkouška nanečisto: oprava zapíše, porovná evidenci DPH, saldokonto a peněžní
 * deník a vrátí se; výpis ukazuje, co by se převedlo. Zápis jen s --commit. Idempotentní.
 *
 * Použití:
 *   php api/bin/repair-pohoda-foreign-purchases.php --all-pohoda                                  # přehled bez exportu
 *   php api/bin/repair-pohoda-foreign-purchases.php --supplier=12 --export=/cesta/12345678_2026.zip # nanečisto
 *   php api/bin/repair-pohoda-foreign-purchases.php --supplier=12 --export=/cesta/export --commit   # zápis
 *   php api/bin/repair-pohoda-foreign-purchases.php --supplier=12 --export=… --quiet               # jen souhrn
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignPurchaseRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all-pohoda', $argv, true);
$suppliers = [];
$exports = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $id = (int) substr($arg, 11);
        if ($id < 1) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
        $suppliers[$id] = $id;
    } elseif (str_starts_with($arg, '--export=')) {
        $exports[] = substr($arg, 9);
    } elseif (!in_array($arg, ['--commit', '--quiet', '--all-pohoda'], true)) {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all-pohoda.\n");
    exit(2);
}
if ($commit && $exports === []) {
    fwrite(STDERR, "Zápis potřebuje --export=<ZIP nebo adresář exportu z POHODY>.\n");
    exit(2);
}

$temp = [];
$opened = [];
foreach ($exports as $path) {
    $dir = $path;
    if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda-repair-' . bin2hex(random_bytes(6));
        $zip = new ZipArchive();
        if ($zip->open($path) !== true || !$zip->extractTo($dir)) {
            fwrite(STDERR, "Export {$path} nejde rozbalit.\n");
            exit(2);
        }
        $zip->close();
        $temp[] = $dir;
        // ZIP z exportního skriptu nese soubory v podadresáři s názvem exportu.
        $inner = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        if ((glob($dir . DIRECTORY_SEPARATOR . '*.xml') ?: []) === [] && count($inner) === 1) {
            $dir = $inner[0];
        }
    }
    $export = PohodaExport::open($dir);
    $opened[PartnerImporter::ico($export->ico)] = $export;
}
register_shutdown_function(static function () use ($temp): void {
    foreach ($temp as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
});

$repair = Bootstrap::buildContainer()->get(PohodaForeignPurchaseRepair::class);
$ids = $all ? $repair->affectedSuppliers() : array_values($suppliers);

$fmt = static fn (?float $v, int $d = 2): string => $v === null ? '-' : rtrim(rtrim(number_format($v, $d, ',', ' '), '0'), ',');
$money = static fn (float $v): string => number_format($v, 2, ',', ' ');
echo ($commit ? '' : '[NANEČISTO] ') . 'Přijaté doklady v cizí měně se samovyměřením převzaté z POHODY v Kč' . PHP_EOL;
$totals = [];
$failed = false;
// Export se přiřazuje podle IČO. Dvě firmy se stejným IČO (např. dvě účetní období vedená
// jako dvě firmy) dostanou export jen výslovně přes --supplier; s --all-pohoda se přeskočí.
$byIco = [];
foreach ($ids as $supplierId) {
    $byIco[$repair->supplierIco($supplierId)][] = $supplierId;
}
foreach ($ids as $supplierId) {
    $ico = $repair->supplierIco($supplierId);
    if ($all && isset($opened[$ico]) && count($byIco[$ico]) > 1) {
        echo PHP_EOL . sprintf('Firma %d: IČO %s mají firmy %s, export přiřaďte výslovně přes --supplier.', $supplierId, $ico, implode(', ', $byIco[$ico])) . PHP_EOL;
        continue;
    }
    $export = $opened[$ico] ?? null;
    try {
        $rows = $repair->run($supplierId, $export, $commit && $export !== null);
    } catch (Throwable $e) {
        $failed = true;
        echo sprintf('Firma %d: CHYBA %s', $supplierId, $e->getMessage()) . PHP_EOL;
        continue;
    }
    if ($rows === []) {
        continue;
    }
    $counts = array_count_values(array_column($rows, 'status'));
    foreach ($counts as $status => $n) {
        $totals[$status] = ($totals[$status] ?? 0) + $n;
    }
    $sum = array_sum(array_map(static fn (array $r): float => $r['total_czk'], $rows));
    echo PHP_EOL . sprintf('Firma %d%s: %d dokladů za %s Kč — %s', $supplierId, $export === null ? ' (bez exportu)' : '', count($rows), $money($sum),
        implode(', ', array_map(static fn (string $s, int $n): string => "{$s} {$n}", array_keys($counts), $counts))) . PHP_EOL;
    if ($quiet) {
        continue;
    }
    foreach ($rows as $r) {
        echo sprintf('  %-12s %-20s %s  %12s Kč  %s  %s', $r['document_no'], mb_substr($r['vendor_invoice_number'], 0, 20), $r['issue_date'], $money($r['total_czk']),
            $r['status'],
            in_array($r['status'], ['convert', 'converted'], true)
                ? sprintf('%s %s × %s%s%s', $fmt($r['total_foreign']), $r['currency'], $fmt($r['rate'], 6),
                    $r['tax_base_czk'] !== null ? sprintf(', základ samovyměření %s Kč', $money($r['tax_base_czk'])) : '',
                    $r['removed_items'] > 0 ? sprintf(', -%d položka rozdílu kurzu', $r['removed_items']) : '')
                : (string) $r['reason']) . PHP_EOL;
    }
}
echo PHP_EOL . 'Celkem: ' . ($totals === [] ? 'nic k opravě' : implode(', ', array_map(static fn (string $s, int $n): string => "{$s} {$n}", array_keys($totals), $totals))) . PHP_EOL;
if (!$commit && ($totals['convert'] ?? 0) > 0) {
    echo 'Zápis: spusťte znovu s --commit.' . PHP_EOL;
}
exit($failed ? 1 : 0);
