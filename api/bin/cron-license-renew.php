<?php

declare(strict_types=1);

/**
 * MyÚčto.cz — cron-license-renew (E4)
 *
 * Pravidelná obnova licenčního tokenu proti licenčnímu serveru. Běžně se síť
 * volá nejvýše jednou denně, kolem další platby a při past_due jednou za hodinu.
 *
 * Plánování:
 *   - Linux/cron:        15 * * * *  cd /path && php api/bin/cron-license-renew.php
 *   - Docker:            stejně, přes `docker compose exec app …`
 *   - Windows/Scheduler: hodinově, akce: php.exe api\bin\cron-license-renew.php
 *
 * Idempotentní: interval i souběh hlídá atomický mutex v LicenseService.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\TelemetryPayloadBuilder;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);

$run = CronRun::start($conn->pdo(), 'cron-license-renew');

// Telemetrie (H-21) se přiváží s obnovou, ne vlastním kanálem. Builder se sem
// předává explicitně jen proto, aby šel jeho stav vypsat do logu úlohy —
// bez něj si ho služba postaví sama. Ani jeho sestavení nesmí shodit obnovu.
try {
    $telemetry = TelemetryPayloadBuilder::forRuntime($conn, $config);
} catch (\Throwable) {
    $telemetry = null;
}

$service = new LicenseService(
    $conn,
    $config,
    new LicenseTokenVerifier(),
    new LicenseClient($config),
    null,
    null,
    $telemetry,
);

try {
    $service->renewScheduled();
    $state = $service->current();
    $telemetryOn = $telemetry !== null && $telemetry->isEnabled();
    echo "[cron-license-renew] OK state={$state->state} last_check_ok=" . ($state->lastCheckOk ? '1' : '0')
        . ' telemetry=' . ($telemetryOn ? '1' : '0') . "\n";
    $run->finish('ok', [
        'state'         => $state->state,
        'valid_until'   => $state->validUntil,
        'last_check_ok' => $state->lastCheckOk,
        'telemetry'     => $telemetryOn,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "[cron-license-renew] FAILED: {$e->getMessage()}\n");
    $run->finish('error', ['error' => $e->getMessage()], $e->getMessage(), 1);
    exit(1);
}
