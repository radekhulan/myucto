<?php

declare(strict_types=1);

/**
 * Background worker pro import_jobs (iDoklad, Fakturoid, PDF AI extraction).
 *
 * Usage:
 *   php api/bin/import-worker.php --job-id=N
 *
 * Worker je spouštěn detached procesem z StartImportAction — Windows přes
 * `proc_open` s DETACHED_PROCESS flag, Linux přes nohup. Status reportuje
 * průběžně do import_jobs (job řádek se polluje frontendem).
 *
 * Lifecycle:
 *   1. Load job by ID (validate existence + 'queued' status)
 *   2. Atomický markRunning (race-safe)
 *   3. Dispatch na konkrétní service podle source (idoklad/fakturoid/pdf_ai)
 *   4. Service updates progress + log; checks isCancelRequested periodicky
 *   5. markCompleted/Failed/Cancelled na konci
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Import\FakturoidImportService;
use MyInvoice\Service\Import\FileImportJobService;
use MyInvoice\Service\Import\IdokladImportService;
use MyInvoice\Service\Export\MonthlyExportService;
use MyInvoice\Service\Export\ClosingPackageService;
use MyInvoice\Service\Document\DocumentJobService;

// STDOUT/STDERR existují pouze v CLI SAPI. Worker může být spuštěn také
// z IIS/FastCGI, php-cgi nebo obdobného webového prostředí (sdílený hosting).
$stdout = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'wb');
$stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');

// Parse args ($argv nemusí být mimo CLI dostupné)
$jobId = null;
foreach (($argv ?? $_SERVER['argv'] ?? []) as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, 9);
    }
}
if ($jobId === null || $jobId <= 0) {
    fwrite($stderr, "Usage: php import-worker.php --job-id=N\n");
    exit(1);
}

// Build container
$app = Bootstrap::buildApp();
$container = $app->getContainer();
$jobs = $container->get(ImportJobRepository::class);

// Load job (any tenant — worker pracuje cross-tenant podle job.supplier_id)
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$stmt = $pdo->prepare('SELECT supplier_id, source, status FROM import_jobs WHERE id = ?');
$stmt->execute([$jobId]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if ($row === false) {
    fwrite($stderr, "Job #{$jobId} nenalezen.\n");
    exit(2);
}
if ($row['status'] !== 'queued') {
    fwrite($stderr, "Job #{$jobId} není ve stavu queued (current: {$row['status']}).\n");
    exit(3);
}

$source = (string) $row['source'];
fwrite($stdout, "Starting import worker for job #{$jobId} (source: {$source})\n");

// Set time limit for long jobs (PHP CLI default = unlimited, but be explicit)
set_time_limit(0);
ignore_user_abort(true);

try {
    if ($source === 'idoklad') {
        $container->get(IdokladImportService::class)->run($jobId);
    } elseif ($source === 'fakturoid') {
        $container->get(FakturoidImportService::class)->run($jobId);
    } elseif ($source === 'monthly_export') {
        $container->get(MonthlyExportService::class)->run($jobId);
    } elseif ($source === 'closing_package') {
        $container->get(ClosingPackageService::class)->run($jobId);
    } elseif ($source === 'file_import') {
        $container->get(FileImportJobService::class)->run($jobId);
    } elseif ($source === 'document_zip_import' || $source === 'document_zip_export' || $source === 'document_folder_import') {
        $container->get(DocumentJobService::class)->run($jobId);
    } else {
        $jobs->appendLog($jobId, "Source '{$source}' není zatím podporován workerem.");
        $jobs->markFailed($jobId, "Source '{$source}' není podporován.");
        exit(4);
    }
} catch (\Throwable $e) {
    // Service má vlastní try/catch — sem se dostane jen pro neexpected errors
    fwrite($stderr, "Unexpected error: " . $e->getMessage() . "\n");
    $jobs->markFailed($jobId, 'Unexpected: ' . $e->getMessage());
    exit(5);
}

fwrite($stdout, "Job #{$jobId} finished.\n");
exit(0);
