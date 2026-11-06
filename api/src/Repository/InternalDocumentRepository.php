<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Interní doklady (migrace 2193): hlavička nad ručním zápisem deníku. Každý dotaz
 * začíná omezením na firmu a jde po složeném indexu `(supplier_id, …)`.
 */
final class InternalDocumentRepository
{
    /** Řazení seznamu: klíč z requestu → sloupec. Neznámý klíč = výchozí řazení. */
    private const SORT_COLUMNS = [
        'document_no' => 'd.document_no',
        'document_date' => 'd.document_date',
        'entry_date' => 'd.entry_date',
        'title' => 'd.title',
        'partner_name' => 'd.partner_name',
        'amount' => 'd.amount',
        'status' => 'd.status',
    ];

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.* FROM internal_documents d
              WHERE d.supplier_id = ? AND d.id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $row = self::hydrate($row);
        $row['lines'] = $this->lines($supplierId, $id);
        return $row;
    }

    /** Doklad, jehož zaúčtováním nebo stornem je daný zápis deníku. */
    public function findByEntry(int $supplierId, int $entryId, bool $lock = false): ?array
    {
        // Zamykací čtení po jednotlivých indexech: souběžná hlavička nad týmž zápisem
        // (from-entry) a storno nebo smazání zápisu v deníku se tak serializují.
        $suffix = $lock ? ' FOR UPDATE' : '';
        foreach (['journal_entry_id', 'reversal_entry_id'] as $column) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM internal_documents WHERE supplier_id = ? AND {$column} = ? LIMIT 1" . $suffix
            );
            $stmt->execute([$supplierId, $entryId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) return $this->find($supplierId, (int) $id, $lock);
        }
        return null;
    }

    /**
     * Odkazy deníku na interní doklady pro stránku zápisů - jeden dotaz na stránku.
     *
     * @param list<int> $entryIds
     * @return array<int, array{id:int, document_no:?string, status:string, role:string}>
     */
    public function forJournalEntries(int $supplierId, array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) return [];
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT journal_entry_id AS entry_id, id, document_no, status, 'posting' AS role
               FROM internal_documents
              WHERE supplier_id = ? AND journal_entry_id IN ({$in})
             UNION ALL
             SELECT reversal_entry_id, id, document_no, status, 'reversal'
               FROM internal_documents
              WHERE supplier_id = ? AND reversal_entry_id IN ({$in})"
        );
        $stmt->execute([$supplierId, ...$entryIds, $supplierId, ...$entryIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['entry_id']] = [
                'id' => (int) $row['id'],
                'document_no' => $row['document_no'] !== null ? (string) $row['document_no'] : null,
                'status' => (string) $row['status'],
                'role' => (string) $row['role'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT position, account_code, side, amount, is_red_storno, cost_center, dimensions_json
               FROM internal_document_lines
              WHERE supplier_id = ? AND internal_document_id = ?
              ORDER BY position'
        );
        $stmt->execute([$supplierId, $id]);
        return array_map(static function (array $row): array {
            $dims = $row['dimensions_json'] !== null ? json_decode((string) $row['dimensions_json'], true) : null;
            return [
                'account_code' => (string) $row['account_code'],
                'side' => (string) $row['side'],
                'amount' => (float) $row['amount'],
                'is_red_storno' => (bool) $row['is_red_storno'],
                'cost_center' => $row['cost_center'] !== null ? (string) $row['cost_center'] : null,
                'dimensions' => is_array($dims) ? $dims : [],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string,mixed>> $lines */
    public function replaceLines(int $supplierId, int $id, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM internal_document_lines WHERE supplier_id = ? AND internal_document_id = ?')
            ->execute([$supplierId, $id]);
        $stmt = $pdo->prepare(
            'INSERT INTO internal_document_lines
               (supplier_id, internal_document_id, position, account_code, side, amount,
                is_red_storno, cost_center, dimensions_json)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        foreach (array_values($lines) as $position => $line) {
            $dims = $line['dimensions'] ?? [];
            $stmt->execute([
                $supplierId, $id, $position + 1, $line['account_code'], $line['side'], $line['amount'],
                !empty($line['is_red_storno']) ? 1 : 0, $line['cost_center'] ?? null,
                $dims !== [] ? json_encode((object) $dims, JSON_THROW_ON_ERROR) : null,
            ]);
        }
    }

    /**
     * Stránkovaný seznam. Filtry: status, from/to (datum dokladu), partner_id, kind, q.
     *
     * @return array{items: list<array<string,mixed>>, total: int, page: int, per_page: int, totals: array<string,float>}
     */
    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        [$whereSql, $params] = self::where($supplierId, $filters);
        $pdo = $this->db->pdo();
        $count = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN d.status = 'posted' THEN d.amount ELSE 0 END), 0) AS posted_amount
               FROM internal_documents d WHERE {$whereSql}"
        );
        $count->execute($params);
        $summary = $count->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'posted_amount' => 0];
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            "SELECT d.* FROM internal_documents d
              WHERE {$whereSql}
              ORDER BY " . self::orderBy($filters) . '
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        $items = array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $counts = $this->documentCounts($supplierId, array_map(static fn (array $row): int => (int) $row['id'], $items));
        foreach ($items as &$item) {
            $item['document_count'] = $counts[(int) $item['id']] ?? 0;
        }
        unset($item);
        return [
            'items' => $items,
            'total' => (int) $summary['cnt'],
            'page' => $page,
            'per_page' => $perPage,
            'totals' => ['posted_amount' => round((float) $summary['posted_amount'], 2)],
        ];
    }

    /**
     * Export po dávkách (keyset přes id), aby celý rok dokladů neskončil v jednom poli.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    public function exportRows(int $supplierId, array $filters, int $batch = 500): \Generator
    {
        [$whereSql, $params] = self::where($supplierId, $filters);
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.* FROM internal_documents d
              WHERE {$whereSql} AND d.id > ?
              ORDER BY d.id
              LIMIT " . (int) $batch
        );
        $lastId = 0;
        do {
            $stmt->execute([...$params, $lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield self::hydrate($row);
            }
        } while (count($rows) === $batch);
    }

    public function insert(int $supplierId, array $data, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO internal_documents
               (supplier_id, kind, title, document_date, entry_date, partner_id, partner_name, amount,
                note, document_links_json, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $supplierId, $data['kind'], $data['title'], $data['document_date'], $data['entry_date'],
            $data['partner_id'], $data['partner_name'], $data['amount'], $data['note'],
            self::encodeLinks($data['document_links']), $userId, $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param bool $clearNumber číslo z dřívějšího zaúčtování patří jinému účetnímu roku */
    public function updateDraft(int $supplierId, int $id, array $data, ?int $userId, bool $clearNumber = false): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE internal_documents
                SET kind = ?, title = ?, document_date = ?, entry_date = ?, partner_id = ?, partner_name = ?,
                    amount = ?, note = ?, document_links_json = ?, updated_by = ?, row_version = row_version + 1"
            . ($clearNumber ? ', document_no = NULL' : '') . "
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([
            $data['kind'], $data['title'], $data['document_date'], $data['entry_date'], $data['partner_id'],
            $data['partner_name'], $data['amount'], $data['note'], self::encodeLinks($data['document_links']),
            $userId, $id, $supplierId,
        ]);
    }

    /** Číslo už nese jiný interní doklad firmy (unikát uq_internal_document_no). */
    public function documentNoTaken(int $supplierId, string $documentNo, ?int $exceptId = null): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM internal_documents WHERE supplier_id = ? AND document_no = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $documentNo, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    public function deleteDraft(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM internal_documents WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    public function setPosted(int $supplierId, int $id, string $documentNo, int $entryId, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE internal_documents
                SET status = 'posted', document_no = ?, journal_entry_id = ?, posted_at = NOW(), posted_by = ?,
                    updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        )->execute([$documentNo, $entryId, $userId, $userId, $id, $supplierId]);
    }

    /** Zápis smazaný v deníku vrací doklad do konceptu; číslo zůstává pro nové zaúčtování. */
    public function setUnposted(int $supplierId, int $id, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE internal_documents
                SET status = 'draft', journal_entry_id = NULL, posted_at = NULL, posted_by = NULL,
                    updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = 'posted'"
        )->execute([$userId, $id, $supplierId]);
    }

    public function setReversed(int $supplierId, int $id, int $reversalEntryId, string $reason, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE internal_documents
                SET status = 'reversed', reversal_entry_id = ?, reversal_reason = ?, reversed_at = NOW(),
                    updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = 'posted'"
        )->execute([$reversalEntryId, $reason, $userId, $id, $supplierId]);
    }

    /** Hlavička nad už existujícím ručním zápisem (akce „Vytvořit interní doklad ze zápisu"). */
    public function insertPosted(int $supplierId, array $data, string $documentNo, int $entryId, ?string $postedAt, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO internal_documents
               (supplier_id, kind, title, document_date, entry_date, partner_id, partner_name, amount, note,
                document_links_json, status, origin, document_no, journal_entry_id, posted_at, posted_by, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,NULL,'posted','journal',?,?,COALESCE(?, NOW()),?,?,?)"
        );
        $stmt->execute([
            $supplierId, $data['kind'], $data['title'], $data['document_date'], $data['entry_date'],
            $data['partner_id'], $data['partner_name'], $data['amount'], $data['note'],
            $documentNo, $entryId, $postedAt, $userId, $userId, $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function documentCounts(int $supplierId, array $ids): array
    {
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT dl.entity_id, COUNT(*)
               FROM document_links dl
              WHERE dl.supplier_id = ? AND dl.entity_type = 'internal_document' AND dl.entity_id IN ({$in})
              GROUP BY dl.entity_id"
        );
        $stmt->execute([$supplierId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array{0:string, 1:list<mixed>} */
    private static function where(int $supplierId, array $filters): array
    {
        $where = ['d.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = 'd.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['kind'])) {
            $where[] = 'd.kind = ?';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['partner_id'])) {
            $where[] = 'd.partner_id = ?';
            $params[] = (int) $filters['partner_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'd.document_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'd.document_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(d.document_no LIKE ? OR d.title LIKE ? OR d.partner_name LIKE ? OR d.note LIKE ?)';
            $term = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            array_push($params, $term, $term, $term, $term);
        }
        return [implode(' AND ', $where), $params];
    }

    private static function orderBy(array $filters): string
    {
        $column = self::SORT_COLUMNS[$filters['sort_by'] ?? ''] ?? null;
        if ($column === null) return 'd.document_date DESC, d.id DESC';
        $direction = ($filters['sort_dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        return $column . ' ' . $direction . ', d.id ' . $direction;
    }

    private static function hydrate(array $row): array
    {
        foreach (['id', 'supplier_id', 'partner_id', 'journal_entry_id', 'reversal_entry_id', 'posted_by',
            'created_by', 'updated_by', 'row_version'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int) $row[$key];
        }
        $row['amount'] = (float) $row['amount'];
        $links = $row['document_links_json'] !== null ? json_decode((string) $row['document_links_json'], true) : null;
        $row['document_links'] = is_array($links) ? $links : [];
        unset($row['document_links_json']);
        return $row;
    }

    private static function encodeLinks(array $links): ?string
    {
        return $links === [] ? null : json_encode(array_values($links), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
