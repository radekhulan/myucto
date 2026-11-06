<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalProof;
use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class JointAllocationReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function list(int $supplierId, int $beforeId = PHP_INT_MAX): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT b.id, b.key_version_id, b.target_date, b.reason, b.journal_entry_id,
                b.created_by, b.created_at, c.reversal_entry_id AS cancellation_entry_id,
                CASE WHEN c.reversal_entry_id IS NULL THEN 'applied' ELSE 'cancelled' END AS status
            FROM joint_allocation_batches b
            LEFT JOIN joint_allocation_cancellations c ON c.batch_id = b.id AND c.supplier_id = b.supplier_id
            WHERE b.supplier_id = ? AND b.id < ? AND b.journal_entry_id IS NOT NULL ORDER BY b.id DESC LIMIT 100");
        $stmt->execute([$supplierId, $beforeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $supplierId, int $batchId, bool $currentRead = false): array
    {
        if ($currentRead) {
            if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Current allocation projection requires a transaction.');
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        }
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare('SELECT * FROM joint_allocation_batches WHERE supplier_id = ? AND id = ? AND journal_entry_id IS NOT NULL' . $lock);
        $stmt->execute([$supplierId, $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new JointAllocationException('allocation_not_found', 'Alokace nebyla nalezena.', 404);
        $batch['snapshot'] = JointAllocationKeyVersion::decodeSnapshot($batch);
        unset($batch['snapshot_json']);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM joint_allocation_cancellations WHERE supplier_id = ? AND batch_id = ? AND reversal_entry_id IS NOT NULL' . $lock);
        $stmt->execute([$supplierId, $batchId]);
        $batch['cancellation'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $batch['status'] = $batch['cancellation'] === null ? 'applied' : 'cancelled';
        $stmt = $this->db->pdo()->prepare("SELECT * FROM joint_allocation_audit WHERE supplier_id = ? AND ((entity_type = 'batch' AND entity_id = ?) OR (entity_type = 'cancellation' AND entity_id = ?)) ORDER BY id" . $lock);
        $stmt->execute([$supplierId, $batchId, $batch['cancellation']['id'] ?? 0]);
        $batch['audit'] = array_map(static function (array $row): array {
            $row['payload'] = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row['payload_json']);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $batch;
    }

    public function jointCells(int $supplierId, int $batchId, bool $currentRead = false): array
    {
        return $this->cellsFromBatch($supplierId, $this->find($supplierId, $batchId, $currentRead), $currentRead);
    }

    private function cellsFromBatch(int $supplierId, array $batch, bool $currentRead): array
    {
        $entries = [(int) $batch['journal_entry_id']];
        if ($batch['cancellation'] !== null) $entries[] = (int) $batch['cancellation']['reversal_entry_id'];
        $lines = $this->lineIdsForEntries($supplierId, $entries, $currentRead);
        return $this->cellsFromResolvedBatch(
            $batch,
            $lines[(int) $batch['journal_entry_id']] ?? [],
            $batch['cancellation'] === null ? [] : ($lines[(int) $batch['cancellation']['reversal_entry_id']] ?? []),
        );
    }

    private function cellsFromResolvedBatch(array $batch, array $lineIds, array $cancellationLines): array
    {
        $snapshot = $batch['snapshot'];
        $out = [];
        $lineNo = 0;
        foreach ($snapshot['plan']['sources'] as $source) {
            $compensationNo = $lineNo++;
            foreach ($source['targets'] as $target) {
                if (!isset($lineIds[$compensationNo], $lineIds[$lineNo]) || ($batch['cancellation'] !== null && !isset($cancellationLines[$lineNo]))) {
                    throw new JointAllocationException('allocation_changed', 'Chybí řádek neměnného alokačního zápisu.', 409);
                }
                $axes = [];
                foreach (JointAllocationCalculator::AXES as $axis) {
                    $valueId = $target['dimensions'][$snapshot['type_ids'][$axis]];
                    $value = $snapshot['semantic_values'][$valueId];
                    $axes[$axis] = ['type_id' => $snapshot['type_ids'][$axis], 'value_id' => $valueId,
                        'semantic_key' => $value['semantic_key'], 'code' => $value['code'], 'name' => $value['name']];
                }
                $cents = bcmul($target['amount'], '100', 0);
                $additional = [];
                foreach ($snapshot['key']['cells'][0]['additional_dimensions'] ?? [] as $typeId => $_) {
                    $valueId = $target['dimensions'][$typeId];
                    $value = $snapshot['semantic_values'][$valueId];
                    $additional[$typeId] = ['type_id' => $typeId, 'value_id' => $valueId,
                        'semantic_key' => $value['semantic_key'], 'code' => $value['code'], 'name' => $value['name']];
                }
                $foreign = $target['amount_foreign'] === null ? null : bcmul($target['amount_foreign'], '100', 0);
                $out[] = ['batch_id' => (int) $batch['id'], 'key_version_id' => (int) $batch['key_version_id'],
                    'key_content_hash' => $snapshot['key']['content_hash'], 'source_line_id' => $source['source_line_id'],
                    'journal_entry_id' => (int) $batch['journal_entry_id'], 'line_id' => (int) $lineIds[$lineNo], 'line_no' => $lineNo,
                    'compensation_line_id' => (int) $lineIds[$compensationNo], 'account_id' => (int) $target['account_id'],
                    'side' => $target['side'], 'is_red_storno' => !empty($target['is_red_storno']),
                    'amount_cents' => $cents, 'signed_amount_cents' => empty($target['is_red_storno']) ? $cents : bcsub('0', $cents, 0),
                    'foreign_amount_cents' => $foreign,
                    'signed_foreign_amount_cents' => $foreign === null || empty($target['is_red_storno']) ? $foreign : bcsub('0', $foreign, 0),
                    'currency_code' => $target['currency_code'] ?? null,
                    'fx_rate' => $target['fx_rate'] ?? null, 'axes' => $axes, 'additional_axes' => $additional, 'status' => $batch['status'],
                    'cancellation_line_id' => isset($cancellationLines[$lineNo]) ? (int) $cancellationLines[$lineNo] : null];
                $lineNo++;
            }
        }
        return $out;
    }

    /** @param list<int> $batchIds @return array<int,array<int,array<string,mixed>>> */
    public function ledgerProjectionsForBatches(int $supplierId, array $batchIds, bool $currentRead = false): array
    {
        $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
        if (count($batchIds) > 500 || array_any($batchIds, static fn (int $id): bool => $id <= 0)) {
            throw new \InvalidArgumentException('Batch projection accepts 1 to 500 positive allocation ids.');
        }
        if ($batchIds === []) return [];
        sort($batchIds, SORT_NUMERIC);
        if ($currentRead) {
            if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Current allocation projection requires a transaction.');
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        }
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $marks = implode(',', array_fill(0, count($batchIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT * FROM joint_allocation_batches
            WHERE supplier_id = ? AND id IN ({$marks}) AND journal_entry_id IS NOT NULL ORDER BY id" . $lock);
        $stmt->execute([$supplierId, ...$batchIds]);
        $batches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $batch) {
            $batch['snapshot'] = JointAllocationKeyVersion::decodeSnapshot($batch);
            unset($batch['snapshot_json']);
            $batches[(int) $batch['id']] = $batch;
        }
        foreach ($batchIds as $batchId) {
            if (!isset($batches[$batchId])) throw new JointAllocationException('allocation_not_found', 'Alokace nebyla nalezena.', 404);
        }
        $stmt = $this->db->pdo()->prepare("SELECT * FROM joint_allocation_cancellations
            WHERE supplier_id = ? AND batch_id IN ({$marks}) AND reversal_entry_id IS NOT NULL ORDER BY batch_id" . $lock);
        $stmt->execute([$supplierId, ...$batchIds]);
        $cancellations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cancellation) $cancellations[(int) $cancellation['batch_id']] = $cancellation;
        $entryIds = [];
        foreach ($batches as $batchId => &$batch) {
            $batch['cancellation'] = $cancellations[$batchId] ?? null;
            $batch['status'] = $batch['cancellation'] === null ? 'applied' : 'cancelled';
            $entryIds[] = (int) $batch['journal_entry_id'];
            if ($batch['cancellation'] !== null) $entryIds[] = (int) $batch['cancellation']['reversal_entry_id'];
        }
        unset($batch);
        if ($currentRead) $this->lockEntryHeaders($supplierId, $entryIds);
        $lines = $this->lineIdsForEntries($supplierId, $entryIds, $currentRead);
        $out = [];
        foreach ($batchIds as $batchId) {
            $batch = $batches[$batchId];
            $out[$batchId] = $this->projectionFromResolvedBatch(
                $batch,
                $lines[(int) $batch['journal_entry_id']] ?? [],
                $batch['cancellation'] === null ? [] : ($lines[(int) $batch['cancellation']['reversal_entry_id']] ?? []),
            );
        }
        return $out;
    }

    public function ledgerProjection(int $supplierId, int $batchId, bool $currentRead = false): array
    {
        return $this->ledgerProjectionsForBatches($supplierId, [$batchId], $currentRead)[$batchId];
    }

    private function projectionFromResolvedBatch(array $batch, array $ids, array $cancellationIds): array
    {
        $snapshot = $batch['snapshot'];
        $cells = $this->cellsFromResolvedBatch($batch, $ids, $cancellationIds);
        $targets = array_column($cells, null, 'line_no');
        $common = ['batch_id' => (int) $batch['id'], 'key_version_id' => (int) $batch['key_version_id'], 'status' => $batch['status']];
        $out = [];
        foreach ($snapshot['source_lines'] as $source) {
            $id = (int) $source['id'];
            $out[$id] = self::projectLine($source) + $common + ['line_id' => $id, 'journal_entry_id' => (int) $source['entry_id'],
                'role' => 'source', 'source_line_id' => $id, 'axes' => null, 'mirrors_line_id' => null];
        }
        $sourceByLine = [];
        $lineNo = 0;
        foreach ($snapshot['plan']['sources'] as $source) {
            $sourceByLine[$lineNo++] = $source['source_line_id'];
            foreach ($source['targets'] as $_) $sourceByLine[$lineNo++] = $source['source_line_id'];
        }
        foreach (JointAllocationJournalProof::materializedLines($snapshot['plan'], (int) $batch['id'], $snapshot['type_ids']) as $line) {
            $no = $line['line_no'];
            if (!isset($ids[$no]) || ($batch['cancellation'] !== null && !isset($cancellationIds[$no]))) {
                throw new JointAllocationException('allocation_changed', 'Chybí řádek neměnného alokačního zápisu.', 409);
            }
            $id = (int) $ids[$no];
            $role = isset($targets[$no]) ? 'target' : 'compensation';
            $attributes = $common + ['source_line_id' => $sourceByLine[$no], 'axes' => $targets[$no]['axes'] ?? null,
                'additional_axes' => $targets[$no]['additional_axes'] ?? null];
            $out[$id] = self::projectLine($line) + $attributes + ['line_id' => $id, 'journal_entry_id' => (int) $batch['journal_entry_id'],
                'role' => $role, 'mirrors_line_id' => null];
            if (isset($cancellationIds[$no])) {
                $cancelId = (int) $cancellationIds[$no];
                $out[$cancelId] = self::projectLine(JournalLineAmount::reversal($line)) + $attributes + ['line_id' => $cancelId,
                    'journal_entry_id' => (int) $batch['cancellation']['reversal_entry_id'], 'role' => 'cancellation_' . $role, 'mirrors_line_id' => $id];
            }
        }
        return $out;
    }

    /** @param list<int> $entryIds @return array<int,array<int,int>> */
    private function lineIdsForEntries(int $supplierId, array $entryIds, bool $currentRead): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) return [];
        sort($entryIds, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT entry_id, line_no, id FROM journal_entry_lines
            WHERE supplier_id = ? AND entry_id IN ({$marks}) ORDER BY entry_id, line_no" . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$entryIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['entry_id']][(int) $row['line_no']] = (int) $row['id'];
        return $out;
    }

    /** @param list<int> $entryIds */
    private function lockEntryHeaders(int $supplierId, array $entryIds): void
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        sort($entryIds, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entries
            WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
        $stmt->execute([$supplierId, ...$entryIds]);
        if (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) !== $entryIds) {
            throw new JointAllocationException('allocation_changed', 'Chybí účetní zápis neměnné alokace.', 409);
        }
    }

    private static function projectLine(array $line): array
    {
        $cents = bcmul($line['amount'], '100', 0);
        $foreign = ($line['amount_foreign'] ?? null) === null ? null : bcmul($line['amount_foreign'], '100', 0);
        $red = !empty($line['is_red_storno']);
        return ['account_id' => (int) $line['account_id'], 'side' => $line['side'], 'is_red_storno' => $red,
            'amount_cents' => $cents, 'signed_amount_cents' => $red ? bcsub('0', $cents, 0) : $cents,
            'foreign_amount_cents' => $foreign, 'signed_foreign_amount_cents' => $foreign !== null && $red ? bcsub('0', $foreign, 0) : $foreign,
            'currency_code' => $line['currency_code'] ?? null, 'fx_rate' => $line['fx_rate'] ?? null,
            'dimensions' => $line['dimensions'] ?? [], 'dimension_splits' => $line['dimension_splits'] ?? [],
            'dimension_origins' => $line['dimension_origins'] ?? [], 'dimension_split_origins' => $line['dimension_split_origins'] ?? []];
    }
}
