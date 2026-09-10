<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Převody z Money S3: běhy průvodce s protokolem (`money_s3_imports`) a mapa „co už
 * z agendy v MyÚčtu vzniklo" (`money_s3_import_map`, migrace 1806).
 *
 * Mapa je nosič idempotence — opakovaný import nezaloží nic, co už v mapě je.
 * Všechno je tenantové: stejná agenda nahraná do jiné firmy má vlastní mapu.
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

    public function put(int $supplierId, string $kind, string $key, int $targetId, ?int $runId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id, run_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE target_id = VALUES(target_id)'
        )->execute([$supplierId, $kind, self::key($key), $targetId, $runId]);
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

    /** @return array<string,mixed>|null */
    public function findRun(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM money_s3_imports WHERE id = ? AND supplier_id = ?');
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
     * Stav automatiky PŘED prvním ostrým během, který ji nechal vypnutou (neúspěšný
     * import ji úmyslně nezapíná — viz {@see \MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch}).
     * Další běh ji musí obnovit na tenhle stav, ne na „vypnuto", které po sobě
     * neúspěšný běh zanechal.
     *
     * @return array<string,mixed>|null
     */
    public function pendingAutomationSnapshot(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT protocol FROM money_s3_imports
              WHERE supplier_id = ? AND mode = 'import' AND status <> 'running' AND protocol IS NOT NULL
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) {
            return null;
        }
        $protocol = json_decode($json, true);
        $automation = is_array($protocol) ? ($protocol['automation'] ?? null) : null;
        if (!is_array($automation) || ($automation['restored'] ?? true) !== false) {
            return null;
        }
        return is_array($automation['before'] ?? null) ? $automation['before'] : null;
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
