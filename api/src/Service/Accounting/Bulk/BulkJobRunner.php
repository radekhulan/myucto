<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Service\ActivityLogger;

/**
 * Společný běh hromadné úlohy (`import_jobs` s `params.kind`) pro všechny operace
 * ({@see BulkOperation}): stránku Přeúčtovat (`journal_rebook`) i Hromadné akce
 * v seznamech (`bulk_action`).
 *
 *   - `dry_run`  … nic nezapíše, u každého záznamu řekne, co by se stalo,
 *   - `apply`    … zpracuje PŘESNĚ položky, které běh nanečisto označil ke změně,
 *                  bez těch, které uživatel vyřadil, a jen když se od té doby nezměnily,
 *   - `rollback` … vrátí položky, které ostrý běh změnil, a jen když je od té doby
 *                  nikdo nezměnil.
 *
 * Každá provedená změna (i vrácení) jde do auditní stopy s číslem úlohy a stavem
 * před a po. Úloha `bulk_action` běží za uživatele, který ji spustil: na začátku každého
 * běhu se znovu ověří, že do firmy smí a operaci spustit může.
 */
final class BulkJobRunner
{
    public const LIMIT = BulkSelection::LIMIT;

    /** Běží úloha uvnitř cizí transakce (testy)? Tu pak po chybě položky neodvolává. */
    private bool $outerTransaction = false;

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly AccountingBulkItemRepository $items,
        private readonly BulkSelection $selection,
        private readonly ActivityLogger $activity,
        private readonly CrossSupplierAuthorization $authorization,
    ) {}

    /**
     * @param bool $actAsUser úloha běží za uživatele (požadavek s jeho rolí, ověření práv)
     */
    public function run(int $jobId, string $source, BulkOperation $operation, bool $actAsUser): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== $source || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        $params = (array) ($job['params'] ?? []);
        $userId = (int) $job['created_by'];
        $counts = ['changed' => 0, 'skipped' => 0, 'failed' => 0];
        $this->outerTransaction = $this->db->pdo()->inTransaction();
        try {
            $target = (string) ($params['target'] ?? 'journal_entry');
            $request = null;
            if ($actAsUser) {
                $request = $this->authorization->backgroundRequestFor($userId, $supplierId);
                if ($request === null) {
                    throw new \RuntimeException('Uživatel, který úlohu spustil, už do firmy nemá přístup.');
                }
                $reason = $operation->unavailableReason($request, $supplierId, $target);
                if ($reason !== null) {
                    throw new \RuntimeException($reason);
                }
            }
            $ctx = new BulkContext($jobId, $supplierId, $userId, $target, $params, $request);
            $kind = (string) ($params['kind'] ?? '');
            $cancelled = match ($kind) {
                'dry_run'  => $this->dryRun($ctx, $operation, $counts),
                'apply'    => $this->apply($ctx, $source, $operation, $counts),
                'rollback' => $this->rollback($ctx, $source, $operation, $counts),
                default    => throw new \RuntimeException('Neznámý druh hromadné úlohy.'),
            };
            $this->progress($jobId, null, $counts, true);
            if ($cancelled) {
                $this->jobs->markCancelled($jobId);
            } elseif ($counts['failed'] > 0) {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
        } catch (\Throwable $e) {
            $this->jobs->appendLog($jobId, $e->getMessage());
            $this->jobs->markFailed($jobId, mb_substr($e->getMessage(), 0, 500));
        }
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function dryRun(BulkContext $ctx, BulkOperation $operation, array &$counts): bool
    {
        $rows = $operation instanceof BulkCandidateSource
            ? $operation->candidates($ctx)
            : $this->selection->rows($ctx->supplierId, $ctx->target, array_map('intval', (array) ($ctx->params['ids'] ?? [])));
        if (count($rows) > self::LIMIT) {
            throw new \RuntimeException('Výběr zasahuje víc než ' . self::LIMIT . ' záznamů, zužte ho.');
        }
        $this->jobs->updateProgress($ctx->jobId, ['total_items' => count($rows), 'current_step' => 'dry_run']);
        foreach ($rows as $i => $row) {
            if ($this->jobs->isCancelRequested($ctx->jobId)) return true;
            $item = $this->guarded(fn (): array => $operation->evaluate($ctx, $row), BulkSelection::itemBase($row));
            $this->items->insert($ctx->jobId, $ctx->supplierId, $item);
            $counts[match ($item['status']) { 'would_change' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($ctx->jobId, $i + 1, $counts);
        }
        return false;
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function apply(BulkContext $ctx, string $source, BulkOperation $operation, array &$counts): bool
    {
        $dryJobId = (int) ($ctx->params['dry_run_job_id'] ?? 0);
        $excludedItems = array_flip(array_map('intval', (array) ($ctx->params['excluded_item_ids'] ?? [])));
        $excludedEntries = array_flip(array_map('intval', (array) ($ctx->params['excluded_entry_ids'] ?? [])));
        $items = $this->items->byStatus($ctx->supplierId, $dryJobId, 'would_change');
        $this->jobs->updateProgress($ctx->jobId, ['total_items' => count($items), 'current_step' => 'apply']);
        foreach ($items as $i => $dry) {
            if ($this->jobs->isCancelRequested($ctx->jobId)) return true;
            if (isset($excludedItems[(int) $dry['id']]) || ($dry['entry_id'] !== null && isset($excludedEntries[(int) $dry['entry_id']]))) {
                $this->items->insert($ctx->jobId, $ctx->supplierId, ['status' => 'excluded', 'reason_code' => 'excluded_by_user'] + BulkSelection::copyItem($dry));
                $counts['skipped']++;
            } else {
                $result = $this->guarded(fn (): array => $operation->apply($ctx, $dry), BulkSelection::copyItem($dry));
                $this->items->insert($ctx->jobId, $ctx->supplierId, $result);
                $this->audit($ctx, $source, $operation, 'applied', $result);
                $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            }
            $this->progress($ctx->jobId, $i + 1, $counts);
        }
        return false;
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function rollback(BulkContext $ctx, string $source, BulkOperation $operation, array &$counts): bool
    {
        if (!$operation->supportsRollback()) {
            throw new \RuntimeException('Tuto hromadnou akci nejde vrátit.');
        }
        $applyJobId = (int) ($ctx->params['rollback_of_job_id'] ?? 0);
        $applyJob = $this->jobs->find($applyJobId, $ctx->supplierId);
        $applyParams = (array) ($applyJob['params'] ?? []);
        if ($applyJob === null || $applyJob['source'] !== $source || ($applyParams['kind'] ?? '') !== 'apply'
            || ($applyParams['operation'] ?? null) !== ($ctx->params['operation'] ?? null)) {
            throw new \RuntimeException('Vrátit lze jen dokončený ostrý běh téže akce.');
        }
        $items = $this->items->byStatus($ctx->supplierId, $applyJobId, 'applied');
        $this->jobs->updateProgress($ctx->jobId, ['total_items' => count($items), 'current_step' => 'rollback']);
        foreach ($items as $i => $applied) {
            if ($this->jobs->isCancelRequested($ctx->jobId)) return true;
            // V položce vrácení je „před" stav po změně a „po" původní stav.
            $base = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
            $result = $this->guarded(fn (): array => $operation->rollback($ctx, $applied), $base);
            $this->items->insert($ctx->jobId, $ctx->supplierId, $result);
            $this->audit($ctx, $source, $operation, 'rolled_back', $result);
            $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($ctx->jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * Chyba jednoho záznamu nezastaví ostatní: položka skončí jako `failed`. Operace si
     * vlastní transakce uzavírá samy; kdyby po chybě zůstala otevřená (odvolaná
     * transakce, deadlock), uklidí se tady, aby další položka nezačala v cizí transakci.
     *
     * @param callable():array<string,mixed> $work
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function guarded(callable $work, array $base): array
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            $pdo = $this->db->pdo();
            if (!$this->outerTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['status' => 'failed', 'reason_code' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 500)] + $base;
        }
    }

    /**
     * Auditní stopa provedené změny: číslo úlohy, operace, stav před a po.
     *
     * @param array<string,mixed> $item
     */
    private function audit(BulkContext $ctx, string $source, BulkOperation $operation, string $what, array $item): void
    {
        if (($item['status'] ?? null) !== 'applied') {
            return;
        }
        $entity = match ((string) $item['source_type']) {
            'manual', 'journal_entry' => 'journal_entry',
            'bank' => 'bank_transaction',
            'cash' => 'cash_document',
            default => (string) $item['source_type'],
        };
        $this->activity->log('bulk_action.item_' . $what, $ctx->userId ?: null, $entity, (int) $item['source_id'], [
            'job_id'      => $ctx->jobId,
            'job_source'  => $source,
            'operation'   => $operation->key(),
            'strategy'    => $item['strategy'] ?? null,
            'reason_code' => $item['reason_code'] ?? null,
            'before'      => $item['before'] ?? null,
            'after'       => $item['after'] ?? null,
            'result_entry_id' => $item['result_entry_id'] ?? null,
        ], null, null, $ctx->supplierId);
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function progress(int $jobId, ?int $processed, array $counts, bool $force = false): void
    {
        if ($force) {
            $processed = $counts['changed'] + $counts['skipped'] + $counts['failed'];
        } elseif ($processed % 10 !== 0) {
            return;
        }
        $this->jobs->updateProgress($jobId, array_filter([
            'processed'     => $processed,
            'created_count' => $counts['changed'],
            'skipped_count' => $counts['skipped'],
            'failed_count'  => $counts['failed'],
        ], static fn (mixed $v): bool => $v !== null));
    }
}
