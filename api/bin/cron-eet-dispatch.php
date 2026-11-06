<?php

declare(strict_types=1);

/**
 * Záložní worker EET: odešle čekající záznamy, zopakuje dočasné chyby a znovu
 * zkusí záznamy bez certifikátu. První pokus dělá příkaz hned po commitu; tenhle
 * běh zajišťuje doručení po výpadku FS, pádu procesu nebo doplnění certifikátu.
 *
 * Běží i bez komerční licence: již přijatou tržbu nelze zatajit licenční chybou.
 * Firmy s `eet_settings.dispatch_blocked = 1` a anonymizovaný klon databáze přeskočí.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Eet\EetDispatchService;

$limit = 100;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(500, (int) substr($arg, 8)));
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

// Preflight před stavbou kontejneru: většina minutových ticků nemá co odeslat.
$lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
if (!CronPreflight::hasEetDispatchWork($lightPdo)) {
    $result = ['processed' => 0, 'skipped' => 'no_open_records'];
    CronRun::start($lightPdo, 'cron-eet-dispatch')->finish('ok', $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$container = Bootstrap::buildContainer();

/** @var Connection $connection */
$connection = $container->get(Connection::class);
/** @var EetDispatchService $dispatch */
$dispatch = $container->get(EetDispatchService::class);

$run = CronRun::start($connection->pdo(), 'cron-eet-dispatch');
try {
    $result = ['processed' => $dispatch->processDue($limit)];
    $run->finish('ok', $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    $run->finish('error', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
