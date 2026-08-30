<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Document\PayrollAnnualDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService;

$limit = 25;
$arguments = $_SERVER['argv'] ?? [];
foreach ($arguments as $argument) {
    if (!is_string($argument)) {
        continue;
    }
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(500, (int) substr($argument, 8)));
    }
}

$lockDir = RuntimePaths::storage('locks');
if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Nelze vytvořit adresář zámku.\n");
    exit(3);
}
$lock = fopen($lockDir . '/payroll-document-worker.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Worker mzdových dokumentů už běží.\n");
    exit(0);
}

try {
    $container = Bootstrap::buildContainer();
    $worker = $container->get(PayrollDocumentBatchQueueService::class);
    if (!$worker instanceof PayrollDocumentBatchQueueService) {
        throw new RuntimeException('Worker mzdových dokumentů není dostupný.');
    }
    // Měsíční pásky mají přednost: jsou vázané na výplatní termín, roční
    // dokumenty na lhůtu v měsících. Roční fronta dostane, co ze stropu zbude,
    // ale vždy aspoň jeden pokus — jinak by ji rušný měsíc vyhladověl.
    $monthly = $worker->processAvailable($limit);
    $annualWorker = $container->get(PayrollAnnualDocumentBatchQueueService::class);
    if (!$annualWorker instanceof PayrollAnnualDocumentBatchQueueService) {
        throw new RuntimeException('Worker ročních mzdových dokumentů není dostupný.');
    }
    $annual = $annualWorker->processAvailable(
        max(1, $limit - (int) $monthly['processed']),
    );
    fwrite(
        STDOUT,
        json_encode(
            ['monthly' => $monthly, 'annual' => $annual],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL,
    );
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
