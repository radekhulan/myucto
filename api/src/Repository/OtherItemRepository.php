<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OtherItemSort;
use PDO;

final class OtherItemRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.*, COALESCE(a.paid_amount, 0) + oi.settled_outside_amount AS paid_amount,
                    oi.amount - oi.settled_outside_amount - COALESCE(a.paid_amount, 0) AS remaining_amount
               FROM other_items oi
               LEFT JOIN (SELECT other_item_id, SUM(amount) paid_amount FROM other_item_allocations
                           WHERE reversed_on IS NULL GROUP BY other_item_id) a
                 ON a.other_item_id = oi.id
              WHERE oi.supplier_id = ? AND oi.id = ? AND oi.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        // Název druhu zvlášť a bez zámku: JOIN v zamykacím čtení by zamkl i řádek druhu
        // a serializoval všechny položky stejného druhu (i s úpravou druhu v Nástrojích).
        $row['kind_name'] = null;
        if ($row['kind_id'] !== null) {
            $kind = $this->db->pdo()->prepare('SELECT name FROM other_item_kinds WHERE id = ? AND supplier_id = ?');
            $kind->execute([(int) $row['kind_id'], $supplierId]);
            $row['kind_name'] = $kind->fetchColumn() ?: null;
        }
        $row['posting_lines'] = $this->postingLines($supplierId, $id);
        if ($row['posting_lines'] === [] && $row['counter_account_code'] !== null) {
            $row['posting_lines'] = [[
                'account_code' => (string) $row['counter_account_code'],
                'amount' => (float) $row['amount_czk'],
            ]];
        }
        return $row;
    }

    public function postingLines(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code, amount FROM other_item_posting_lines
            WHERE supplier_id = ? AND other_item_id = ? ORDER BY position');
        $stmt->execute([$supplierId, $id]);
        return array_map(static fn (array $row): array => [
            'account_code' => (string) $row['account_code'], 'amount' => (float) $row['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function replacePostingLines(int $supplierId, int $id, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM other_item_posting_lines WHERE supplier_id = ? AND other_item_id = ?')
            ->execute([$supplierId, $id]);
        $stmt = $pdo->prepare('INSERT INTO other_item_posting_lines
            (supplier_id, other_item_id, position, account_code, amount) VALUES (?,?,?,?,?)');
        foreach ($lines as $position => $line) {
            $stmt->execute([$supplierId, $id, $position + 1, $line['account_code'], $line['amount']]);
        }
    }

    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $where = ['oi.supplier_id = ?', 'oi.deleted_at IS NULL'];
        $params = [$supplierId];
        if (!empty($filters['side'])) {
            $where[] = 'oi.side = ?';
            $params[] = $filters['side'];
        }
        // Vlastní druh se filtruje podle kind_id; vestavěný podle kind bez vlastního druhu,
        // jinak by „other“ vracel i položky vlastních druhů (ty nesou kind = other).
        if (!empty($filters['kind_id'])) {
            $where[] = 'oi.kind_id = ?';
            $params[] = (int) $filters['kind_id'];
        } elseif (!empty($filters['kind'])) {
            $where[] = 'oi.kind = ? AND oi.kind_id IS NULL';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'open') {
                $where[] = "oi.status IN ('draft','confirmed','posted')";
                $where[] = 'oi.amount - oi.settled_outside_amount > COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a WHERE a.other_item_id = oi.id AND a.supplier_id = oi.supplier_id AND a.reversed_on IS NULL), 0)';
            } else {
                $where[] = 'oi.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['q'])) {
            $where[] = '(oi.title LIKE ? OR oi.partner_name LIKE ? OR oi.document_no LIKE ? OR oi.variable_symbol LIKE ?)';
            $term = '%' . $filters['q'] . '%';
            array_push($params, $term, $term, $term, $term);
        }
        if (!empty($filters['from'])) {
            $where[] = 'oi.due_on >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'oi.due_on <= ?';
            $params[] = $filters['to'];
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM other_items oi WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.*, COALESCE(a.paid_amount, 0) + oi.settled_outside_amount paid_amount,
                    oi.amount - oi.settled_outside_amount - COALESCE(a.paid_amount, 0) remaining_amount,
                    k.name kind_name
               FROM other_items oi
               LEFT JOIN (SELECT other_item_id, SUM(amount) paid_amount FROM other_item_allocations
                           WHERE reversed_on IS NULL GROUP BY other_item_id) a
                 ON a.other_item_id = oi.id
               LEFT JOIN other_item_kinds k ON k.id = oi.kind_id AND k.supplier_id = oi.supplier_id
              WHERE ' . $whereSql . '
              ORDER BY ' . OtherItemSort::orderBy($filters) . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function insert(int $supplierId, array $data, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO other_items
              (supplier_id, side, kind, kind_id, title, partner_id, partner_name, issued_on, accounting_on,
               due_on, currency, amount, exchange_rate, amount_czk, variable_symbol,
               account_code, counter_account_code, note, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $supplierId, $data['side'], $data['kind'], $data['kind_id'] ?? null, $data['title'], $data['partner_id'],
            $data['partner_name'], $data['issued_on'], $data['accounting_on'], $data['due_on'],
            $data['currency'], $data['amount'], $data['exchange_rate'], $data['amount_czk'],
            $data['variable_symbol'], $data['account_code'], $data['counter_account_code'],
            $data['note'], $userId, $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateDraft(int $supplierId, int $id, array $data, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET side = ?, kind = ?, kind_id = ?, title = ?, partner_id = ?, partner_name = ?,
                    issued_on = ?, accounting_on = ?, due_on = ?, currency = ?, amount = ?,
                    exchange_rate = ?, amount_czk = ?, variable_symbol = ?, account_code = ?,
                    counter_account_code = ?, note = ?, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'draft\' AND deleted_at IS NULL'
        );
        $stmt->execute([
            $data['side'], $data['kind'], $data['kind_id'] ?? null, $data['title'], $data['partner_id'], $data['partner_name'],
            $data['issued_on'], $data['accounting_on'], $data['due_on'], $data['currency'], $data['amount'],
            $data['exchange_rate'], $data['amount_czk'], $data['variable_symbol'], $data['account_code'],
            $data['counter_account_code'], $data['note'], $userId, $id, $supplierId,
        ]);
    }

    public function softDeleteDraft(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET deleted_at = NOW(), row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'draft\' AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $supplierId]);
    }

    public function cancelDraft(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET status = \'cancelled\', row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'draft\' AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $supplierId]);
    }

    public function setPosted(int $supplierId, int $id, string $status, string $number, ?int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET status = ?, document_no = ?, journal_entry_id = ?, posted_at = NOW(),
                    row_version = row_version + 1 WHERE id = ? AND supplier_id = ? AND status = \'draft\''
        );
        $stmt->execute([$status, $number, $entryId, $id, $supplierId]);
    }

    public function setUnposted(int $supplierId, int $id, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE other_items SET status = 'draft', journal_entry_id = NULL, reversal_entry_id = NULL,
                    posted_at = NULL, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = 'posted' AND deleted_at IS NULL"
        )->execute([$userId, $id, $supplierId]);
    }

    public function setReversed(int $supplierId, int $id, string $status, ?int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET status = ?, reversal_entry_id = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$status, $entryId, $id, $supplierId]);
    }

    public function setReposted(int $supplierId, int $id, string $accountCode, ?string $counterCode,
        string $accountingOn, int $entryId, int $reversalId, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET account_code = ?, counter_account_code = ?, accounting_on = ?,
                    journal_entry_id = ?, reversal_entry_id = ?, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'posted\' AND deleted_at IS NULL'
        );
        $stmt->execute([$accountCode, $counterCode, $accountingOn, $entryId, $reversalId,
            $userId, $id, $supplierId]);
    }
}
