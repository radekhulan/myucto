<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator;
use MyInvoice\Service\Accounting\Allocation\JointAllocationAudit;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use PDO;

final class JointAllocationKeyRepository
{
    public function __construct(private readonly Connection $db) {}

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT k.id AS key_id, k.code, k.name, v.* FROM joint_allocation_keys k JOIN joint_allocation_key_versions v ON v.key_id = k.id AND v.supplier_id = k.supplier_id WHERE k.supplier_id = ? ORDER BY k.code, v.version_no');
        $stmt->execute([$supplierId]);
        return array_map(static function (array $row): array {
            $row = JointAllocationKeyVersion::decodeCells($row);
            $row['cell_ids'] = array_map(JointAllocationCalculator::cellId(...), $row['cells']);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function create(int $supplierId, string $code, string $name, array $version, ?int $actor = null): array
    {
        $code = trim($code);
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,50}$/D', $code) || $name === '' || mb_strlen($name) > 190) {
            throw new JointAllocationException('allocation_invalid_key', 'Zadejte kód a název alokačního klíče.');
        }
        $version = JointAllocationKeyVersion::normalize($version);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $code, $name, $version, $actor): array {
            $this->lockAndValidate($supplierId, $version);
            $pdo = $this->db->pdo();
            $find = $pdo->prepare('SELECT id FROM joint_allocation_keys WHERE supplier_id = ? AND code = ? FOR UPDATE');
            $find->execute([$supplierId, $code]);
            if ($find->fetchColumn() !== false) {
                throw new JointAllocationException('allocation_duplicate_key', 'Kód alokačního klíče již existuje.', 409);
            }
            $pdo->prepare('INSERT INTO joint_allocation_keys (supplier_id, code, name, created_by) VALUES (?, ?, ?, ?)')->execute([$supplierId, $code, $name, $actor]);
            $keyId = (int) $pdo->lastInsertId();
            $this->audit($supplierId, 'key', $keyId, 'create', ['code' => $code, 'name' => $name], $actor);
            return $this->insertVersion($supplierId, $keyId, 1, $version, $actor);
        });
    }

    public function addVersion(int $supplierId, int $keyId, array $version, ?int $actor = null): array
    {
        $version = JointAllocationKeyVersion::normalize($version);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $keyId, $version, $actor): array {
            $this->lockAndValidate($supplierId, $version);
            $key = $this->db->pdo()->prepare('SELECT id FROM joint_allocation_keys WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $key->execute([$supplierId, $keyId]);
            if ($key->fetchColumn() === false) {
                throw new JointAllocationException('allocation_key_not_found', 'Alokační klíč nebyl nalezen.', 404);
            }
            $stmt = $this->db->pdo()->prepare('SELECT version_no, valid_from, valid_to FROM joint_allocation_key_versions WHERE supplier_id = ? AND key_id = ? ORDER BY version_no FOR UPDATE');
            $stmt->execute([$supplierId, $keyId]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            JointAllocationKeyVersion::assertNoOverlap($version, $versions);
            $number = $versions === [] ? 1 : max(array_column($versions, 'version_no')) + 1;
            return $this->insertVersion($supplierId, $keyId, $number, $version, $actor);
        });
    }

    public function findForUpdate(int $supplierId, int $versionId): array
    {
        try { (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) {
            if ($e->errorCode !== 'property_not_found') throw $e;
            throw new JointAllocationException('allocation_key_not_found', 'Verze alokačního klíče nebyla nalezena.', 404);
        }
        $stmt = $this->db->pdo()->prepare('SELECT v.*, k.code, k.name FROM joint_allocation_key_versions v
            JOIN joint_allocation_keys k ON k.id = v.key_id AND k.supplier_id = v.supplier_id
            WHERE v.supplier_id = ? AND v.id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new JointAllocationException('allocation_key_not_found', 'Verze alokačního klíče nebyla nalezena.', 404);
        }
        return JointAllocationKeyVersion::decodeCells($row);
    }

    public function validateCurrent(int $supplierId, array $version): array
    {
        return $this->lockAndValidate($supplierId, $version);
    }

    private function lockAndValidate(int $supplierId, array $version): array
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        $last = (new \DateTimeImmutable($version['valid_to']))->modify('-1 day')->format('Y-m-d');
        $frameworks = (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->forInterval($supplierId, $version['valid_from'], $last, true);
        if (array_unique(array_values($frameworks)) !== [AccountingFrameworkService::CZ_504]) {
            throw new JointAllocationException('allocation_framework_unsupported', 'Společné systémové alokace vyžadují rámec 504 po celou účinnost verze.');
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, system_key FROM dimension_types WHERE supplier_id = ? AND system_key IS NOT NULL ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId]);
        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array($row['system_key'], JointAllocationCalculator::AXES, true)) {
                $types[$row['system_key']] = (int) $row['id'];
            }
        }
        if (count($types) !== 3) {
            throw new JointAllocationException('allocation_invalid_types', 'Firma nemá všechny tři systémové dimenze.');
        }
        $lines = [];
        $additional = [];
        foreach ($version['cells'] as $cell) {
            $dimensions = $cell['additional_dimensions'] ?? [];
            foreach ($dimensions as $typeId => $valueId) {
                if (in_array($typeId, $types, true)) throw new JointAllocationException('allocation_invalid_types', 'Systémová osa je uvedena mezi dalšími osami.');
                if (isset($additional[$valueId]) && $additional[$valueId] !== $typeId) throw new JointAllocationException('allocation_invalid_types', 'Hodnota nemůže patřit ke dvěma různým osám.');
                $additional[$valueId] = $typeId;
            }
            foreach ($types as $axis => $typeId) {
                $dimensions[$typeId] = $cell[$axis];
            }
            $lines[] = ['dimensions' => $dimensions];
        }
        if ($additional !== []) {
            $group = $this->db->pdo()->prepare('SELECT supplier_group_id FROM supplier WHERE id = ? FOR UPDATE');
            $group->execute([$supplierId]);
            $groupId = (int) $group->fetchColumn();
            $ids = array_keys($additional);
            sort($ids, SORT_NUMERIC);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT v.id, v.type_id, v.supplier_id, v.supplier_group_id, v.is_active,
                    t.supplier_id AS type_supplier, t.supplier_group_id AS type_group, t.is_active AS type_active
                FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id
                WHERE v.id IN ({$marks}) ORDER BY v.id FOR UPDATE");
            $stmt->execute($ids);
            $values = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            foreach ($additional as $valueId => $typeId) {
                $value = $values[$valueId] ?? null;
                if ($value === null || (int) $value['type_id'] !== $typeId || !(bool) $value['is_active'] || !(bool) $value['type_active']
                    || ((int) $value['supplier_id'] !== $supplierId && ($groupId === 0 || (int) $value['supplier_group_id'] !== $groupId))
                    || ((int) $value['type_supplier'] !== $supplierId && ($groupId === 0 || (int) $value['type_group'] !== $groupId))) {
                    throw new JointAllocationException('allocation_invalid_types', 'Další osa nebo hodnota není platná pro tuto firmu.');
                }
            }
        }
        (new SystemDimensionWritePolicy($this->db))->assertAssignments($supplierId, $lines);
        return $types;
    }

    private function insertVersion(int $supplierId, int $keyId, int $number, array $version, ?int $actor): array
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO joint_allocation_key_versions (supplier_id, key_id, version_no, valid_from, valid_to, basis, explanation, rounding_policy, cells_json, content_hash, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$supplierId, $keyId, $number, $version['valid_from'], $version['valid_to'], $version['basis'], $version['explanation'], $version['rounding_policy'], JointAllocationKeyVersion::encodeCells($version), $version['content_hash'], $actor]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $result = ['id' => $id, 'supplier_id' => $supplierId, 'key_id' => $keyId, 'version_no' => $number] + $version;
        $this->audit($supplierId, 'version', $id, 'create', $result, $actor);
        return $result;
    }

    private function audit(int $supplierId, string $entity, int $id, string $action, array $payload, ?int $actor): void
    {
        (new JointAllocationAudit($this->db))->record($supplierId, $entity, $id, $action, $payload, $actor);
    }
}
