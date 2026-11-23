<?php

declare(strict_types=1);

/**
 * Oprava přijatých dokladů převedených z POHODY s prohozeným DUZP a datem odpočtu.
 *
 * Převod z POHODY zapisoval „Datum odp./doruč." do DUZP (`tax_date`) a „Datum KH DPH"
 * (DUZP z dokladu dodavatele) do ručního data přijetí. Přiznání k DPH tím zůstalo
 * správné, kontrolní hlášení ale neslo jako DPPD datum odpočtu. Skript data prohodí,
 * podrobnosti a pravidla výběru jsou v {@see \MyInvoice\Service\Migration\Pohoda\PohodaDuzpRepair}.
 *
 * Doklady v obdobích s podaným přiznáním nebo KH, v uzavřeném účetním období, pod zámkem
 * k datu, zaúčtované v MyÚčtu a zahraniční přenesení s daty v různých měsících skript
 * přeskočí a vypíše s důvodem. Idempotentní: opravený doklad výběru už neodpovídá.
 *
 * Použití:
 *   php api/bin/repair-pohoda-duzp.php                    # nanečisto, všechny firmy
 *   php api/bin/repair-pohoda-duzp.php --supplier=12      # nanečisto, jedna firma
 *   php api/bin/repair-pohoda-duzp.php --supplier=12 --apply
 *   php api/bin/repair-pohoda-duzp.php --quiet            # jen souhrn bez seznamu dokladů
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaDuzpRepair;

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

$rows = Bootstrap::buildContainer()->get(PohodaDuzpRepair::class)->run($supplierId, $apply);

echo ($apply ? '' : '[NANEČISTO] ') . 'Přijaté doklady z POHODY s prohozeným DUZP a datem odpočtu: ' . count($rows) . PHP_EOL;
$counts = [];
foreach ($rows as $r) {
    $key = $r['status'] . ($r['reason'] !== null ? ' (' . $r['reason'] . ')' : '');
    $counts[$r['supplier_id']][$key] = ($counts[$r['supplier_id']][$key] ?? 0) + 1;
    if (!$quiet) {
        printf(
            "  firma %d  #%d  %-20s  DUZP %s -> %s  přijetí %s -> %s  %s%s\n",
            $r['supplier_id'], $r['id'], $r['document_no'], $r['tax_date'], $r['received_at'], $r['received_at'], $r['tax_date'],
            $r['status'], $r['reason'] !== null ? ': ' . $r['reason'] . ($r['detail'] !== null ? ' - ' . $r['detail'] : '') : '',
        );
    }
}
foreach ($counts as $sid => $byStatus) {
    ksort($byStatus);
    echo "Firma {$sid}:" . PHP_EOL;
    foreach ($byStatus as $status => $n) {
        echo "  {$status}: {$n}" . PHP_EOL;
    }
}
if (!$apply && $rows !== []) {
    echo PHP_EOL . 'Nic nezměněno. Opravu provede spuštění s --apply.' . PHP_EOL;
}
