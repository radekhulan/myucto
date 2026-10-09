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
use MyInvoice\Service\Accounting\PostingBackfillJobService;
use MyInvoice\Service\Import\IdokladImportService;
use MyInvoice\Service\Export\MonthlyExportService;
use MyInvoice\Service\Export\ClosingPackageService;
use MyInvoice\Service\Document\DocumentJobService;
use MyInvoice\Service\Accounting\Setup\AccountingSetupAnalysisService;
use MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService;

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

// Fatální chyba (typicky vyčerpaná paměť) obejde try/catch níže i v service a job by
// zůstal ve stavu running, dokud ho po čtvrthodině neuklidí reapStale() — do té doby
// UI hlásí „Převod už běží". Otevřenou transakci (zkouška nanečisto) je nutné vrátit,
// jinak by se s ní vrátil i zápis chyby do jobu.
// Protokoly převodů (běhy) téhož jobu by jinak zůstaly „running" a průvodce by místo
// chyby ukazoval rozpracovaný běh, dokud je nezavře další převod.
$runRepositories = match ($source) {
    'pohoda_import' => [\MyInvoice\Repository\PohodaImportRepository::class],
    'premier_import' => [\MyInvoice\Repository\PremierImportRepository::class],
    'abra_flexi_import' => [\MyInvoice\Repository\AbraImportRepository::class],
    'money_s3_import', 'money_s3_batch' => [\MyInvoice\Repository\MoneyS3ImportRepository::class],
    default => [],
};
register_shutdown_function(static function () use ($pdo, $jobs, $jobId, $stderr, $container, $runRepositories, $source, $row): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $limit = (string) ini_get('memory_limit');
    ini_set('memory_limit', '-1');
    $message = str_contains($error['message'], 'Allowed memory size')
        ? "Převod spadl na nedostatek paměti serveru (PHP memory_limit {$limit}). Zvyšte memory_limit v konfiguraci PHP, nebo kontaktujte podporu."
        : 'Převod spadl na neočekávané chybě serveru. Kontaktujte podporu.';
    fwrite($stderr, "Fatal error in job #{$jobId}: {$error['message']} in {$error['file']}:{$error['line']}\n");
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $stmt = $pdo->prepare('SELECT status FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        if (in_array($stmt->fetchColumn(), ['queued', 'running'], true)) {
            foreach ($runRepositories as $class) {
                $container->get($class)->failJobRuns($jobId, $message);
            }
            if ($source === 'money_s3_batch') {
                $container->get(\MyInvoice\Repository\MigrationBatchRepository::class)->closeInterrupted($jobId, (int) $row['supplier_id'], $message);
            }
            $jobs->appendLog($jobId, $message);
            $jobs->markFailed($jobId, $message);
        }
    } catch (\Throwable $e) {
        fwrite($stderr, "Job #{$jobId}: zápis pádu do jobu selhal: {$e->getMessage()}\n");
    }
});

try {
    if ($source === 'idoklad') {
        $container->get(IdokladImportService::class)->run($jobId);
    } elseif ($source === 'fakturoid') {
        $container->get(FakturoidImportService::class)->run($jobId);
    } elseif ($source === 'monthly_export') {
        $container->get(MonthlyExportService::class)->run($jobId);
    } elseif ($source === 'closing_package') {
        $container->get(ClosingPackageService::class)->run($jobId);
    } elseif ($source === 'document_backfill') {
        $container->get(PostingBackfillJobService::class)->run($jobId);
    } elseif ($source === 'file_import') {
        $container->get(FileImportJobService::class)->run($jobId);
    } elseif ($source === 'document_zip_import' || $source === 'document_zip_export' || $source === 'document_folder_import') {
        $container->get(DocumentJobService::class)->run($jobId);
    } elseif ($source === 'automation_recommendations') {
        $container->get(\MyInvoice\Service\Automation\AutomationRecommendationJobService::class)->run($jobId);
    } elseif ($source === 'accounting_setup_analysis') {
        $container->get(AccountingSetupAnalysisService::class)->run($jobId);
    } elseif ($source === 'accounting_history_reclassification') {
        $container->get(AccountingHistoryReclassificationService::class)->run($jobId);
    } elseif ($source === 'money_s3_import') {
        $container->get(\MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService::class)->run($jobId);
    } elseif ($source === 'money_s3_batch') {
        $container->get(\MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchJobService::class)->run($jobId);
    } elseif ($source === 'pohoda_import') {
        $container->get(\MyInvoice\Service\Migration\Pohoda\PohodaImportJobService::class)->run($jobId);
    } elseif ($source === 'abra_flexi_import') {
        $container->get(\MyInvoice\Service\Migration\Abra\AbraImportJobService::class)->run($jobId);
    } elseif ($source === 'premier_import') {
        $container->get(\MyInvoice\Service\Migration\Premier\PremierImportJobService::class)->run($jobId);
    } elseif ($source === 'myucto_import') {
        $container->get(\MyInvoice\Service\Migration\Myucto\MyuctoImportJobService::class)->run($jobId);
    } elseif ($source === 'stereo_nx_import') {
        $container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxImportJobService::class)->run($jobId);
    } elseif ($source === 'scan_attach') {
        $container->get(\MyInvoice\Service\Document\ScanAttach\ScanAttachJobService::class)->run($jobId);
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
