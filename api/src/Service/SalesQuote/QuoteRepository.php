<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class QuoteRepository
{
    public function __construct(private readonly Connection $db) {}

    public function transaction(callable $action): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $action();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function list(int $supplierId, array $filters): array
    {
        $pdo = $this->db->pdo();
        $where = ['q.supplier_id = ?'];
        $params = [$supplierId];
        $status = $filters['status'] ?? '';
        if ($status === 'expired' || $status === 'sent') {
            $where[] = "q.status = 'sent' AND q.valid_until " . ($status === 'expired' ? '<' : '>=') . ' CURRENT_DATE';
        } elseif (in_array($status, ['draft', 'accepted', 'declined', 'withdrawn'], true)) {
            $where[] = 'q.status = ?';
            $params[] = $status;
        }
        foreach (['client_id', 'project_id'] as $name) {
            if (isset($filters[$name]) && (int) $filters[$name] > 0) {
                $where[] = 'q.' . $name . ' = ?';
                $params[] = (int) $filters[$name];
            }
        }
        $search = trim(is_string($filters['search'] ?? null) ? $filters['search'] : '');
        if ($search !== '') {
            $like = '%' . strtr(mb_substr($search, 0, 190), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where[] = "(q.quote_number LIKE ? ESCAPE '!' OR q.title LIKE ? ESCAPE '!' OR c.company_name LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        $conditions = implode(' AND ', $where);
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM sales_quotes q JOIN clients c ON c.id = q.client_id AND c.supplier_id = q.supplier_id WHERE ' . $conditions
        );
        $count->execute($params);
        $pageSize = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset = (max(1, min(1000000, (int) ($filters['page'] ?? 1))) - 1) * $pageSize;
        $rows = $pdo->prepare(
            'SELECT q.*, v.revision, v.data_json, v.id AS version_id
               FROM sales_quotes q JOIN clients c ON c.id = q.client_id AND c.supplier_id = q.supplier_id
               JOIN sales_quote_versions v ON v.id = q.current_version_id AND v.quote_id = q.id AND v.supplier_id = q.supplier_id
              WHERE ' . $conditions . ' ORDER BY q.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset
        );
        $rows->execute($params);
        return [
            'items' => array_map($this->present(...), $rows->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function header(int $supplierId, int $id, bool $lock = false): array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM sales_quotes WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$supplierId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new QuoteException('quote_not_found', 'Nabídka nenalezena.', 404);
        }
        return $row;
    }

    public function version(array $header, ?int $versionId = null): array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM sales_quote_versions WHERE supplier_id = ? AND quote_id = ? AND id = ?');
        $query->execute([$header['supplier_id'], $header['id'], $versionId ?? $header['current_version_id']]);
        $version = $query->fetch(PDO::FETCH_ASSOC);
        if ($version === false) {
            throw new QuoteException('quote_version_not_found', 'Verze nabídky nenalezena.', 404);
        }
        return $version;
    }

    public function present(array $head, ?array $version = null): array
    {
        $version ??= [
            'id' => $head['version_id'], 'revision' => $head['revision'],
            'data_json' => $head['data_json'], 'status' => $head['status'],
        ];
        $current = (int) $head['current_version_id'] === (int) $version['id'];
        $data = self::decode($version['data_json']);
        $selected = $current ? self::decode($head['accepted_section_ids']) : [];
        if ($current) {
            $data['project_id'] = $head['project_id'] === null ? null : (int) $head['project_id'];
        }
        return [
            'id' => (int) $head['id'],
            'supplier_id' => (int) $head['supplier_id'],
            'number' => $head['quote_number'],
            'status' => self::status($version['status'], $data['valid_until']),
            'row_version' => (int) $head['row_version'],
            'version_id' => (int) $version['id'],
            'current_version_id' => (int) $head['current_version_id'],
            'revision' => (int) $version['revision'],
            'accepted_section_ids' => $selected,
            'created_at' => $head['created_at'],
            'issue_date' => substr($version['created_at'] ?? $head['created_at'], 0, 10),
            'totals' => QuoteContent::totals($data, $selected),
        ] + $data;
    }

    public static function status(string $status, string $validUntil): string
    {
        return $status === 'sent' && $validUntil < date('Y-m-d') ? 'expired' : $status;
    }

    public function revisions(int $supplierId, int $id): array
    {
        $query = $this->db->pdo()->prepare(
            'SELECT id, revision, status, created_at FROM sales_quote_versions WHERE supplier_id = ? AND quote_id = ? ORDER BY revision DESC'
        );
        $query->execute([$supplierId, $id]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function files(int $supplierId, int $id, int $versionId, ?int $fileId = null): array
    {
        $query = $this->db->pdo()->prepare(
            'SELECT f.id, f.name, f.mime_type, f.size_bytes, f.storage_key, vf.mode, vf.position
               FROM sales_quote_version_files vf JOIN sales_quote_files f ON f.id = vf.file_id
              WHERE f.supplier_id = ? AND f.quote_id = ? AND vf.version_id = ?'
            . ($fileId === null ? '' : ' AND f.id = ?') . ' ORDER BY vf.position, f.id'
        );
        $query->execute($fileId === null ? [$supplierId, $id, $versionId] : [$supplierId, $id, $versionId, $fileId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(int $supplierId, int $id): array
    {
        $query = $this->db->pdo()->prepare(
            'SELECT e.id, e.event, e.delivery_status, e.pdf_key IS NOT NULL AS has_pdf, e.data_json, e.version_id, e.created_at, u.name AS user_name
               FROM sales_quote_events e LEFT JOIN users u ON u.id = e.user_id
              WHERE e.supplier_id = ? AND e.quote_id = ? ORDER BY e.id DESC LIMIT 250'
        );
        $query->execute([$supplierId, $id]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['data'] = self::decode($row['data_json']);
            unset($row['data_json']);
        }
        return $rows;
    }

    public function event(int $supplierId, int $id, int $versionId, int $userId, string $event, array $data = []): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO sales_quote_events (supplier_id, quote_id, version_id, user_id, event, data_json) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $id, $versionId, $userId, $event, self::encode($data)]);
    }

    public function guardMutable(array $header): void
    {
        if ($header['status'] !== 'draft') {
            throw new QuoteException('quote_locked', 'Upravit lze pouze koncept nabídky.', 409);
        }
        $this->guardDelivery($header);
    }

    public function guardDelivery(array $header): void
    {
        $query = $this->db->pdo()->prepare(
            "SELECT 1 FROM sales_quote_events WHERE supplier_id = ? AND quote_id = ? AND delivery_status IN ('pending','deferred') LIMIT 1"
        );
        $query->execute([$header['supplier_id'], $header['id']]);
        if ($query->fetchColumn() !== false) {
            throw new QuoteException('quote_sending', 'Nabídka čeká na výsledek odeslání.', 409);
        }
    }

    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $data): array
    {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}
