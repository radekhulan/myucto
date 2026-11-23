<?php

declare(strict_types=1);

/**
 * Doplnění úhrad bez dokladu úhrady u firem převedených z Money S3.
 *
 * Převod nesl úhradu zápočtem, interním dokladem nebo bez čísla dokladu úhrady jen
 * v uhrazené částce dokladu (`paid_total`); první spárování nebo smazání platby ji přepočtem
 * smaže a doklad se vrátí mezi neuhrazené. Skript doplní vydaným dokladům zápočet interním
 * dokladem navázaným na doklad a zbytek jako evidovanou platbu „Převzato z Money S3, úhrada
 * bez dokladu úhrady" k datu úhrady, přijatým dokladům zbytek do `settled_outside_amount`
 * a jediné platbě zálohy vazbu na jediný DDKP z Money. Deník, uhrazená částka ani stav
 * dokladů se nemění. Pravidla a pojistky jsou
 * v {@see \MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair}.
 *
 * Výchozí je zkouška nanečisto s výpisem, zápis jen s --commit. Idempotentní.
 *
 * Stejnou opravu spouští Kontrola dat po převodu (stránka v aplikaci a
 * `api/bin/migration-data-check.php --check=settled_without_payment`); tento skript zůstává tenkou obálkou
 * nad společnou službou opravy pro dávkové běhy přes všechny firmy.
 *
 * Použití:
 *   php api/bin/repair-money-s3-settled-payments.php --all                         # nanečisto, všechny firmy převedené z Money S3
 *   php api/bin/repair-money-s3-settled-payments.php --supplier=12 --supplier=14   # nanečisto, vybrané firmy
 *   php api/bin/repair-money-s3-settled-payments.php --supplier=12 --commit        # zápis
 *   php api/bin/repair-money-s3-settled-payments.php --all --quiet                 # jen souhrn po firmách
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all', $argv, true);
$suppliers = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $id = (int) substr($arg, 11);
        if ($id < 1) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
        $suppliers[$id] = $id;
    } elseif (!in_array($arg, ['--commit', '--quiet', '--all'], true)) {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all.\n");
    exit(2);
}

$repair = Bootstrap::buildContainer()->get(MoneyS3SettledPaymentRepair::class);
$rows = $repair->run($all ? $repair->convertedSuppliers() : array_values($suppliers), $commit);

$fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
echo ($commit ? '' : '[NANEČISTO] ') . 'Úhrady bez dokladu úhrady u dokladů převedených z Money S3' . PHP_EOL;
$totals = ['issued' => 0, 'issued_czk' => 0.0, 'purchase' => 0, 'purchase_czk' => 0.0, 'links' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'skipped') {
        printf("Firma %d %s: přeskočeno, %s\n", $r['supplier_id'], $r['company'], $r['reason']);
        continue;
    }
    printf("Firma %d %s (úhrady do %s): vydané %d dokladů, %s Kč (zápočty interním dokladem %d, %s Kč; bez dokladu úhrady %s Kč); "
        . "přijaté %d dokladů, %s Kč (zápočty interním dokladem %d, %s Kč; zbytek mimo evidenci); vazby platby na DDKP %d%s\n",
        $r['supplier_id'], $r['company'], $r['last_period_end'], $r['issued_count'], $fmt($r['issued_amount_czk']),
        $r['settlements'], $fmt($r['settlement_amount_czk']), $fmt($r['without_document_amount_czk']),
        $r['purchase_count'], $fmt($r['purchase_amount_czk']), $r['purchase_settlements'], $fmt($r['purchase_settlement_amount_czk']), $r['tax_document_links'],
        $r['status'] === 'repaired' ? ', doplněno' : ($r['status'] === 'would_repair' ? ', k doplnění' : ''));
    if ($r['left_for_matching'] !== []) {
        printf("  %d dokladů má úhradu v převedené bance bez evidované platby (výplň nevzniká, doplní ji opakovaný převod): %s Kč\n",
            count($r['left_for_matching']), $fmt(array_sum(array_map(static fn (array $i): float => $i['amount'] * ($i['currency'] === 'CZK' ? 1.0 : ($i['rate'] ?? 1.0)), $r['left_for_matching']))));
    }
    $totals['left'] = ($totals['left'] ?? 0) + count($r['left_for_matching']);
    $totals['issued'] += $r['issued_count'];
    $totals['issued_czk'] += $r['issued_amount_czk'];
    $totals['purchase'] += $r['purchase_count'];
    $totals['purchase_czk'] += $r['purchase_amount_czk'];
    $totals['links'] += $r['tax_document_links'];
    if (!$quiet) {
        foreach ($r['invoices'] as $i) {
            printf("  FV #%d  %-16s %-9s %-6s uhrazeno %s  uhrazená částka %s  platby %s  zápočet %s  bez dokladu %s %s%s\n",
                $i['id'], $i['document_no'], $i['invoice_type'], $i['status'], (string) $i['paid_at'], $fmt($i['paid_total']),
                $fmt($i['others'] + $i['own_amount']), $fmt($i['settled']), $fmt($i['amount']), $i['currency'],
                $i['action'] === 'deleted' ? ' (zrušeno, úhrada po konci převáděných období)' : '');
        }
        foreach ($r['purchases'] as $d) {
            printf("  FP #%d  %-20s uhrazeno %s  k úhradě %s  kryto %s  zápočet %s  mimo evidenci %s -> %s %s\n",
                $d['id'], $d['document_no'], $d['paid_at'], $fmt($d['amount_to_pay']), $fmt($d['covered']), $fmt($d['settled']), $fmt($d['outside']), $fmt($d['target']), $d['currency']);
        }
    }
}
printf("Celkem vydané %d dokladů, %s Kč; přijaté %d dokladů, %s Kč; vazby na DDKP %d; ponecháno párování %d.\n",
    $totals['issued'], $fmt($totals['issued_czk']), $totals['purchase'], $fmt($totals['purchase_czk']), $totals['links'], $totals['left'] ?? 0);
if (!$commit && ($totals['issued'] + $totals['purchase'] + $totals['links']) > 0) {
    echo PHP_EOL . 'Nic nezměněno. Zápis provede spuštění s --commit.' . PHP_EOL;
}
