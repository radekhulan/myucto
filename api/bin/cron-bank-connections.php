<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Connector\BankConnectionService;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::load(Bootstrap::rootDir());
$connection = new Connection($config);
$run = CronRun::start($connection->pdo(), 'cron-bank-connections');

if (!CronPreflight::hasBankConnections($connection->pdo())) {
    fwrite(STDOUT, "[bank-connections] žádné aktivní bankovní spojení - přeskočeno.\n");
    $run->finish('ok', ['skipped' => 'no enabled bank connections']);
    exit(0);
}

try {
    $service = Bootstrap::buildContainer()->get(BankConnectionService::class);
    $summary = $service->syncAll();
    echo '[' . date('Y-m-d H:i:s') . '] bank-connections: '
        . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $run->finish(
        $summary['errors'] > 0 ? 'error' : 'ok',
        $summary,
        $summary['errors'] > 0 ? 'Některá bankovní spojení se nepodařilo synchronizovat.' : null,
    );
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable) {
    $run->finish('error', [], 'Synchronizace bankovních spojení selhala.');
    fwrite(STDERR, "[bank-connections] synchronizace selhala.\n");
    exit(1);
}
