<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzTargetCatalog;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollComponentJmhzMappingRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentJmhzTargetCatalog $targets,
        private readonly JmhzSpecPackageRepository $specPackages,
    ) {}

    /** @return array<string, mixed>|null */
    public function find(int $supplierId, int $componentId): ?array
    {
        $current = $this->findCurrent($supplierId, $componentId);
        if ($current !== null && PayrollTimeValue::bool($current['is_active'] ?? null, 'is_active')) {
            return $current;
        }

        return $this->findActiveLegacy($supplierId, $componentId) ?? $current;
    }

    /** @return array<string, mixed>|null */
    private function findCurrent(int $supplierId, int $componentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT mapping.id, mapping.supplier_id, mapping.component_definition_id,
                    mapping.spec_package_id, package.package_key,
                    package.manifest_sha256 AS spec_manifest_sha256,
                    mapping.target_attribute_id, attribute.name AS target_attribute_name,
                    attribute.xsd_mapping AS target_xsd_mapping,
                    mapping.is_active, mapping.disabled_at,
                    mapping.row_version, mapping.created_at, mapping.updated_at
               FROM payroll_component_jmhz_mappings mapping
               JOIN payroll_jmhz_spec_packages package
                 ON package.id = mapping.spec_package_id
               JOIN payroll_jmhz_dictionary_attributes attribute
                 ON attribute.package_id = mapping.spec_package_id
                AND attribute.attribute_id = mapping.target_attribute_id
              WHERE mapping.supplier_id = ? AND mapping.component_definition_id = ?
                AND package.package_key = ? AND package.manifest_sha256 = ?',
        );
        $stmt->execute([
            $supplierId,
            $componentId,
            PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
            PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }
        return $this->enrich(self::cast($row), true);
    }

    /** @return array<string, mixed>|null */
    private function findActiveLegacy(int $supplierId, int $componentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT mapping.id, mapping.supplier_id, mapping.component_definition_id,
                    mapping.spec_package_id, package.package_key,
                    package.manifest_sha256 AS spec_manifest_sha256,
                    mapping.target_attribute_id, attribute.name AS target_attribute_name,
                    attribute.xsd_mapping AS target_xsd_mapping,
                    mapping.is_active, mapping.disabled_at,
                    mapping.row_version, mapping.created_at, mapping.updated_at
               FROM payroll_component_jmhz_mappings mapping
               JOIN payroll_jmhz_spec_packages package ON package.id = mapping.spec_package_id
               JOIN payroll_jmhz_dictionary_attributes attribute
                 ON attribute.package_id = mapping.spec_package_id
                AND attribute.attribute_id = mapping.target_attribute_id
              WHERE mapping.supplier_id = ? AND mapping.component_definition_id = ?
                AND mapping.is_active = 1
                AND NOT (package.package_key = ? AND package.manifest_sha256 = ?)
              ORDER BY package.id DESC
              LIMIT 1',
        );
        $stmt->execute([
            $supplierId,
            $componentId,
            PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
            PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->enrich(self::cast($row), false) : null;
    }

    /**
     * Id balíku specifikace, ze kterého aplikace zařazení čte.
     *
     * Chybí-li, nainstaluje se — přesně to dělá i první zápis zařazení
     * ({@see self::put()}). Bez toho by zakládání výchozího zařazení na
     * instalaci, kde ještě nikdo nic nezařadil (nebo po přechodu na nový balík),
     * tiše nevložilo nic a kontrola před během by dál hlásila „nemá zařazení".
     * V ustáleném stavu stojí jeden dotaz.
     */
    public function currentPackageId(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_jmhz_spec_packages
              WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([
            PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
            PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
        ]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return PayrollTimeValue::int($id, 'package_id');
        }

        return $this->specPackages->install($this->targets->specManifest());
    }

    /**
     * Převezme zařazení složek ze staršího balíku specifikace do aktuálního —
     * JEDINÉ místo tohoto pravidla v aplikaci; migrace 1840 je jeho zmrazená
     * kopie pro existující instalace (shodu hlídá
     * `PayrollComponentJmhzLegacyPackageAdoptionTest`).
     *
     * Snímek hlášení čte zařazení jen z aktuálního balíku ({@see self::snapshot()}),
     * kdežto obrazovka zařazení ukazuje i aktivní zařazení ze staršího balíku
     * ({@see self::find()}). Po přechodu na nový balík tak složka vypadala
     * zařazená, příprava hlášení ji blokovala jako nezařazenou a výchozí
     * zařazení se nedoplnilo, protože složka nějaký záznam už měla.
     *
     * Pravidlo:
     *  - bere se rozhodnutí, které ukazuje {@see self::find()}: aktivní zařazení
     *    z nejnovějšího staršího balíku, jinak nejnovější deaktivované;
     *  - převede se jen tam, kde v aktuálním balíku ještě žádný záznam není
     *    a kde aktuální balík cílový atribut zná. Cíl, který balík nezná,
     *    zůstane ve starším balíku aktivní a složka se dál hlásí jako
     *    nezařazená — tiše zahodit volbu účetní by bylo horší;
     *  - deaktivované zařazení se převede jako deaktivované: vědomé
     *    „nezařazovat" platí dál a výchozí zařazení ho nepřepíše;
     *  - autor zůstává: volba účetní zůstává volbou účetní, předvyplnění
     *    aplikací (`created_by` NULL) předvyplněním;
     *  - aktivní starší zařazení se deaktivuje všude, kde už aktuální balík
     *    záznam má. Jinak by blokovalo zápis zařazení („nejprve deaktivujte
     *    mapování ze staršího balíku") i změnu zacházení složky (trigger
     *    z migrace 1344 hlídá aktivní zařazení v jakémkoli balíku).
     *
     * Opakované volání nic nemění. V ustáleném stavu stojí dva příkazy.
     *
     * @param int|null $packageId balík, do kterého se převádí; `null` = ten, který aplikace čte
     * @return int počet převzatých zařazení
     */
    public function adoptLegacy(int $supplierId, ?int $packageId = null): int
    {
        $packageId ??= $this->currentPackageId();
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $adopt = $pdo->prepare(
                'INSERT INTO payroll_component_jmhz_mappings
                    (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
                     is_active, disabled_at, created_by, updated_by)
                 SELECT legacy.supplier_id, legacy.component_definition_id, attribute.package_id,
                        attribute.attribute_id, legacy.is_active, legacy.disabled_at,
                        legacy.created_by, legacy.updated_by
                   FROM (
                         SELECT mapping.supplier_id, mapping.component_definition_id,
                                mapping.target_attribute_id, mapping.is_active, mapping.disabled_at,
                                mapping.created_by, mapping.updated_by,
                                ROW_NUMBER() OVER (
                                  PARTITION BY mapping.supplier_id, mapping.component_definition_id
                                  ORDER BY mapping.is_active DESC, mapping.spec_package_id DESC
                                ) AS pick
                           FROM payroll_component_jmhz_mappings mapping
                          WHERE mapping.supplier_id = ? AND mapping.spec_package_id <> ?
                        ) legacy
                   JOIN payroll_component_definitions definition
                     ON definition.supplier_id = legacy.supplier_id
                    AND definition.id = legacy.component_definition_id
                    AND definition.jmhz_treatment = \'included\'
                   JOIN payroll_jmhz_dictionary_attributes attribute
                     ON attribute.package_id = ?
                    AND attribute.attribute_id = legacy.target_attribute_id
                  WHERE legacy.pick = 1
                    AND NOT EXISTS (
                          SELECT 1
                            FROM payroll_component_jmhz_mappings existing
                           WHERE existing.supplier_id = legacy.supplier_id
                             AND existing.component_definition_id = legacy.component_definition_id
                             AND existing.spec_package_id = ?
                        )',
            );
            $adopt->execute([$supplierId, $packageId, $packageId, $packageId]);
            $adopted = $adopt->rowCount();
            $pdo->prepare(
                'UPDATE payroll_component_jmhz_mappings legacy
                   JOIN payroll_component_jmhz_mappings current_mapping
                     ON current_mapping.supplier_id = legacy.supplier_id
                    AND current_mapping.component_definition_id = legacy.component_definition_id
                    AND current_mapping.spec_package_id = ?
                    SET legacy.is_active = 0, legacy.disabled_at = CURRENT_TIMESTAMP,
                        legacy.updated_by = NULL, legacy.row_version = legacy.row_version + 1
                  WHERE legacy.supplier_id = ? AND legacy.spec_package_id <> ?
                    AND legacy.is_active = 1',
            )->execute([$packageId, $supplierId, $packageId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $adopted;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT mapping.id, mapping.supplier_id, mapping.component_definition_id,
                    mapping.spec_package_id, package.package_key,
                    package.manifest_sha256 AS spec_manifest_sha256,
                    mapping.target_attribute_id, attribute.name AS target_attribute_name,
                    attribute.xsd_mapping AS target_xsd_mapping,
                    mapping.is_active, mapping.disabled_at,
                    mapping.row_version, mapping.created_at, mapping.updated_at
               FROM payroll_component_jmhz_mappings mapping
               JOIN payroll_jmhz_spec_packages package ON package.id = mapping.spec_package_id
               JOIN payroll_jmhz_dictionary_attributes attribute
                 ON attribute.package_id = mapping.spec_package_id
                AND attribute.attribute_id = mapping.target_attribute_id
              WHERE mapping.supplier_id = ?
                AND (
                  mapping.is_active = 1
                  OR (package.package_key = ? AND package.manifest_sha256 = ?)
                )
              ORDER BY mapping.component_definition_id, package.id DESC',
        );
        $stmt->execute([
            $supplierId,
            PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
            PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
        ]);
        $indexed = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'jmhz_mappings') as $row) {
            $isCurrent = hash_equals(
                PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
                PayrollTimeValue::string($row['package_key'] ?? null, 'package_key'),
            ) && hash_equals(
                PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
                PayrollTimeValue::string($row['spec_manifest_sha256'] ?? null, 'spec_manifest_sha256'),
            );
            $mapping = $this->enrich(self::cast($row), $isCurrent);
            $componentId = PayrollTimeValue::int(
                $mapping['component_definition_id'] ?? null,
                'component_definition_id',
            );
            $existing = $indexed[$componentId] ?? null;
            if (!is_array($existing) || $this->displayPriority($mapping) > $this->displayPriority($existing)) {
                $indexed[$componentId] = $mapping;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string,mixed> $mapping
     * @return array<string,mixed>
     */
    private function enrich(array $mapping, bool $isCurrentPackage): array
    {
        if (!$isCurrentPackage) {
            $mapping['parent_attribute_id'] = null;
            $mapping['ancestor_attribute_ids'] = [];
            $mapping['aggregation_role'] = null;
            $mapping['aggregation_scope'] = null;
            $mapping['topology_hash'] = null;
            $mapping['is_current_package'] = false;

            return $mapping;
        }
        $target = $this->targets->requireTarget(
            PayrollTimeValue::string($mapping['target_attribute_id'] ?? null, 'target_attribute_id'),
        );
        $mapping['parent_attribute_id'] = $target['parent_attribute_id'];
        $mapping['ancestor_attribute_ids'] = $target['ancestor_attribute_ids'];
        $mapping['aggregation_role'] = $target['aggregation_role'];
        $mapping['aggregation_scope'] = $target['aggregation_scope'];
        $mapping['topology_hash'] = $this->targets->topologyHash();
        $mapping['is_current_package'] = $isCurrentPackage;

        return $mapping;
    }

    /** @return array<string, mixed> */
    public function put(
        int $supplierId,
        int $componentId,
        string $targetAttributeId,
        ?int $expectedVersion,
        ?int $userId,
    ): array {
        $target = $this->targets->requireTarget($targetAttributeId);
        $packageId = $this->specPackages->install($this->targets->specManifest());
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $component = $pdo->prepare(
                'SELECT id, jmhz_treatment
                   FROM payroll_component_definitions
                  WHERE supplier_id = ? AND id = ? FOR UPDATE',
            );
            $component->execute([$supplierId, $componentId]);
            $componentRow = $component->fetch(PDO::FETCH_ASSOC);
            if (!is_array($componentRow)) {
                throw new \OutOfBoundsException('Mzdová složka nebyla nalezena.');
            }
            if (($componentRow['jmhz_treatment'] ?? null) !== 'included') {
                throw new \DomainException('Mapovat lze jen mzdovou složku zahrnutou do JMHZ.');
            }
            $existing = $pdo->prepare(
                'SELECT id, target_attribute_id, is_active, row_version
                   FROM payroll_component_jmhz_mappings
                  WHERE supplier_id = ? AND component_definition_id = ?
                    AND spec_package_id = ? FOR UPDATE',
            );
            $existing->execute([$supplierId, $componentId, $packageId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            $legacy = $pdo->prepare(
                'SELECT id FROM payroll_component_jmhz_mappings
                  WHERE supplier_id = ? AND component_definition_id = ?
                    AND spec_package_id <> ? AND is_active = 1 FOR UPDATE',
            );
            $legacy->execute([$supplierId, $componentId, $packageId]);
            if ($legacy->fetchColumn() !== false) {
                throw new \DomainException(
                    'Nejprve deaktivujte mapování ze staršího balíku specifikace JMHZ.',
                );
            }
            if (is_array($row)) {
                $currentVersion = PayrollTimeValue::int($row['row_version'] ?? null, 'row_version');
                if (PayrollTimeValue::bool($row['is_active'] ?? null, 'is_active')
                    && hash_equals(
                        PayrollTimeValue::string(
                            $row['target_attribute_id'] ?? null,
                            'target_attribute_id',
                        ),
                        $target['attribute_id'],
                    )
                ) {
                    // Účetní uložila tentýž cíl, který předvyplnila aplikace:
                    // obsah se nemění, ale zařazení je od teď její rozhodnutí.
                    // Bez toho by upozornění na neověřené výchozí zařazení
                    // ({@see \MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults::unverifiedBonusDefaults()})
                    // nešlo potvrzením odstranit.
                    if ($userId !== null) {
                        $pdo->prepare(
                            'UPDATE payroll_component_jmhz_mappings
                                SET updated_by = ?
                              WHERE supplier_id = ? AND id = ?
                                AND created_by IS NULL AND updated_by IS NULL',
                        )->execute([
                            $userId,
                            $supplierId,
                            PayrollTimeValue::int($row['id'] ?? null, 'id'),
                        ]);
                    }
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }

                    return $this->findCurrent($supplierId, $componentId)
                        ?? throw new \RuntimeException('Mapování JMHZ se nepodařilo načíst.');
                }
                if ($expectedVersion === null || $expectedVersion !== $currentVersion) {
                    throw new PayrollComponentJmhzMappingConflictException($currentVersion);
                }
                $update = $pdo->prepare(
                    'UPDATE payroll_component_jmhz_mappings
                        SET target_attribute_id = ?, is_active = 1, disabled_at = NULL,
                            updated_by = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ? AND row_version = ?',
                );
                $update->execute([
                    $target['attribute_id'],
                    $userId,
                    $supplierId,
                    PayrollTimeValue::int($row['id'] ?? null, 'id'),
                    $currentVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollComponentJmhzMappingConflictException($currentVersion);
                }
            } else {
                if ($expectedVersion !== null) {
                    throw new PayrollComponentJmhzMappingConflictException(0);
                }
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_component_jmhz_mappings
                        (supplier_id, component_definition_id, spec_package_id,
                         target_attribute_id, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?)',
                );
                $insert->execute([
                    $supplierId,
                    $componentId,
                    $packageId,
                    $target['attribute_id'],
                    $userId,
                    $userId,
                ]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->findCurrent($supplierId, $componentId)
            ?? throw new \RuntimeException('Mapování JMHZ se nepodařilo načíst.');
    }

    /** @return array<string,mixed>|null */
    public function remove(
        int $supplierId,
        int $componentId,
        int $expectedVersion,
        ?int $userId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $component = $pdo->prepare(
                'SELECT id FROM payroll_component_definitions
                  WHERE supplier_id = ? AND id = ? FOR UPDATE',
            );
            $component->execute([$supplierId, $componentId]);
            if ($component->fetchColumn() === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return null;
            }
            $mapping = $this->find($supplierId, $componentId);
            if ($mapping === null || !PayrollTimeValue::bool(
                $mapping['is_active'] ?? null,
                'is_active',
            )) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return $mapping;
            }
            $currentVersion = PayrollTimeValue::int(
                $mapping['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollComponentJmhzMappingConflictException($currentVersion);
            }
            $disable = $pdo->prepare(
                'UPDATE payroll_component_jmhz_mappings
                    SET is_active = 0, disabled_at = CURRENT_TIMESTAMP,
                        updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ? AND is_active = 1',
            );
            $disable->execute([
                $userId,
                $supplierId,
                PayrollTimeValue::int($mapping['id'] ?? null, 'id'),
                $currentVersion,
            ]);
            if ($disable->rowCount() !== 1) {
                throw new PayrollComponentJmhzMappingConflictException($currentVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->findById(
            $supplierId,
            PayrollTimeValue::int($mapping['id'] ?? null, 'mapping_id'),
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(int $supplierId, int $componentId): array
    {
        $mapping = $this->findCurrent($supplierId, $componentId)
            ?? throw new \DomainException('Mzdová složka zahrnutá do JMHZ nemá cílový atribut.');
        if (!PayrollTimeValue::bool($mapping['is_active'] ?? null, 'is_active')) {
            throw new \DomainException('Mapování mzdové složky do JMHZ není aktivní.');
        }

        return [
            'supplier_id' => $mapping['supplier_id'],
            'component_definition_id' => $mapping['component_definition_id'],
            'mapping_id' => $mapping['id'],
            'mapping_row_version' => $mapping['row_version'],
            'package_key' => $mapping['package_key'],
            'spec_manifest_sha256' => $mapping['spec_manifest_sha256'],
            'target_attribute_id' => $mapping['target_attribute_id'],
            'target_attribute_name' => $mapping['target_attribute_name'],
            'target_xsd_mapping' => $mapping['target_xsd_mapping'],
            'parent_attribute_id' => $mapping['parent_attribute_id'],
            'ancestor_attribute_ids' => $mapping['ancestor_attribute_ids'],
            'aggregation_role' => $mapping['aggregation_role'],
            'aggregation_scope' => $mapping['aggregation_scope'],
            'topology_hash' => $mapping['topology_hash'],
            'mapping_hash' => hash('sha256', CanonicalJson::encode([
                'supplier_id' => $mapping['supplier_id'],
                'component_definition_id' => $mapping['component_definition_id'],
                'mapping_id' => $mapping['id'],
                'mapping_row_version' => $mapping['row_version'],
                'package_key' => $mapping['package_key'],
                'spec_manifest_sha256' => $mapping['spec_manifest_sha256'],
                'target_attribute_id' => $mapping['target_attribute_id'],
                'target_xsd_mapping' => $mapping['target_xsd_mapping'],
                'parent_attribute_id' => $mapping['parent_attribute_id'],
                'ancestor_attribute_ids' => $mapping['ancestor_attribute_ids'],
                'aggregation_role' => $mapping['aggregation_role'],
                'aggregation_scope' => $mapping['aggregation_scope'],
                'topology_hash' => $mapping['topology_hash'],
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'component_definition_id', 'spec_package_id', 'row_version'] as $key) {
            $row[$key] = PayrollTimeValue::int($row[$key] ?? null, $key);
        }
        $row['is_active'] = PayrollTimeValue::bool($row['is_active'] ?? null, 'is_active');

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findById(int $supplierId, int $mappingId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT mapping.id, mapping.supplier_id, mapping.component_definition_id,
                    mapping.spec_package_id, package.package_key,
                    package.manifest_sha256 AS spec_manifest_sha256,
                    mapping.target_attribute_id, attribute.name AS target_attribute_name,
                    attribute.xsd_mapping AS target_xsd_mapping,
                    mapping.is_active, mapping.disabled_at,
                    mapping.row_version, mapping.created_at, mapping.updated_at
               FROM payroll_component_jmhz_mappings mapping
               JOIN payroll_jmhz_spec_packages package ON package.id = mapping.spec_package_id
               JOIN payroll_jmhz_dictionary_attributes attribute
                 ON attribute.package_id = mapping.spec_package_id
                AND attribute.attribute_id = mapping.target_attribute_id
              WHERE mapping.supplier_id = ? AND mapping.id = ?',
        );
        $stmt->execute([$supplierId, $mappingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $isCurrent = hash_equals(
            PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
            PayrollTimeValue::string($row['package_key'] ?? null, 'package_key'),
        ) && hash_equals(
            PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
            PayrollTimeValue::string($row['spec_manifest_sha256'] ?? null, 'spec_manifest_sha256'),
        );

        return $this->enrich(self::cast($row), $isCurrent);
    }

    /** @param array<string,mixed> $mapping */
    private function displayPriority(array $mapping): int
    {
        $active = PayrollTimeValue::bool($mapping['is_active'] ?? null, 'is_active');
        $current = PayrollTimeValue::bool(
            $mapping['is_current_package'] ?? null,
            'is_current_package',
        );

        return match (true) {
            $active && $current => 3,
            $active => 2,
            $current => 1,
            default => 0,
        };
    }
}
