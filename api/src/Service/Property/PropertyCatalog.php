<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionGuard;
use PDO;

final class PropertyCatalog
{
    public function __construct(private readonly Connection $db) {}

    public function lockContext(int $supplierId): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $this->lockAccountingContext($supplierId);
    }

    public function lockAccountingContext(int $supplierId): void
    {
        (new AccountingFrameworkRepository($this->db))->listForTenantForUpdate($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');
        $stmt->execute([$supplierId]);
        if (!$stmt->fetchColumn()) throw new PropertyException('property_not_found', 'Firma nebyla nalezena.', 404);
    }

    public function lock(int $supplierId, string $at): array
    {
        $this->lockContext($supplierId);
        $date = (new \DateTimeImmutable(PropertyInstant::normalize($at)))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        if ($framework->forDateForUpdate($supplierId, $date) !== AccountingFrameworkService::CZ_504) {
            throw new PropertyException('property_framework_required', 'Registr SVJ vyžaduje účetní rámec podle vyhlášky 504 k datu účinnosti.', 409);
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        if (!$stmt->fetchColumn()) throw new PropertyException('property_not_found', 'Firma nebyla nalezena.', 404);
        $stmt = $pdo->prepare('SELECT * FROM dimension_types WHERE supplier_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT id FROM dimension_values WHERE supplier_id = ? ORDER BY type_id, id FOR UPDATE');
        $stmt->execute([$supplierId]);
        $stmt->fetchAll();
        return $types;
    }

    public function bindLocked(int $supplierId, string $kind, int $objectId, string $code, array $lockedTypes, int $actor): int
    {
        if (!$this->db->pdo()->inTransaction() || !in_array($kind, ['unit', 'service'], true)) throw new \LogicException('Property binding requires the registry transaction.');
        $typeId = null;
        foreach ($lockedTypes as $type) {
            if ($type['system_key'] === $kind) $typeId = (int) $type['id'];
            elseif (strcasecmp($type['code'], $kind) === 0) throw new PropertyException('property_dimension_conflict', 'Kód dimenze již používá jiný číselník.', 409);
        }
        $pdo = $this->db->pdo();
        $guard = new SystemDimensionGuard($this->db);
        if ($typeId === null) {
            $name = $kind === 'unit' ? 'Jednotka' : 'Služba SVJ';
            $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, system_key) VALUES (?, ?, ?, 'custom', ?)")->execute([$supplierId, $kind, $name, $kind]);
            $typeId = (int) $pdo->lastInsertId();
            $guard->audit('type', $typeId, $supplierId, null, ['system_key' => $kind, 'name' => $name], $actor, 'property_registry');
        }
        $semantic = $kind . ':' . $objectId;
        $name = ($kind === 'unit' ? 'Jednotka ' : 'Služba ') . $code;
        $pdo->prepare('INSERT INTO dimension_values (supplier_id, type_id, code, name, semantic_key) VALUES (?, ?, ?, ?, ?)')->execute([$supplierId, $typeId, $semantic, $name, $semantic]);
        $valueId = (int) $pdo->lastInsertId();
        $guard->audit('value', $valueId, $supplierId, null, ['type_id' => $typeId, 'semantic_key' => $semantic, 'name' => $name], $actor, 'property_registry');
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        return $valueId;
    }
}
