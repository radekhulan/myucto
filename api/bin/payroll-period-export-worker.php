<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportQueueService;

$limit = 1;
$drain = false;
$jobId = null;
$supplierId = null;
$maxSeconds = PayrollPeriodExportQueueService::DRAIN_MAX_SECONDS;
$maxIterations = PayrollPeriodExportQueueService::DRAIN_MAX_ITERATIONS;
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (!is_string($argument)) {
        continue;
    }
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(20, (int) substr($argument, 8)));
    } elseif ($argument === '--drain') {
        $drain = true;
    } elseif (str_starts_with($argument, '--job-id=')) {
        // Cílený běh z aplikace: uživatel čeká na TENHLE archiv, ne na frontu.
        $jobId = max(0, (int) substr($argument, 9));
        $drain = true;
    } elseif (str_starts_with($argument, '--supplier-id=')) {
        $supplierId = max(0, (int) substr($argument, 14));
    } elseif (str_starts_with($argument, '--max-seconds=')) {
        $maxSeconds = max(1, min(900, (int) substr($argument, 14)));
    } elseif (str_starts_with($argument, '--max-iterations=')) {
        $maxIterations = max(1, min(1000, (int) substr($argument, 17)));
    }
}
$lockDir = RuntimePaths::storage('locks');
if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Nelze vytvořit adresář zámku.\n");
    exit(3);
}
$lock = fopen($lockDir . '/payroll-period-export-worker.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Souběh spawnu z aplikace a cronu je normální stav, ne chyba: kdo přišel
    // druhý, prostě skončí a práci dodělá ten první (nebo příští cron tick).
    fwrite(STDOUT, "Worker exportu mezd už běží.\n");
    exit(0);
}
try {
    $container = Bootstrap::buildContainer();
    $worker = $container->get(PayrollPeriodExportQueueService::class);
    if (!$worker instanceof PayrollPeriodExportQueueService) {
        throw new RuntimeException('Worker exportu mezd není dostupný.');
    }
    $summary = $drain
        ? $worker->drain(
            $supplierId !== null && $supplierId > 0 ? $supplierId : null,
            $jobId !== null && $jobId > 0 ? $jobId : null,
            $maxIterations,
            $maxSeconds,
        )
        : $worker->processAvailable($limit);
    fwrite(STDOUT, json_encode(
        $summary,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
