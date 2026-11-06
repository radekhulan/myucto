<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Catalog;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyState;
use MyInvoice\Service\Property\PropertyUnitState;
use PDO;

final class PropertyLegalRoleRepository
{
    private const REVISION_BATCH = 500;
    private const MATERIALIZED_BATCH = 500;
    private const OWNERSHIP_KINDS = ['single', 'coownership_share', 'marital_community'];
    private const ROLE_KINDS = ['payer', 'representative', 'delivery'];

    public function __construct(private readonly Connection $db) {}

    public function units(int $supplierId, string $knownAt, int $afterId, int $limit, ?int $unitId = null): array
    {
        $pdo = $this->db->pdo();
        $knownSql = PropertyInstant::sql($knownAt);
        if ($unitId !== null) {
            $stmt = $pdo->prepare("SELECT id, parent_id, code, created_at FROM property_objects FORCE INDEX (uq_property_object_tenant)
                WHERE supplier_id = ? AND id = ? AND kind = 'unit' AND created_at <= ? LIMIT 1");
            $stmt->execute([$supplierId, $unitId, $knownSql]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new PropertyException('property_not_found', 'Jednotka nebyla k zadanému okamžiku nalezena.', 404);
            return [$this->object($row)];
        }
        $limit = max(1, min(101, $limit));
        $stmt = $pdo->prepare("SELECT id, parent_id, code, created_at FROM property_objects FORCE INDEX (uq_property_object_tenant)
            WHERE supplier_id = ? AND id > ? AND kind = 'unit' AND created_at <= ? ORDER BY supplier_id, id LIMIT {$limit}");
        $stmt->execute([$supplierId, $afterId, $knownSql]);
        return array_map($this->object(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function proven(int $supplierId, array $objects, string $asOf, string $knownAt): array
    {
        if ($objects === []) return [];
        $objectIds = array_map(static fn (array $row): int => (int) $row['id'], $objects);
        if (count($objectIds) > 101 || count(array_unique($objectIds)) !== count($objectIds)) self::corrupt();
        $byObject = [];
        foreach ($objects as $object) $byObject[(int) $object['id']] = ['last_revision' => 0, 'selected' => null];
        $marks = implode(',', array_fill(0, count($objectIds), '?'));
        $afterObjectId = 0;
        $afterRevisionId = 0;
        do {
            $stmt = $this->db->pdo()->prepare("SELECT r.*, t.id AS target_id, t.object_id AS target_object_id,
                    t.revision_no AS target_revision_no, t.status AS target_status, t.recorded_at AS target_recorded_at
                FROM property_revisions r FORCE INDEX (uq_property_revision_object)
                LEFT JOIN property_revisions t ON t.supplier_id = r.supplier_id AND t.object_id = r.object_id AND t.id = r.resolves_revision_id
                WHERE r.supplier_id = ? AND r.object_id IN ({$marks})
                  AND (r.object_id > ? OR (r.object_id = ? AND r.id > ?)) AND r.recorded_at <= ?
                ORDER BY r.supplier_id, r.object_id, r.id LIMIT " . self::REVISION_BATCH);
            $stmt->execute([$supplierId, ...$objectIds, $afterObjectId, $afterObjectId, $afterRevisionId, PropertyInstant::sql($knownAt)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $objectId = (int) $row['object_id'];
                if (!isset($byObject[$objectId])) self::corrupt();
                $revision = $this->revision($row, $objects, $byObject[$objectId]['last_revision'] + 1, $knownAt);
                $byObject[$objectId]['last_revision'] = $revision['revision_no'];
                if ($revision['status'] === 'confirmed' && PropertyInstant::contains($revision['effective_from'], $revision['effective_to'], $asOf)) {
                    $byObject[$objectId]['selected'] = $revision;
                }
                $afterObjectId = $objectId;
                $afterRevisionId = (int) $row['id'];
            }
        } while (count($rows) === self::REVISION_BATCH);

        $pending = $this->pendingCounts($supplierId, $objectIds, $asOf, $knownAt);
        $selectedIds = [];
        foreach ($byObject as $proof) if ($proof['selected'] !== null) $selectedIds[] = $proof['selected']['id'];
        [$groups, $roles] = $this->materialized($supplierId, $selectedIds);
        $result = [];
        foreach ($objects as $object) {
            $objectId = (int) $object['id'];
            $proof = $byObject[$objectId];
            $selected = $proof['selected'];
            if ($selected === null) {
                $result[] = $this->emptyProjection($object, $pending[$objectId] ?? 0, $proof['last_revision']);
                continue;
            }
            $revisionId = $selected['id'];
            $groupRows = $groups[$revisionId] ?? [];
            $roleRows = $roles[$revisionId] ?? [];
            $this->verifyMaterialized($selected['state'], $groupRows, $roleRows);
            $result[] = [
                'unit_id' => $objectId,
                'house_id' => (int) $object['parent_id'],
                'revision_id' => $revisionId,
                'revision_no' => $selected['revision_no'],
                'effective_from' => $selected['effective_from'],
                'effective_to' => $selected['effective_to'],
                'recorded_at' => $selected['recorded_at'],
                'active' => $selected['state']['active'],
                'ownership_groups' => array_map(static fn (array $group): array => [
                    'ownership_group_id' => $group['id'], 'kind' => $group['kind'], 'share' => $group['share'], 'member_ids' => $group['members'],
                ], $groupRows),
                'delegated_roles' => array_map(static fn (array $role): array => [
                    'role_id' => $role['id'], 'client_id' => $role['client_id'], 'role' => $role['role'], 'scopes' => $role['scopes'],
                ], $roleRows),
                'pending_revision_count' => $pending[$objectId] ?? 0,
                'ownership_review_required' => ($pending[$objectId] ?? 0) > 0 || $selected['state']['ownerships'] === [],
                'role_proof_status' => 'verified',
                'verified_revision_count' => $proof['last_revision'],
            ];
        }
        return $result;
    }

    private function revision(array $row, array $objects, int $expectedRevision, string $knownAt): array
    {
        $id = (int) $row['id'];
        $objectId = (int) $row['object_id'];
        $revisionNo = (int) $row['revision_no'];
        if ($id <= 0 || $revisionNo !== $expectedRevision || !in_array($row['status'], ['pending', 'confirmed'], true)) self::corrupt();
        $object = null;
        foreach ($objects as $candidate) if ((int) $candidate['id'] === $objectId) { $object = $candidate; break; }
        if ($object === null) self::corrupt();
        try {
            $from = $this->instant((string) $row['effective_from']);
            $to = $row['effective_to'] === null ? null : $this->instant((string) $row['effective_to']);
            $recorded = $this->instant((string) $row['recorded_at']);
            if ($recorded > $knownAt) self::corrupt();
            $stateRaw = json_decode((string) $row['state_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($stateRaw)) self::corrupt();
            $state = PropertyState::normalize('unit', $stateRaw);
        } catch (\JsonException|PropertyException) { self::corrupt(); }
        if (!PropertyInput::sameJsonGraph($state, $stateRaw) || !is_string($row['state_hash'])
            || !hash_equals((string) $row['state_hash'], PropertyInput::hash($state))) self::corrupt();
        $resolves = $row['resolves_revision_id'] === null ? null : (int) $row['resolves_revision_id'];
        if ($resolves !== null && ($resolves <= 0 || (int) $row['target_id'] !== $resolves || (int) $row['target_object_id'] !== $objectId
            || (int) $row['target_revision_no'] >= $revisionNo || $row['target_status'] !== 'pending'
            || $this->instant((string) $row['target_recorded_at']) > $recorded)) self::corrupt();
        $request = ['expected_revision' => $revisionNo - 1, 'effective_from' => $from, 'effective_to' => $to, 'status' => $row['status'],
            'state' => $state, 'reason' => (string) $row['reason'], 'title_ref' => (string) $row['title_ref'], 'idempotency_key' => (string) $row['idempotency_key']];
        $envelopeId = $objectId;
        if ($revisionNo === 1) {
            $request['code'] = (string) $object['code'];
            $request['parent_id'] = (int) $object['parent_id'];
            $envelopeId = null;
        }
        if ($resolves !== null) $request['resolves_revision_id'] = $resolves;
        $expectedHash = PropertyInput::hash(['kind' => 'unit', 'id' => $envelopeId, 'request' => $request, 'actor' => (int) $row['created_by']]);
        if (!is_string($row['request_hash']) || !hash_equals((string) $row['request_hash'], $expectedHash)) self::corrupt();
        return ['id' => $id, 'revision_no' => $revisionNo, 'status' => $row['status'], 'effective_from' => $from,
            'effective_to' => $to, 'recorded_at' => $recorded, 'state' => $state];
    }

    private function pendingCounts(int $supplierId, array $objectIds, string $asOf, string $knownAt): array
    {
        $marks = implode(',', array_fill(0, count($objectIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT p.object_id, COUNT(*) AS pending_count
            FROM property_revisions p FORCE INDEX (idx_property_revision_interval)
            LEFT JOIN property_revisions r ON r.supplier_id = p.supplier_id AND r.object_id = p.object_id
                AND r.resolves_revision_id = p.id AND r.recorded_at <= ?
            WHERE p.supplier_id = ? AND p.object_id IN ({$marks}) AND p.status = 'pending' AND p.recorded_at <= ?
                AND p.effective_from <= ? AND (p.effective_to IS NULL OR p.effective_to > ?) AND r.id IS NULL
            GROUP BY p.object_id");
        $asSql = PropertyInstant::sql($asOf);
        $knownSql = PropertyInstant::sql($knownAt);
        $stmt->execute([$knownSql, $supplierId, ...$objectIds, $knownSql, $asSql, $asSql]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['object_id']] = (int) $row['pending_count'];
        return $out;
    }

    private function materialized(int $supplierId, array $revisionIds): array
    {
        if ($revisionIds === []) return [[], []];
        $requested = implode(' UNION ALL ', array_fill(0, count($revisionIds), 'SELECT ? revision_id'));
        $stmt = $this->db->pdo()->prepare("WITH requested AS ({$requested})
            SELECT g.id, g.revision_id, g.group_key, g.kind, g.share_num, g.share_den, g.title_ref, m.client_id
            FROM requested r
            JOIN property_revision_groups g FORCE INDEX (fk_property_group_revision)
              ON g.supplier_id = ? AND g.revision_id = r.revision_id
            LEFT JOIN property_revision_members m FORCE INDEX (fk_property_member_group) ON m.supplier_id = g.supplier_id AND m.group_id = g.id
            WHERE g.id > ? OR (g.id = ? AND COALESCE(m.client_id, 0) > ?)
            ORDER BY g.id, COALESCE(m.client_id, 0) LIMIT " . self::MATERIALIZED_BATCH);
        $groups = [];
        $afterGroup = $afterMember = 0;
        do {
            $stmt->execute([...$revisionIds, $supplierId, $afterGroup, $afterGroup, $afterMember]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $revision = (int) $row['revision_id'];
                $id = (int) $row['id'];
                if (!isset($groups[$revision][$id])) {
                    $groups[$revision][$id] = ['id' => $id, 'group_key' => (string) $row['group_key'], 'kind' => (string) $row['kind'],
                        'share' => ['num' => (string) $row['share_num'], 'den' => (string) $row['share_den']], 'members' => [], 'title_ref' => (string) $row['title_ref']];
                }
                if ($row['client_id'] !== null) $groups[$revision][$id]['members'][] = (int) $row['client_id'];
                $afterGroup = $id;
                $afterMember = $row['client_id'] === null ? 0 : (int) $row['client_id'];
            }
        } while (count($rows) === self::MATERIALIZED_BATCH);
        foreach ($groups as &$revisionGroups) {
            ksort($revisionGroups, SORT_NUMERIC);
            foreach ($revisionGroups as &$group) sort($group['members'], SORT_NUMERIC);
            unset($group);
            $revisionGroups = array_values($revisionGroups);
        }
        unset($revisionGroups);
        $stmt = $this->db->pdo()->prepare("WITH requested AS ({$requested})
            SELECT rr.id, rr.revision_id, rr.client_id, rr.role, rr.scopes_json, rr.title_ref
            FROM requested r STRAIGHT_JOIN property_revision_roles rr FORCE INDEX (fk_property_role_revision)
              ON rr.supplier_id = ? AND rr.revision_id = r.revision_id
            WHERE rr.id > ? ORDER BY rr.id LIMIT " . self::MATERIALIZED_BATCH);
        $roles = [];
        $afterRole = 0;
        do {
            $stmt->execute([...$revisionIds, $supplierId, $afterRole]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                try { $scopes = json_decode((string) $row['scopes_json'], true, flags: JSON_THROW_ON_ERROR); }
                catch (\JsonException) { self::corrupt(); }
                if (!is_array($scopes) || !array_is_list($scopes) || array_diff($scopes, PropertyUnitState::ROLE_SCOPES) !== []
                    || count(array_unique($scopes)) !== count($scopes)) self::corrupt();
                sort($scopes, SORT_STRING);
                $roles[(int) $row['revision_id']][(int) $row['id']] = ['id' => (int) $row['id'], 'client_id' => (int) $row['client_id'],
                    'role' => (string) $row['role'], 'scopes' => $scopes, 'title_ref' => (string) $row['title_ref']];
                $afterRole = (int) $row['id'];
            }
        } while (count($rows) === self::MATERIALIZED_BATCH);
        foreach ($roles as &$revisionRoles) { ksort($revisionRoles, SORT_NUMERIC); $revisionRoles = array_values($revisionRoles); }
        unset($revisionRoles);
        return [$groups, $roles];
    }

    private function verifyMaterialized(array $state, array $groups, array $roles): void
    {
        foreach ($groups as $group) if ($group['id'] <= 0 || !in_array($group['kind'], self::OWNERSHIP_KINDS, true) || $group['members'] === []) self::corrupt();
        foreach ($roles as $role) if ($role['id'] <= 0 || $role['client_id'] <= 0 || !in_array($role['role'], self::ROLE_KINDS, true)) self::corrupt();
        $nativeGroups = array_map(static fn (array $group): array => ['group_key' => $group['group_key'], 'kind' => $group['kind'],
            'share' => $group['share'], 'members' => $group['members'], 'title_ref' => $group['title_ref']], $groups);
        $nativeRoles = array_map(static fn (array $role): array => ['client_id' => $role['client_id'], 'role' => $role['role'],
            'scopes' => $role['scopes'], 'title_ref' => $role['title_ref']], $roles);
        if (!PropertyInput::sameJsonGraph($state['ownerships'], $nativeGroups) || !PropertyInput::sameJsonGraph($state['roles'], $nativeRoles)) self::corrupt();
    }

    private function emptyProjection(array $object, int $pending, int $verified): array
    {
        return ['unit_id' => (int) $object['id'], 'house_id' => (int) $object['parent_id'], 'revision_id' => null, 'revision_no' => null,
            'effective_from' => null, 'effective_to' => null, 'recorded_at' => null, 'active' => null, 'ownership_groups' => [],
            'delegated_roles' => [], 'pending_revision_count' => $pending, 'ownership_review_required' => true,
            'role_proof_status' => 'unavailable', 'verified_revision_count' => $verified];
    }

    private function object(array $row): array
    {
        return ['id' => (int) $row['id'], 'parent_id' => (int) $row['parent_id'], 'code' => (string) $row['code'],
            'created_at' => $this->instant((string) $row['created_at'])];
    }

    private function instant(string $sql): string
    {
        return PropertyInstant::normalize(str_replace(' ', 'T', $sql) . 'Z');
    }

    private static function corrupt(): never
    {
        throw new PropertyException('property_legal_role_history_corrupt', 'Historii právních rolí nelze ověřit proti nativnímu registru.', 409);
    }
}
