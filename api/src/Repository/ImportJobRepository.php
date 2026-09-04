<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro import_jobs tabulku — background import joby (iDoklad, Fakturoid,
 * PDF inbox, AI extraction).
 *
 * Lifecycle: queued → running → completed | failed | cancelled.
 * Counters průběžně updatované workerem.
 */
final class ImportJobRepository
{
    /** Po této době nečinnosti (updated_at) považujeme queued/running job za mrtvý. */
    private const STALE_MINUTES = 15;

    public function __construct(private readonly Connection $db) {}

    /**
     * Označí "zaseknuté" joby jako failed — queued/running, jejichž worker už
     * neodpovídá (updated_at starší než STALE_MINUTES). Worker při každém kroku
     * touchne updated_at (progress/log), takže zmrzlý updated_at = mrtvý worker
     * (typicky selhal spawn na Windows, nebo proces spadl). Bez tohohle by mrtvý
     * job navždy blokoval spuštění nového (StartImportAction → "už běží").
     *
     * @return int počet uklizených jobů
     */
    public function reapStale(int $supplierId, ?string $source = null, int $staleMinutes = self::STALE_MINUTES): int
    {
        $sql = 'UPDATE import_jobs
                   SET status = "failed", finished_at = NOW(),
                       last_error = "Job ukončen jako neaktivní — worker neodpovídá (timeout). Spusť import znovu."
                 WHERE supplier_id = ?
                   AND status IN ("queued", "running")
                   AND updated_at < (NOW() - INTERVAL ? MINUTE)';
        $params = [$supplierId, $staleMinutes];
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $params[] = $source;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Vytvoří nový job se status='queued'.
     *
     * @param string $source 'idoklad'|'fakturoid'|'pdf_isdoc_inbox'|'pdf_ai'
     * @return int Job ID
     */
    public function create(int $supplierId, string $source, array $params, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO import_jobs (supplier_id, source, status, params, created_by)
             VALUES (?, ?, "queued", ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $source,
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Najdi job pro daný tenant. Vrátí null pokud neexistuje nebo cizí.
     */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM import_jobs WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Worker kontext — načte job napříč tenanty (supplier_id je v řádku). */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * List jobs pro tenant.
     *
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, ?string $source = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM import_jobs WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $params[] = $source;
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        $stmt->bindValue($idx, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => $this->cast($r), $rows);
    }

    public function hasAccountingRollbackFor(int $supplierId, int $appliedJobId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM import_jobs
              WHERE supplier_id = ? AND source = "accounting_history_reclassification"
                AND status NOT IN ("failed", "cancelled")
                AND CAST(JSON_VALUE(params, "$.rollback_of_job_id") AS UNSIGNED) = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $appliedJobId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Najdi nejstarší queued job — volá worker.
     */
    public function findNextQueued(?string $source = null): ?array
    {
        $sql = 'SELECT * FROM import_jobs WHERE status = "queued"';
        $params = [];
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $params[] = $source;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Atomický transition queued → running. Vrátí true pokud se podařilo
     * (jiný worker nás nemohl předběhnout — race-safe přes WHERE status='queued').
     */
    public function markRunning(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE import_jobs SET status = "running", started_at = NOW()
              WHERE id = ? AND status = "queued"'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Update progress (current_step, processed, counters). Worker volá průběžně.
     */
    public function updateProgress(int $id, array $updates): void
    {
        $allowed = ['total_items', 'processed', 'created_count', 'skipped_count', 'failed_count', 'current_step'];
        $sets = [];
        $params = [];
        foreach ($updates as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "$k = ?";
            $params[] = $v;
        }
        if (empty($sets)) return;
        $params[] = $id;
        $sql = 'UPDATE import_jobs SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Append řádku do log_text. Pro UI live tail.
     */
    public function appendLog(int $id, string $line): void
    {
        $ts = date('H:i:s');
        $entry = "[{$ts}] {$line}\n";
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET log_text = CONCAT(COALESCE(log_text, ""), ?) WHERE id = ?'
        )->execute([$entry, $id]);
    }

    /**
     * Ulož výsledný soubor jobu (jen export joby — result_path je relativní v rámci
     * storage/monthly-exports). Import joby tohle nepoužívají.
     */
    public function setResult(int $id, string $relPath, string $name, int $size, string $mime): void
    {
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET result_path = ?, result_name = ?, result_size = ?, result_mime = ? WHERE id = ?'
        )->execute([$relPath, $name, $size, $mime, $id]);
    }

    public function markCompleted(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET status = "completed", finished_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * EP-6: „hotovo s upozorněními" — všechny POVINNÉ části uspěly, ale některé
     * doplňkové části selhaly (viz ClosingPackageService). Výsledek je ke stažení,
     * ale uživatel vidí počet selhání + log chybějících částí.
     */
    public function markCompletedWithWarnings(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET status = "completed_with_warnings", finished_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET status = "failed", finished_at = NOW(), last_error = ? WHERE id = ?'
        )->execute([$error, $id]);
    }

    public function markCancelled(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET status = "cancelled", finished_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Request cancellation.
     *
     * - **queued** (worker ještě nenaběhl) nebo **running se zmrzlým workerem**
     *   (updated_at starší než STALE_MINUTES) → rovnou `cancelled`. Tím se uvolní
     *   patová situace, kdy mrtvý job blokuje nový start a graceful cancel nikdo
     *   nepřečte (worker neběží).
     * - **aktivně running** (čerstvý updated_at) → jen `cancel_requested=1`, worker
     *   si flag periodicky přečte a graceful skončí.
     */
    public function requestCancel(int $id, int $supplierId): bool
    {
        // Okamžité zrušení pro queued / stale running (žádný živý worker).
        $immediate = $this->db->pdo()->prepare(
            'UPDATE import_jobs
                SET status = "cancelled", finished_at = NOW(), cancel_requested = 1
              WHERE id = ? AND supplier_id = ?
                AND (status = "queued"
                     OR (status = "running" AND updated_at < (NOW() - INTERVAL ? MINUTE)))'
        );
        $immediate->execute([$id, $supplierId, self::STALE_MINUTES]);
        if ($immediate->rowCount() === 1) return true;

        // Graceful pro aktivně běžící worker.
        $graceful = $this->db->pdo()->prepare(
            'UPDATE import_jobs SET cancel_requested = 1
              WHERE id = ? AND supplier_id = ? AND status = "running"'
        );
        $graceful->execute([$id, $supplierId]);
        return $graceful->rowCount() === 1;
    }

    /**
     * Worker periodicky volá pro graceful exit check.
     */
    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT cancel_requested FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Ruční smazání jobu (escape hatch pro uživatele). Scope na tenanta.
     * Povoleno v jakémkoli stavu — i "running" (mrtvý worker by stejně psal do 0 řádků).
     */
    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM import_jobs WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    private function cast(array $row): array
    {
        $row['id']            = (int) $row['id'];
        $row['supplier_id']   = (int) $row['supplier_id'];
        $row['created_by']    = (int) $row['created_by'];
        $row['processed']     = (int) $row['processed'];
        $row['created_count'] = (int) $row['created_count'];
        $row['skipped_count'] = (int) $row['skipped_count'];
        $row['failed_count']  = (int) $row['failed_count'];
        $row['cancel_requested'] = (bool) $row['cancel_requested'];
        if ($row['total_items'] !== null) $row['total_items'] = (int) $row['total_items'];
        if (array_key_exists('result_size', $row) && $row['result_size'] !== null) {
            $row['result_size'] = (int) $row['result_size'];
        }
        if ($row['params'] !== null) {
            $decoded = json_decode((string) $row['params'], true);
            $row['params'] = is_array($decoded) ? $decoded : null;
        }
        return $row;
    }
}
