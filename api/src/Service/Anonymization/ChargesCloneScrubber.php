<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;

final class ChargesCloneScrubber
{
    private const TABLES = ['unit_charge_components', 'unit_charge_versions', 'unit_charge_rates', 'unit_charge_audit'];

    public function __construct(private readonly PDO $pdo, private readonly TextScrubber $scrubber) {}

    public function run(array $registryStateMap): array
    {
        $marks = implode(',', array_fill(0, count(self::TABLES), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$marks})");
        $stmt->execute(self::TABLES);
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) return [];
        if ($count !== count(self::TABLES) || $this->pdo->inTransaction()) throw new \LogicException('Charge clone requires the complete schema and its own transaction.');
        if (TriggerMetadata::any($this->pdo)) throw new \LogicException('Charge clone must run before installing triggers.');
        $this->pdo->beginTransaction();
        try {
            $components = []; $versions = [];
            foreach ($this->rows('unit_charge_components') as $row) {
                $request = self::decode($row['request_json']);
                self::hash($row['request_hash'], $request + ['actor' => (int) $row['created_by']]);
                foreach (['code', 'name', 'kind', 'account_code', 'idempotency_key'] as $field) self::same($request[$field], $row[$field]);
                self::same($request['service_id'], $row['service_id'] === null ? null : (int) $row['service_id']);
                $old = $row;
                foreach (['id', 'supplier_id', 'service_id', 'created_by'] as $field) if ($old[$field] !== null) $old[$field] = (int) $old[$field];
                $old['effective_at'] = $request['effective_at'];
                unset($old['idempotency_key'], $old['request_hash'], $old['request_json']);
                $request['name'] = $this->scrubber->scrub($request['name']);
                $new = $old; $new['name'] = $request['name'];
                $hash = PropertyInput::hash($request + ['actor' => (int) $row['created_by']]);
                $components[(int) $row['supplier_id']][(int) $row['id']] = ['before' => $old, 'after' => $new, 'old_hash' => $row['request_hash'], 'new_hash' => $hash];
                $this->update('unit_charge_components', $row, ['name' => $request['name'], 'request_json' => self::json($request), 'request_hash' => $hash]);
            }
            foreach ($this->rows('unit_charge_versions') as $row) {
                $supplier = (int) $row['supplier_id'];
                $request = self::decode($row['request_json']); $preview = self::decode($row['preview_json']);
                self::hash($row['preview_hash'], $preview);
                self::hash($row['request_hash'], self::envelope($row, $request, $row['preview_hash']));
                self::same($request, $preview['request']);
                self::same($request['house_id'], (int) $row['house_id']);
                self::same($request['expected_revision'] + 1, (int) $row['revision_no']);
                self::same(PropertyInstant::sql($request['effective_from']), $row['effective_from']);
                self::same($request['effective_to'] === null ? null : PropertyInstant::sql($request['effective_to']), $row['effective_to']);
                $materialized = $this->rates($row);
                self::same(count($materialized), count($preview['calculation']['rates']));
                foreach ($preview['calculation']['rates'] as $i => &$entry) {
                    $id = $entry['component']['id'];
                    $component = $components[$supplier][$id] ?? throw new \RuntimeException('Charge component snapshot is missing.');
                    self::same($entry['component'], $component['before']);
                    self::same($entry['rate'], $request['rates'][$i]);
                    $stored = $materialized[$id] ?? throw new \RuntimeException('Charge materialization is missing.');
                    self::same($entry['rate'], self::decode($stored['rate_json']));
                    self::same($entry['calculation'], self::decode($stored['calculation_json']));
                    $entry['component'] = $component['after'];
                    $entry['rate'] = $this->rate($entry['rate']);
                    $this->update('unit_charge_rates', $stored, ['rate_json' => self::json($entry['rate'])]);
                }
                unset($entry);
                $this->snapshot($preview['registry']['house'], $supplier, $registryStateMap);
                foreach (['units', 'services'] as $field) foreach ($preview['registry'][$field] as &$node) $this->snapshot($node, $supplier, $registryStateMap);
                unset($node);
                if ($preview['previous_version_id'] !== null) {
                    $previous = $versions[$supplier][$preview['previous_version_id']] ?? throw new \RuntimeException('Charge previous version proof is missing.');
                    self::same($preview['previous_preview_hash'], $previous['old_preview_hash']);
                    $preview['previous_preview_hash'] = $previous['new_preview_hash'];
                } else self::same($preview['previous_preview_hash'], null);
                foreach ($preview['previous_units'] as &$unit) $unit['unit_no'] = $this->scrubber->scrub($unit['unit_no']);
                unset($unit);
                foreach ($preview['overlapping_versions'] as &$overlap) $overlap['decision_ref'] = $this->scrubber->scrub($overlap['decision_ref']);
                unset($overlap);
                foreach (['decision_ref', 'reason'] as $field) $request[$field] = $this->scrubber->scrub($request[$field]);
                $request['rates'] = array_map($this->rate(...), $request['rates']);
                $preview['request'] = $request;
                $previewHash = PropertyInput::hash($preview);
                $requestHash = PropertyInput::hash(self::envelope($row, $request, $previewHash));
                $versions[$supplier][(int) $row['id']] = ['old_preview_hash' => $row['preview_hash'], 'new_preview_hash' => $previewHash, 'old_hash' => $row['request_hash'], 'new_hash' => $requestHash];
                $this->update('unit_charge_versions', $row, ['request_json' => self::json($request), 'preview_json' => self::json($preview), 'preview_hash' => $previewHash, 'request_hash' => $requestHash]);
            }
            foreach ($this->rows('unit_charge_audit') as $row) {
                $map = match ($row['action']) {
                    'component' => $components[(int) $row['supplier_id']][(int) $row['component_id']] ?? null,
                    'version' => $versions[(int) $row['supplier_id']][(int) $row['version_id']] ?? null,
                    default => null,
                };
                if ($map === null) throw new \RuntimeException('Charge audit has no verified owner.');
                $payload = self::decode($row['payload_json']);
                $expected = ['request_hash' => $map['old_hash']]; $replacement = ['request_hash' => $map['new_hash']];
                if ($row['action'] === 'version') {
                    $expected['preview_hash'] = $map['old_preview_hash']; $replacement['preview_hash'] = $map['new_preview_hash'];
                }
                self::same($payload, $expected);
                $this->update('unit_charge_audit', $row, ['payload_json' => self::json($replacement)]);
            }
            $this->pdo->commit();
            return $versions;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function snapshot(array &$node, int $supplier, array $map): void
    {
        $proof = $map[$supplier][$node['revision_id']] ?? throw new \RuntimeException('Charge registry revision proof is missing.');
        self::same($node['state_hash'], $proof['before_state_hash']);
        $node['state_hash'] = $proof['after_state_hash'];
        foreach (['name', 'unit_no'] as $field) if (isset($node[$field])) $node[$field] = $this->scrubber->scrub($node[$field]);
    }
    private function rate(array $rate): array
    {
        foreach (['scope_ref', 'correction_reason'] as $field) $rate[$field] = $this->scrubber->scrub($rate[$field]);
        return $rate;
    }
    private function rates(array $version): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM unit_charge_rates WHERE supplier_id=? AND version_id=? ORDER BY id');
        $stmt->execute([$version['supplier_id'], $version['id']]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'component_id');
    }
    private static function envelope(array $row, array $request, string $previewHash): array
    {
        return ['request' => $request, 'preview_hash' => $previewHash, 'idempotency_key' => $row['idempotency_key'], 'actor' => (int) $row['created_by']];
    }
    private static function same(mixed $expected, mixed $actual): void
    {
        if (PropertyInput::hash(['value' => $expected]) !== PropertyInput::hash(['value' => $actual])) throw new \RuntimeException('Charge clone materialization mismatch.');
    }
    private static function hash(string $expected, array $payload): void
    {
        if (!hash_equals($expected, PropertyInput::hash($payload))) throw new \RuntimeException('Charge clone canonical hash mismatch.');
    }
    private function rows(string $table): array { return $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
    private function update(string $table, array $row, array $changes): void
    {
        $sets = implode(',', array_map(static fn(string $field): string => $field . '=?', array_keys($changes)));
        $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE supplier_id=? AND id=?')->execute([...array_values($changes), $row['supplier_id'], $row['id']]);
    }
    private static function decode(string $value): array { return json_decode($value, true, flags: JSON_THROW_ON_ERROR); }
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }
}
