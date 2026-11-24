<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3VatRateAccounts;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;

$backupPath = '';
$supplierId = false;
$commit = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--backup=')) {
        $backupPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--supplier=')) {
        $supplierId = filter_var(substr($arg, 11), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    } elseif ($arg === '--commit') {
        $commit = true;
    } else {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($backupPath === '' || $supplierId === false) {
    fwrite(STDERR, "Použití: php api/bin/repair-money-s3-vat-rate-accounts.php --backup=<soubor .lz nebo rozbalená záloha> --supplier=ID [--commit]\n");
    exit(2);
}

$tmp = '';
$exitCode = 0;
try {
    if (is_dir($backupPath)) {
        $backup = Ms3Backup::open($backupPath);
    } else {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-money-vat-' . bin2hex(random_bytes(12));
        if (!mkdir($tmp, 0700)) {
            throw new RuntimeException('Nepodařilo se vytvořit dočasný adresář pro zálohu.');
        }
        $backup = Ms3Backup::extract($backupPath, $tmp);
    }
    $service = Bootstrap::buildContainer()->get(MoneyS3VatRateAccounts::class);
    $plan = $service->run($backup, $supplierId, $commit);
    echo ($commit ? 'Doplnění' : '[NANEČISTO] Návrh') . " kontací DPH pro firmu {$supplierId}\n";
    foreach ($plan as $rule) {
        printf("  %s (MD %s / D %s)\n", $rule['rule_key'], $rule['debit_account_code'] ?? '-', $rule['credit_account_code'] ?? '-');
    }
    if ($plan === []) {
        echo "Žádné nové kontace k doplnění.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    MoneyS3BatchImporter::removeTree($tmp);
}
exit($exitCode);
