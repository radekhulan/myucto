<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportQueueService;

$limit = 1;
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(20, (int) substr($argument, 8)));
    }
}
$lockDir = RuntimePaths::storage('locks');
if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Nelze vytvořit adresář zámku.\n");
    exit(3);
}
$lock = fopen($lockDir . '/payroll-period-export-worker.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Worker exportu mezd už běží.\n");
    exit(0);
}
try {
    $container = Bootstrap::buildContainer();
    $worker = $container->get(PayrollPeriodExportQueueService::class);
    if (!$worker instanceof PayrollPeriodExportQueueService) {
        throw new RuntimeException('Worker exportu mezd není dostupný.');
    }
    fwrite(STDOUT, json_encode(
        $worker->processAvailable($limit),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
