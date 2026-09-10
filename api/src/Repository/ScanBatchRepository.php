<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Dávky připojení skenů: soubory dávky (`scan_batch_items`) a výsledky párování
 * (`scan_matches`). Dávka sama je řádek `import_jobs` se zdrojem `scan_attach`.
 */
final class ScanBatchRepository
{
    public const SOURCE = 'scan_attach';

    private const ITEM_FIELDS = ['document_id', 'status', 'ownership', 'outcome', 'error'];

    public function __construct(private readonly Connection $db) {}

    // ── soubory dávky ──────────────────────────────────────────────────────────

    public function itemIdBySha(int $supplierId, int $jobId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM scan_batch_items WHERE supplier_id = ? AND job_id = ? AND sha256 = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $jobId, $sha256]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Idempotentní: stejný obsah v téže dávce vrátí existující řádek. */
    public function insertItem(int $supplierId, int $jobId, string $fileName, string $sha256, int $size, ?int $documentId, string $status, ?string $error): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO scan_batch_items (supplier_id, job_id, file_name, sha256, size_bytes, document_id, status, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $jobId, mb_substr($fileName, 0, 255), $sha256, $size, $documentId, $status,
            $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
        return $this->itemIdBySha($supplierId, $jobId, $sha256) ?? 0;
    }

    /** @param array<string,mixed> $fields */
    public function updateItem(int $supplierId, int $itemId, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, self::ITEM_FIELDS, true)) {
                continue;
            }
            $sets[] = "$k = ?";
            $params[] = $k === 'error' && is_string($v) ? mb_substr($v, 0, 500) : $v;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $itemId;
        $params[] = $supplierId;
        $this->db->pdo()->prepare(
            'UPDATE scan_batch_items SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        )->execute($params);
    }

    /**
     * Soubory dávky s údaji o uloženém dokumentu (cesta k souboru na disku).
     *
     * @return list<array<string,mixed>>
     */
    public function listItems(int $supplierId, int $jobId, ?array $outcomes = null, ?int $limit = null): array
    {
        $sql = 'SELECT i.*, d.filename AS doc_filename, d.original_name AS doc_original_name
                  FROM scan_batch_items i
             LEFT JOIN documents d ON d.id = i.document_id AND d.supplier_id = i.supplier_id AND d.deleted_at IS NULL
                 WHERE i.supplier_id = ? AND i.job_id = ?';
        $params = [$supplierId, $jobId];
        if ($outcomes !== null && $outcomes !== []) {
            $sql .= ' AND i.outcome IN (' . implode(',', array_fill(0, count($outcomes), '?')) . ')';
            array_push($params, ...$outcomes);
        }
        $sql .= ' ORDER BY i.id' . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(self::castItem(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findItem(int $supplierId, int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.*, d.filename AS doc_filename, d.original_name AS doc_original_name
               FROM scan_batch_items i
          LEFT JOIN documents d ON d.id = i.document_id AND d.supplier_id = i.supplier_id AND d.deleted_at IS NULL
              WHERE i.supplier_id = ? AND i.id = ?'
        );
        $stmt->execute([$supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castItem($row);
    }

    /** @return array<string,int> outcome → počet */
    public function countItemsByOutcome(int $supplierId, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT outcome, COUNT(*) AS n FROM scan_batch_items WHERE supplier_id = ? AND job_id = ? GROUP BY outcome'
        );
        $stmt->execute([$supplierId, $jobId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['outcome']] = (int) $r['n'];
        }
        return $out;
    }

    /** Dokument sekce Dokumenty se stejným obsahem (deduplikace podle sha256). */
    public function documentIdBySha(int $supplierId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM documents WHERE supplier_id = ? AND sha256 = ? AND deleted_at IS NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    // ── výsledky párování ──────────────────────────────────────────────────────

    /**
     * @param list<string>|null $states
     * @return list<array<string,mixed>>
     */
    public function listMatches(int $supplierId, int $jobId, ?array $states = null, ?int $limit = null): array
    {
        $sql = 'SELECT m.*, i.file_name, i.document_id, i.sha256
                  FROM scan_matches m
                  JOIN scan_batch_items i ON i.id = m.item_id AND i.supplier_id = m.supplier_id
                 WHERE m.supplier_id = ? AND m.job_id = ?';
        $params = [$supplierId, $jobId];
        if ($states !== null && $states !== []) {
            $sql .= ' AND m.state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
            array_push($params, ...$states);
        }
        $sql .= ' ORDER BY m.id' . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(self::castMatch(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,int> state → počet */
    public function countMatchesByState(int $supplierId, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT state, COUNT(*) AS n FROM scan_matches WHERE supplier_id = ? AND job_id = ? GROUP BY state'
        );
        $stmt->execute([$supplierId, $jobId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['state']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findMatch(int $supplierId, int $matchId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.*, i.file_name, i.document_id, i.sha256
               FROM scan_matches m
               JOIN scan_batch_items i ON i.id = m.item_id AND i.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.id = ?'
        );
        $stmt->execute([$supplierId, $matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castMatch($row);
    }

    /**
     * Uloží výsledek párování. Rozhodnutý pár (připojený, potvrzený, odmítnutý)
     * se opakovaným během nepřepíše.
     */
    public function upsertMatch(int $supplierId, int $jobId, int $itemId, string $targetType, int $targetId, string $method, string $level, int $score, string $state, string $note): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO scan_matches (supplier_id, job_id, item_id, target_type, target_id, method, level, score, state, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                method = IF(state IN ('attached','confirmed','rejected'), method, VALUES(method)),
                level  = IF(state IN ('attached','confirmed','rejected'), level, VALUES(level)),
                score  = IF(state IN ('attached','confirmed','rejected'), score, VALUES(score)),
                note   = IF(state IN ('attached','confirmed','rejected'), note, VALUES(note)),
                state  = IF(state IN ('attached','confirmed','rejected'), state, VALUES(state))"
        )->execute([$supplierId, $jobId, $itemId, $targetType, $targetId, $method, $level, $score, $state, mb_substr($note, 0, 500)]);
    }

    /** Návrhy čekající na potvrzení se při novém párování počítají znovu. */
    public function deleteProposed(int $supplierId, int $jobId): void
    {
        $this->db->pdo()->prepare(
            "DELETE FROM scan_matches WHERE supplier_id = ? AND job_id = ? AND state = 'proposed'"
        )->execute([$supplierId, $jobId]);
    }

    public function setMatchState(int $supplierId, int $matchId, string $state, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE scan_matches SET state = ?, decided_by = ?, decided_at = NOW() WHERE supplier_id = ? AND id = ?'
        )->execute([$state, $userId, $supplierId, $matchId]);
    }

    /**
     * Po potvrzení páru odmítne ostatní návrhy pro týž doklad i pro týž soubor
     * (kandidáti ze shody nerozhodnutelné mezi víc skeny nebo doklady).
     *
     * @return list<int> soubory, kterých se odmítnutí týkalo
     */
    public function rejectCompetingProposals(int $supplierId, int $jobId, int $exceptMatchId, string $targetType, int $targetId, int $itemId, ?int $userId): array
    {
        $params = [$supplierId, $jobId, $exceptMatchId, $targetType, $targetId, $itemId];
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT item_id FROM scan_matches
              WHERE supplier_id = ? AND job_id = ? AND state = 'proposed' AND id <> ?
                AND ((target_type = ? AND target_id = ?) OR item_id = ?)"
        );
        $stmt->execute($params);
        $items = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->db->pdo()->prepare(
            "UPDATE scan_matches SET state = 'rejected', decided_by = ?, decided_at = NOW()
              WHERE supplier_id = ? AND job_id = ? AND state = 'proposed' AND id <> ?
                AND ((target_type = ? AND target_id = ?) OR item_id = ?)"
        )->execute([$userId, ...$params]);
        return $items;
    }

    /** @return list<string> stavy párů daného souboru */
    public function matchStatesForItem(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT state FROM scan_matches WHERE supplier_id = ? AND item_id = ?');
        $stmt->execute([$supplierId, $itemId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Smaže dávku (soubory v sekci Dokumenty i jejich vazby na doklady zůstávají). */
    public function deleteBatch(int $supplierId, int $jobId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM scan_matches WHERE supplier_id = ? AND job_id = ?')->execute([$supplierId, $jobId]);
        $pdo->prepare('DELETE FROM scan_batch_items WHERE supplier_id = ? AND job_id = ?')->execute([$supplierId, $jobId]);
    }

    // ── dávka (import_jobs) a identita firmy ─────────────────────────────────

    /**
     * Vrátí dávku do fronty — navázání po pádu, zrušení nebo nové párování po
     * doplnění dokladů. Běh je idempotentní, hotové kroky se přeskočí.
     */
    public function requeue(int $supplierId, int $jobId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE import_jobs
                SET status = 'queued', cancel_requested = 0, finished_at = NULL, last_error = NULL
              WHERE id = ? AND supplier_id = ? AND source = ?
                AND status IN ('failed', 'cancelled', 'completed', 'completed_with_warnings')"
        );
        $stmt->execute([$jobId, $supplierId, self::SOURCE]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{ico:?string,name:?string} */
    public function supplierIdentity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, company_name FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'ico'  => isset($row['ic']) && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'name' => isset($row['company_name']) && $row['company_name'] !== '' ? (string) $row['company_name'] : null,
        ];
    }

    /**
     * SPZ vozidel firmy z knihy jízd — účtenka za PHM nebo parkování odběratele
     * neuvádí, přiřadí se podle SPZ.
     *
     * @return list<string>
     */
    public function ownPlates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT registration FROM cars WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castItem(array $row): array
    {
        foreach (['id', 'supplier_id', 'job_id', 'size_bytes'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['document_id'] = $row['document_id'] !== null ? (int) $row['document_id'] : null;
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castMatch(array $row): array
    {
        foreach (['id', 'supplier_id', 'job_id', 'item_id', 'target_id', 'score'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['document_id'] = $row['document_id'] !== null ? (int) $row['document_id'] : null;
        $row['decided_by'] = $row['decided_by'] !== null ? (int) $row['decided_by'] : null;
        return $row;
    }
}
