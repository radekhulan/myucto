<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Integration\WebhookDeliveryWorker;

if ($argc !== 1) {
    fwrite(STDERR, "Použití: php api/bin/cron-webhooks.php\n");
    exit(1);
}

$run = null;
try {
    $pdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
    $run = CronRun::start($pdo, 'cron-webhooks');
    $result = ['processed' => CronPreflight::hasWebhookDeliveryWork($pdo)
        ? Bootstrap::buildContainer()->get(WebhookDeliveryWorker::class)->run()
        : 0];
    $run->finish('ok', $result);
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    $run?->finish('error', ['error' => $e::class]);
    fwrite(STDERR, 'Doručování webhooků selhalo (' . $e::class . ').' . PHP_EOL);
    exit(1);
}
