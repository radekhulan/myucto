<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollDocumentBatchRepository;

final class PayrollDocumentBatchQueueService
{
    public function __construct(
        private readonly PayrollDocumentBatchRepository $batches,
        private readonly ApprovedRevisionPayslipBatchService $payslips,
        private readonly PayrollDocumentService $documents,
    ) {}

    /** @return array<string,mixed> */
    public function enqueueApprovedRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
        ?int $actorUserId,
        ?string $idempotencyKey = null,
    ): array {
        if ($supplierId <= 0 || $runId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException('Identita dávky dokumentů není platná.');
        }
        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            $key = "approved-payslip-batch:{$supplierId}:{$runId}:{$revisionId}";
        }
        if (mb_strlen($key) > 190) {
            throw new \InvalidArgumentException('Idempotency key dávky je příliš dlouhý.');
        }
        return $this->batches->enqueueApprovedRevision(
            $supplierId,
            $runId,
            $revisionId,
            $key,
            $actorUserId,
        );
    }

    /**
     * Zastaví rozpracované dávky nad revizemi běhu, které už jsou odsunuté.
     *
     * Volá se po schválení opravné revize: z revize, kterou nahradila, se nové
     * výplatní pásky negenerují, a `claimNext()` ji přeskakuje — bez tohohle
     * kroku by čekající položky zůstaly ve frontě viset navždy.
     *
     * @return int počet zastavených položek
     */
    public function cancelSupersededRevisions(int $supplierId, int $runId): int
    {
        if ($supplierId <= 0 || $runId <= 0) {
            throw new \InvalidArgumentException('Identita mzdového běhu není platná.');
        }

        return $this->batches->cancelSupersededRevisionsOfRun($supplierId, $runId);
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $batchId): ?array
    {
        return $this->batches->detail($supplierId, $batchId);
    }

    /** @return array<string,mixed>|null */
    public function forRevision(int $supplierId, int $revisionId): ?array
    {
        return $this->batches->forRevision($supplierId, $revisionId);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function items(int $supplierId, int $batchId, int $limit, int $offset): array
    {
        return $this->batches->items($supplierId, $batchId, $limit, $offset);
    }

    /** @return array<string,mixed> */
    public function retry(
        int $supplierId,
        int $batchId,
        int $itemId,
    ): array {
        return $this->batches->retry($supplierId, $batchId, $itemId);
    }

    /**
     * @return array{processed:bool,succeeded:bool|null,batch_id:int|null,item_id:int|null}
     */
    public function processOne(): array
    {
        $claim = $this->batches->claimNext();
        if ($claim === null) {
            $this->finalizeReadyBatches();
            return [
                'processed' => false,
                'succeeded' => null,
                'batch_id' => null,
                'item_id' => null,
            ];
        }
        try {
            $document = $this->payslips->generateEmployee(
                (int) $claim['supplier_id'],
                (int) $claim['run_id'],
                (int) $claim['revision_id'],
                (int) $claim['employee_id'],
                $claim['requested_by'] === null
                    ? null : (int) $claim['requested_by'],
            );
            if (!hash_equals(
                (string) $claim['source_snapshot_hash'],
                (string) $document['source_snapshot_hash'],
            )) {
                throw new \RuntimeException(
                    'Vygenerovaný dokument neodpovídá položce fronty.',
                );
            }
            $this->batches->succeed($claim, (int) $document['id']);
            $succeeded = true;
        } catch (\Throwable $exception) {
            $this->batches->fail(
                $claim,
                self::errorCode($exception),
                $exception->getMessage(),
            );
            $succeeded = false;
        }
        $this->finalizeReadyBatches();
        return [
            'processed' => true,
            'succeeded' => $succeeded,
            'batch_id' => (int) $claim['batch_id'],
            'item_id' => (int) $claim['id'],
        ];
    }

    /** @return array{processed:int,succeeded:int,failed:int} */
    public function processAvailable(int $limit = 25): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        for ($index = 0; $index < max(1, min(500, $limit)); $index++) {
            $item = $this->processOne();
            if (!$item['processed']) {
                break;
            }
            $result['processed']++;
            $item['succeeded'] === true
                ? $result['succeeded']++
                : $result['failed']++;
        }
        $this->finalizeReadyBatches();
        return $result;
    }

    public function finalizeReadyBatches(): void
    {
        foreach ($this->batches->readyForBundle() as $batch) {
            $bundle = $this->documents->generateMonthlyBundle(
                (int) $batch['supplier_id'],
                (int) $batch['run_id'],
                (int) $batch['revision_id'],
                'payroll-document-queue-bundle:' . (int) $batch['id'],
                $batch['requested_by'] === null
                    ? null : (int) $batch['requested_by'],
            );
            $this->batches->attachBundle(
                (int) $batch['supplier_id'],
                (int) $batch['id'],
                (int) $bundle['id'],
            );
        }
    }

    private static function errorCode(\Throwable $exception): string
    {
        $short = (new \ReflectionClass($exception))->getShortName();
        $normalized = strtolower((string) preg_replace(
            '/(?<!^)[A-Z]/',
            '_$0',
            $short,
        ));
        return substr('render_' . $normalized, 0, 64);
    }
}
