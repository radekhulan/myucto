<?php

declare(strict_types=1);

/**
 * Daily, read-only monitor of official MPSV/ČSSZ JMHZ documentation.
 *
 * It saves only local observation metadata. A detected document change never
 * imports a codebook and never alters any payroll setting or submission.
 */

require __DIR__ . '/../vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/tools/JmhzOfficialSourceMonitor.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Tooling\JmhzOfficialSourceMonitor;

$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$argument}\n");
    exit(1);
}

$container = Bootstrap::buildContainer();
$run = CronRun::start($container->get(Connection::class)->pdo(), 'cron-jmhz-source-monitor');

try {
    $sources = require dirname(__DIR__, 2) . '/tools/jmhz-official-source-monitor-sources.php';
    $monitor = new JmhzOfficialSourceMonitor($sources);
    $report = $monitor->monitor(RuntimePaths::storage('monitoring/jmhz-official-sources.json'), !$dryRun);
    $report['dry_run'] = $dryRun;
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $run->finish('ok', $report, null, 0, $report['baseline_created'] || $report['changed']);
} catch (Throwable $e) {
    fwrite(STDERR, "[cron-jmhz-source-monitor] FAILED: {$e->getMessage()}\n");
    $run->finish('error', ['error' => $e->getMessage()], $e->getMessage(), 1);
    exit(1);
}
