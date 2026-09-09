<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use MyInvoice\Service\Stock\StockValuationJobService;

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
$run = CronRun::start($pdo, 'cron-catalog-worker');
$stats = ['processed' => 0, 'failed' => 0];
$lanes = $pdo->query("SELECT DISTINCT supplier_id, kind FROM catalog_jobs WHERE kind IN ('price_recompute','stock_valuation') AND status IN ('queued','running') ORDER BY supplier_id, kind")->fetchAll(PDO::FETCH_ASSOC);
foreach ($lanes as $lane) {
    try {
        $handler = $lane['kind'] === 'stock_valuation' ? StockValuationJobService::class : CatalogPriceJobService::class;
        $result = $container->get($handler)->tick((int) $lane['supplier_id']);
        if ($result !== null) {
            $stats['processed']++;
        }
    } catch (Throwable) {
        $stats['failed']++;
    }
}
$run->finish($stats['failed'] > 0 ? 'error' : 'ok', $stats);
echo json_encode($stats, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($stats['failed'] > 0 ? 1 : 0);
