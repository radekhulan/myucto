<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogJobItemRepository
{
    public const STATUSES = ['pending', 'ready', 'applied', 'unchanged', 'failed', 'conflict', 'skipped'];

    public function __construct(private readonly Connection $db) {}

    public function append(int $supplierId, int $jobId, array $items): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Položky musí být součástí transakce úlohy.');
        }
        $parent = $this->db->pdo()->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ?');
        $parent->execute([$supplierId, $jobId]);
        if ($parent->fetchColumn() === false) {
            throw new \OutOfBoundsException('Úloha nenalezena.');
        }
        if (count($items) > 1000) {
            throw new \InvalidArgumentException('Dávka smí obsahovat nejvýše 1000 položek.');
        }
        $stmt = $this->db->pdo()->prepare('INSERT INTO catalog_job_items
            (supplier_id, job_id, ordinal, stock_item_id, source_row, expected_version, input_json)
            VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ordinal = VALUES(ordinal)');
        foreach ($items as $item) {
            if (!is_int($item['ordinal'] ?? null) || $item['ordinal'] < 1) {
                throw new \InvalidArgumentException('Neplatné pořadí položky.');
            }
            $stmt->execute([$supplierId, $jobId, $item['ordinal'], $item['stock_item_id'] ?? null,
                $item['source_row'] ?? null, $item['expected_version'] ?? null,
                json_encode($item['input'] ?? [], JSON_THROW_ON_ERROR)]);
        }
    }

    public function batch(int $supplierId, int $jobId, int $after = 0, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare('SELECT * FROM catalog_job_items
            WHERE supplier_id = ? AND job_id = ? AND ordinal > ? ORDER BY ordinal LIMIT ' . $limit);
        $stmt->execute([$supplierId, $jobId, $after]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function page(int $supplierId, int $jobId, int $page = 1, int $limit = 50, ?string $status = null): array
    {
        if ($page < 1 || $page > 2147483647 || $limit < 1 || $limit > 500 || ($status !== null && !in_array($status, self::STATUSES, true))) {
            throw new \InvalidArgumentException('Neplatné stránkování reportu.');
        }
        $where = 'supplier_id = ? AND job_id = ?';
        $params = [$supplierId, $jobId];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM catalog_job_items WHERE ' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT * FROM catalog_job_items WHERE ' . $where
            . ' ORDER BY ordinal LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
        $stmt->execute($params);
        return ['items' => array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
    }

    public function finish(int $supplierId, int $jobId, int $ordinal, string $status, ?array $before = null, ?array $after = null, ?string $errorCode = null): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Výsledek položky musí být součástí transakce úlohy.');
        }
        if (!in_array($status, self::STATUSES, true)
            || ($errorCode !== null && !preg_match('/^[a-z0-9_]{1,100}$/D', $errorCode))) {
            throw new \InvalidArgumentException('Neplatný stav položky.');
        }
        $stmt = $this->db->pdo()->prepare('UPDATE catalog_job_items SET status = ?, error_code = ?, before_json = ?, after_json = ?
            WHERE supplier_id = ? AND job_id = ? AND ordinal = ?');
        $stmt->execute([$status, $errorCode, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), $supplierId, $jobId, $ordinal]);
    }

    public function counts(int $supplierId, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, COUNT(*) AS count FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? GROUP BY status');
        $stmt->execute([$supplierId, $jobId]);
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }
        return $counts;
    }

    private static function cast(array $row): array
    {
        foreach (['job_id', 'supplier_id', 'ordinal', 'stock_item_id', 'source_row', 'expected_version'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        foreach (['input', 'before', 'after'] as $key) {
            $row[$key] = $row[$key . '_json'] === null ? null : json_decode($row[$key . '_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row[$key . '_json']);
        }
        return $row;
    }
}
