<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationCompanyLock;
use Psr\Log\LoggerInterface;

/** One transaction per run; progress and protocols live in the shared import queue. */
final class MyuctoImportJobService extends AbstractImportJobService
{
    public const SOURCE = 'myucto_import';
    public const FAILED = 'Obnovu se nepodařilo dokončit. Zkontrolujte data opakováním zkoušky.';

    public function __construct(
        ImportJobRepository $jobs,
        private readonly MyuctoImportWorkflow $workflow,
        private readonly SecretEncryption $secrets,
        private readonly MigrationCompanyLock $companyLock,
        ActivityLogger $logger,
        private readonly LoggerInterface $log,
    ) {
        parent::__construct($jobs, null, $logger);
    }

    public static function passwordContext(int $supplier, string $token): string
    {
        return 'myucto-import:' . $supplier . ':' . $token;
    }

    public function start(
        int $supplier,
        int $actor,
        string $token,
        string $source,
        ?string $password,
        bool $apply,
        bool $confirmed,
    ): array {
        if (!$this->companyLock->acquire(self::SOURCE, $supplier)) {
            throw new MyuctoImportException('already_running', 'Obnova této firmy právě běží.', [], 409);
        }
        try {
            // Reaping is safe only while holding the same lock as the worker: a long
            // transaction does not update the job heartbeat, but is still alive.
            $this->jobs->reapStale($supplier, self::SOURCE);
            foreach ($this->jobs->listForTenant($supplier, self::SOURCE) as $job) {
                if (in_array($job['status'], ['queued', 'running'], true)) {
                    throw new MyuctoImportException('already_running', 'Obnova této firmy už čeká nebo běží.', ['existing_job_id' => $job['id']], 409);
                }
                $this->jobs->removeParams((int) $job['id'], ['password_enc']);
            }
            $this->workflow->validateStart($supplier, $actor, $token, $source, $apply, $confirmed);
            $params = ['token' => $token, 'source_name' => $source, 'mode' => $apply ? 'import' : 'dry_run'];
            if ($password !== null && $password !== '') {
                $params['password_enc'] = $this->secrets->encryptFor($password, self::passwordContext($supplier, $token));
            }
            $id = $this->jobs->create($supplier, self::SOURCE, $params, $actor);
            $stored = $this->jobs->find($id, $supplier);
            if ($stored === null || ($stored['source'] ?? '') !== self::SOURCE) {
                $this->jobs->delete($id, $supplier);
                throw new MyuctoImportException('migration_required', 'Spusťte databázové migrace pro obnovu na pozadí.', [], 503);
            }
            return ['job_id' => $id, 'status' => 'queued'];
        } finally {
            $this->releaseCompanyLock($supplier);
        }
    }

    public function launch(int $id): void
    {
        if (!BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $id],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        )) {
            $this->jobs->removeParams($id, ['password_enc']);
            $this->jobs->markFailed($id, 'Worker importu nelze spustit. Ověřte dostupnost PHP CLI.');
        }
    }

    public function run(int $jobId): void
    {
        try {
            parent::run($jobId);
        } catch (\Throwable $e) {
            $this->log->error('MyÚčto import failed', ['job_id' => $jobId, 'exception_class' => $e::class]);
            // Never expose database values or decrypted archive data in a job error.
            $this->jobs->markFailed($jobId, self::FAILED);
        } finally {
            $job = $this->jobs->findById($jobId);
            if ($job !== null && !in_array($job['status'], ['queued', 'running'], true)) {
                $this->jobs->removeParams($jobId, ['password_enc']);
            }
        }
    }

    public function status(int $supplier, int $actor, int $id): array
    {
        $job = $this->jobs->find($id, $supplier);
        if ($job === null || ($job['source'] ?? '') !== self::SOURCE || (int) $job['created_by'] !== $actor) {
            throw new MyuctoImportException('run_not_found', 'Běh obnovy nebyl nalezen.', [], 404);
        }
        $this->recoverInactive($supplier);
        return $this->publicJob($this->jobs->find($id, $supplier) ?? $job);
    }

    public function history(int $supplier, int $actor): array
    {
        $this->recoverInactive($supplier);
        $runs = [];
        foreach ($this->jobs->listForTenant($supplier, self::SOURCE) as $job) {
            if ((int) $job['created_by'] === $actor) {
                $runs[] = $this->publicJob($job);
            }
        }
        return ['runs' => $runs];
    }

    private function recoverInactive(int $supplier): void
    {
        if (!$this->companyLock->acquire(self::SOURCE, $supplier)) {
            return;
        }
        try {
            $this->jobs->reapStale($supplier, self::SOURCE);
            foreach ($this->jobs->listForTenant($supplier, self::SOURCE) as $job) {
                if (!in_array($job['status'], ['queued', 'running'], true)) {
                    $this->jobs->removeParams((int) $job['id'], ['password_enc']);
                }
            }
        } finally {
            $this->releaseCompanyLock($supplier);
        }
    }

    private function publicJob(array $job): array
    {
        $result = array_intersect_key($job, array_flip([
            'id', 'status', 'total_items', 'processed', 'created_count', 'skipped_count', 'failed_count',
            'current_step', 'log_text', 'last_error', 'created_at', 'finished_at',
        ]));
        $result['token'] = (string) ($job['params']['token'] ?? '');
        $result['source_name'] = (string) ($job['params']['source_name'] ?? '');
        $result['mode'] = (string) ($job['params']['mode'] ?? 'dry_run');
        $result['result'] = $job['report'] ?? null;
        return $result;
    }

    protected function uploads(): ChunkedUploadStore
    {
        return $this->workflow->uploads();
    }

    protected function acquireCompanyLock(int $supplierId): bool
    {
        // Wait for a brief HTTP queue/status lock, but never start concurrently.
        return $this->companyLock->acquire(self::SOURCE, $supplierId, 5);
    }

    protected function releaseCompanyLock(int $supplierId): void
    {
        $this->companyLock->release(self::SOURCE, $supplierId);
    }

    protected function supportsPrepareJob(): bool
    {
        return false;
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        throw new \LogicException('Kontrola nativního ZIPu je součástí zkoušky nanečisto.');
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        throw new \LogicException('Kontrola nativního ZIPu je součástí zkoušky nanečisto.');
    }

    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $params = (array) ($job['params'] ?? []);
        $token = (string) ($params['token'] ?? '');
        $apply = ($params['mode'] ?? '') === 'import';
        try {
            $password = null;
            if (isset($params['password_enc'])) {
                if (!str_starts_with((string) $params['password_enc'], 'enc:v2:')) {
                    throw new MyuctoImportException('password_invalid', 'Heslo exportu nelze přečíst, spusťte kontrolu znovu.');
                }
                $password = $this->secrets->decryptFor((string) $params['password_enc'], self::passwordContext($supplierId, $token));
            }
            $this->jobs->removeParams($jobId, ['password_enc']);
            $this->jobs->updateProgress($jobId, ['total_items' => 1, 'processed' => 0, 'current_step' => $apply ? 'Obnova běží' : 'Kontrola exportu běží']);
            $this->jobs->appendLog($jobId, $apply ? 'Obnova do aktuální firmy.' : 'Zkouška nanečisto; data firmy se nezmění.');
            $result = $this->workflow->run($supplierId, (int) $job['created_by'], $token, (string) ($params['source_name'] ?? ''), $password, $apply, $apply);
            $this->jobs->setReport($jobId, $result);
            $this->jobs->updateProgress($jobId, [
                'processed' => 1,
                'created_count' => array_sum($result['report']['created'] ?? []),
                'skipped_count' => array_sum($result['report']['existing'] ?? []) + array_sum($result['report']['reused'] ?? []),
                'current_step' => 'Hotovo',
            ]);
            $this->jobs->appendLog($jobId, 'Kontrola shody přenesených dat dokončena.');
            $this->jobs->markCompleted($jobId);
        } catch (MyuctoImportException $e) {
            $this->jobs->appendLog($jobId, $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        } catch (\Throwable $e) {
            $this->log->error('MyÚčto import failed', ['job_id' => $jobId, 'exception_class' => $e::class]);
            $this->jobs->markFailed($jobId, self::FAILED);
        } finally {
            $this->jobs->removeParams($jobId, ['password_enc']);
        }
    }
}
