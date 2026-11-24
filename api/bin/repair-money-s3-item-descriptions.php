<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ItemDescriptionRepair;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;

$backupPath = '';
$supplierId = false;
$commit = in_array('--commit', $argv, true);
$quiet = in_array('--quiet', $argv, true);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--backup=')) {
        $backupPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--supplier=')) {
        $supplierId = filter_var(substr($arg, 11), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    } elseif (!in_array($arg, ['--commit', '--quiet'], true)) {
        fwrite(STDERR, "Neznámý parametr {$arg}.\n");
        exit(2);
    }
}
if ($backupPath === '' || $supplierId === false) {
    fwrite(STDERR, "Použití: php api/bin/repair-money-s3-item-descriptions.php --backup=<rozbalená záloha agendy> --supplier=ID [--commit] [--quiet]\n");
    exit(2);
}

try {
    $backup = Ms3Backup::open($backupPath);
    $repair = Bootstrap::buildContainer()->get(MoneyS3ItemDescriptionRepair::class);
    echo ($commit ? '' : '[NANEČISTO] ') . "Popisy položek faktur převedených z Money S3, firma {$supplierId}" . PHP_EOL;
    $documents = 0;
    $items = 0;
    foreach ($repair->run($backup, $supplierId, $commit) as $document) {
        $documents++;
        $items += count($document['items']);
        if (!$quiet) {
            $side = $document['kind'] === MoneyS3ImportRepository::KIND_INVOICE ? 'FV' : 'FP';
            foreach ($document['items'] as $item) {
                printf("  %s %s (%d, ID %d), položka #%d (%s %%): %s -> %s\n", $side, $document['document_no'], $document['year'],
                    $document['document_id'], $item['id'], $item['rate'], $document['old'], $item['new']);
            }
        }
    }
    printf("%s dokladů: %d, položek: %d.\n", $commit ? 'Opraveno' : 'K opravě', $documents, $items);
    if (!$commit && $items > 0) {
        echo 'Zápis provede spuštění s --commit.' . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
