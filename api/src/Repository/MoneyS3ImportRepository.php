<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use PDO;

/**
 * Převody z Money S3: běhy průvodce s protokolem (`money_s3_imports`) a mapa „co už
 * z agendy v MyÚčtu vzniklo" (`money_s3_import_map`, migrace 1806).
 *
 * Mapa je nosič idempotence — opakovaný import nezaloží nic, co už v mapě je.
 * Všechno je tenantové: stejná agenda nahraná do jiné firmy má vlastní mapu.
 *
 * Převod jedné firmy smí běžet jen jednou naráz ({@see acquireLock()}): dva běhy nad
 * toutéž mapou by založily tytéž doklady dvakrát.
 */
final class MoneyS3ImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_POSTING_RULE = 'posting_rule';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_INVOICE = 'invoice';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';

    /** Jméno zámku je na serveru globální — obsahuje proto i databázi (instalace sdílí server). */
    private const LOCK_SQL = "CONCAT('money_s3:', DATABASE(), ':', ?)";

    public function __construct(private readonly Connection $db) {}

    public function get(int $supplierId, string $kind, string $key): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT target_id FROM money_s3_import_map WHERE supplier_id = ? AND kind = ? AND money_key = ?'
        );
        $stmt->execute([$supplierId, $kind, self::key($key)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,int> money_key => target_id */
    public function all(int $supplierId, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT money_key, target_id FROM money_s3_import_map WHERE supplier_id = ? AND kind = ? ORDER BY money_key'
        );
        $stmt->execute([$supplierId, $kind]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['money_key']] = (int) $r['target_id'];
        }
        return $out;
    }

    /**
     * Zápis do mapy. Klíč, který už v mapě je, je chyba: znamená, že tentýž záznam
     * z Money založil v MyÚčtu dva doklady (souběžný běh nebo chyba kroku). Tiché
     * přepsání cíle by první doklad z mapy vyřadilo a další běh by ho založil znovu.
     */
    public function put(int $supplierId, string $kind, string $key, int $targetId, ?int $runId): void
    {
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id, run_id) VALUES (?, ?, ?, ?, ?)'
            )->execute([$supplierId, $kind, self::key($key), $targetId, $runId]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            throw new MoneyS3Exception('map_conflict', "Záznam {$kind} {$key} z Money už v MyÚčtu převedený je — převod se zastavil, aby nic nezdvojil.");
        }
    }

    public function countAll(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM money_s3_import_map WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $meta */
    public function startRun(int $supplierId, ?int $jobId, string $mode, array $meta, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO money_s3_imports
                (supplier_id, job_id, mode, status, agenda_ico, agenda_name, money_version, backup_sha256, created_by)
             VALUES (?, ?, ?, "running", ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $jobId,
            $mode === 'import' ? 'import' : 'dry_run',
            self::str($meta['agenda_ico'] ?? null, 20),
            self::str($meta['agenda_name'] ?? null, 190),
            self::str($meta['money_version'] ?? null, 20),
            self::str($meta['backup_sha256'] ?? null, 64),
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $protocol */
    public function finishRun(int $id, int $supplierId, string $status, array $protocol): void
    {
        $json = json_encode($protocol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->db->pdo()->prepare(
            'UPDATE money_s3_imports SET status = ?, protocol = ?, finished_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([$status, $json === false ? null : $json, $id, $supplierId]);
    }

    /**
     * Běhy, které zůstaly „running", ale žádný worker je už nedrží (spadl, byl ukončen
     * pro nečinnost). Volá se se zámkem firmy ({@see acquireLock()}) — kdo ho drží, je
     * jediný živý převod, takže každý jiný „running" řádek je mrtvý.
     */
    public function closeInterruptedRuns(int $supplierId): int
    {
        $protocol = json_encode(['status' => 'failed', 'failure' => 'interrupted', 'error' => 'Převod byl přerušen (worker neodpovídá).', 'steps' => []], JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE money_s3_imports
                SET status = 'failed', finished_at = NOW(), protocol = COALESCE(protocol, ?)
              WHERE supplier_id = ? AND status = 'running'"
        );
        $stmt->execute([$protocol, $supplierId]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, job_id, mode, status, agenda_ico, agenda_name, money_version,
                    backup_sha256, protocol, created_by, created_at, finished_at
               FROM money_s3_imports WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::cast($row);
        $decoded = $row['protocol'] !== null ? json_decode((string) $row['protocol'], true) : null;
        $row['protocol'] = is_array($decoded) ? $decoded : null;
        return $row;
    }

    /** @return list<array<string,mixed>> běhy bez protokolu (ten je velký — stahuje se v detailu) */
    public function listRuns(int $supplierId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, job_id, mode, status, agenda_ico, agenda_name, money_version,
                    backup_sha256, created_by, created_at, finished_at
               FROM money_s3_imports
              WHERE supplier_id = ?
              ORDER BY id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stav automatiky, na který se má vrátit: snímek NEJSTARŠÍHO ostrého běhu, po kterém
     * se automatika ještě neobnovila. Novější neobnovené běhy už snímaly automatiku
     * vypnutou po předchozím neúspěšném (nebo spadlém) běhu.
     *
     * Snímek se ukládá před vypnutím automatiky ({@see saveAutomationSnapshot()}), ne až
     * s protokolem na konci běhu — spadlý worker protokol nezapíše.
     *
     * @return array<string,mixed>|null
     */
    public function pendingAutomationSnapshot(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT automation_snapshot FROM money_s3_imports
              WHERE supplier_id = ? AND mode = 'import'
                AND automation_snapshot IS NOT NULL AND automation_restored_at IS NULL
              ORDER BY id
              LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) {
            return null;
        }
        $snapshot = json_decode($json, true);
        return is_array($snapshot) ? $snapshot : null;
    }

    /** @param array<string,mixed> $snapshot */
    public function saveAutomationSnapshot(int $runId, int $supplierId, array $snapshot): void
    {
        $this->db->pdo()->prepare(
            'UPDATE money_s3_imports SET automation_snapshot = ?
              WHERE id = ? AND supplier_id = ? AND automation_snapshot IS NULL'
        )->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE), $runId, $supplierId]);
    }

    /** Automatika je zpět — žádný dosavadní snímek firmy už nečeká na obnovení. */
    public function markAutomationRestored(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE money_s3_imports SET automation_restored_at = NOW()
              WHERE supplier_id = ? AND automation_snapshot IS NOT NULL AND automation_restored_at IS NULL'
        )->execute([$supplierId]);
    }

    /**
     * Zámek převodu firmy (MariaDB named lock, drží ho spojení workeru a uvolní se
     * i při pádu procesu). Neblokuje: druhý běh se odmítne, nečeká.
     */
    public function acquireLock(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT GET_LOCK(' . self::LOCK_SQL . ', 0)');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function releaseLock(int $supplierId): void
    {
        $this->db->pdo()->prepare('SELECT RELEASE_LOCK(' . self::LOCK_SQL . ')')->execute([$supplierId]);
    }

    /** Neběží teď převod firmy? (Zámek nedrží žádné spojení.) */
    public function isLockFree(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT IS_FREE_LOCK(' . self::LOCK_SQL . ')');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function key(string $key): string
    {
        return mb_substr($key, 0, 190);
    }

    private static function str(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return mb_substr((string) $value, 0, $max);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['job_id'] = $row['job_id'] !== null ? (int) $row['job_id'] : null;
        $row['created_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        return $row;
    }
}
