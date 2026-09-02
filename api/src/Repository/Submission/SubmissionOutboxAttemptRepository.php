<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Append-only ledger pokusů o odeslání (migrace 1381), vzorem
 * `payroll_submission_transport_attempts` z 1372.
 *
 * Řádek je důkaz o jednom pokusu, ne stavová proměnná: identita se nemění,
 * nic se nemaže a jednou přidělené `external_message_id` se nepřepisuje.
 * Hlídají to DB triggery, ne dobrá vůle volajícího.
 */
final class SubmissionOutboxAttemptRepository
{
    private const TABLE = 'submission_outbox_attempts';

    private const COLUMNS = 'id, supplier_id, outbox_id, channel, attempt_no, outcome,
        request_sha256, correlation_reference, external_message_id, error_code, error_message,
        started_at, finished_at, row_version, created_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    public function nextAttemptNo(int $supplierId, int $outboxId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND outbox_id = ?'
        );
        $stmt->execute([$supplierId, $outboxId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Založí pokus PŘED voláním kanálu — jinak by přerušené volání nezanechalo stopu.
     *
     * @return array<string,mixed>
     */
    public function open(
        int $supplierId,
        int $outboxId,
        string $channel,
        int $attemptNo,
        string $requestSha256,
        string $correlationReference,
        ?int $createdBy,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, outbox_id, channel, attempt_no, outcome, request_sha256,
                 correlation_reference, started_at, created_by)
             VALUES (?, ?, ?, ?, \'in_flight\', ?, ?, UTC_TIMESTAMP(), ?)'
        );
        $stmt->execute([$supplierId, $outboxId, $channel, $attemptNo, $requestSha256, $correlationReference, $createdBy]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $row = $this->find($supplierId, $id);
        if ($row === null) {
            throw new \RuntimeException('Pokus se založil, ale nepodařilo se ho načíst.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function markSent(int $supplierId, int $attemptId, string $externalMessageId, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $attemptId,
            'outcome = \'sent\', external_message_id = ?, finished_at = UTC_TIMESTAMP()',
            [$externalMessageId],
            $expectedVersion,
        );
    }

    /** @return array<string,mixed> */
    public function markUncertain(int $supplierId, int $attemptId, string $errorCode, string $errorMessage, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $attemptId,
            'outcome = \'uncertain\', error_code = ?, error_message = ?',
            [$errorCode, mb_substr($errorMessage, 0, 500)],
            $expectedVersion,
        );
    }

    /** @return array<string,mixed> */
    public function markFailed(int $supplierId, int $attemptId, string $errorCode, string $errorMessage, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $attemptId,
            'outcome = \'failed\', error_code = ?, error_message = ?, finished_at = UTC_TIMESTAMP()',
            [$errorCode, mb_substr($errorMessage, 0, 500)],
            $expectedVersion,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $attemptId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForOutbox(int $supplierId, int $outboxId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND outbox_id = ? ORDER BY attempt_no ASC'
        );
        $stmt->execute([$supplierId, $outboxId]);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Co ledger říká o tom, jestli zpráva opustila aplikaci.
     *
     * `left_application` počítá pokusy, u kterých NELZE tvrdit, že zpráva
     * zůstala uvnitř:
     *   - přidělené `external_message_id` — zpráva u příjemce prokazatelně je,
     *   - `sent` — kanál odeslání potvrdil,
     *   - `uncertain` — spojení se přerušilo a nevíme (nevědomost není důkaz
     *     o neodeslání),
     *   - `in_flight` — proces zemřel mezi voláním kanálu a zápisem výsledku,
     *     tedy tatáž nevědomost,
     *   - `rejected` — odmítnout může jen ten, komu zpráva došla.
     * Zbývá `failed`: kanál se ozval chybou JEŠTĚ PŘED odesláním, takže sám
     * o sobě neznamená, že zpráva ven šla. Proto se počítá zvlášť do `total`.
     *
     * @return array{total:int,left_application:int}
     */
    public function deletionEvidence(int $supplierId, int $outboxId): array
    {
        if (!$this->isAvailable()) {
            return ['total' => 0, 'left_application' => 0];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(external_message_id IS NOT NULL OR outcome <> \'failed\'), 0) AS left_application
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND outbox_id = ?'
        );
        $stmt->execute([$supplierId, $outboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'left_application' => (int) ($row['left_application'] ?? 0),
        ];
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string,mixed>
     */
    private function mutate(int $supplierId, int $attemptId, string $set, array $params, int $expectedVersion): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET ' . $set . ', row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute([...$params, $supplierId, $attemptId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            $current = $this->find($supplierId, $attemptId);
            if ($current === null) {
                throw new \DomainException('Pokus o odeslání neexistuje.');
            }
            throw new \DomainException(sprintf(
                'Pokus se mezitím změnil (očekávána verze %d, aktuální %d).',
                $expectedVersion,
                (int) $current['row_version'],
            ));
        }
        $row = $this->find($supplierId, $attemptId);
        if ($row === null) {
            throw new \RuntimeException('Pokus se změnil, ale nepodařilo se ho načíst.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'supplier_id', 'outbox_id', 'attempt_no', 'row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['created_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        return $row;
    }
}
