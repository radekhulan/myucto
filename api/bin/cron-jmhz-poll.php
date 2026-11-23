<?php

declare(strict_types=1);

/**
 * Cron — dotažení protokolu ČSSZ a uzavření transakce u VREP (migrace 1379).
 * Obsluhuje hlášení JMHZ i odeslané registrace PREZEC/REGZEC; registrace
 * se sám neodesílá, jen se dotahuje výsledek už odeslaných.
 *
 * Použití:
 *   php api/bin/cron-jmhz-poll.php
 *   php api/bin/cron-jmhz-poll.php --limit=20
 *
 * Nahrazuje ruční klikání: dokud ČSSZ protokol nevydá, musel se uživatel sám
 * doptávat, a po dotažení sám mačkat „uzavřít transakci". Kdo přestal, nechal
 * podání viset ve stavu „převzato" a transakci otevřenou — přitom podací
 * protokol její uzavření vyžaduje.
 *
 * Skript je jen obal: rozhodování drží
 * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportSweepService},
 * ať jde otestovat bez procesu. Opakované spuštění je bezpečné — fronta bere
 * jen pokusy, kterým dozrál termín, a každý zápis do ledgeru posouvá
 * `row_version`, takže souběžný běh prohraje optimistický zámek.
 *
 * Preflight běží PŘED stavbou kontejneru: u typického tenanta nemá 99 % ticků
 * co dělat a stavět kvůli tomu celý DI kontejner je zbytečné.
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
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportSweepService;

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(200, (int) substr($arg, 8)));
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

$lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
if (!CronPreflight::hasJmhzTransportWork($lightPdo)) {
    $result = [
        'polled' => 0,
        'completed' => 0,
        'closed' => 0,
        'errors' => 0,
        'skipped' => 'no_open_attempts',
    ];
    CronRun::start($lightPdo, 'cron-jmhz-poll')->finish('ok', $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$container = Bootstrap::buildContainer();

/** @var Connection $connection */
$connection = $container->get(Connection::class);
/** @var JmhzTransportSweepService $sweep */
$sweep = $container->get(JmhzTransportSweepService::class);

$run = CronRun::start($connection->pdo(), 'cron-jmhz-poll');
try {
    $result = $sweep->run($limit);
    // Neúspěšný dotaz NENÍ selhání běhu: ČSSZ může být dočasně nedostupná a
    // pokus zůstává otevřený. Chybou je až to, když se nepodařilo vůbec nic.
    $status = $result['errors'] > 0 && $result['polled'] === $result['errors'] ? 'error' : 'ok';
    $run->finish($status, $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status === 'error' ? 2 : 0);
} catch (\Throwable $e) {
    $run->finish('error', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
