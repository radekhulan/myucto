<?php

declare(strict_types=1);

/**
 * Zapečetění rodných čísel dětí a manžela/manželky v daňovém profilu (H28),
 * která byla do migrace 2160 uložená otevřeně. Logika a zdůvodnění, proč nejde
 * o UPDATE v migraci: MyInvoice\Service\Tax\TaxFamilyBirthNumberBackfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill
 * v api/bin/migrate.php, když najde řádky k převodu. Hodnoty nevypisuje.
 *
 * Použití:
 *   php api/bin/backfill-tax-family-birth-numbers.php           # dry-run (jen ověří zapečetění)
 *   php api/bin/backfill-tax-family-birth-numbers.php --apply   # skutečně zapíše
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true);

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$sensitive = $container->get(\MyInvoice\Service\Payroll\Security\PayrollSensitiveData::class);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = (new \MyInvoice\Service\Tax\TaxFamilyBirthNumberBackfill($pdo, $sensitive))->run(
    $apply,
    static function (string $table, int $id, string $outcome) use ($mode): void {
        echo "  {$mode}{$table} #{$id} → {$outcome}\n";
    },
);

if ($result['found'] === 0) {
    echo "Žádná otevřeně uložená rodná čísla v daňovém profilu — nic k převodu.\n";
    exit(0);
}
if ($apply) {
    echo "\nHotovo. Nalezeno: {$result['found']}, zapečetěno: {$result['sealed']}, selhalo: {$result['failed']}\n";
} else {
    echo "\n{$mode}Nalezeno {$result['found']} řádků, ověření selhalo u {$result['failed']}. Spusť znovu s --apply pro skutečný zápis.\n";
}
exit($result['failed'] > 0 ? 1 : 0);
