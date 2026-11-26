<?php

declare(strict_types=1);

/**
 * Jednorázové doplnění mzdových závazků (účty 331, 336, 342) do agendy Ostatní pohledávky
 * a závazky u firem převedených z POHODY dřív, než je převod zakládal.
 *
 * Převod je vynechal jako předpisy mzdového modulu a nechal jen v deníku, takže úhrady
 * z banky nešly spárovat. Doplnění čte původní export POHODY použitý k převodu (MyÚčto ho
 * po převodu nedrží, dodá ho účetní) a založí jen chybějící mzdové doklady stejně jako
 * převod: závazek s vlastním zápisem ho převezme bez nového účtování, mzda zaměstnance
 * a závazek z počátečního stavu jsou potvrzené bez zápisu. Úhrady z exportu přiřadí
 * k převzatým bankovním a pokladním dokladům. Do deníku nic nezapisuje, opakované spuštění
 * nic nezdvojí. Logika je v {@see \MyInvoice\Service\Migration\Pohoda\PohodaPayrollItemBackfill}.
 *
 * Výpis ke každé agendě: založené položky (s převzetím zápisu / bez zápisu), vynechané
 * s důvodem a odsouhlasení mzdových účtů (zůstatek v deníku proti otevřeným položkám).
 *
 * Kontrola bez exportu (--check) hlásí u převedené firmy duplicitní mzdové závazky, závazky
 * bez předpisu v deníku, měsíce s mzdami v deníku dvakrát (převzatý deník POHODY i zaúčtování
 * mezd z PAMICY načtené po převodu) a srážky 331 v zápisech ostatních položek. Opravy jdou
 * jen výslovně: --cancel-duplicates zruší duplicitní závazky z převodu bez úhrad a bez předpisu
 * (deník nemění), --reverse-pamica-overlap stornuje zaúčtování mezd z PAMICY za měsíce, které
 * nese převzatý deník POHODY (MĚNÍ DENÍK, spouštět jen se souhlasem vlastníka firmy).
 * Logika je v {@see \MyInvoice\Service\Migration\Pohoda\PohodaPayrollItemCheck}.
 *
 * Výchozí je zkouška nanečisto, zápis jen s --commit. Storno z PAMICY se zápisem vyžaduje
 * --user (autor storna v deníku). Kód návratu: 0 = v pořádku, 1 = doplnění některé firmy
 * selhalo nebo oprava byla odmítnuta (refused), 2 = neplatné zadání.
 *
 * Použití:
 *   php api/bin/pohoda-backfill-payroll-items.php --all                                         # přehled dotčených firem
 *   php api/bin/pohoda-backfill-payroll-items.php --supplier=12 --export=/cesta/export.zip     # nanečisto
 *   php api/bin/pohoda-backfill-payroll-items.php --supplier=12 --export=/cesta/12345678_2026 --commit
 *   php api/bin/pohoda-backfill-payroll-items.php --all --export=/cesta/exporty                # složka s agendami IČO_rok více firem
 *   php api/bin/pohoda-backfill-payroll-items.php --all --check                                # kontrola všech převedených firem
 *   php api/bin/pohoda-backfill-payroll-items.php --supplier=12 --check --cancel-duplicates [--commit]
 *   php api/bin/pohoda-backfill-payroll-items.php --supplier=12 --check --reverse-pamica-overlap [--commit]
 *   volitelně --year=RRRR (jen agenda roku), --user=ID (autor zápisů a běhu v historii převodů)
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\Pohoda\PohodaPayrollItemBackfill;
use MyInvoice\Service\Migration\Pohoda\PohodaPayrollItemCheck;

$usage = "Použití: php api/bin/pohoda-backfill-payroll-items.php --supplier=ID|--all [--export=<ZIP exportu nebo složka agendy>] [--year=RRRR] [--user=ID] [--commit]\n"
    . "        php api/bin/pohoda-backfill-payroll-items.php --supplier=ID|--all --check [--cancel-duplicates] [--reverse-pamica-overlap] [--commit]\n";
$check = false;
$fix = ['cancel_duplicates' => false, 'reverse_pamica' => false];
$supplierId = null;
$all = false;
$source = null;
$year = null;
$userId = null;
$commit = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--commit') {
        $commit = true;
    } elseif ($arg === '--check') {
        $check = true;
    } elseif ($arg === '--cancel-duplicates') {
        $fix['cancel_duplicates'] = true;
    } elseif ($arg === '--reverse-pamica-overlap') {
        $fix['reverse_pamica'] = true;
    } elseif ($arg === '--all') {
        $all = true;
    } elseif (preg_match('/^--supplier=(\d+)$/', $arg, $m) === 1 && (int) $m[1] > 0) {
        $supplierId = (int) $m[1];
    } elseif (preg_match('/^--export=(.+)$/', $arg, $m) === 1) {
        $source = $m[1];
    } elseif (preg_match('/^--year=(\d{4})$/', $arg, $m) === 1) {
        $year = (int) $m[1];
    } elseif (preg_match('/^--user=(\d+)$/', $arg, $m) === 1 && (int) $m[1] > 0) {
        $userId = (int) $m[1];
    } else {
        fwrite(STDERR, "Neznámý nebo neplatný parametr {$arg}.\n" . $usage);
        exit(PohodaPayrollItemBackfill::EXIT_INVALID);
    }
}
$fixing = $fix['cancel_duplicates'] || $fix['reverse_pamica'];
if (($supplierId === null) === !$all || ($fixing && !$check) || ($check && $source !== null)
    || ($commit && !$check && $source === null) || ($commit && $check && !$fixing) || ($fixing && $all)) {
    fwrite(STDERR, $usage);
    exit(PohodaPayrollItemBackfill::EXIT_INVALID);
}
if ($fix['reverse_pamica'] && $commit && $userId === null) {
    fwrite(STDERR, "Storno zaúčtování mezd z PAMICY zapisuje do deníku: zadejte autora --user=ID.\n");
    exit(PohodaPayrollItemBackfill::EXIT_INVALID);
}

$container = Bootstrap::buildContainer();
if ($check) {
    $suppliers = $supplierId !== null ? [$supplierId] : $container->get(PohodaImportRepository::class)->completedSuppliers();
    $checker = $container->get(PohodaPayrollItemCheck::class);
    $backfill = $container->get(PohodaPayrollItemBackfill::class);
    echo ($fixing && !$commit ? '[NANEČISTO] ' : '') . 'Kontrola mzdových závazků (331, 336, 342) firem převedených z POHODY' . PHP_EOL;
    if ($year !== null) {
        echo "Upozornění: --year se u kontroly nepoužije, kontrola projde celý deník firmy." . PHP_EOL;
    }
    $exit = PohodaPayrollItemBackfill::EXIT_OK;
    if ($fix['reverse_pamica']) {
        echo 'POZOR: --reverse-pamica-overlap mění deník (storna). Spouštějte ho s --commit jen se souhlasem vlastníka firmy.' . PHP_EOL;
    }
    foreach ($suppliers as $id) {
        $report = $checker->run($id, $fix, $commit, $userId);
        echo PHP_EOL . "Firma {$id}" . PHP_EOL;
        foreach ($backfill->reconcile($id, date('Y-m-d')) as $a) {
            echo sprintf('    %s  deník %14s  otevřené položky %14s  rozdíl %14s', $a['account'], number_format($a['ledger'], 2, ',', ' '),
                number_format($a['open_items'], 2, ',', ' '), number_format($a['difference'], 2, ',', ' ')) . PHP_EOL;
        }
        PohodaPayrollItemCheck::print($report, static function (string $line): void {
            echo $line . PHP_EOL;
        });
        foreach ([...($report['fixes']['cancelled'] ?? []), ...($report['fixes']['reversed'] ?? [])] as $fixed) {
            if (str_starts_with($fixed['result'], 'refused')) {
                $exit = PohodaPayrollItemBackfill::EXIT_FAILED;
            }
        }
    }
    if ($fixing && !$commit) {
        echo PHP_EOL . 'Nic nezměněno. Opravy zapíše spuštění s --commit.' . PHP_EOL;
    }
    exit($exit);
}

$service = $container->get(PohodaPayrollItemBackfill::class);
exit($service->run($supplierId, $source, $commit, $year, $userId, static function (string $line): void {
    echo $line . PHP_EOL;
}));
