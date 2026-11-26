<?php

declare(strict_types=1);

/**
 * Ostatní závazky POHODY převedené jako přijaté faktury (převod před verzí 6.72) převede na
 * ostatní závazky, které převezmou svůj zápis z deníku POHODY; bez zápisu se založí jako
 * potvrzené bez zaúčtování. Úhrady z banky a pokladny se přesunou na položku, nic se
 * neúčtuje. Ostatní závazek s DPH zůstává přijatou fakturou; zápis s původním číslem
 * přečíslovaného dokladu dostane vazbu. Pravidla výběru a pojistky jsou
 * v {@see \MyInvoice\Service\Migration\Pohoda\PohodaOtherLiabilityRepair}.
 *
 * Výchozí je zkouška nanečisto: oprava proběhne celá i s výpočtem saldokonta 321 a 325
 * proti hlavní knize a na konci se vrátí. Zápis jen s --commit. Idempotentní.
 *
 * Použití:
 *   php api/bin/repair-pohoda-other-liabilities.php --all-pohoda                  # nanečisto, všechny firmy převedené z POHODY
 *   php api/bin/repair-pohoda-other-liabilities.php --supplier=12 --supplier=14   # nanečisto, vybrané firmy
 *   php api/bin/repair-pohoda-other-liabilities.php --supplier=12 --commit        # zápis
 *   php api/bin/repair-pohoda-other-liabilities.php --all-pohoda --include-posted # i zaúčtované přijaté faktury z ostatních závazků bez DPH
 *   php api/bin/repair-pohoda-other-liabilities.php --all-pohoda --quiet          # jen souhrn po firmách
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaOtherLiabilityRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all-pohoda', $argv, true);
$includePosted = in_array('--include-posted', $argv, true);
$suppliers = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $id = (int) substr($arg, 11);
        if ($id < 1) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
        $suppliers[$id] = $id;
    } elseif (!in_array($arg, ['--commit', '--quiet', '--all-pohoda', '--include-posted'], true)) {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all-pohoda.\n");
    exit(2);
}

$fmt = static fn (?float $v): string => $v === null ? '?' : number_format($v, 2, ',', ' ');
$actions = ['other_item_posted' => 'ostatní závazek se zápisem', 'other_item' => 'ostatní závazek bez zápisu', 'link' => 'vazba na zápis'];

$repair = Bootstrap::buildContainer()->get(PohodaOtherLiabilityRepair::class);
echo ($commit ? '' : '[NANEČISTO] ') . 'Ostatní závazky POHODY převedené jako přijaté faktury' . PHP_EOL;
$sum = ['converted' => 0, 'converted_czk' => 0.0, 'linked' => 0, 'refused' => 0, 'refused_czk' => 0.0, 'failed' => 0];
$print = static function (array $r) use (&$sum, $quiet, $fmt, $actions): void {
    if ($r['status'] === 'failed') {
        $sum['failed']++;
        printf("Firma %d %s: CHYBA, změny firmy vráceny: %s\n", $r['supplier_id'], $r['company'], $r['reason']);
        return;
    }
    if ($r['status'] === 'skipped') {
        if (!$quiet) {
            printf("Firma %d %s: přeskočeno, %s\n", $r['supplier_id'], $r['company'], $r['reason']);
        }
        return;
    }
    $t = $r['totals'];
    if ($quiet && $r['documents'] === []) {
        return;
    }
    printf("Firma %d %s: převést %d dokladů (%s Kč; se zápisem %d, bez zápisu %d), navázat %d, odmítnuto %d (%s Kč); ponecháno: zaúčtovaných %d, s DPH %d%s\n",
        $r['supplier_id'], $r['company'], $t['converted'], $fmt($t['converted_czk']), $t['posted'], $t['unposted'], $t['linked'],
        $t['refused'], $fmt($t['refused_czk']), $t['posted_kept'], $t['vat_kept'],
        $r['status'] === 'repaired' ? ', zapsáno' : ($r['status'] === 'would_repair' ? ', k opravě' : ''));
    if ($r['reason'] !== null) {
        printf("  %s\n", $r['reason']);
    }
    foreach ($r['saldo'] as $code => $s) {
        printf("  saldokonto %s k %s, rozdíl proti hlavní knize: %s -> %s Kč\n", $code, $r['saldo_as_of'] ?? '?', $fmt($s['before']), $fmt($s['after']));
    }
    if (!$quiet) {
        foreach ($r['documents'] as $d) {
            printf("  #%-7d %-14s %s %12s  %-30s %s\n", $d['id'], $d['document_no'], $d['issue_date'], $fmt($d['amount']),
                mb_strimwidth($d['title'], 0, 30, '…'),
                $d['state'] === 'refused' ? 'ODMÍTNUTO: ' . $d['reason']
                    : ($actions[$d['action']] ?? $d['action']) . ($d['account'] !== null ? ', účet ' . $d['account'] : '')
                        . ($d['entry_id'] !== null ? ', zápis #' . $d['entry_id'] : '') . ($d['payments'] > 0 ? ', úhrad ' . $d['payments'] : ''));
        }
    }
    foreach (['converted', 'linked', 'refused'] as $k) {
        $sum[$k] += $t[$k];
    }
    $sum['converted_czk'] += $t['converted_czk'];
    $sum['refused_czk'] += $t['refused_czk'];
};
$repair->run($all ? $repair->convertedSuppliers() : array_values($suppliers), $commit, null, $includePosted, $print);

printf("Celkem převést %d dokladů (%s Kč), navázat %d, odmítnuto %d (%s Kč), firem s chybou %d.\n", $sum['converted'], $fmt($sum['converted_czk']), $sum['linked'],
    $sum['refused'], $fmt($sum['refused_czk']), $sum['failed']);
if (!$commit && $sum['converted'] + $sum['linked'] > 0) {
    echo PHP_EOL . 'Nic nezměněno. Zápis provede spuštění s --commit.' . PHP_EOL;
}
exit($sum['failed'] > 0 ? 1 : 0);
