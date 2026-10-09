<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kategorie a pravidla příchozích zpráv datové schránky (migrace 1982).
 *
 * Každý dotaz nese `supplier_id`: kategorie i pravidla patří jedné firmě
 * a databáze shodu firmy kategorie a zprávy sama nehlídá (viz migrace).
 */
final class SubmissionInboxCategoryRepository
{
    private const CATEGORIES = 'submission_inbox_categories';
    private const RULES = 'submission_inbox_category_rules';
    private const MESSAGES = 'submission_inbox_messages';

    public const MATCH_FIELDS = ['sender_box', 'sender_name', 'subject'];

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::CATEGORIES)
            && $this->db->hasColumn(self::MESSAGES, 'category_id');
    }

    /** @param array<string,int> $codes code → sort_order */
    public function ensureSystemCategories(int $supplierId, array $codes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::CATEGORIES . ' (supplier_id, code, sort_order)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        foreach ($codes as $code => $order) {
            $stmt->execute([$supplierId, $code, $order]);
        }
    }

    /** @return list<array{id:int,code:?string,name:?string,sort_order:int}> */
    public function listCategories(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, name, sort_order FROM ' . self::CATEGORIES . '
              WHERE supplier_id = ?
              ORDER BY sort_order, id'
        );
        $stmt->execute([$supplierId]);

        return array_values(array_map(self::normalizeCategory(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array{id:int,code:?string,name:?string,sort_order:int}|null */
    public function findCategory(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, name, sort_order FROM ' . self::CATEGORIES . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalizeCategory($row) : null;
    }

    /** @return array<string,int> code → id */
    public function systemCategoryIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, id FROM ' . self::CATEGORIES . ' WHERE supplier_id = ? AND code IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }
        return $map;
    }

    public function createCategory(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::CATEGORIES . ' (supplier_id, code, name, sort_order)
             SELECT ?, NULL, ?, GREATEST(100, COALESCE(MAX(sort_order), 0) + 10)
               FROM ' . self::CATEGORIES . ' WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId, $name, $supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function renameCategory(int $supplierId, int $id, ?string $name): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::CATEGORIES . ' SET name = ?
              WHERE supplier_id = ? AND id = ? AND (code IS NOT NULL OR ? IS NOT NULL)'
        );
        $stmt->execute([$name, $supplierId, $id, $name]);

        return $stmt->rowCount() > 0 || $this->findCategory($supplierId, $id) !== null;
    }

    /** Smaže vlastní kategorii; její zprávy se vrátí k novému zařazení. */
    public function deleteCustomCategory(int $supplierId, int $id): bool
    {
        $release = $this->db->pdo()->prepare(
            'UPDATE ' . self::MESSAGES . ' SET category_id = NULL, category_source = NULL
              WHERE supplier_id = ? AND category_id = ?'
        );
        $release->execute([$supplierId, $id]);
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::CATEGORIES . ' WHERE supplier_id = ? AND id = ? AND code IS NULL'
        );
        $stmt->execute([$supplierId, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{id:int,category_id:int,match_field:string,pattern:string,origin:string,created_by:?int,created_at:string}>
     */
    public function listRules(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, category_id, match_field, pattern, origin, created_by, created_at
               FROM ' . self::RULES . '
              WHERE supplier_id = ?
              ORDER BY origin DESC, match_field, pattern'
        );
        $stmt->execute([$supplierId]);

        return array_values(array_map(self::normalizeRule(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array{id:int,category_id:int,match_field:string,pattern:string,origin:string,created_by:?int,created_at:string}|null */
    public function findRule(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, category_id, match_field, pattern, origin, created_by, created_at
               FROM ' . self::RULES . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalizeRule($row) : null;
    }

    /**
     * Ruční pravidlo: založí nové, nebo přesměruje existující (i automatické,
     * které se tím stává ručním).
     */
    public function upsertUserRule(int $supplierId, int $categoryId, string $field, string $pattern, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::RULES . ' (supplier_id, category_id, match_field, pattern, origin, created_by)
             VALUES (?, ?, ?, ?, \'user\', ?)
             ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), origin = \'user\',
                                     created_by = VALUES(created_by), id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$supplierId, $categoryId, $field, $pattern, $userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Automatické pravidlo nikdy nepřepisuje existující (ani ruční) pravidlo. */
    public function insertAutoRule(int $supplierId, int $categoryId, string $boxId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::RULES . ' (supplier_id, category_id, match_field, pattern, origin)
             VALUES (?, ?, \'sender_box\', ?, \'auto\')
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([$supplierId, $categoryId, $boxId]);
    }

    public function updateRuleCategory(int $supplierId, int $id, int $categoryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::RULES . ' SET category_id = ?, origin = \'user\' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$categoryId, $supplierId, $id]);

        return $stmt->rowCount() > 0 || $this->findRule($supplierId, $id) !== null;
    }

    public function deleteRule(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . self::RULES . ' WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Fakta pro zařazení zpráv firmy. Bez `$onlyPending` vrací všechny zprávy,
     * které nejsou ručně přeřazené (přepočet po změně pravidel).
     *
     * @return list<array{
     *   id:int, classification:string, sender_box_id:?string, sender_name:?string,
     *   subject:?string, sender_is_public_authority:?bool, category_id:?int,
     *   category_source:?string, direction:?string, sender_type:?int, envelope_direction:?string
     * }>
     */
    public function messageFacts(int $supplierId, bool $onlyPending): array
    {
        $where = $onlyPending
            ? ' AND (m.category_id IS NULL OR m.direction IS NULL)'
            : ' AND (m.category_id IS NULL OR m.category_source IS NULL OR m.category_source <> \'manual\')';
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.id, m.classification, m.sender_box_id, m.sender_name, m.subject,
                    m.sender_is_public_authority, m.category_id, m.category_source, m.direction,
                    dm.sender_type, dm.direction AS envelope_direction
               FROM ' . self::MESSAGES . ' m
               LEFT JOIN document_dms_messages dm ON dm.id = (
                    SELECT MIN(d2.id) FROM document_dms_messages d2 WHERE d2.document_id = m.document_id
               )
              WHERE m.supplier_id = ?' . $where . '
              ORDER BY m.id'
        );
        $stmt->execute([$supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $senderType = $row['sender_type'] ?? null;
            $rows[] = [
                'id' => (int) $row['id'],
                'classification' => (string) $row['classification'],
                'sender_box_id' => $row['sender_box_id'] !== null ? (string) $row['sender_box_id'] : null,
                'sender_name' => $row['sender_name'] !== null ? (string) $row['sender_name'] : null,
                'subject' => $row['subject'] !== null ? (string) $row['subject'] : null,
                'sender_is_public_authority' => $row['sender_is_public_authority'] !== null
                    ? (bool) $row['sender_is_public_authority']
                    : null,
                'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
                'category_source' => $row['category_source'] !== null ? (string) $row['category_source'] : null,
                'direction' => $row['direction'] !== null ? (string) $row['direction'] : null,
                'sender_type' => is_numeric($senderType) ? (int) $senderType : null,
                'envelope_direction' => $row['envelope_direction'] !== null ? (string) $row['envelope_direction'] : null,
            ];
        }
        return $rows;
    }

    /** @param list<int> $ids */
    public function assign(int $supplierId, array $ids, int $categoryId, string $source, string $direction): void
    {
        if ($ids === []) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                'UPDATE ' . self::MESSAGES . '
                    SET category_id = ?, category_source = ?, direction = ?
                  WHERE supplier_id = ? AND id IN (' . $marks . ')'
            );
            $stmt->execute([$categoryId, $source, $direction, $supplierId, ...$chunk]);
        }
    }

    public function assignManual(int $supplierId, int $messageId, int $categoryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::MESSAGES . ' m
               JOIN ' . self::CATEGORIES . ' c ON c.id = ? AND c.supplier_id = m.supplier_id
                SET m.category_id = c.id, m.category_source = \'manual\'
              WHERE m.supplier_id = ? AND m.id = ?'
        );
        $stmt->execute([$categoryId, $supplierId, $messageId]);

        return $stmt->rowCount() > 0 || $this->messageCategory($supplierId, $messageId) === $categoryId;
    }

    private function messageCategory(int $supplierId, int $messageId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT category_id FROM ' . self::MESSAGES . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $messageId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /** @return list<string> ID schránek firmy (malými písmeny) z nastavení přístupu. */
    public function ownBoxIds(int $supplierId): array
    {
        if (!$this->db->hasTable('submission_channel_credentials')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT LOWER(box_id) FROM submission_channel_credentials
              WHERE supplier_id = ? AND box_id IS NOT NULL AND box_id <> \'\''
        );
        $stmt->execute([$supplierId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,code:?string,name:?string,sort_order:int}
     */
    private static function normalizeCategory(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'] !== null ? (string) $row['code'] : null,
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,category_id:int,match_field:string,pattern:string,origin:string,created_by:?int,created_at:string}
     */
    private static function normalizeRule(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'category_id' => (int) $row['category_id'],
            'match_field' => (string) $row['match_field'],
            'pattern' => (string) $row['pattern'],
            'origin' => (string) $row['origin'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
