<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Repository\ImportJobRepository;

/**
 * Úloha Hromadné akce v seznamech (`import_jobs.source = bulk_action`). Operaci určuje
 * `params.operation`, výběr pevný seznam `params.ids`; běh řídí {@see BulkJobRunner}
 * za uživatele, který úlohu spustil.
 */
final class BulkActionService
{
    public const SOURCE = 'bulk_action';

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly BulkOperations $operations,
        private readonly BulkJobRunner $runner,
    ) {}

    /** Vstupní bod workeru ({@see \MyInvoice\Bootstrap} import-worker.php). */
    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== self::SOURCE) {
            return;
        }
        $params = (array) ($job['params'] ?? []);
        try {
            $operation = $this->operations->get((string) ($params['operation'] ?? ''), (string) ($params['target'] ?? ''));
        } catch (BulkActionException $e) {
            if ($this->jobs->markRunning($jobId)) {
                $this->jobs->markFailed($jobId, $e->getMessage());
            }
            return;
        }
        $this->runner->run($jobId, self::SOURCE, $operation, true);
    }
}
