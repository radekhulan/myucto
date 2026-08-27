<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AccountingBackfillJobRepository
{
    private const STALE_MINUTES = 15;

    /**
     * Kolik minut se čeká na worker, který se ještě ani nepřihlásil.
     *
     * ⚠️ Kratší než {@see self::STALE_MINUTES} schválně. Worker si job vezme
     * (`queued` → `running`) během vteřin; když to neudělá, nespustil se vůbec
     * a čekat na něj čtvrt hodiny znamená čtvrt hodiny hlásit „Čeká" u něčeho,
     * co je dávno mrtvé. Přesně tohle se stalo, když se worker spouštěl starší
     * verzí PHP: umřel na první řádce a obrazovka o tom nevěděla.
     */
    private const UNCLAIMED_MINUTES = 2;

    public function __construct(private readonly Connection $db) {}

    public function create(int $supplierId, string $kind, array $params, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO accounting_backfill_jobs (supplier_id, kind, params, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $kind, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_backfill_jobs WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_backfill_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function listForTenant(int $supplierId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_backfill_jobs WHERE supplier_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $row) => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function paginateForTenant(int $supplierId, int $limit, int $offset): array
    {
        $pdo = $this->db->pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM accounting_backfill_jobs WHERE supplier_id = ?');
        $count->execute([$supplierId]);
        $stmt = $pdo->prepare(
            'SELECT * FROM accounting_backfill_jobs WHERE supplier_id = ? ORDER BY id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function activeForTenant(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM accounting_backfill_jobs WHERE supplier_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function lastForTenant(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_backfill_jobs WHERE supplier_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function completedDryRun(int $supplierId, string $startsOn, string $openingHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM accounting_backfill_jobs
              WHERE supplier_id = ? AND kind = 'dry_run' AND status = 'completed'
                AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.starts_on')) = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.opening_hash')) = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $startsOn, $openingHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function markRunning(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE accounting_backfill_jobs SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'queued'"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    public function updateProgress(int $id, string $phase, int $processed, ?int $totalItems = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE accounting_backfill_jobs SET phase = ?, processed = ?, total_items = COALESCE(?, total_items) WHERE id = ?'
        )->execute([$phase, $processed, $totalItems, $id]);
    }

    public function appendLog(int $id, string $line): void
    {
        $entry = '[' . date('H:i:s') . '] ' . rtrim($line, "\r\n") . "\n";
        $this->db->pdo()->prepare(
            'UPDATE accounting_backfill_jobs SET log_text = CONCAT(COALESCE(log_text, ""), ?) WHERE id = ?'
        )->execute([$entry, $id]);
    }

    public function saveReport(int $id, array $report): void
    {
        $this->db->pdo()->prepare('UPDATE accounting_backfill_jobs SET report_json = ? WHERE id = ?')
            ->execute([json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    public function markCompleted(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE accounting_backfill_jobs SET status = 'completed', finished_at = NOW() WHERE id = ?"
        )->execute([$id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->pdo()->prepare(
            "UPDATE accounting_backfill_jobs SET status = 'failed', finished_at = NOW(), last_error = ? WHERE id = ?"
        )->execute([$error, $id]);
    }

    public function markCancelled(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE accounting_backfill_jobs SET status = 'cancelled', finished_at = NOW() WHERE id = ?"
        )->execute([$id]);
    }

    public function requestCancel(int $id, int $supplierId): bool
    {
        $immediate = $this->db->pdo()->prepare(
            "UPDATE accounting_backfill_jobs
                SET status = 'cancelled', finished_at = NOW(), cancel_requested = 1
              WHERE id = ? AND supplier_id = ?
                AND (status = 'queued' OR (status = 'running' AND updated_at < (NOW() - INTERVAL ? MINUTE)))"
        );
        $immediate->execute([$id, $supplierId, self::STALE_MINUTES]);
        if ($immediate->rowCount() === 1) return true;

        $stmt = $this->db->pdo()->prepare(
            "UPDATE accounting_backfill_jobs SET cancel_requested = 1 WHERE id = ? AND supplier_id = ? AND status = 'running'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT cancel_requested FROM accounting_backfill_jobs WHERE id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public function reapStale(int $supplierId, int $staleMinutes = self::STALE_MINUTES): int
    {
        // Rozlišujeme „worker se vůbec nepřihlásil" od „přihlásil se a zamrzl":
        // první případ je jistota, druhý může být pomalý běh nad velkou historií.
        $stmt = $this->db->pdo()->prepare(
            // ⚠️ `last_error` se přiřazuje PŘED `status`. MySQL vyhodnocuje SET
            // zleva doprava nad už změněnými hodnotami, takže v opačném pořadí
            // by CASE četl `failed`, které si právě nastavilo samo, a hláška by
            // vždycky vyšla na tu druhou větev.
            "UPDATE accounting_backfill_jobs
                SET last_error = CASE WHEN status = 'queued'
                        THEN 'Doúčtování se nerozběhlo — worker se vůbec nespustil. Zkuste je prosím spustit znovu; když to nepomůže, ozvěte se podpoře.'
                        ELSE 'Doúčtování bylo ukončeno jako neaktivní — worker neodpovídá. Spusťte je znovu.'
                    END,
                    status = 'failed', finished_at = NOW()
              WHERE supplier_id = ?
                AND (
                    (status = 'queued'  AND updated_at < (NOW() - INTERVAL ? MINUTE))
                    OR (status = 'running' AND updated_at < (NOW() - INTERVAL ? MINUTE))
                )"
        );
        $stmt->execute([$supplierId, min(self::UNCLAIMED_MINUTES, $staleMinutes), $staleMinutes]);
        if ($stmt->rowCount() > 0) {
            $this->db->pdo()->prepare(
                "UPDATE supplier SET accounting_activation_status = 'failed'
                  WHERE id = ? AND accounting_activation_status = 'running'"
            )->execute([$supplierId]);
        }
        return $stmt->rowCount();
    }

    private function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'created_by', 'processed'] as $field) $row[$field] = (int) $row[$field];
        $row['total_items'] = $row['total_items'] === null ? null : (int) $row['total_items'];
        $row['cancel_requested'] = (bool) $row['cancel_requested'];
        foreach (['params', 'report_json'] as $field) {
            if ($row[$field] !== null) {
                $decoded = json_decode((string) $row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : null;
            }
        }
        return $row;
    }
}
