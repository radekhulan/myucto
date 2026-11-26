<?php

declare(strict_types=1);

/**
 * Oprava dokladů minulého období převedených z POHODY jako koncept k ruční kontrole DPH.
 *
 * Převod dřív nechal v konceptu i doklad minulého období (zůstatek v počátečních stavech),
 * když jeho členění DPH neuměl zařadit. Saldokonto koncepty nebere, takže rozdíl proti hlavní
 * knize narostl o neuhrazenou část dokladu. Skript doklad převede do stavu, jaký by dostal
 * bez kontroly, podrobnosti a pravidla výběru jsou
 * v {@see \MyInvoice\Service\Migration\Pohoda\PohodaPreviousPeriodDraftRepair}.
 *
 * Doklady v období s podaným přiznáním nebo KH a s otevíracím zápisem v uzavřeném účetním
 * období nebo pod zámkem k datu skript přeskočí a vypíše s důvodem. Idempotentní.
 *
 * Použití:
 *   php api/bin/repair-pohoda-previous-period-drafts.php                    # nanečisto, všechny firmy
 *   php api/bin/repair-pohoda-previous-period-drafts.php --supplier=12      # nanečisto, jedna firma
 *   php api/bin/repair-pohoda-previous-period-drafts.php --supplier=12 --apply
 *   php api/bin/repair-pohoda-previous-period-drafts.php --quiet            # jen souhrn bez seznamu dokladů
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaPreviousPeriodDraftRepair;

$apply = in_array('--apply', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$supplierId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $supplierId = (int) substr($arg, 11);
        if ($supplierId < 1) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
    }
}

$rows = Bootstrap::buildContainer()->get(PohodaPreviousPeriodDraftRepair::class)->run($supplierId, $apply);

echo ($apply ? '' : '[NANEČISTO] ') . 'Doklady minulého období z POHODY v konceptu: ' . count($rows) . PHP_EOL;
$summary = [];
foreach ($rows as $r) {
    $key = $r['status'] . ($r['reason'] !== null ? ' (' . $r['reason'] . ')' : '');
    $summary[$r['supplier_id']][$key]['n'] = ($summary[$r['supplier_id']][$key]['n'] ?? 0) + 1;
    $summary[$r['supplier_id']][$key]['total'] = ($summary[$r['supplier_id']][$key]['total'] ?? 0.0) + $r['total'];
    if (!$quiet) {
        printf(
            "  firma %d  %-16s #%d  %-20s  DUZP %s  %14s Kč  -> %-8s %s%s\n",
            $r['supplier_id'], $r['doc_type'], $r['id'], $r['document_no'], $r['tax_date'], number_format($r['total'], 2, ',', ' '),
            $r['new_status'], $r['status'], $r['reason'] !== null ? ': ' . $r['reason'] . ($r['detail'] !== null ? ' - ' . $r['detail'] : '') : '',
        );
    }
}
foreach ($summary as $sid => $byStatus) {
    ksort($byStatus);
    echo "Firma {$sid}:" . PHP_EOL;
    foreach ($byStatus as $status => $s) {
        echo "  {$status}: {$s['n']} dokladů, " . number_format($s['total'], 2, ',', ' ') . ' Kč' . PHP_EOL;
    }
}
if (!$apply && $rows !== []) {
    echo PHP_EOL . 'Nic nezměněno. Opravu provede spuštění s --apply.' . PHP_EOL;
}
