<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyCatalog;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;

final class PropertyProfileRepository
{
    public function __construct(private readonly Connection $db) {}

    public function history(int $supplierId, bool $currentRead = false): array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current property reads require owning locks.');
        $stmt = $this->db->pdo()->prepare('SELECT * FROM property_module_profiles WHERE supplier_id = ? ORDER BY revision_no DESC' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['enabled'] = (bool) $row['enabled'];
            foreach (['effective_from', 'effective_to', 'recorded_at'] as $field) if ($row[$field] !== null) $row[$field] = str_replace(' ', 'T', $row[$field]) . 'Z';
            unset($row['idempotency_key'], $row['request_hash']);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function at(int $supplierId, string $at, bool $currentRead = false, ?string $knownAt = null): ?array
    {
        $at = PropertyInstant::normalize($at);
        $knownAt = $knownAt === null ? null : PropertyInstant::normalize($knownAt);
        foreach ($this->history($supplierId, $currentRead) as $profile) {
            if (($knownAt === null || PropertyInstant::normalize($profile['recorded_at']) <= $knownAt)
                && PropertyInstant::contains($profile['effective_from'], $profile['effective_to'], $at)) return $profile;
        }
        return null;
    }

    public function assertEnabledLocked(int $supplierId, string $at): void
    {
        if (!($this->at($supplierId, $at, true)['enabled'] ?? false)) throw new PropertyException('property_profile_required', 'K tomuto okamžiku není potvrzený aktivní profil SVJ.', 409);
    }

    public function save(int $supplierId, array $input, int $actor): array
    {
        PropertyInput::fields($input, ['expected_revision', 'effective_from', 'effective_to', 'enabled', 'reason', 'title_ref', 'idempotency_key']);
        PropertyInput::id($actor, 'actor');
        if (!is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0 || !is_bool($input['enabled'] ?? null)) PropertyInput::invalid('expected_revision');
        [$from, $to] = PropertyInstant::interval($input['effective_from'] ?? null, $input['effective_to'] ?? null);
        $request = ['expected_revision' => $input['expected_revision'], 'effective_from' => $from, 'effective_to' => $to, 'enabled' => $input['enabled'],
            'reason' => PropertyInput::text($input['reason'] ?? null, 'reason', 4000), 'title_ref' => PropertyInput::text($input['title_ref'] ?? null, 'title_ref', 2000),
            'idempotency_key' => PropertyInput::code($input['idempotency_key'] ?? null, 'idempotency_key'), 'actor' => $actor];
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $request, $actor): array {
            (new PropertyCatalog($this->db))->lock($supplierId, $request['effective_from']);
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT * FROM property_module_profiles WHERE supplier_id = ? ORDER BY revision_no FOR UPDATE');
            $stmt->execute([$supplierId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hash = PropertyInput::hash($request);
            foreach ($rows as $row) if ($row['idempotency_key'] === $request['idempotency_key']) {
                if (!hash_equals($row['request_hash'], $hash)) throw new PropertyException('property_idempotency_conflict', 'Identifikátor požadavku již patří jiné změně.', 409);
                return ['id' => (int) $row['id'], 'revision' => (int) $row['revision_no']];
            }
            $current = $rows === [] ? 0 : (int) end($rows)['revision_no'];
            if ($current !== $request['expected_revision']) throw new PropertyException('property_revision_conflict', 'Profil mezitím změnil jiný uživatel. Načtěte aktuální stav.', 409);
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $pdo->prepare('INSERT INTO property_module_profiles (supplier_id, revision_no, effective_from, effective_to, enabled, reason, title_ref, idempotency_key, request_hash, created_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $current + 1, PropertyInstant::sql($request['effective_from']), $request['effective_to'] === null ? null : PropertyInstant::sql($request['effective_to']),
                    $request['enabled'] ? 1 : 0, $request['reason'], $request['title_ref'], $request['idempotency_key'], $hash, $actor, $now]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO property_audit (supplier_id, action, payload_json, created_by, recorded_at) VALUES (?, 'profile', ?, ?, ?)")
                ->execute([$supplierId, json_encode(['profile_id' => $id, 'revision' => $current + 1, 'request_hash' => $hash], JSON_THROW_ON_ERROR), $actor, $now]);
            return ['id' => $id, 'revision' => $current + 1];
        });
    }
}
