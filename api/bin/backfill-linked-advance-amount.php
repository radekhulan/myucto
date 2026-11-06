<?php

declare(strict_types=1);

/**
 * Doplní uhrazenou zálohu konečným přijatým fakturám, které se se zálohou propojily dřív,
 * než se záloha spárovala s platbou. Faktura pak dál chtěla doplatit i zálohu (issue #313).
 * Logika: MyInvoice\Service\PurchaseInvoice\LinkedAdvanceAmount. Mění jen
 * advance_paid_amount (z něj generovaný amount_to_pay) a stav „uhrazeno"; zápisy deníku ne.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill v api/bin/migrate.php.
 *
 * Použití:
 *   php api/bin/backfill-linked-advance-amount.php                     # dry-run (jen vypíše)
 *   php api/bin/backfill-linked-advance-amount.php --apply             # skutečně zapíše
 *   php api/bin/backfill-linked-advance-amount.php --supplier=1 --apply
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true) && !in_array('--dry-run', $argv, true);
$supplierId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $supplierId = (int) substr($arg, strlen('--supplier='));
    }
}

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$db = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $db->pdo();
$mode = $apply ? '' : '[DRY-RUN] ';

$pdo->beginTransaction();
try {
    $rows = (new \MyInvoice\Service\PurchaseInvoice\LinkedAdvanceAmount($db))->backfill($supplierId, $apply);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Doplnění uhrazené zálohy selhalo, beze změny: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($rows as $row) {
    printf("  %sfirma #%d konečná faktura #%d (záloha #%d): uhrazená záloha 0,00 → %s\n",
        $mode, $row['supplier_id'], $row['final_id'], $row['advance_id'], number_format($row['amount'], 2, ',', ' '));
}
if ($rows === []) {
    echo "Žádná konečná faktura s chybějící uhrazenou zálohou.\n";
    exit(0);
}
echo "\n{$mode}Konečných faktur " . ($apply ? 'doplněno' : 'k doplnění') . ': ' . count($rows) . ".\n";
if (!$apply) {
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
}
