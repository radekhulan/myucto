<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyRevisionRequest;
use MyInvoice\Service\Property\PropertyState;
use PDO;

final class PropertyCloneScrubber
{
    private const TABLES = ['property_module_profiles', 'property_objects', 'property_revisions', 'property_revision_groups',
        'property_revision_members', 'property_revision_roles', 'property_revision_identifiers', 'property_revision_services', 'property_audit'];

    public function __construct(private readonly PDO $pdo, private readonly TextScrubber $scrubber) {}

    public function run(): array
    {
        $marks = implode(',', array_fill(0, count(self::TABLES), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$marks})");
        $stmt->execute(self::TABLES);
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) return [];
        if ($count !== count(self::TABLES) || $this->pdo->inTransaction()) throw new \LogicException('Property clone postprocess requires the complete schema and its own transaction.');
        if (TriggerMetadata::any($this->pdo)) {
            throw new \LogicException('Property clone postprocess must run before installing triggers.');
        }
        $this->pdo->beginTransaction();
        try {
            $hashes = []; $stateMap = [];
            $objects = array_column($this->rows('property_objects'), null, 'id');
            foreach ($this->rows('property_module_profiles') as $row) {
                $request = ['expected_revision' => (int) $row['revision_no'] - 1, 'effective_from' => self::instant($row['effective_from']),
                    'effective_to' => self::instant($row['effective_to']), 'enabled' => (bool) $row['enabled'], 'reason' => $row['reason'],
                    'title_ref' => $row['title_ref'], 'idempotency_key' => $row['idempotency_key'], 'actor' => (int) $row['created_by']];
                $this->assertHash($row['request_hash'], $request);
                $request['reason'] = $this->scrubber->scrub($request['reason']);
                $request['title_ref'] = $this->scrubber->scrub($request['title_ref']);
                $hashes[$row['request_hash']] = $hash = PropertyInput::hash($request);
                $this->update('property_module_profiles', $row, ['reason' => $request['reason'], 'title_ref' => $request['title_ref'], 'request_hash' => $hash]);
            }
            foreach ($this->rows('property_revisions') as $row) {
                $object = $objects[$row['object_id']] ?? throw new \RuntimeException('Property revision has no object.');
                if ((int) $object['supplier_id'] !== (int) $row['supplier_id']) throw new \RuntimeException('Property revision tenant mismatch.');
                $state = json_decode($row['state_json'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertHash($row['state_hash'], $state);
                $this->assertMaterialization($row, $state);
                $create = (int) $row['revision_no'] === 1;
                $request = ['expected_revision' => (int) $row['revision_no'] - 1, 'effective_from' => self::instant($row['effective_from']),
                    'effective_to' => self::instant($row['effective_to']), 'status' => $row['status'], 'state' => $state,
                    'reason' => $row['reason'], 'title_ref' => $row['title_ref'], 'idempotency_key' => $row['idempotency_key']];
                if ($create) $request += ['code' => $object['code'], 'parent_id' => $object['parent_id'] === null ? null : (int) $object['parent_id']];
                $request = PropertyRevisionRequest::normalize($object['kind'], $request, $create);
                if ($row['resolves_revision_id'] !== null) $request['resolves_revision_id'] = (int) $row['resolves_revision_id'];
                $envelope = ['kind' => $object['kind'], 'id' => $create ? null : (int) $object['id'], 'request' => $request, 'actor' => (int) $row['created_by']];
                $this->assertHash($row['request_hash'], $envelope);
                $state = $this->scrubSnapshotState($object['kind'], $state);
                $envelope['request']['state'] = $state;
                $envelope['request']['reason'] = $this->scrubber->scrub($row['reason']);
                $envelope['request']['title_ref'] = $this->scrubber->scrub($row['title_ref']);
                $hashes[$row['state_hash']] = $stateHash = PropertyInput::hash($state);
                $stateMap[(int) $row['supplier_id']][(int) $row['id']] = ['before_state_hash' => $row['state_hash'], 'after_state_hash' => $stateHash];
                $hashes[$row['request_hash']] = $requestHash = PropertyInput::hash($envelope);
                $this->update('property_revisions', $row, ['state_json' => self::json($state), 'state_hash' => $stateHash, 'request_hash' => $requestHash,
                    'reason' => $envelope['request']['reason'], 'title_ref' => $envelope['request']['title_ref']]);
            }
            foreach (['property_revision_groups', 'property_revision_roles', 'property_revision_identifiers'] as $table) foreach ($this->rows($table) as $row) {
                $changes = ['title_ref' => $this->scrubber->scrub($row['title_ref'])];
                if ($table === 'property_revision_identifiers') $changes['value'] = $this->scrubber->scrub($row['value']);
                $this->update($table, $row, $changes);
            }
            foreach ($this->rows('property_audit') as $row) {
                $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                if ($row['action'] === 'import') {
                    $request = $payload['request'];
                    $this->assertHash($payload['request_hash'], $request + ['actor' => (int) $row['created_by']]);
                    $this->assertHash($request['preview_hash'], $request['plan']);
                    foreach ($request['plan']['units'] as &$unit) $unit['state'] = $this->scrubSnapshotState('unit', $unit['state']);
                    unset($unit);
                    foreach (['reason', 'title_ref'] as $field) $request['plan'][$field] = $this->scrubber->scrub($request['plan'][$field]);
                    $request['preview_hash'] = PropertyInput::hash($request['plan']);
                    $payload['request'] = $request;
                    $payload['request_hash'] = PropertyInput::hash($request + ['actor' => (int) $row['created_by']]);
                } elseif (in_array($row['action'], ['profile', 'revision'], true)) {
                    foreach (['state_hash', 'request_hash'] as $field) if (isset($payload[$field])) {
                        $payload[$field] = $hashes[$payload[$field]] ?? throw new \RuntimeException('Property audit refers to an unknown canonical hash.');
                    }
                } elseif ($row['action'] !== 'export') throw new \RuntimeException('Unknown property audit action.');
                $this->update('property_audit', $row, ['payload_json' => self::json($payload)]);
            }
            $this->pdo->commit();
            return $stateMap;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function scrubSnapshotState(string $kind, array $state): array
    {
        if ($kind === 'house') {
            $state['name'] = $this->scrubber->scrub($state['name']);
            foreach (['street', 'city', 'postal_code'] as $field) {
                $key = $field === 'postal_code' ? 'zip' : $field;
                $value = json_decode($this->scrubber->scrubJson(self::json([$key => $state['address'][$field]])), true, flags: JSON_THROW_ON_ERROR);
                $state['address'][$field] = $value[$key];
            }
            $state['declaration_ref'] = $this->scrubber->scrub($state['declaration_ref']);
        } elseif ($kind === 'service') {
            $state['name'] = $this->scrubber->scrub($state['name']);
            $state['measure_label'] = $this->scrubber->scrub($state['measure_label']);
        } else {
            $state['unit_no'] = $this->scrubber->scrub($state['unit_no']);
            foreach (['ownerships', 'roles', 'identifiers'] as $field) foreach ($state[$field] as &$item) {
                $item['title_ref'] = $this->scrubber->scrub($item['title_ref']);
                if ($field === 'identifiers') $item['value'] = $this->scrubber->scrub($item['value']);
            }
            unset($item);
        }
        return PropertyState::normalize($kind, $state);
    }

    private function assertMaterialization(array $revision, array $state): void
    {
        $groups = [];
        foreach ($this->children('property_revision_groups', $revision) as $group) {
            $stmt = $this->pdo->prepare('SELECT client_id FROM property_revision_members WHERE supplier_id = ? AND group_id = ? ORDER BY client_id');
            $stmt->execute([$revision['supplier_id'], $group['id']]);
            $groups[strtolower($group['group_key'])] = ['group_key' => $group['group_key'], 'kind' => $group['kind'], 'share' => ['num' => $group['share_num'], 'den' => $group['share_den']],
                'members' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), 'title_ref' => $group['title_ref']];
        }
        ksort($groups, SORT_STRING);
        $roles = [];
        foreach ($this->children('property_revision_roles', $revision) as $role) $roles[$role['client_id'] . ':' . $role['role']] = [
            'client_id' => (int) $role['client_id'], 'role' => $role['role'], 'scopes' => json_decode($role['scopes_json'], true, flags: JSON_THROW_ON_ERROR), 'title_ref' => $role['title_ref']];
        ksort($roles, SORT_STRING);
        $identifiers = [];
        foreach ($this->children('property_revision_identifiers', $revision) as $item) $identifiers[$item['kind'] . ':' . $item['payment_scope'] . ':' . $item['value']] = array_intersect_key($item, array_flip(['kind', 'value', 'payment_scope', 'source', 'title_ref']));
        ksort($identifiers, SORT_STRING);
        $services = array_map('intval', array_column($this->children('property_revision_services', $revision), 'service_id'));
        sort($services, SORT_NUMERIC);
        $expected = ['ownerships' => $state['ownerships'] ?? [], 'roles' => $state['roles'] ?? [], 'identifiers' => $state['identifiers'] ?? [], 'services' => $state['services'] ?? []];
        $actual = ['ownerships' => array_values($groups), 'roles' => array_values($roles), 'identifiers' => array_values($identifiers), 'services' => $services];
        if (PropertyInput::hash($expected) !== PropertyInput::hash($actual)) throw new \RuntimeException('Property materialization differs from immutable state.');
    }

    private function children(string $table, array $revision): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id = ? AND revision_id = ?');
        $stmt->execute([$revision['supplier_id'], $revision['id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertHash(string $expected, array $payload): void
    {
        if (!hash_equals($expected, PropertyInput::hash($payload))) throw new \RuntimeException('Property clone canonical hash mismatch.');
    }

    private function rows(string $table): array { return $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
    private function update(string $table, array $row, array $changes): void
    {
        $sets = implode(', ', array_map(static fn (string $field): string => $field . ' = ?', array_keys($changes)));
        $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE supplier_id = ? AND id = ?')->execute([...array_values($changes), $row['supplier_id'], $row['id']]);
    }
    private static function instant(?string $sql): ?string { return $sql === null ? null : PropertyInstant::normalize(str_replace(' ', 'T', $sql) . 'Z'); }
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }
}
