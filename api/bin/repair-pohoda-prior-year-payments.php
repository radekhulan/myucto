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
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all-pohoda', $argv, true);
$suppliers = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
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

$repair = Bootstrap::buildContainer()->get(PohodaPriorYearPaymentRepair::class);
$rows = $repair->run($all ? $repair->convertedSuppliers() : array_values($suppliers), $commit);

$fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
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
