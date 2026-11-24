<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3AdvanceAccounts;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;

$backupPath = null;
$supplierId = false;
$fromJournal = false;
$commit = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--backup=')) {
        $backupPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--supplier=')) {
        $supplierId = filter_var(substr($arg, 11), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    } elseif ($arg === '--from-journal') {
        $fromJournal = true;
    } elseif ($arg === '--commit') {
        $commit = true;
    } else {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($supplierId === false || $backupPath === '' || $fromJournal === ($backupPath !== null)) {
    fwrite(STDERR, "Použití: php api/bin/repair-money-s3-advance-accounts.php (--backup=<rozbalená záloha> | --from-journal) --supplier=ID [--commit]\n");
    exit(2);
}

try {
    $backup = $fromJournal ? null : Ms3Backup::open($backupPath);
    $service = Bootstrap::buildContainer()->get(MoneyS3AdvanceAccounts::class);
    $result = $service->run($backup, $supplierId, $commit);
    echo ($commit ? 'Doplnění' : '[NANEČISTO] Návrh') . " předkontací záloh pro firmu {$supplierId}\n";
    foreach ($result['current'] as $rule) {
        $label = $rule['supplier_id'] === null ? 'globální' : ($rule['is_active'] ? 'vlastní, ponecháno' : 'vlastní, vypnuto a ponecháno');
        printf("  Stávající %s (MD %s / D %s, %s)\n", $rule['rule_key'], $rule['debit_account_code'] ?? '-', $rule['credit_account_code'] ?? '-', $label);
    }
    foreach ($result['rules'] as $rule) {
        printf("  %s %s (MD %s / D %s)\n", $commit ? 'Doplněno' : 'Návrh', $rule['rule_key'], $rule['debit_account_code'] ?? '-', $rule['credit_account_code'] ?? '-');
    }
    foreach ($result['warnings'] as $warning) {
        echo 'Varování: ' . $warning['text'] . PHP_EOL;
    }
    if ($result['rules'] === []) {
        echo "Žádné nové předkontace k doplnění.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
