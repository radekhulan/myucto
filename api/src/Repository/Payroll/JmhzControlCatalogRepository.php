<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use PDO;

final class JmhzControlCatalogRepository
{
    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('payroll_jmhz_control_catalogs');
    }

    /**
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $specManifest
     */
    public function install(array $manifest, array $specManifest): int
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Registr katalogu kontrol JMHZ není dostupný.');
        }
        JmhzControlSourceCatalog::validateManifest($manifest, $specManifest);
        $payload = $manifest['payload'];
        $packageId = $this->packageId(
            $this->requiredString($payload, 'spec_package_key'),
            $this->requiredString($payload, 'spec_manifest_sha256'),
        );
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $existing = $pdo->prepare(
                'SELECT id, manifest_sha256 FROM payroll_jmhz_control_catalogs
                  WHERE package_id = ? AND catalog_key = ? FOR UPDATE',
            );
            $existing->execute([$packageId, $this->requiredString($payload, 'catalog_key')]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string) $row['manifest_sha256'], $manifest['manifest_sha256'])) {
                    throw new \LogicException('Katalog kontrol JMHZ už existuje s jiným hashem.');
                }
                $catalogId = (int) $row['id'];
                $this->verifyStoredRows($catalogId, $payload);
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return $catalogId;
            }
            $source = $payload['source'] ?? null;
            $counts = $payload['counts'] ?? null;
            if (!is_array($source) || !is_array($counts)) {
                throw new \InvalidArgumentException('Katalog kontrol JMHZ nemá zdroj nebo počty.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO payroll_jmhz_control_catalogs
                    (package_id, catalog_key, version, source_filename, source_sha256,
                     manifest_json, manifest_sha256, control_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([
                $packageId,
                $this->requiredString($payload, 'catalog_key'),
                $this->requiredString($payload, 'version'),
                $this->requiredString($source, 'filename'),
                $this->requiredString($source, 'sha256'),
                CanonicalJson::encode($manifest),
                $manifest['manifest_sha256'],
                $this->requiredInt($counts, 'controls'),
            ]);
            $catalogId = (int) $pdo->lastInsertId();
            $definitions = $this->insertDefinitions(
                $catalogId,
                $packageId,
                $this->rows($payload, 'controls'),
            );
            $this->insertParameters(
                $catalogId,
                $packageId,
                $definitions,
                $this->rows($payload, 'parameters'),
            );
            $this->verifyStoredRows($catalogId, $payload);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $catalogId;
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>}|null */
    public function find(string $catalogKey, string $manifestHash): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, manifest_json, manifest_sha256 FROM payroll_jmhz_control_catalogs
              WHERE catalog_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([$catalogKey, $manifestHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $manifest = $this->decodedManifest($row['manifest_json']);
        if (!hash_equals($manifestHash, $manifest['manifest_sha256'])) {
            throw new \UnexpectedValueException('Uložený katalog kontrol JMHZ má neplatný hash.');
        }
        $spec = $this->storedSpecManifest($manifest['payload']);
        JmhzControlSourceCatalog::validateManifest($manifest, $spec);
        $this->verifyStoredRows((int) $row['id'], $manifest['payload']);

        return $manifest;
    }

    private function packageId(string $packageKey, string $manifestHash): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_jmhz_spec_packages WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([$packageKey, $manifestHash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('Rodičovský balík specifikace JMHZ není nainstalovaný.');
        }

        return (int) $id;
    }

    /**
     * @param list<array<string, mixed>> $controls
     * @return array<int, int>
     */
    private function insertDefinitions(int $catalogId, int $packageId, array $controls): array
    {
        $definitionStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_control_definitions
                (catalog_id, package_id, control_id, source_row, name, attribute_refs_raw,
                 symbolic_refs_json, area, rejection_scope, owner_name, portal_system,
                 portal_passability, remote_system, remote_passability, category,
                 detail_text, detail_formula, error_message, error_message_formula,
                 source_label, note, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $refStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_control_attribute_refs
                (catalog_id, package_id, definition_id, control_id, attribute_id, ordinal, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $ids = [];
        foreach ($controls as $row) {
            $controlId = $this->requiredInt($row, 'control_id');
            $symbolic = $row['symbolic_attribute_refs'] ?? null;
            if (!is_array($symbolic)) {
                throw new \InvalidArgumentException('Kontrola JMHZ nemá symbolické odkazy.');
            }
            $definitionStatement->execute([
                $catalogId,
                $packageId,
                $controlId,
                $this->requiredInt($row, 'source_row'),
                $this->requiredString($row, 'name'),
                $this->nullableString($row, 'attribute_refs_raw'),
                CanonicalJson::encode($symbolic),
                $this->nullableString($row, 'area'),
                $this->requiredString($row, 'scope'),
                $this->requiredString($row, 'owner'),
                $this->requiredString($row, 'portal_system'),
                $this->requiredString($row, 'portal_passability'),
                $this->requiredString($row, 'remote_system'),
                $this->requiredString($row, 'remote_passability'),
                $this->nullableString($row, 'category'),
                $this->requiredString($row, 'detail_text'),
                $this->nullableString($row, 'detail_formula'),
                $this->requiredString($row, 'error_message'),
                $this->nullableString($row, 'error_message_formula'),
                $this->requiredString($row, 'source_label'),
                $this->nullableString($row, 'note'),
                $this->requiredString($row, 'row_hash'),
            ]);
            $definitionId = (int) $this->db->pdo()->lastInsertId();
            $ids[$controlId] = $definitionId;
            foreach ($this->rows($row, 'attribute_refs') as $ref) {
                $refStatement->execute([
                    $catalogId,
                    $packageId,
                    $definitionId,
                    $controlId,
                    $this->requiredString($ref, 'attribute_id'),
                    $this->requiredInt($ref, 'ordinal'),
                    $this->requiredString($ref, 'row_hash'),
                ]);
            }
        }

        return $ids;
    }

    /**
     * @param array<int, int> $definitionIds
     * @param list<array<string, mixed>> $parameters
     */
    private function insertParameters(
        int $catalogId,
        int $packageId,
        array $definitionIds,
        array $parameters,
    ): void {
        $parameterStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_control_parameters
                (catalog_id, package_id, parameter_key, source_row, name,
                 control_refs_raw, control_refs_formatted, control_refs_anomaly, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $refStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_control_parameter_refs
                (catalog_id, package_id, parameter_id, control_id, definition_id,
                 ordinal, resolution, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $valueStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_control_parameter_values
                (catalog_id, package_id, parameter_id, source_cell, effective_from,
                 raw_type, raw_value, normalized_value, canonical_value, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($parameters as $row) {
            $parameterStatement->execute([
                $catalogId,
                $packageId,
                $this->requiredString($row, 'parameter_key'),
                $this->requiredInt($row, 'source_row'),
                $this->requiredString($row, 'name'),
                $this->requiredString($row, 'control_refs_raw'),
                $this->requiredString($row, 'control_refs_formatted'),
                $this->nullableString($row, 'control_refs_anomaly'),
                $this->requiredString($row, 'row_hash'),
            ]);
            $parameterId = (int) $this->db->pdo()->lastInsertId();
            foreach ($this->rows($row, 'control_refs') as $ref) {
                $controlId = $this->requiredInt($ref, 'control_id');
                $refStatement->execute([
                    $catalogId,
                    $packageId,
                    $parameterId,
                    $controlId,
                    $definitionIds[$controlId] ?? null,
                    $this->requiredInt($ref, 'ordinal'),
                    $this->requiredString($ref, 'resolution'),
                    $this->requiredString($ref, 'row_hash'),
                ]);
            }
            foreach ($this->rows($row, 'values') as $value) {
                $valueStatement->execute([
                    $catalogId,
                    $packageId,
                    $parameterId,
                    $this->requiredString($value, 'source_cell'),
                    $this->requiredString($value, 'effective_from'),
                    $this->requiredString($value, 'raw_type'),
                    $this->requiredString($value, 'raw_value'),
                    $this->requiredString($value, 'normalized_value'),
                    $this->requiredString($value, 'canonical_value'),
                    $this->requiredString($value, 'row_hash'),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function verifyStoredRows(int $catalogId, array $payload): void
    {
        $refs = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT definition_id, attribute_id, ordinal, row_hash
               FROM payroll_jmhz_control_attribute_refs WHERE catalog_id = ?
              ORDER BY definition_id, ordinal',
        );
        $stmt->execute([$catalogId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $refs[(int) $row['definition_id']][] = [
                'attribute_id' => (string) $row['attribute_id'],
                'ordinal' => (int) $row['ordinal'],
                'row_hash' => (string) $row['row_hash'],
            ];
        }
        $controls = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_jmhz_control_definitions WHERE catalog_id = ? ORDER BY source_row',
        );
        $stmt->execute([$catalogId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $controls[] = [
                'control_id' => (int) $row['control_id'],
                'source_row' => (int) $row['source_row'],
                'name' => (string) $row['name'],
                'attribute_refs_raw' => self::nullable($row['attribute_refs_raw']),
                'symbolic_attribute_refs' => self::decodedList($row['symbolic_refs_json']),
                'area' => self::nullable($row['area']),
                'scope' => (string) $row['rejection_scope'],
                'owner' => (string) $row['owner_name'],
                'portal_system' => (string) $row['portal_system'],
                'portal_passability' => (string) $row['portal_passability'],
                'remote_system' => (string) $row['remote_system'],
                'remote_passability' => (string) $row['remote_passability'],
                'category' => self::nullable($row['category']),
                'detail_text' => (string) $row['detail_text'],
                'detail_formula' => self::nullable($row['detail_formula']),
                'error_message' => (string) $row['error_message'],
                'error_message_formula' => self::nullable($row['error_message_formula']),
                'source_label' => (string) $row['source_label'],
                'note' => self::nullable($row['note']),
                'attribute_refs' => $refs[(int) $row['id']] ?? [],
                'row_hash' => (string) $row['row_hash'],
            ];
        }

        $parameterRefs = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT parameter_id, control_id, ordinal, resolution, row_hash
               FROM payroll_jmhz_control_parameter_refs WHERE catalog_id = ?
              ORDER BY parameter_id, ordinal',
        );
        $stmt->execute([$catalogId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parameterRefs[(int) $row['parameter_id']][] = [
                'control_id' => (int) $row['control_id'],
                'ordinal' => (int) $row['ordinal'],
                'resolution' => (string) $row['resolution'],
                'row_hash' => (string) $row['row_hash'],
            ];
        }
        $parameterValues = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT parameter_id, source_cell, effective_from, raw_type, raw_value,
                    normalized_value, canonical_value, row_hash
               FROM payroll_jmhz_control_parameter_values WHERE catalog_id = ?
              ORDER BY parameter_id, effective_from',
        );
        $stmt->execute([$catalogId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parameterValues[(int) $row['parameter_id']][] = [
                'source_cell' => (string) $row['source_cell'],
                'effective_from' => (string) $row['effective_from'],
                'raw_type' => (string) $row['raw_type'],
                'raw_value' => (string) $row['raw_value'],
                'normalized_value' => (string) $row['normalized_value'],
                'canonical_value' => (string) $row['canonical_value'],
                'row_hash' => (string) $row['row_hash'],
            ];
        }
        $parameters = [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, parameter_key, source_row, name, control_refs_raw,
                    control_refs_formatted, control_refs_anomaly, row_hash
               FROM payroll_jmhz_control_parameters WHERE catalog_id = ? ORDER BY source_row',
        );
        $stmt->execute([$catalogId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parameters[] = [
                'parameter_key' => (string) $row['parameter_key'],
                'source_row' => (int) $row['source_row'],
                'name' => (string) $row['name'],
                'control_refs_raw' => (string) $row['control_refs_raw'],
                'control_refs_formatted' => (string) $row['control_refs_formatted'],
                'control_refs_anomaly' => self::nullable($row['control_refs_anomaly']),
                'control_refs' => $parameterRefs[(int) $row['id']] ?? [],
                'values' => $parameterValues[(int) $row['id']] ?? [],
                'row_hash' => (string) $row['row_hash'],
            ];
        }
        $expectedControls = $this->rows($payload, 'controls');
        foreach ($expectedControls as &$expectedControl) {
            unset($expectedControl['source_anomaly']);
        }
        unset($expectedControl);
        if (CanonicalJson::encode(['controls' => $controls, 'parameters' => $parameters])
            !== CanonicalJson::encode([
                'controls' => $expectedControls,
                'parameters' => $this->rows($payload, 'parameters'),
            ])
        ) {
            throw new \UnexpectedValueException('Uložený katalog kontrol JMHZ neodpovídá manifestu.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{manifest_sha256:string,payload:array<string, mixed>}
     */
    private function storedSpecManifest(array $payload): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT manifest_json FROM payroll_jmhz_spec_packages
              WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([
            $this->requiredString($payload, 'spec_package_key'),
            $this->requiredString($payload, 'spec_manifest_sha256'),
        ]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) {
            throw new \UnexpectedValueException('Rodičovský balík katalogu JMHZ chybí.');
        }

        return $this->decodedManifest($json);
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    private function decodedManifest(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Manifest JMHZ není text.');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest JMHZ má neplatnou strukturu.');
        }

        return ['manifest_sha256' => $decoded['manifest_sha256'], 'payload' => $decoded['payload']];
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @return list<string> */
    private static function decodedList(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('JSON seznam katalogu JMHZ není text.');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \UnexpectedValueException('JSON seznam katalogu JMHZ není seznam.');
        }
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('JSON seznam katalogu JMHZ neobsahuje text.');
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function rows(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Pole {$field} katalogu JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Pole {$field} katalogu JMHZ má neplatný řádek.");
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Pole {$field} katalogu JMHZ není text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} katalogu JMHZ není text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException("Pole {$field} katalogu JMHZ není kladné číslo.");
        }

        return $value;
    }
}
