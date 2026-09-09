<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Eshop\CatalogJobDispatcher;

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
$run = CronRun::start($pdo, 'cron-catalog-worker');
$stats = $container->get(CatalogJobDispatcher::class)->tick();
$run->finish($stats['failed'] > 0 ? 'error' : 'ok', $stats);
echo json_encode($stats, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($stats['failed'] > 0 ? 1 : 0);
