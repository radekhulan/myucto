<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceService;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::load(Bootstrap::rootDir());
$connection = new Connection($config);
$run = CronRun::start($connection->pdo(), 'cron-logbook-odometer-source');

if (!CronPreflight::hasOdometerSourceConnections($connection->pdo())) {
    fwrite(STDOUT, "[odometer-source] žádné auto napojené na zdroj stavu tachometru - přeskočeno.\n");
    $run->finish('ok', ['skipped' => 'no connected cars']);
    exit(0);
}

try {
    $summary = Bootstrap::buildContainer()->get(OdometerSourceService::class)->syncAll();
    echo '[' . date('Y-m-d H:i:s') . '] odometer-source: '
        . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $run->finish(
        $summary['errors'] > 0 ? 'error' : 'ok',
        $summary,
        $summary['errors'] > 0 ? 'Stav tachometru se u některých aut nepodařilo načíst.' : null,
    );
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    // Zpráva výjimky z HTTP klienta může nést odpověď služby i s tokeny, do logu jde jen třída a kód.
    $error = ['error_class' => $e::class, 'error_code' => $e->getCode()];
    $run->finish('error', $error, 'Načtení stavu tachometru z aut selhalo.');
    fwrite(STDERR, '[odometer-source] načtení selhalo: ' . $e::class . ' (' . $e->getCode() . ")\n");
    exit(1);
}
