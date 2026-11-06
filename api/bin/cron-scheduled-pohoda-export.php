<?php

declare(strict_types=1);

/**
 * Cron: plánovaný export vydaných dokladů do Pohody (e-mailem účetní).
 * Doporučeno 1× denně; export za uplynulý měsíc se pošle jednou, v nastavený den nebo později.
 *
 *   php api/bin/cron-scheduled-pohoda-export.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Export\ScheduledPohodaExportService;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--dry-run') { fwrite(STDERR, "Unknown arg: {$arg}\n"); exit(1); }
}

$container = Bootstrap::buildContainer();
if ($container === null) { fwrite(STDERR, "Container not available.\n"); exit(1); }
$run = CronRun::start($container->get(Connection::class)->pdo(), 'cron-scheduled-pohoda-export');
try {
    $service = $container->get(ScheduledPohodaExportService::class);
    $report = ['due' => $service->dueMonths(new DateTimeImmutable()), 'results' => []];
    if (!$dryRun) {
        // Za běh jeden měsíc na firmu; chyba jedné firmy nesmí zastavit ostatní.
        foreach ($report['due'] as $sid => $label) {
            try {
                $report['results'][$sid] = ['period' => $label] + $service->run($sid, $label, 'cron');
            } catch (Throwable $e) {
                $report['results'][$sid] = ['period' => $label, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }
    }
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    $errors = array_filter($report['results'], fn ($r) => $r['status'] === 'error');
    $run->finish($errors ? 'error' : 'ok', ['due' => count($report['due']), 'errors' => count($errors), 'dry_run' => $dryRun]);
    exit($errors ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $run->finish('error', ['error' => $e->getMessage()]);
    exit(1);
}
