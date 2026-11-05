<?php

declare(strict_types=1);

/**
 * MyÚčto — cron-maintenance-notice
 *
 * Každých 15 minut stáhne ze serveru provozovatele ohlášení plánované údržby
 * a uloží je do `app_meta`. Aplikace pak hlášku jen čte, žádné síťové volání
 * při načtení stránky. Viz {@see \MyInvoice\Service\System\MaintenanceNoticeService}.
 *
 * Plánování:
 *   - Linux/cron:        *\/15 * * * *  cd /path && php api/bin/cron-maintenance-notice.php
 *   - Windows/Scheduler: každých 15 minut, akce: php.exe api\bin\cron-maintenance-notice.php
 *
 * Idempotentní. Při chybě sítě zůstává poslední známá hláška.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\System\MaintenanceNoticeService;

$container = Bootstrap::buildContainer();
$conn = $container->get(Connection::class);

$run = CronRun::start($conn->pdo(), 'cron-maintenance-notice');

$result = $container->get(MaintenanceNoticeService::class)->refresh();

if (!$result['ok']) {
    fwrite(STDERR, "[cron-maintenance-notice] FAILED: {$result['error']}\n");
    $run->finish('error', ['error' => $result['error']], $result['error'], 1);
    exit(1);
}

echo "[cron-maintenance-notice] OK notices={$result['count']}\n";

$run->finish('ok', ['notices' => $result['count']]);
