<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use RuntimeException;
use Throwable;

final class AutomationRecommendationJobService
{
    public const SOURCE = 'automation_recommendations';

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly AutomationRecommendationCache $cache,
        private readonly AutomationRecommendationWorkerLauncher $launcher,
    ) {}

    public function latest(int $supplierId): ?array
    {
        $job = $this->jobs->listForTenant($supplierId, self::SOURCE, 1)[0] ?? null;
        if ($job === null) return null;
        if (in_array($job['status'], ['queued', 'running'], true)
            && strtotime((string) $job['updated_at']) < time() - 900) {
            $job['status'] = 'failed';
            $job['last_error'] = 'worker_timeout';
        }
        return self::view($job);
    }

    public function start(int $supplierId, int $userId): array
    {
        $pdo = $this->db->pdo();
        $name = 'automation_recommendation_job:' . $supplierId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 2)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('job_start_busy');
        try {
            $this->jobs->reapStale($supplierId, self::SOURCE);
            $active = $pdo->prepare("SELECT id FROM import_jobs WHERE supplier_id=? AND source=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
            $active->execute([$supplierId, self::SOURCE]);
            $activeId = $active->fetchColumn();
            if ($activeId !== false) return self::view($this->jobs->find((int) $activeId, $supplierId));
            $jobId = $this->jobs->create($supplierId, self::SOURCE, [], $userId);
            $this->jobs->updateProgress($jobId, ['current_step' => 'queued', 'total_items' => 6]);
            $this->cache->requestRefresh(0, true, [$supplierId]);
            try {
                if (!$this->launcher->spawn($jobId)) throw new RuntimeException('worker_spawn_failed');
            } catch (Throwable) {
                $this->jobs->markFailed($jobId, 'worker_spawn_failed');
            }
            return self::view($this->jobs->find($jobId, $supplierId));
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== self::SOURCE || !$this->jobs->markRunning($jobId)) return;
        try {
            $supplierId = (int) $job['supplier_id'];
            $this->jobs->updateProgress($jobId, ['current_step' => 'waiting', 'total_items' => 6]);
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $this->cache->rebuildSupplier($supplierId, function (string $step, int $processed) use ($jobId): void {
                    $this->jobs->updateProgress($jobId, ['current_step' => $step, 'processed' => $processed]);
                }, 60);
                $state = $this->cache->recommendations(0, true, ['suppliers' => [$supplierId], 'per_page' => 1]);
                if (empty($state['snapshots'][0]['refresh_pending'])) break;
            }
            if (empty($state['snapshots'][0]['generated_at']) || $state['snapshots'][0]['refresh_pending']) {
                throw new RuntimeException('refresh_not_completed');
            }
            $this->jobs->updateProgress($jobId, ['current_step' => 'completed', 'processed' => 6, 'created_count' => $state['total']]);
            $this->jobs->markCompleted($jobId);
        } catch (Throwable) {
            $this->jobs->markFailed($jobId, 'recommendation_refresh_failed');
        }
    }

    private static function view(array $job): array
    {
        return array_intersect_key($job, array_flip([
            'id', 'status', 'total_items', 'processed', 'current_step', 'created_count',
            'last_error', 'created_at', 'finished_at',
        ]));
    }
}
