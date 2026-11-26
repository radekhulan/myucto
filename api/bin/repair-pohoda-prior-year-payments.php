<?php

declare(strict_types=1);

/**
 * Doplnění úhrad minulých let u vydaných dokladů převedených z POHODY.
 *
 * Převod podvojného účetnictví úhrady s datem před začátkem agendy přeskočil a uhrazenou
 * část nesl jen `paid_total`; první spárování nebo smazání platby ji přepočtem smaže.
 * Skript doplní rozdíl jako evidovanou platbu „Převzato z POHODY, úhrada v minulém roce"
 * k poslednímu dni před začátkem agendy, bez pohybu v bance a bez zápisu v deníku.
 * Pravidla výběru a pojistky jsou v {@see \MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair}.
 *
 * Výchozí je zkouška nanečisto s výpisem, zápis jen s --commit. Idempotentní.
 *
 * Stejnou opravu spouští Kontrola dat po převodu (stránka v aplikaci a
 * `api/bin/migration-data-check.php --check=settled_without_payment`); tento skript zůstává tenkou obálkou
 * nad společnou službou opravy pro dávkové běhy přes všechny firmy.
 *
 * Použití:
 *   php api/bin/repair-pohoda-prior-year-payments.php --all-pohoda                 # nanečisto, všechny firmy převedené z POHODY
 *   php api/bin/repair-pohoda-prior-year-payments.php --supplier=12 --supplier=14  # nanečisto, vybrané firmy
 *   php api/bin/repair-pohoda-prior-year-payments.php --supplier=12 --commit       # zápis
 *   php api/bin/repair-pohoda-prior-year-payments.php --all-pohoda --quiet         # jen souhrn po firmách
 *
 * Podle knihy pohledávek účetní (doklady, jejichž úhrady minulých let už přepočet smazal,
 * nebo je stará oprava nadsadila), jedna firma:
 *   php api/bin/repair-pohoda-prior-year-payments.php --supplier=12 --balances=kniha.csv --as-of=2026-09-30           # nanečisto
 *   php api/bin/repair-pohoda-prior-year-payments.php --supplier=12 --balances=kniha.csv --as-of=2026-09-30 --commit  # zápis
 * CSV: řádky `číslo dokladu;zbývá uhradit` (Kč k datu --as-of), volitelná hlavička, desetinná
 * čárka i mezery v částce. Pravidla v {@see PohodaPriorYearPaymentRepair::runBalances()}.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all-pohoda', $argv, true);
$suppliers = [];
$balancesFile = null;
$asOf = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--balances=')) {
        $balancesFile = substr($arg, 11);
    } elseif (str_starts_with($arg, '--as-of=')) {
        $asOf = substr($arg, 8);
    } elseif (str_starts_with($arg, '--supplier=')) {
        $id = (int) substr($arg, 11);
        if ($id < 1) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
        $suppliers[$id] = $id;
    } elseif (!in_array($arg, ['--commit', '--quiet', '--all-pohoda'], true)) {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all-pohoda.\n");
    exit(2);
}

$fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');

if ($balancesFile !== null || $asOf !== null) {
    if ($balancesFile === null || $asOf === null || $all || count($suppliers) !== 1) {
        fwrite(STDERR, "Oprava podle knihy pohledávek: zadejte právě jedno --supplier=ID, --balances=soubor.csv a --as-of=RRRR-MM-DD.\n");
        exit(2);
    }
    if (!IsoDate::isValid($asOf)) {
        fwrite(STDERR, "Neplatné --as-of {$asOf}, očekáváno RRRR-MM-DD.\n");
        exit(2);
    }
    $csv = is_file($balancesFile) ? file_get_contents($balancesFile) : false;
    if ($csv === false) {
        fwrite(STDERR, "Soubor {$balancesFile} nejde přečíst.\n");
        exit(2);
    }
    try {
        $balances = PohodaPriorYearPaymentRepair::parseBalances($csv);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
    if ($balances === []) {
        fwrite(STDERR, "Kniha pohledávek {$balancesFile} je prázdná.\n");
        exit(2);
    }
    $repair = Bootstrap::buildContainer()->get(PohodaPriorYearPaymentRepair::class);
    $r = $repair->runBalances((int) reset($suppliers), $balances, $asOf, $commit);
    echo ($commit ? '' : '[NANEČISTO] ') . 'Úhrady minulých let podle knihy pohledávek k ' . $asOf . ' (' . count($balances) . ' řádků knihy)' . PHP_EOL;
    printf("Firma %d %s, začátek agendy %s, úhrada minulých let k %s\n", $r['supplier_id'], $r['company'], $r['period_start'] ?? '?', $r['paid_on'] ?? '?');
    if ($r['status'] === 'skipped' && $r['documents'] === []) {
        printf("Přeskočeno: %s\n", $r['reason']);
        exit(1);
    }
    $label = ['change' => 'změna', 'unchanged' => 'beze změny', 'refused' => 'ODMÍTNUTO'];
    printf("  %-14s %-30s %15s %15s %15s %15s  %s\n", 'Doklad', 'Odběratel', 'Kniha', 'Minulý rok dřív', 'Minulý rok nově', 'Zbývá potom', 'Stav');
    foreach ($r['documents'] as $d) {
        if ($quiet && $d['state'] === 'unchanged') {
            continue;
        }
        printf("  %-14s %-30s %15s %15s %15s %15s  %s%s\n", $d['document_no'], mb_strimwidth($d['client'], 0, 30, '…'), $fmt($d['book_remaining']),
            $fmt($d['old_prior']), $fmt($d['new_prior']), $fmt($d['remaining_after']), $label[$d['state']], $d['reason'] !== null ? ': ' . $d['reason'] : '');
    }
    $t = $r['totals'];
    printf("Celkem %d dokladů z knihy: %d ke změně, %d beze změny, %d odmítnuto. Úhrady minulých let %s -> %s Kč (rozdíl %s Kč), zbývá potom %s Kč.\n",
        $t['count'], $t['changed'], $t['count'] - $t['changed'] - $t['refused'], $t['refused'], $fmt($t['old_prior']), $fmt($t['new_prior']),
        $fmt($t['new_prior'] - $t['old_prior']), $fmt($t['remaining_after']));
    if ($r['skipped'] !== []) {
        printf("V knize, oprava je neřeší (%d):\n", count($r['skipped']));
        foreach ($r['skipped'] as $d) {
            printf("  %-14s %-30s kniha %s, zbývá %s, podle knihy po pozdějších platbách %s: %s\n", $d['document_no'], mb_strimwidth($d['client'], 0, 30, '…'),
                $fmt($d['book_remaining']), $fmt($d['remaining']), $fmt($d['expected_remaining']), $d['reason']);
        }
    }
    if ($r['not_found'] !== []) {
        printf("V knize, ve firmě nenalezeno (%d): %s\n", count($r['not_found']), implode(', ', $r['not_found']));
    }
    if ($r['missing'] !== []) {
        $open = array_values(array_filter($r['missing'], static fn (array $d): bool => abs($d['remaining']) >= 0.01));
        printf("Doklady před agendou, které v knize nejsou (k %s uhrazené), beze změny: %d, z toho otevřených %d\n", $asOf, count($r['missing']), count($open));
        foreach ($quiet ? [] : $open as $d) {
            printf("  %-14s %-30s zbývá %s, úhrada minulých let %s\n", $d['document_no'], mb_strimwidth($d['client'], 0, 30, '…'), $fmt($d['remaining']), $fmt($d['old_prior']));
        }
    }
    if ($r['status'] === 'skipped') {
        printf("Nezapsáno: %s\n", $r['reason']);
        exit(1);
    }
    if ($r['status'] === 'repaired') {
        echo 'Zapsáno.' . PHP_EOL;
    } elseif ($r['status'] === 'would_repair') {
        echo PHP_EOL . 'Nic nezměněno. Zápis provede spuštění s --commit.' . PHP_EOL;
    } else {
        echo 'Není co měnit.' . PHP_EOL;
    }
    exit(0);
}

$repair = Bootstrap::buildContainer()->get(PohodaPriorYearPaymentRepair::class);
$rows = $repair->run($all ? $repair->convertedSuppliers() : array_values($suppliers), $commit);

echo ($commit ? '' : '[NANEČISTO] ') . 'Úhrady minulých let u vydaných dokladů převedených z POHODY' . PHP_EOL;
$total = 0;
$sum = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'skipped') {
        printf("Firma %d %s: přeskočeno, %s\n", $r['supplier_id'], $r['company'], $r['reason']);
        continue;
    }
    printf("Firma %d %s (platba k %s): %d dokladů, %s Kč%s%s\n", $r['supplier_id'], $r['company'], $r['paid_on'], $r['count'], $fmt($r['amount_czk']),
        $r['status'] === 'repaired' ? ', doplněno' : ($r['status'] === 'would_repair' ? ', k doplnění' : ''),
        $r['other_count'] > 0 ? sprintf('; jiný rozdíl u %d dokladů roku agendy (%s Kč) se neřeší', $r['other_count'], $fmt($r['other_amount_czk'])) : '');
    $total += $r['count'];
    $sum += $r['amount_czk'];
    if (!$quiet) {
        foreach ($r['invoices'] as $i) {
            printf("  #%d  %-16s %-9s %-6s %s  k úhradě %s  uhrazeno %s  platby %s  doplnit %s %s\n",
                $i['id'], $i['document_no'], $i['invoice_type'], $i['status'], $i['issue_date'], $fmt($i['amount_to_pay']), $fmt($i['paid_total']),
                $fmt($i['paid_before']), $fmt($i['amount']), $i['currency']);
        }
    }
}
printf("Celkem %d dokladů, %s Kč.\n", $total, $fmt($sum));
if (!$commit && $total > 0) {
    echo PHP_EOL . 'Nic nezměněno. Zápis provede spuštění s --commit.' . PHP_EOL;
}
