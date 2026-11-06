<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use PDO;

final class SystemDimensionCatalog
{
    public const DEFINITIONS = [
        'activity' => ['name' => 'Činnost', 'values' => ['main' => 'Hlavní činnost', 'economic' => 'Hospodářská činnost']],
        'activity_kind' => ['name' => 'Druh činnosti', 'values' => []],
        'tax_regime' => ['name' => 'Předběžné daňové zařazení', 'values' => ['subject' => 'Předmět daně', 'out_of_scope' => 'Mimo předmět daně', 'exempt' => 'Osvobozeno']],
    ];

    public function __construct(private readonly Connection $db) {}

    public function ensureForFramework(int $supplierId, string $date, ?int $actor = null): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $date, $actor): array {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            if ((new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->forDateForUpdate($supplierId, $date) !== AccountingFrameworkService::CZ_504) {
                return [];
            }
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT * FROM dimension_types WHERE supplier_id = ? ORDER BY id FOR UPDATE');
            $stmt->execute([$supplierId]);
            $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach (self::DEFINITIONS as $key => $definition) {
                $type = null;
                foreach ($existing as $row) {
                    if ($row['system_key'] === $key) {
                        $type = $row;
                    } elseif ($row['code'] === $key) {
                        throw new DimensionException('system_dimension_code_conflict', 'Kód systémové dimenze již používá vlastní typ. Vlastní typ nelze automaticky převést.', 409);
                    }
                }
                if ($type === null) {
                    $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, system_key) VALUES (?, ?, ?, 'custom', ?)")
                        ->execute([$supplierId, $key, $definition['name'], $key]);
                    $id = (int) $pdo->lastInsertId();
                    $type = ['id' => $id, 'supplier_id' => $supplierId, 'system_key' => $key, 'code' => $key, 'name' => $definition['name']];
                    (new SystemDimensionGuard($this->db))->audit('type', $id, $supplierId, null, $type, $actor, 'system_catalog');
                }
                $typeId = (int) $type['id'];
                $stmt = $pdo->prepare('SELECT * FROM dimension_values WHERE type_id = ? ORDER BY id FOR UPDATE');
                $stmt->execute([$typeId]);
                $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $byKey = [];
                foreach ($definition['values'] as $semantic => $name) {
                    $found = null;
                    foreach ($values as $value) {
                        if ($value['semantic_key'] === $semantic) {
                            $found = $value;
                        } elseif ($value['code'] === $semantic) {
                            throw new DimensionException('system_dimension_code_conflict', 'Kód systémové hodnoty již používá vlastní hodnota.', 409);
                        }
                    }
                    if ($found === null) {
                        $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name, semantic_key) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$typeId, $supplierId, $semantic, $name, $semantic]);
                        $id = (int) $pdo->lastInsertId();
                        $found = ['id' => $id, 'type_id' => $typeId, 'supplier_id' => $supplierId, 'code' => $semantic, 'name' => $name, 'semantic_key' => $semantic];
                        (new SystemDimensionGuard($this->db))->audit('value', $id, $supplierId, null, $found, $actor, 'system_catalog');
                    }
                    $byKey[$semantic] = (int) $found['id'];
                }
                $out[$key] = ['id' => $typeId, 'values' => $byKey];
            }
            $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ? AND dimensions_enabled <> 1')->execute([$supplierId]);
            return $out;
        });
    }
}
