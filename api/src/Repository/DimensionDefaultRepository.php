<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Výchozí dimenze klienta, zakázky, produktu, kategorie produktu a vlastního bankovního
 * účtu (`dimension_defaults`, migrace 1861, 1949 a 2232).
 *
 * Mapa typ => hodnota, nejvýš jedna hodnota za typ (unikátní klíč). Validaci hodnot
 * dělá volající přes {@see \MyInvoice\Service\Accounting\Dimension\DimensionService::normalize()};
 * vlastnictví entity firmou hlídá {@see ownsEntity()}.
 */
final class DimensionDefaultRepository
{
    public const ENTITIES = ['client', 'project', 'product', 'product_category', 'bank_account'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Klient, produkt, kategorie a bankovní účet patří firmě přímo, zakázka přes svého
     * klienta (projects nemá supplier_id).
     */
    public function ownsEntity(int $supplierId, string $entity, int $entityId): bool
    {
        $stmt = $this->db->pdo()->prepare(match ($entity) {
            'project' => 'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?',
            'product' => 'SELECT 1 FROM stock_items WHERE id = ? AND supplier_id = ?',
            'product_category' => 'SELECT 1 FROM stock_categories WHERE id = ? AND supplier_id = ?',
            'bank_account' => 'SELECT 1 FROM supplier_bank_accounts WHERE id = ? AND supplier_id = ?',
            default => 'SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?',
        });
        $stmt->execute([$entityId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int,int> typ => hodnota */
    public function forEntity(int $supplierId, string $entity, int $entityId): array
    {
        return $this->forEntities($supplierId, $entity, [$entityId])[$entityId] ?? [];
    }

    /**
     * @param list<int> $entityIds
     * @return array<int,array<int,int>> entita => typ => hodnota
     */
    public function forEntities(int $supplierId, string $entity, array $entityIds): array
    {
        $column = self::column($entity);
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        if ($entityIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$column} AS entity_id, dimension_type_id, dimension_value_id FROM dimension_defaults
              WHERE supplier_id = ? AND {$column} IN ({$placeholders}) ORDER BY dimension_type_id"
        );
        $stmt->execute([$supplierId, ...$entityIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['entity_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /** @param array<int,int> $dims typ => hodnota (už normalizovaná) */
    public function replace(int $supplierId, string $entity, int $entityId, array $dims): void
    {
        $column = self::column($entity);
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM dimension_defaults WHERE supplier_id = ? AND {$column} = ?")
            ->execute([$supplierId, $entityId]);
        if ($dims === []) {
            return;
        }
        $rows = [];
        $params = [];
        foreach ($dims as $typeId => $valueId) {
            $rows[] = '(?, ?, ?, ?)';
            array_push($params, $supplierId, $entityId, (int) $typeId, (int) $valueId);
        }
        $pdo->prepare(
            "INSERT INTO dimension_defaults (supplier_id, {$column}, dimension_type_id, dimension_value_id) VALUES "
            . implode(',', $rows)
        )->execute($params);
    }

    private static function column(string $entity): string
    {
        return match ($entity) {
            'client' => 'client_id',
            'project' => 'project_id',
            'product' => 'product_id',
            'product_category' => 'product_category_id',
            'bank_account' => 'supplier_bank_account_id',
            default => throw new \InvalidArgumentException("Neznámá entita výchozích dimenzí: {$entity}"),
        };
    }
}
