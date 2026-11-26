<?php

declare(strict_types=1);

/**
 * Doplnění evidence úhrad vydaných dokladů převedených z POHODY nebo z Heliosu, jejichž
 * uhrazenou částku (`paid_total`) nenesou evidované platby.
 *
 * Převod z POHODY dřív dorovnání úhrady zakládal jen fakturám s datem úhrady; zálohové
 * listy, částečně uhrazené doklady, doklady bez data úhrady a doklady převedené před
 * zavedením dorovnání nesly uhrazenou část jen v `paid_total`, převod z Heliosu vždy.
 * První změna plateb dokladu (spárování, smazání platby) ji přepočtem smaže a doklad se
 * vrátí mezi neuhrazené. Skript doplní chráněnou platbu dorovnání („Převzato z POHODY,
 * dorovnání úhrady", resp. „Převzato z Heliosu, dorovnání úhrady", zdroj `settlement`)
 * k datu úhrady podle převodu, u dokladu bez data úhrady k DUZP. Deník, `paid_total`,
 * stav ani datum úhrady se nemění. Pravidla a pojistky jsou v
 * {@see \MyInvoice\Service\Migration\Shared\IssuedRemainderRepair}.
 *
 * U POHODY jen doklady roku agendy: úhrady minulých let (doklady vystavené před začátkem
 * agendy) řeší `api/bin/repair-pohoda-prior-year-payments.php`, tento skript je nemění. Jen ohlásí doklady
 * vyrovnané ručním zápisem v deníku (platba by vyrovnání započetla podruhé) a doklady,
 * jejichž datum dorovnání leží za zámkem účtování nebo v podaném období DPH.
 *
 * Výchozí je zkouška nanečisto s výpisem, zápis jen s --commit. Idempotentní.
 *
 * Použití:
 *   php api/bin/repair-issued-remainders.php --source=pohoda --all                 # nanečisto, všechny firmy převedené z POHODY
 *   php api/bin/repair-issued-remainders.php --source=helios --all --quiet         # Helios, jen souhrn po firmách
 *   php api/bin/repair-issued-remainders.php --source=pohoda --supplier=12 --supplier=14
 *   php api/bin/repair-issued-remainders.php --source=pohoda --supplier=12 --commit   # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Shared\IssuedRemainderRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all', $argv, true);
$source = null;
$suppliers = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--source=')) {
        $source = substr($arg, 9);
    } elseif (str_starts_with($arg, '--supplier=')) {
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
if (!in_array($source, IssuedRemainderRepair::SOURCES, true)) {
    fwrite(STDERR, 'Zadejte --source=' . implode('|', IssuedRemainderRepair::SOURCES) . ".\n");
    exit(2);
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all.\n");
    exit(2);
}

$repair = Bootstrap::buildContainer()->get(IssuedRemainderRepair::class);
$fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
$labels = [
    IssuedRemainderRepair::FIXABLE => 'k doplnění',
    IssuedRemainderRepair::LINKED_UNRECORDED => 'spárováno bez záznamu platby, doplní párování',
    IssuedRemainderRepair::JOURNAL_SETTLED => 'vyrovnáno ručním zápisem, jen ohlásit',
    IssuedRemainderRepair::LOCKED => 'zamčené nebo podané období',
];
echo ($commit ? '' : '[NANEČISTO] ') . 'Dorovnání úhrad vydaných dokladů převedených z ' . ($source === 'helios' ? 'Heliosu' : 'POHODY (rok agendy)') . PHP_EOL;
$totals = array_fill_keys(array_keys($labels), ['count' => 0, 'amount' => 0.0]);
$failed = 0;
foreach ($all ? $repair->convertedSuppliers($source) : array_values($suppliers) as $supplierId) {
    try {
        $r = $repair->run($source, [$supplierId], $commit)[0];
    } catch (Throwable $e) {
        $failed++;
        printf("Firma %d: chyba, nic nezapsáno: %s\n", $supplierId, $e->getMessage());
        continue;
    }
    if ($r['status'] === 'skipped') {
        printf("Firma %d %s: přeskočeno, %s\n", $r['supplier_id'], $r['company'], $r['reason']);
        continue;
    }
    if ($r['invoices'] === []) {
        continue;
    }
    $parts = [];
    foreach ($labels as $key => $label) {
        if ($r['counts'][$key] > 0) {
            $parts[] = sprintf('%s %d dokladů (%s Kč)', $label, $r['counts'][$key], $fmt($r['amount_czk'][$key]));
            $totals[$key]['count'] += $r['counts'][$key];
            $totals[$key]['amount'] += $r['amount_czk'][$key];
        }
    }
    printf("Firma %d %s%s: %s%s\n", $r['supplier_id'], $r['company'], $r['period_start'] !== null ? ' (agenda od ' . $r['period_start'] . ')' : '', implode('; ', $parts),
        $r['status'] === 'repaired' ? ', doplněno' : '');
    if (!$quiet) {
        foreach ($r['invoices'] as $i) {
            printf("  #%d  %-16s %-9s %-6s %s  uhrazeno %s  platby %s  doplnit %s %s k %s  %s\n",
                $i['id'], $i['document_no'], $i['invoice_type'], $i['status'], $i['issue_date'], $fmt($i['paid_total']),
                $fmt($i['paid_before']), $fmt($i['amount']), $i['currency'], $i['paid_on'], $labels[$i['category']]);
        }
    }
}
echo 'Celkem' . PHP_EOL;
foreach ($labels as $key => $label) {
    printf("  %s: %d dokladů, %s Kč\n", $label, $totals[$key]['count'], $fmt($totals[$key]['amount']));
}
if (!$commit && $totals[IssuedRemainderRepair::FIXABLE]['count'] > 0) {
    echo PHP_EOL . 'Nic nezměněno. Zápis provede spuštění s --commit.' . PHP_EOL;
}
exit($failed > 0 ? 1 : 0);
