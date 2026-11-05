<?php

declare(strict_types=1);

/**
 * Backfill splátky u úhrad ostatních položek (other_item_allocations.installment_id)
 * přiřazených před migrací 2237. Logika a zdůvodnění, proč nejde o UPDATE v migraci:
 * MyInvoice\Service\Accounting\OtherItemInstallmentBackfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill
 * v api/bin/migrate.php, když najde úhrady k doplnění.
 *
 * Použití:
 *   php api/bin/backfill-other-item-installments.php           # dry-run (jen vypíše)
 *   php api/bin/backfill-other-item-installments.php --apply   # skutečně zapíše
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$mode = $apply ? '' : '[DRY-RUN] ';
$result = (new \MyInvoice\Service\Accounting\OtherItemInstallmentBackfill($pdo))->run(
    $apply,
    static function (int $allocationId, int $installmentId) use ($mode): void {
        echo "  {$mode}úhrada #{$allocationId} → splátka #{$installmentId}\n";
    },
);

if ($result['found'] === 0) {
    echo "Žádné úhrady ostatních položek bez splátky, nic k doplnění.\n";
    exit(0);
}
if ($apply) {
    echo "\nHotovo. Nalezeno: {$result['found']}, doplněno: {$result['updated']}\n";
} else {
    echo "\n{$mode}Nalezeno {$result['found']} úhrad. Spusť znovu s --apply pro skutečný zápis.\n";
}
