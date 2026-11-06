<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class JointAllocationService
{
    public function __construct(private readonly Connection $db, private readonly JointAllocationJournalWriter $journal) {}

    public function apply(int $supplierId, array $input, ?int $actor = null): array
    {
        $request = JointAllocationRequest::apply($input);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $request, $actor): array {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
            $existing = $this->byToken('joint_allocation_batches', $supplierId, $request['idempotency_key']);
            if ($existing !== null) {
                self::assertSameRequest($existing, $request);
                if ($existing['journal_entry_id'] === null) {
                    throw new JointAllocationException('allocation_incomplete', 'Alokace nemá dokončený účetní zápis.', 409);
                }
                return $this->batchResult($existing);
            }
            $preview = (new JointAllocationPreviewService($this->db))->previewLocked($supplierId, $request['key_version_id'], $request['target_date'], $request['source_line_ids'], $request['exact_cells']);
            if (!hash_equals($request['preview_hash'], $preview['preview_hash'])) {
                throw new JointAllocationException('allocation_preview_stale', 'Podklady se od náhledu změnily. Načtěte nový náhled.', 409);
            }
            $pdo = $this->db->pdo();
            $pdo->prepare('INSERT INTO joint_allocation_batches (supplier_id, key_version_id, target_date, reason, idempotency_key, request_hash, preview_hash, snapshot_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $request['key_version_id'], $request['target_date'], $request['reason'], $request['idempotency_key'], $request['request_hash'], $preview['preview_hash'], JointAllocationKeyVersion::encodeJson($preview), $actor]);
            $batchId = (int) $pdo->lastInsertId();
            $claim = $pdo->prepare('INSERT INTO joint_allocation_source_claims (supplier_id, line_id, batch_id) VALUES (?, ?, ?)');
            foreach ($request['source_line_ids'] as $lineId) {
                $claim->execute([$supplierId, $lineId, $batchId]);
            }
            $journalId = $this->journal->insertCurrentAllocation($supplierId, $batchId);
            if ($journalId <= 0) {
                throw new \LogicException('Allocation writer returned no journal entry.');
            }
            $batch = $this->findBatch($supplierId, $batchId);
            if ((int) $batch['journal_entry_id'] !== $journalId) {
                throw new JointAllocationException('allocation_changed', 'Alokace se během zápisu změnila.', 409);
            }
            return $this->batchResult($batch);
        });
    }

    public function cancel(int $supplierId, array $input, ?int $actor = null): array
    {
        $request = JointAllocationRequest::cancel($input);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $request, $actor): array {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
            $existing = $this->byToken('joint_allocation_cancellations', $supplierId, $request['idempotency_key']);
            if ($existing !== null) {
                self::assertSameRequest($existing, $request);
                if ($existing['reversal_entry_id'] === null) {
                    throw new JointAllocationException('allocation_incomplete', 'Zrušení alokace nemá dokončený účetní zápis.', 409);
                }
                return $this->batchResult($this->findBatch($supplierId, (int) $existing['batch_id']));
            }
            $batch = $this->findBatch($supplierId, $request['batch_id']);
            if ($this->cancellation($supplierId, (int) $batch['id']) !== null) {
                throw new JointAllocationException('allocation_already_cancelled', 'Alokace již byla zrušena.', 409);
            }
            if ($batch['journal_entry_id'] === null) {
                throw new JointAllocationException('allocation_incomplete', 'Alokace nemá dokončený účetní zápis.', 409);
            }
            if ($request['target_date'] < $batch['target_date']) {
                throw new JointAllocationException('allocation_backdating', 'Zrušení nesmí předcházet původnímu přeúčtování.', 409);
            }
            (new JointAllocationPreviewService($this->db))->targetPeriod($supplierId, $request['target_date']);
            $snapshot = json_decode($batch['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            $sourceIds = array_map('intval', array_column($snapshot['source_lines'], 'id'));
            (new JointAllocationUseGuard($this->db))->assertOwnedSources($supplierId, (int) $batch['id'], $sourceIds);
            $pdo = $this->db->pdo();
            $pdo->prepare('INSERT INTO joint_allocation_cancellations (supplier_id, batch_id, target_date, reason, idempotency_key, request_hash, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $batch['id'], $request['target_date'], $request['reason'], $request['idempotency_key'], $request['request_hash'], $actor]);
            $cancellationId = (int) $pdo->lastInsertId();
            $reversalId = $this->journal->reverseCurrentAllocation($supplierId, $cancellationId);
            if ($reversalId <= 0) {
                throw new \LogicException('Allocation writer returned no cancellation entry.');
            }
            if ((int) ($this->cancellation($supplierId, (int) $batch['id'])['reversal_entry_id'] ?? 0) !== $reversalId) {
                throw new JointAllocationException('allocation_changed', 'Zrušení alokace se během zápisu změnilo.', 409);
            }
            return $this->batchResult($batch);
        });
    }

    private function byToken(string $table, int $supplierId, string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id = ? AND idempotency_key = ? FOR UPDATE");
        $stmt->execute([$supplierId, $token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findBatch(int $supplierId, int $batchId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM joint_allocation_batches WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw new JointAllocationException('allocation_not_found', 'Alokace nebyla nalezena.', 404);
        }
        JointAllocationKeyVersion::decodeSnapshot($batch);
        return $batch;
    }

    private function cancellation(int $supplierId, int $batchId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, reversal_entry_id FROM joint_allocation_cancellations WHERE supplier_id = ? AND batch_id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $batchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function batchResult(array $batch): array
    {
        JointAllocationKeyVersion::decodeSnapshot($batch);
        $cancellation = $this->cancellation((int) $batch['supplier_id'], (int) $batch['id']);
        return ['id' => (int) $batch['id'], 'journal_entry_id' => (int) $batch['journal_entry_id'],
            'key_version_id' => (int) $batch['key_version_id'], 'target_date' => $batch['target_date'],
            'preview_hash' => $batch['preview_hash'], 'status' => $cancellation === null ? 'applied' : 'cancelled',
            'cancellation_entry_id' => $cancellation === null ? null : (int) $cancellation['reversal_entry_id']];
    }

    private static function assertSameRequest(array $existing, array $request): void
    {
        if (!hash_equals($existing['request_hash'], $request['request_hash'])) {
            throw new JointAllocationException('allocation_idempotency_conflict', 'Identifikátor operace již patří jinému požadavku.', 409);
        }
    }
}
