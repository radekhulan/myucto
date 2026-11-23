<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Výsledky hromadných úloh účtování po dokladech (`accounting_bulk_items`): přeúčtování
 * ({@see \MyInvoice\Service\Accounting\JournalRebookService}) a bankovní pohyby podle
 * filtru ({@see \MyInvoice\Service\Accounting\Bank\BankBulkService}). Každá úloha
 * (nanečisto, ostře, vrácení) má vlastní položky; navazující běh čte položky předchozího.
 */
final class AccountingBulkItemRepository
{
    public const STATUSES = ['would_change', 'unchanged', 'skipped', 'excluded', 'applied', 'failed'];

    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $item */
    public function insert(int $jobId, int $supplierId, array $item): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_bulk_items
                (job_id, supplier_id, entry_id, source_type, source_id, entry_date, document_no, description,
                 amount, status, strategy, target_date, date_shifted, reason_code, message, fingerprint,
                 before_json, after_json, result_entry_id, items_synced)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $jobId,
            $supplierId,
            isset($item['entry_id']) && (int) $item['entry_id'] > 0 ? (int) $item['entry_id'] : null,
            (string) $item['source_type'],
            (int) $item['source_id'],
            (string) $item['entry_date'],
            ($item['document_no'] ?? null) === null ? null : mb_substr((string) $item['document_no'], 0, 50),
            ($item['description'] ?? null) === null ? null : mb_substr((string) $item['description'], 0, 255),
            (float) ($item['amount'] ?? 0),
            (string) $item['status'],
            $item['strategy'] ?? null,
            $item['target_date'] ?? null,
            !empty($item['date_shifted']) ? 1 : 0,
            $item['reason_code'] ?? null,
            isset($item['message']) ? mb_substr((string) $item['message'], 0, 500) : null,
            $item['fingerprint'] ?? null,
            json_encode($item['before'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($item['after'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $item['result_entry_id'] ?? null,
            (int) ($item['items_synced'] ?? 0),
        ]);
    }

    /**
     * Položky běhu v daném stavu (pro navazující běh). Běh má nejvýš stovky položek.
     *
     * @return list<array<string,mixed>>
     */
    public function byStatus(int $supplierId, int $jobId, string $status): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM accounting_bulk_items WHERE supplier_id = ? AND job_id = ? AND status = ? ORDER BY entry_date, id'
        );
        $stmt->execute([$supplierId, $jobId, $status]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stránka položek běhu pro obrazovku.
     *
     * @return array{items:list<array<string,mixed>>, total:int, counts:array<string,int>}
     */
    public function page(int $supplierId, int $jobId, ?string $status, int $page, int $perPage): array
    {
        $counts = $this->db->pdo()->prepare(
            'SELECT status, COUNT(*) FROM accounting_bulk_items WHERE supplier_id = ? AND job_id = ? GROUP BY status'
        );
        $counts->execute([$supplierId, $jobId]);
        $byStatus = array_map('intval', $counts->fetchAll(PDO::FETCH_KEY_PAIR));

        $where = 'supplier_id = ? AND job_id = ?';
        $params = [$supplierId, $jobId];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM accounting_bulk_items WHERE {$where} ORDER BY entry_date, id LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'items'  => array_map(static function (array $row): array {
                $item = self::cast($row);
                unset($item['fingerprint']);
                return $item;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total'  => $status === null ? array_sum($byStatus) : ($byStatus[$status] ?? 0),
            'counts' => $byStatus,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'entry_id'        => $row['entry_id'] === null ? null : (int) $row['entry_id'],
            'source_type'     => (string) $row['source_type'],
            'source_id'       => (int) $row['source_id'],
            'document_no'     => $row['document_no'],
            'entry_date'      => (string) $row['entry_date'],
            'description'     => $row['description'],
            'amount'          => (float) $row['amount'],
            'status'          => (string) $row['status'],
            'strategy'        => $row['strategy'],
            'target_date'     => $row['target_date'],
            'date_shifted'    => (bool) $row['date_shifted'],
            'reason_code'     => $row['reason_code'],
            'message'         => $row['message'],
            'fingerprint'     => $row['fingerprint'],
            'before'          => json_decode((string) ($row['before_json'] ?? '[]'), true) ?: [],
            'after'           => json_decode((string) ($row['after_json'] ?? '[]'), true) ?: [],
            'result_entry_id' => $row['result_entry_id'] === null ? null : (int) $row['result_entry_id'],
            'items_synced'    => (int) $row['items_synced'],
        ];
    }
}
