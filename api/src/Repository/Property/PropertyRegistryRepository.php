<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyCatalog;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyIdentifierPolicy;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyRevisionRequest;
use MyInvoice\Service\Property\PropertyTimeline;
use PDO;

final class PropertyRegistryRepository
{
    public function __construct(private readonly Connection $db) {}

    public function create(int $supplierId, string $kind, array $input, int $actor): array
    {
        $request = PropertyRevisionRequest::normalize($kind, $input, true);
        return $this->save($supplierId, $kind, null, $request, $actor);
    }

    public function revise(int $supplierId, int $id, string $kind, array $input, int $actor): array
    {
        PropertyInput::id($id, 'id');
        return $this->save($supplierId, $kind, $id, PropertyRevisionRequest::normalize($kind, $input, false), $actor);
    }

    public function confirm(int $supplierId, int $id, int $pendingId, array $input, int $actor): array
    {
        PropertyInput::fields($input, ['expected_revision', 'effective_from', 'reason', 'title_ref', 'idempotency_key']);
        $at = PropertyInstant::normalize($input['effective_from'] ?? null);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $id, $pendingId, $input, $at, $actor): array {
            (new PropertyCatalog($this->db))->lock($supplierId, $at);
            $object = (new PropertyReadRepository($this->db))->find($supplierId, $id, $at, currentRead: true);
            $history = array_column($object['history'], null, 'id');
            $pending = $history[$pendingId] ?? null;
            if ($pending === null || $pending['status'] !== 'pending' || PropertyInstant::normalize($pending['effective_from']) !== $at) {
                throw new PropertyException('property_pending_not_found', 'Čekající podklad nebyl nalezen nebo má jinou účinnost.', 409);
            }
            $request = PropertyRevisionRequest::normalize($object['kind'], $input + ['effective_to' => $pending['effective_to'], 'status' => 'confirmed', 'state' => $pending['state']], false);
            $request['resolves_revision_id'] = $pendingId;
            return $this->save($supplierId, $object['kind'], $id, $request, $actor);
        });
    }

    private function save(int $supplierId, string $kind, ?int $id, array $request, int $actor): array
    {
        PropertyInput::id($actor, 'actor');
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $kind, $id, $request, $actor): array {
            $catalog = new PropertyCatalog($this->db);
            $types = $catalog->lock($supplierId, $request['effective_from']);
            $reader = new PropertyReadRepository($this->db);
            $objects = array_column($reader->objects($supplierId, null, true), null, 'id');
            $pdo = $this->db->pdo();
            $hash = PropertyInput::hash(['kind' => $kind, 'id' => $id, 'request' => $request, 'actor' => $actor]);
            $stmt = $pdo->prepare('SELECT object_id, revision_no, request_hash FROM property_revisions WHERE supplier_id = ? AND idempotency_key = ? FOR UPDATE');
            $stmt->execute([$supplierId, $request['idempotency_key']]);
            if ($old = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!hash_equals($old['request_hash'], $hash)) throw new PropertyException('property_idempotency_conflict', 'Identifikátor požadavku již patří jiné změně.', 409);
                return ['id' => (int) $old['object_id'], 'revision' => (int) $old['revision_no']];
            }
            (new PropertyProfileRepository($this->db))->assertEnabledLocked($supplierId, $request['effective_from']);
            if ($id !== null && (!isset($objects[$id]) || $objects[$id]['kind'] !== $kind)) throw new PropertyException('property_not_found', 'Záznam registru nebyl nalezen.', 404);
            $object = $id === null ? null : $objects[$id];
            if (($object['current_revision'] ?? 0) !== $request['expected_revision']) throw new PropertyException('property_revision_conflict', 'Registr mezitím změnil jiný uživatel. Načtěte aktuální stav.', 409);
            $histories = $reader->histories($supplierId, array_keys($objects), true);
            if (isset($request['resolves_revision_id'])) {
                foreach ($histories[$id] ?? [] as $revision) if ($revision['resolves_revision_id'] === $request['resolves_revision_id']) {
                    throw new PropertyException('property_revision_conflict', 'Tento čekající podklad již byl vyřešen.', 409);
                }
            }
            $parent = $object['parent_id'] ?? $request['parent_id'] ?? null;
            if ($kind === 'unit') {
                if (!isset($objects[$parent]) || $objects[$parent]['kind'] !== 'house') throw new PropertyException('property_not_found', 'Dům nebyl nalezen.', 404);
                if (!(PropertyTimeline::at($histories[$parent] ?? [], $request['effective_from'])['revision']['state']['active'] ?? false)) throw new PropertyException('property_house_inactive', 'Dům není k okamžiku účinnosti aktivní.', 409);
                $this->assertParties($supplierId, $request['state']);
                foreach ($request['state']['services'] as $service) {
                    if (!isset($objects[$service]) || $objects[$service]['kind'] !== 'service'
                        || !(PropertyTimeline::at($histories[$service] ?? [], $request['effective_from'])['revision']['state']['active'] ?? false)) {
                        throw new PropertyException('property_service_unavailable', 'Služba není v této firmě k okamžiku účinnosti dostupná.', 409);
                    }
                }
            }
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            if ($object === null) {
                foreach ($objects as $existing) if ($existing['kind'] === $kind && strcasecmp($existing['code'], $request['code']) === 0) throw new PropertyException('property_code_conflict', 'Tento kód již registr používá.', 409);
                $pdo->prepare('INSERT INTO property_objects (supplier_id, kind, parent_id, code, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$supplierId, $kind, $parent, $request['code'], $now]);
                $id = (int) $pdo->lastInsertId();
                if ($kind !== 'house') {
                    $value = $catalog->bindLocked($supplierId, $kind, $id, $request['code'], $types, $actor);
                    $pdo->prepare('UPDATE property_objects SET dimension_value_id = ? WHERE supplier_id = ? AND id = ?')->execute([$value, $supplierId, $id]);
                }
            }
            if ($kind === 'unit') {
                $unitHistories = array_intersect_key($histories, array_filter($objects, static fn (array $row): bool => $row['kind'] === 'unit'));
                PropertyIdentifierPolicy::assertUnique($id, ['id' => 0, 'revision' => $request['expected_revision'] + 1, 'status' => $request['status'],
                    'effective_from' => $request['effective_from'], 'effective_to' => $request['effective_to'], 'recorded_at' => str_replace(' ', 'T', $now) . 'Z', 'state' => $request['state']], $unitHistories);
            }
            return $this->insertRevision($supplierId, $id, $request, $hash, $actor, $now);
        });
    }

    private function insertRevision(int $supplierId, int $id, array $request, string $hash, int $actor, string $now): array
    {
        $pdo = $this->db->pdo();
        $revision = $request['expected_revision'] + 1;
        $state = $request['state'];
        $pdo->prepare('INSERT INTO property_revisions (supplier_id, object_id, revision_no, status, effective_from, effective_to, state_json, state_hash, idempotency_key, request_hash, reason, title_ref, created_by, recorded_at, resolves_revision_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $id, $revision, $request['status'], PropertyInstant::sql($request['effective_from']), $request['effective_to'] === null ? null : PropertyInstant::sql($request['effective_to']),
                self::json($state), PropertyInput::hash($state), $request['idempotency_key'], $hash, $request['reason'], $request['title_ref'], $actor, $now, $request['resolves_revision_id'] ?? null]);
        $revisionId = (int) $pdo->lastInsertId();
        foreach ($state['ownerships'] ?? [] as $group) {
            $pdo->prepare('INSERT INTO property_revision_groups (supplier_id, revision_id, group_key, kind, share_num, share_den, title_ref) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $revisionId, $group['group_key'], $group['kind'], $group['share']['num'], $group['share']['den'], $group['title_ref']]);
            $groupId = (int) $pdo->lastInsertId();
            foreach ($group['members'] as $client) $pdo->prepare('INSERT INTO property_revision_members (supplier_id, group_id, client_id) VALUES (?, ?, ?)')->execute([$supplierId, $groupId, $client]);
        }
        foreach ($state['roles'] ?? [] as $role) $pdo->prepare('INSERT INTO property_revision_roles (supplier_id, revision_id, client_id, role, scopes_json, title_ref) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $revisionId, $role['client_id'], $role['role'], self::json($role['scopes']), $role['title_ref']]);
        foreach ($state['identifiers'] ?? [] as $identifier) $pdo->prepare('INSERT INTO property_revision_identifiers (supplier_id, revision_id, kind, value, payment_scope, source, title_ref) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $revisionId, $identifier['kind'], $identifier['value'], $identifier['payment_scope'], $identifier['source'], $identifier['title_ref']]);
        foreach ($state['services'] ?? [] as $service) $pdo->prepare('INSERT INTO property_revision_services (supplier_id, revision_id, service_id) VALUES (?, ?, ?)')->execute([$supplierId, $revisionId, $service]);
        $stmt = $pdo->prepare('UPDATE property_objects SET current_revision = ? WHERE supplier_id = ? AND id = ? AND current_revision = ?');
        $stmt->execute([$revision, $supplierId, $id, $request['expected_revision']]);
        if ($stmt->rowCount() !== 1) throw new PropertyException('property_revision_conflict', 'Registr se během ukládání změnil.', 409);
        $pdo->prepare("INSERT INTO property_audit (supplier_id, object_id, revision_id, action, payload_json, created_by, recorded_at) VALUES (?, ?, ?, 'revision', ?, ?, ?)")
            ->execute([$supplierId, $id, $revisionId, self::json(['revision' => $revision, 'state_hash' => PropertyInput::hash($state), 'request_hash' => $hash]), $actor, $now]);
        return ['id' => $id, 'revision' => $revision];
    }

    private function assertParties(int $supplierId, array $state): void
    {
        $ids = array_column($state['roles'], 'client_id');
        foreach ($state['ownerships'] as $group) array_push($ids, ...$group['members']);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) return;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id FROM clients WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids]);
        if (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) !== $ids) throw new PropertyException('property_party_not_found', 'Účastník nepatří do adresáře této firmy.', 404);
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
