<?php

declare(strict_types=1);

/**
 * Backfill koncovky karty (bank_transactions.card_last4) z maskovaného čísla
 * v popisu pohybů importovaných před zavedením sloupce. Logika a zdůvodnění, proč
 * nejde o UPDATE v migraci: MyInvoice\Service\Bank\Card\CardLast4Backfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill
 * v api/bin/migrate.php, když najde pohyby k doplnění.
 *
 * Použití:
 *   php api/bin/backfill-card-last4.php           # dry-run (jen vypíše)
 *   php api/bin/backfill-card-last4.php --apply   # skutečně zapíše
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$mode = $apply ? '' : '[DRY-RUN] ';
$result = (new \MyInvoice\Service\Bank\Card\CardLast4Backfill($pdo))->run(
    $apply,
    static function (int $id, string $last4) use ($mode): void {
        echo "  {$mode}pohyb #{$id} → karta …{$last4}\n";
    },
);

if ($result['found'] === 0) {
    echo "Žádné pohyby s maskovaným číslem karty bez koncovky — nic k doplnění.\n";
    exit(0);
}
if ($apply) {
    echo "\nHotovo. Nalezeno: {$result['found']}, doplněno: {$result['updated']}\n";
} else {
    echo "\n{$mode}Nalezeno {$result['found']} pohybů. Spusť znovu s --apply pro skutečný zápis.\n";
}
