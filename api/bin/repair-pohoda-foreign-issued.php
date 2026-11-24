<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignIssuedRepair;

if (PHP_SAPI !== 'cli') {
    exit("Jen z příkazové řádky.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all-pohoda', $argv, true);
$suppliers = [];
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--supplier=([1-9][0-9]*)$/D', $arg, $match) === 1) {
        $suppliers[(int) $match[1]] = (int) $match[1];
    } elseif (str_starts_with($arg, '--export=') && strlen($arg) > 9) {
        $paths[] = substr($arg, 9);
    } elseif (!in_array($arg, ['--commit', '--quiet', '--all-pohoda'], true)) {
        fwrite(STDERR, "Neplatný parametr {$arg}.\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all-pohoda.\n");
    exit(2);
}
if ($commit && $paths === []) {
    fwrite(STDERR, "Zápis potřebuje --export=<ZIP nebo adresář exportu z POHODY>.\n");
    exit(2);
}

$temp = [];
register_shutdown_function(static function () use (&$temp): void {
    foreach ($temp as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
});

$opened = [];
try {
    foreach ($paths as $path) {
        $dirs = [$path];
        if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda-issued-' . bin2hex(random_bytes(8));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Nelze založit složku pro rozbalení exportu.');
            }
            $temp[] = $dir;
            PohodaExport::extractArchive($path, $dir);
            $dirs = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            if ($dirs === []) {
                throw new RuntimeException(sprintf('ZIP %s neobsahuje složku agendy z exportu POHODY.', $path));
            }
        }
        foreach ($dirs as $dir) {
            $export = PohodaExport::open($dir);
            if (isset($opened[$export->ico][$export->year])) {
                throw new RuntimeException(sprintf('Pro IČO %s a rok %d je zadaných víc exportů.', $export->ico, $export->year));
            }
            $opened[$export->ico][$export->year] = $export;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

$repair = Bootstrap::buildContainer()->get(PohodaForeignIssuedRepair::class);
$ids = $all ? $repair->affectedSuppliers() : array_values($suppliers);
$byIco = [];
foreach ($ids as $id) {
    $byIco[$repair->supplierIco($id)][] = $id;
}
$labels = ['needs_export' => 'potřebuje export', 'locked' => 'chráněné období', 'review' => 'ke kontrole',
    PohodaForeignIssuedRepair::PAST_PERIOD => 'minulé období, ponecháno v Kč',
    'convert' => 'lze převést', 'converted' => 'převedeno'];
$countText = static function (array $counts) use ($labels): string {
    return implode(', ', array_map(static fn (string $status, int $n): string => $labels[$status] . ' ' . $n, array_keys($counts), $counts));
};

echo ($commit ? '' : '[NANEČISTO] ') . 'Vydané doklady v cizí měně převzaté z POHODY v Kč' . PHP_EOL;
$failed = false;
$totals = [];
foreach ($byIco as $ico => $supplierIds) {
    $exports = $opened[$ico] ?? [];
    if (!$all && $exports === [] && count($opened) === 1) {
        $exports = reset($opened);
    }
    if ($all && $exports !== [] && count($supplierIds) > 1) {
        fwrite(STDERR, sprintf('IČO %s mají firmy %s. Export přiřaďte výslovně pomocí --supplier.', $ico, implode(', ', $supplierIds)) . PHP_EOL);
        $exports = [];
        $failed = true;
    }
    foreach ($supplierIds as $id) {
        try {
            $rows = $repair->run($id, $exports, $commit && $exports !== []);
        } catch (Throwable $e) {
            $failed = true;
            fwrite(STDERR, sprintf('Firma %d: %s', $id, $e->getMessage()) . PHP_EOL);
            continue;
        }
        if ($rows === []) {
            continue;
        }
        $counts = array_count_values(array_column($rows, 'status'));
        foreach ($counts as $status => $count) {
            $totals[$status] = ($totals[$status] ?? 0) + $count;
        }
        echo PHP_EOL . sprintf('Firma %d, nalezeno %d dokladů (%s), úhrady k převodu nebo převedené: %d',
            $id, count($rows), $countText($counts), array_sum(array_column($rows, 'payments_converted'))) . PHP_EOL;
        $reasons = [];
        foreach ($rows as $row) {
            if (in_array($row['status'], ['review', 'locked'], true)) {
                $reason = $row['reason'];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }
        foreach ($reasons as $reason => $count) {
            echo sprintf('  %d ke kontrole: %s', $count, $reason) . PHP_EOL;
        }
        if ($quiet) {
            continue;
        }
        foreach ($rows as $row) {
            $detail = $row['rate'] === null
                ? $row['reason'] . ' Úhrady: 0. Původní důvod převzetí v Kč: ' . $row['source_reason']
                : sprintf('%s %s × %s. Úhrady: %d.', number_format($row['total_foreign'], 2, ',', ' '), $row['currency'],
                    rtrim(rtrim(number_format($row['rate'], 6, ',', ''), '0'), ','), $row['payments_converted']);
            echo sprintf('  %-16s %s  %s Kč  %s. %s', $row['document_no'], $row['issue_date'],
                number_format($row['total_czk'], 2, ',', ' '), $labels[$row['status']], $detail) . PHP_EOL;
        }
    }
}

echo PHP_EOL . 'Celkem ' . ($totals === [] ? 'nic k opravě' : $countText($totals)) . PHP_EOL;
if (!$commit && ($totals['convert'] ?? 0) > 0) {
    echo 'Pro zápis spusťte stejný příkaz s --commit.' . PHP_EOL;
}
exit($failed ? 1 : 0);
