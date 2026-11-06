<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class PropertyImportService
{
    public function __construct(private readonly Connection $db) {}

    public function previewFile(int $supplierId, array $input): array
    {
        PropertyInput::fields($input, ['format', 'content_base64', 'party_map', 'service_map', 'house_id', 'effective_from', 'effective_to', 'status', 'reason', 'title_ref']);
        if (!is_string($input['content_base64'] ?? null) || strlen($input['content_base64']) > 6_666_668) PropertyInput::invalid('content_base64');
        $content = base64_decode($input['content_base64'], true);
        if ($content === false || !is_array($input['party_map'] ?? null) || !is_array($input['service_map'] ?? null)) PropertyInput::invalid('content_base64');
        $parsed = (new PropertyRegistryImportParser())->parse((string) ($input['format'] ?? ''), $content, $input['party_map'], $input['service_map']);
        if (!$parsed['ready']) return $parsed + ['plan' => null, 'preview_hash' => null];
        unset($input['format'], $input['content_base64'], $input['party_map'], $input['service_map']);
        $preview = $this->previewPlan($supplierId, $input + ['units' => $parsed['units']]);
        return $parsed + $preview;
    }

    public function previewPlan(int $supplierId, array $input, bool $currentRead = false): array
    {
        PropertyInput::fields($input, ['house_id', 'house_revision', 'catalog_revisions', 'profile_revision', 'effective_from', 'effective_to', 'status', 'reason', 'title_ref', 'units']);
        $houseId = PropertyInput::id($input['house_id'] ?? null, 'house_id');
        [$from, $to] = PropertyInstant::interval($input['effective_from'] ?? null, $input['effective_to'] ?? null);
        if (!in_array($input['status'] ?? null, ['pending', 'confirmed'], true)) PropertyInput::invalid('status');
        $reader = new PropertyReadRepository($this->db);
        $objects = array_column($reader->objects($supplierId, null, $currentRead), null, 'id');
        if (($objects[$houseId]['kind'] ?? null) !== 'house') throw new PropertyException('property_not_found', 'Dům nebyl nalezen.', 404);
        $existing = [];
        foreach ($objects as $object) if ($object['kind'] === 'unit') $existing[strtolower($object['code'])] = $object;
        $units = [];
        $parties = [];
        $catalog = [];
        foreach (PropertyInput::list($input['units'] ?? null, 'units', 1000) as $unit) {
            if (!is_array($unit)) PropertyInput::invalid('units');
            PropertyInput::fields($unit, ['code', 'state', 'object_id', 'expected_revision']);
            $code = PropertyInput::code($unit['code'] ?? null, 'unit.code');
            if (isset($units[strtolower($code)]) || !is_array($unit['state'] ?? null)) PropertyInput::invalid('units');
            $state = PropertyUnitState::normalize($unit['state']);
            $old = $existing[strtolower($code)] ?? null;
            if ($old !== null && $old['parent_id'] !== $houseId) throw new PropertyException('property_code_conflict', 'Kód jednotky již patří jinému domu.', 409);
            foreach ($state['ownerships'] as $group) array_push($parties, ...$group['members']);
            array_push($parties, ...array_column($state['roles'], 'client_id'));
            foreach ($state['services'] as $service) {
                if (($objects[$service]['kind'] ?? null) !== 'service') throw new PropertyException('property_service_unavailable', 'Služba nepatří do této firmy.', 409);
                $catalog[$service] = $objects[$service]['current_revision'];
            }
            $units[strtolower($code)] = ['code' => $code, 'object_id' => $old['id'] ?? null, 'expected_revision' => $old['current_revision'] ?? 0, 'state' => $state];
        }
        if ($units === []) PropertyInput::invalid('units');
        $parties = array_values(array_unique($parties));
        if (count($reader->parties($supplierId, $parties, $currentRead)) !== count($parties)) throw new PropertyException('property_party_not_found', 'Účastník nepatří do adresáře této firmy.', 404);
        $profile = (new PropertyProfileRepository($this->db))->at($supplierId, $from, $currentRead);
        if (!($profile['enabled'] ?? false)) throw new PropertyException('property_profile_required', 'K tomuto okamžiku není potvrzený aktivní profil SVJ.', 409);
        ksort($units, SORT_STRING);
        ksort($catalog, SORT_NUMERIC);
        $plan = ['house_id' => $houseId, 'house_revision' => $objects[$houseId]['current_revision'], 'catalog_revisions' => $catalog, 'profile_revision' => $profile['revision_no'],
            'effective_from' => $from, 'effective_to' => $to, 'status' => $input['status'],
            'reason' => PropertyInput::text($input['reason'] ?? null, 'reason', 4000), 'title_ref' => PropertyInput::text($input['title_ref'] ?? null, 'title_ref', 2000), 'units' => array_values($units)];
        return ['plan' => $plan, 'preview_hash' => PropertyInput::hash($plan)];
    }

    public function apply(int $supplierId, array $input, int $actor): array
    {
        PropertyInput::fields($input, ['plan', 'preview_hash', 'idempotency_key']);
        PropertyInput::id($actor, 'actor');
        if (!is_array($input['plan'] ?? null) || !is_string($input['preview_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['preview_hash'])) PropertyInput::invalid('preview_hash');
        $token = PropertyInput::code($input['idempotency_key'] ?? null, 'idempotency_key');
        $at = PropertyInstant::normalize($input['plan']['effective_from'] ?? null);
        $requestHash = PropertyInput::hash($input + ['actor' => $actor]);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $input, $actor, $token, $at, $requestHash): array {
            (new PropertyCatalog($this->db))->lock($supplierId, $at);
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare("SELECT payload_json FROM property_audit WHERE supplier_id = ? AND action = 'import' ORDER BY id FOR UPDATE");
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $old = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if ($old['idempotency_key'] !== $token) continue;
                if (!hash_equals($old['request_hash'], $requestHash)) throw new PropertyException('property_idempotency_conflict', 'Identifikátor požadavku již patří jinému importu.', 409);
                return $old['result'];
            }
            $preview = $this->previewPlan($supplierId, $input['plan'], true);
            if (!hash_equals($preview['preview_hash'], $input['preview_hash'])) throw new PropertyException('property_preview_stale', 'Podklady registru se od náhledu změnily. Vytvořte nový náhled.', 409);
            $plan = $preview['plan'];
            $repo = new PropertyRegistryRepository($this->db);
            $result = [];
            foreach ($plan['units'] as $unit) {
                $request = ['expected_revision' => $unit['expected_revision'], 'effective_from' => $plan['effective_from'], 'effective_to' => $plan['effective_to'], 'status' => $plan['status'],
                    'state' => $unit['state'], 'reason' => $plan['reason'], 'title_ref' => $plan['title_ref'], 'idempotency_key' => hash('sha256', $token . ':' . strtolower($unit['code']))];
                $result[] = $unit['object_id'] === null
                    ? $repo->create($supplierId, 'unit', $request + ['code' => $unit['code'], 'parent_id' => $plan['house_id']], $actor)
                    : $repo->revise($supplierId, $unit['object_id'], 'unit', $request, $actor);
            }
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $pdo->prepare("INSERT INTO property_audit (supplier_id, action, payload_json, created_by, recorded_at) VALUES (?, 'import', ?, ?, ?)")
                ->execute([$supplierId, json_encode(['idempotency_key' => $token, 'request_hash' => $requestHash, 'request' => $input, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $actor, $now]);
            return $result;
        });
    }
}
