<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyTimeline;
use PDO;

final class PropertyReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function lockObjects(int $supplierId): void
    {
        $this->assertCurrent(true);
        $stmt=$this->db->pdo()->prepare('SELECT id FROM property_objects WHERE supplier_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId]);$stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function objects(int $supplierId, ?string $kind = null, bool $currentRead = false): array
    {
        $this->assertCurrent($currentRead);
        if ($kind !== null && !in_array($kind, ['house', 'unit', 'service'], true)) PropertyInput::invalid('kind');
        $stmt = $this->db->pdo()->prepare('SELECT * FROM property_objects WHERE supplier_id = ?' . ($kind === null ? '' : ' AND kind = ?') . ' ORDER BY id' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($kind === null ? [$supplierId] : [$supplierId, $kind]);
        return array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'parent_id', 'dimension_value_id', 'current_revision'] as $field) if ($row[$field] !== null) $row[$field] = (int) $row[$field];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function histories(int $supplierId, array $objectIds, bool $currentRead = false): array
    {
        $this->assertCurrent($currentRead);
        if ($objectIds === []) return [];
        foreach ($objectIds as $id) PropertyInput::id($id, 'object_id');
        $marks = implode(',', array_fill(0, count($objectIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT * FROM property_revisions WHERE supplier_id = ? AND object_id IN ({$marks}) ORDER BY object_id, revision_no" . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$objectIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['id', 'supplier_id', 'object_id', 'created_by', 'resolves_revision_id'] as $field) if ($row[$field] !== null) $row[$field] = (int) $row[$field];
            $row['revision'] = (int) $row['revision_no'];
            $row['state'] = json_decode($row['state_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($row['state_hash'], PropertyInput::hash($row['state']))) throw new PropertyException('property_history_corrupt', 'Historický záznam registru neodpovídá svému kontrolnímu otisku.', 409);
            foreach (['effective_from', 'effective_to', 'recorded_at'] as $field) if ($row[$field] !== null) $row[$field] = str_replace(' ', 'T', $row[$field]) . 'Z';
            unset($row['revision_no'], $row['state_json'], $row['idempotency_key'], $row['request_hash']);
            $out[$row['object_id']][] = $row;
        }
        return $out;
    }

    public function list(int $supplierId, string $kind, string $at, ?string $knownAt = null, bool $currentRead = false): array
    {
        PropertyInstant::normalize($at);
        $objects = $this->objects($supplierId, $kind, $currentRead);
        $histories = $this->histories($supplierId, array_column($objects, 'id'), $currentRead);
        return array_map(static function (array $object) use ($histories, $at, $knownAt): array {
            $snapshot = PropertyTimeline::at($histories[$object['id']] ?? [], $at, $knownAt);
            if ($object['kind'] !== 'unit') unset($snapshot['ownership_review_required']);
            return $object + $snapshot;
        }, $objects);
    }

    public function find(int $supplierId, int $id, string $at, ?string $knownAt = null, bool $currentRead = false): array
    {
        $objects = array_column($this->objects($supplierId, null, $currentRead), null, 'id');
        $object = $objects[$id] ?? throw new PropertyException('property_not_found', 'Záznam registru nebyl nalezen.', 404);
        $history = $this->histories($supplierId, [$id], $currentRead)[$id] ?? [];
        $snapshot = PropertyTimeline::at($history, $at, $knownAt);
        if ($object['kind'] !== 'unit') unset($snapshot['ownership_review_required']);
        return $object + $snapshot + ['history' => $history];
    }

    public function parties(int $supplierId, array $clientIds, bool $currentRead = false): array
    {
        $this->assertCurrent($currentRead);
        $clientIds = array_values(array_unique($clientIds));
        if ($clientIds === []) return [];
        foreach ($clientIds as $id) PropertyInput::id($id, 'client_id');
        $marks = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, company_name, first_name, last_name, archived_at FROM clients WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY id" . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$clientIds]);
        return array_map(static function (array $row): array { $row['id'] = (int) $row['id']; return $row; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function assertCurrent(bool $currentRead): void
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current registry reads require owning period/catalog locks.');
    }
}
