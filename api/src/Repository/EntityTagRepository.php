<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class EntityTagRepository
{
    public function __construct(private readonly Connection $db) {}

    public static function validNames(mixed $names): bool
    {
        return is_array($names) && array_is_list($names) && count($names) <= 64
            && array_all($names, static fn ($name) => is_string($name) && trim($name) !== '' && mb_strlen(trim($name)) <= 64);
    }

    /** Číselník sdílí entity jedné firmy. Prefix využívá index (supplier_id, name). */
    public function suggest(int $supplierId, string $prefix): array
    {
        $query = $this->db->pdo()->prepare(
            'SELECT id, name FROM entity_tags WHERE supplier_id = ? AND name LIKE ? ORDER BY name LIMIT 50'
        );
        $query->execute([$supplierId, addcslashes($prefix, '%_\\') . '%']);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Popisky pro obnovení uloženého filtru mimo první stránku našeptávače. */
    public function byIds(int $supplierId, array $ids): array
    {
        if (!$ids) return [];
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, name FROM entity_tags WHERE supplier_id = ? AND id IN ($slots)");
        $stmt->execute([$supplierId, ...$ids]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Nahrazení celé sady se serializuje zámkem vlastníka v transakci volajícího. */
    public function replace(TagTarget $target, int $supplierId, int $entityId, array $names): void
    {
        if (!self::validNames($names)) {
            throw new \InvalidArgumentException('Neplatný seznam štítků.');
        }
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Uložení štítků vyžaduje transakci.');
        }
        $owner = $pdo->prepare("SELECT id FROM {$target->entityTable()} WHERE supplier_id = ? AND id = ? FOR UPDATE");
        $owner->execute([$supplierId, $entityId]);
        if (!$owner->fetchColumn()) {
            throw new \InvalidArgumentException('Záznam pro přiřazení štítků nenalezen.');
        }
        $pdo->prepare("DELETE FROM {$target->value} WHERE entity_id = ?")->execute([$entityId]);
        if (!$names) return;

        $names = array_unique(array_map(trim(...), $names));
        sort($names, SORT_STRING);
        $pairs = implode(', ', array_fill(0, count($names), '(?, ?)'));
        $params = [];
        foreach ($names as $name) {
            array_push($params, $supplierId, $name);
        }
        // Kolace číselníku sjednotí velikost písmen. Zachová se původní název.
        $pdo->prepare("INSERT INTO entity_tags (supplier_id, name) VALUES $pairs ON DUPLICATE KEY UPDATE id = id")
            ->execute($params);
        $slots = implode(',', array_fill(0, count($names), '?'));
        $pdo->prepare("INSERT INTO {$target->value} (entity_id, tag_id)
                       SELECT ?, id FROM entity_tags WHERE supplier_id = ? AND name IN ($slots)")
            ->execute([$entityId, $supplierId, ...$names]);
    }

    /** @return array<int, list<array{id:int,name:string}>> */
    public function forEntities(TagTarget $target, int $supplierId, array $ids): array
    {
        if (!$ids) return [];
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT m.entity_id, t.id, t.name FROM {$target->value} m JOIN entity_tags t ON t.id = m.tag_id
             WHERE t.supplier_id = ? AND m.entity_id IN ($slots) ORDER BY t.name"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $result = [];
        foreach ($stmt as $row) {
            $result[(int) $row['entity_id']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
        return $result;
    }

    public static function validFilter(mixed $filter): bool
    {
        return is_string($filter) && ($filter === '' || $filter === 'none'
            || preg_match('/^[1-9][0-9]{0,17}(?:,[1-9][0-9]{0,17}){0,63}$/D', $filter) === 1);
    }

    /** Každá podmínka požaduje jeden štítek. Nadřazený dotaz omezuje záznamy na firmu. */
    public static function filter(TagTarget $target, string $entityIdSql, string $filter, array &$where, array &$params): void
    {
        if ($filter === '') return;
        $lookup = "SELECT 1 FROM {$target->value} m WHERE m.entity_id = $entityIdSql";
        if ($filter === 'none') {
            $where[] = "NOT EXISTS ($lookup)";
            return;
        }
        foreach (array_unique(explode(',', $filter)) as $id) {
            $where[] = "EXISTS ($lookup AND m.tag_id = ?)";
            $params[] = (int) $id;
        }
    }
}
