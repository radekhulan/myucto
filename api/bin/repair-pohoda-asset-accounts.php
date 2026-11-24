<?php

declare(strict_types=1);

/**
 * Doplnění účtů majetku a oprávek na kartách převedených z POHODY podle počátečních stavů a deníku.
 *
 * Použití:
 *   php api/bin/repair-pohoda-asset-accounts.php --all-pohoda
 *   php api/bin/repair-pohoda-asset-accounts.php --supplier=12 --supplier=14
 *   php api/bin/repair-pohoda-asset-accounts.php --supplier=12 --commit
 *   php api/bin/repair-pohoda-asset-accounts.php --all-pohoda --quiet
 */

if (PHP_SAPI !== 'cli') {
    exit("Pouze z příkazové řádky.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaAssetAccountRepair;

$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$all = in_array('--all-pohoda', $argv, true);
$suppliers = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $id = filter_var(substr($arg, 11), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
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

$repair = Bootstrap::buildContainer()->get(PohodaAssetAccountRepair::class);
$rows = $repair->run($all ? $repair->convertedSuppliers() : array_values($suppliers), $commit);

echo ($commit ? '' : '[NANEČISTO] ') . 'Účty majetku a oprávek na kartách převedených z POHODY' . PHP_EOL;
$total = 0;
$review = 0;
$label = $commit ? 'opraveno' : 'k opravě';
foreach ($rows as $row) {
    if ($row['reason'] !== null) {
        printf("Firma %d %s: přeskočeno, %s\n", $row['supplier_id'], $row['company'], $row['reason']);
        continue;
    }
    $count = count(array_filter($row['cards'], static fn (array $card): bool => $card['reason'] === null));
    $skipped = count($row['cards']) - $count;
    $total += $count;
    $review += $skipped;
    printf("Firma %d %s: %s %d, ke kontrole %d\n", $row['supplier_id'], $row['company'], $label, $count, $skipped);
    if (!$quiet) {
        foreach ($row['cards'] as $card) {
            $changes = array_filter([
                $card['new_account'] === null ? null : sprintf('majetkový účet %s → %s', $card['asset_account_code'], $card['new_account']),
                $card['new_accumulated_account'] === null ? null : sprintf('účet oprávek %s → %s', $card['accumulated_account_code'], $card['new_accumulated_account']),
            ]);
            $change = $card['reason'] ?? implode(', ', $changes);
            printf("  #%d  %s  %s  %s Kč  %s\n", $card['id'], $card['inventory_number'], $card['name'],
                number_format($card['opening_price'], 2, ',', ' '), $change);
        }
    }
}
printf("Celkem %s %d karet, ke kontrole %d.\n", $label, $total, $review);
