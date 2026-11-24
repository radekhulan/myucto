<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Integration\InvoicePaidWebhookWorker;

$daemon = in_array('--watch', $argv, true);
$container = Bootstrap::buildContainer();
$worker = $container->get(InvoicePaidWebhookWorker::class);
$pdo = $container->get(Connection::class)->pdo();
do {
    $run = $daemon ? null : CronRun::start($pdo, 'cron-invoice-webhooks');
    try {
        $count = $worker->run();
        $run?->finish('ok', ['processed' => $count]);
    } catch (Throwable $e) {
        $run?->finish('error', ['error' => 'webhook_worker_failed']);
        fwrite(STDERR, "Zpracování fronty webhooků selhalo.\n");
        exit(1);
    }
    if ($daemon && $count < 25) sleep(2);
} while ($daemon);
