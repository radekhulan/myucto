<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalProof;
use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use MyInvoice\Service\Accounting\Allocation\JointAllocationRequest;
use PDO;

final class JointAllocationCloneScrubber
{
    private const TABLES = ['joint_allocation_keys', 'joint_allocation_key_versions', 'joint_allocation_batches',
        'joint_allocation_source_claims', 'joint_allocation_cancellations', 'joint_allocation_audit'];
    private const TEXT_FIELDS = ['name', 'description', 'entry_description', 'account_name', 'explanation', 'evidence', 'reason'];

    public function __construct(private readonly PDO $pdo, private readonly TextScrubber $scrubber) {}

    public function run(): void
    {
        $marks = implode(',', array_fill(0, count(self::TABLES), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$marks})");
        $stmt->execute(self::TABLES);
        $tables = (int) $stmt->fetchColumn();
        if ($tables === 0) return;
        if ($tables !== count(self::TABLES) || $this->pdo->inTransaction()) {
            throw new \LogicException('Allocation clone postprocess requires the complete schema and its own transaction.');
        }
        if (TriggerMetadata::any($this->pdo)) {
            throw new \LogicException('Allocation clone postprocess must run before installing clone triggers.');
        }
        $this->pdo->beginTransaction();
        try {
            $hashes = [];
            foreach ($this->rows('joint_allocation_keys') as $row) {
                $this->update('joint_allocation_keys', $row, ['name' => $this->scrubber->scrub($row['name'])]);
            }
            foreach ($this->rows('joint_allocation_key_versions') as $row) {
                $version = $this->scrubFields(JointAllocationKeyVersion::decodeCells($row));
                $hash = JointAllocationKeyVersion::normalize($version)['content_hash'];
                $hashes[$row['content_hash']] = $hash;
                $this->update('joint_allocation_key_versions', $row, ['explanation' => $version['explanation'], 'content_hash' => $hash]);
            }
            $batches = $this->rows('joint_allocation_batches');
            foreach ($batches as $row) {
                $snapshot = JointAllocationKeyVersion::decodeSnapshot($row);
                $oldHash = $snapshot['preview_hash'] ?? '';
                unset($snapshot['preview_hash']);
                $snapshot = $this->scrubFields($snapshot);
                $this->assertLedger($row, $snapshot);
                $snapshot['key']['content_hash'] = JointAllocationKeyVersion::normalize($snapshot['key'])['content_hash'];
                $hash = JointAllocationKeyVersion::hash($snapshot);
                $hashes[$oldHash] = $hash;
                $snapshot['preview_hash'] = $hash;
                $reason = $this->scrubber->scrub($row['reason']);
                $request = JointAllocationRequest::apply([
                    'target_date' => $row['target_date'], 'reason' => $reason, 'idempotency_key' => $row['idempotency_key'],
                    'key_version_id' => (int) $row['key_version_id'], 'source_line_ids' => array_map('intval', array_column($snapshot['source_lines'], 'id')),
                    'preview_hash' => $hash, 'exact_cells' => $snapshot['exact_cells'] ?? [],
                ]);
                $hashes[$row['request_hash']] = $request['request_hash'];
                $this->update('joint_allocation_batches', $row, ['reason' => $reason, 'preview_hash' => $hash,
                    'request_hash' => $request['request_hash'], 'snapshot_json' => self::json($snapshot)]);
            }
            $cancellations = $this->rows('joint_allocation_cancellations');
            foreach ($cancellations as $row) {
                if ($row['reversal_entry_id'] === null) throw new \RuntimeException('Incomplete allocation cancellation in clone.');
                $reason = $this->scrubber->scrub($row['reason']);
                $request = JointAllocationRequest::cancel(['target_date' => $row['target_date'], 'reason' => $reason,
                    'idempotency_key' => $row['idempotency_key'], 'batch_id' => (int) $row['batch_id']]);
                $hashes[$row['request_hash']] = $request['request_hash'];
                $this->update('joint_allocation_cancellations', $row, ['reason' => $reason, 'request_hash' => $request['request_hash']]);
            }
            foreach ($this->rows('joint_allocation_audit') as $row) {
                $payload = $this->scrubFields(json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR), $hashes);
                $this->update('joint_allocation_audit', $row, ['payload_json' => self::json($payload)]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function scrubFields(array $value, array $hashes = []): array
    {
        foreach ($value as $key => $item) {
            if ($key === 'cost_center' && is_string($item)) {
                $value[$key] = $this->scrubber->byStrategy('cost_center', $item);
            } elseif (is_array($item) && in_array($key, ['dimension_origins', 'dimension_split_origins'], true)) {
                $value[$key] = $this->scrubOrigins($item, $key === 'dimension_split_origins');
            } elseif (is_array($item)) {
                $value[$key] = $this->scrubFields($item, $hashes);
            } elseif (is_string($item) && in_array($key, self::TEXT_FIELDS, true)) {
                $value[$key] = $this->scrubber->scrub($item);
            } elseif (is_string($item) && in_array($key, ['content_hash', 'preview_hash', 'request_hash'], true)) {
                $value[$key] = $hashes[$item] ?? $item;
            }
        }
        return $value;
    }

    private function scrubOrigins(array $origins, bool $splits): array
    {
        foreach ($origins as $id => $origin) {
            if ($splits) {
                $origins[$id] = $this->scrubOrigins($origin, false);
            } elseif (is_string($origin['ref'] ?? null)) {
                $origins[$id]['ref'] = $this->scrubber->scrub($origin['ref']);
            }
        }
        return $origins;
    }

    private function rows(string $table): array
    {
        return $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function update(string $table, array $row, array $values): void
    {
        $sets = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($values)));
        $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE supplier_id = ? AND id = ?')
            ->execute([...array_values($values), $row['supplier_id'], $row['id']]);
    }

    private function assertLedger(array $batch, array $snapshot): void
    {
        if ($batch['journal_entry_id'] === null) throw new \RuntimeException('Incomplete allocation batch in clone.');
        $stmt = $this->pdo->prepare('SELECT * FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id');
        $stmt->execute([$batch['supplier_id'], $batch['journal_entry_id']]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expected = JointAllocationJournalProof::materializedLines($snapshot['plan'], (int) $batch['id'], $snapshot['type_ids']);
        if (count($lines) !== count($expected)) throw new \RuntimeException('Allocation clone ledger differs from snapshot.');
        $dimensions = $this->pdo->prepare('SELECT * FROM journal_entry_line_dimensions WHERE supplier_id = ? AND line_id = ?');
        $splits = $this->pdo->prepare('SELECT * FROM journal_entry_line_dimension_splits WHERE supplier_id = ? AND line_id = ?');
        foreach ($lines as $index => $line) {
            $line['dimensions'] = $line['dimension_splits'] = $line['dimension_origins'] = $line['dimension_split_origins'] = [];
            $dimensions->execute([$batch['supplier_id'], $line['id']]);
            foreach ($dimensions->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
                $type = (int) $assignment['dimension_type_id'];
                $line['dimensions'][$type] = (int) $assignment['dimension_value_id'];
                $line['dimension_origins'][$type] = ['origin' => $assignment['assignment_origin'], 'ref' => $assignment['assignment_origin_ref']];
            }
            $splits->execute([$batch['supplier_id'], $line['id']]);
            foreach ($splits->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
                $type = (int) $assignment['dimension_type_id'];
                $value = (int) $assignment['dimension_value_id'];
                $line['dimension_splits'][$type][$value] = $assignment['share'];
                $line['dimension_split_origins'][$type][$value] = ['origin' => $assignment['assignment_origin'], 'ref' => $assignment['assignment_origin_ref']];
            }
            if (!JointAllocationJournalProof::sameLine($line, $expected[$index])) {
                throw new \RuntimeException('Allocation clone ledger differs from snapshot.');
            }
        }
    }

    private static function json(array $value): string
    {
        return JointAllocationKeyVersion::encodeJson($value);
    }
}
