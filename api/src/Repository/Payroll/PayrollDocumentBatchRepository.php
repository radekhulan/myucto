<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollDocumentBatchRepository
{
    public const MAX_ATTEMPTS = 3;
    public const STALE_AFTER_SECONDS = 900;

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function enqueueApprovedRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $idempotencyKey,
        ?int $requestedBy,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_document_enqueue');
        }
        try {
            $batch = $this->enqueueLocked(
                $supplierId,
                $runId,
                $revisionId,
                $idempotencyKey,
                $requestedBy,
            );
            $this->finish($pdo, $ownsTransaction, 'payroll_document_enqueue');
            return $batch;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction, 'payroll_document_enqueue');
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function enqueueLocked(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $idempotencyKey,
        ?int $requestedBy,
    ): array {
        $pdo = $this->db->pdo();
        $revision = $pdo->prepare(
            // `superseded` tu být NESMÍ: z revize, kterou nahradila opravná,
            // se nové výplatní pásky negenerují. Už vydané dokumenty na ní
            // dál visí a platí, ale zdroj nové dávky je vždycky ta AKTUÁLNÍ
            // schválená revize — proto i pojistka, že novější schválená
            // revize téhož běhu neexistuje.
            'SELECT result_snapshot_hash
               FROM payroll_run_revisions revision
              WHERE supplier_id = ? AND run_id = ? AND id = ?
                AND status = "approved"
                AND result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status = "approved"
                       AND newer.result_snapshot_hash IS NOT NULL
                )'
        );
        $revision->execute([$supplierId, $runId, $revisionId]);
        $sourceHash = $revision->fetchColumn();
        if (!is_string($sourceHash) || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            throw new \DomainException(
                'Frontu dokumentů lze založit jen nad aktuální schválenou mzdovou revizí.',
            );
        }

        $people = $pdo->prepare(
            'SELECT employee_id, result_hash
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ?
                AND status = "calculated" AND result_hash IS NOT NULL
              ORDER BY employee_id'
        );
        $people->execute([$supplierId, $revisionId]);
        $items = $people->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            throw new \DomainException(
                'Schválená revize neobsahuje žádnou vypočtenou osobu.',
            );
        }
        foreach ($items as $item) {
            if (!is_string($item['result_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $item['result_hash']) !== 1
            ) {
                throw new \DomainException(
                    'Výsledek osoby nemá platný zdrojový otisk.',
                );
            }
        }

        $keyHash = hash('sha256', trim($idempotencyKey), true);
        $existing = $this->findByRevision($supplierId, $revisionId);
        if ($existing !== null) {
            $this->assertSameIdentity($existing, $runId, $revisionId, $sourceHash);
            return $this->detail($supplierId, (int) $existing['id'])
                ?? throw new \RuntimeException('Dávku dokumentů nelze načíst.');
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO payroll_document_batches
                    (supplier_id, run_id, revision_id, status,
                     source_snapshot_hash, idempotency_key_hash, item_count,
                     requested_by)
                 VALUES (?, ?, ?, "queued", ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $runId,
                $revisionId,
                $sourceHash,
                $keyHash,
                count($items),
                $requestedBy,
            ]);
            $batchId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO payroll_document_batch_items
                    (supplier_id, batch_id, employee_id, source_snapshot_hash,
                     available_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            foreach ($items as $item) {
                $insertItem->execute([
                    $supplierId,
                    $batchId,
                    (int) $item['employee_id'],
                    $item['result_hash'],
                ]);
            }
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->findByRevision($supplierId, $revisionId);
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSameIdentity($existing, $runId, $revisionId, $sourceHash);
            $batchId = (int) $existing['id'];
        }

        return $this->detail($supplierId, $batchId)
            ?? throw new \RuntimeException('Dávku dokumentů nelze načíst.');
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $batchId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT batch.*, run.period_start,
                    document.suggested_filename AS bundle_filename
               FROM payroll_document_batches batch
               JOIN payroll_runs run
                 ON run.supplier_id = batch.supplier_id AND run.id = batch.run_id
          LEFT JOIN payroll_generated_documents document
                 ON document.supplier_id = batch.supplier_id
                AND document.id = batch.bundle_document_id
              WHERE batch.supplier_id = ? AND batch.id = ?'
        );
        $statement->execute([$supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeBatch($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function forRevision(int $supplierId, int $revisionId): ?array
    {
        $row = $this->findByRevision($supplierId, $revisionId);
        if ($row === null) {
            return null;
        }
        return $this->detail($supplierId, (int) $row['id']);
    }

    /**
     * Zastavení rozpracované dávky nad revizí, kterou právě odsunula opravná.
     *
     * Bez toho by fronta dál renderovala PŘEDKOREKČNÍ výplatní pásky, přestože
     * platná je už nová revize — a `claimNext()` odsunuté revize přeskakuje,
     * takže by položky navíc zůstaly viset ve frontě navždy. Zbylé čekající
     * položky se proto uzavřou jako neúspěšné s pojmenovaným důvodem; už
     * vyrobené dokumenty zůstávají a platí jako historie.
     *
     * Položka, kterou má právě v ruce worker (`processing`), se nechává
     * doběhnout — přerušit cizí pronájem zvenčí by rozbilo optimistický zámek.
     *
     * @return int počet zastavených položek
     */
    public function cancelSupersededRevisionsOfRun(
        int $supplierId,
        int $runId,
    ): int {
        $batches = $this->db->pdo()->prepare(
            'SELECT batch.id
               FROM payroll_document_batches batch
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = batch.supplier_id
                AND revision.id = batch.revision_id
                AND revision.run_id = batch.run_id
              WHERE batch.supplier_id = ? AND batch.run_id = ?
                AND batch.status <> "completed"
                AND revision.status = "superseded"'
        );
        $batches->execute([$supplierId, $runId]);
        $ids = $batches->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            return 0;
        }

        $cancel = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items
                SET status = "failed", available_at = UTC_TIMESTAMP(),
                    lease_token = NULL, locked_at = NULL,
                    last_error_code = "payroll_revision_superseded",
                    last_error_message = ?
              WHERE supplier_id = ? AND batch_id = ?
                AND status IN ("queued", "retry_wait")'
        );
        $cancelled = 0;
        foreach ($ids as $id) {
            $batchId = (int) $id;
            $cancel->execute([
                'Revizi mzdového běhu nahradila opravná, z odsunuté revize se'
                    . ' nové dokumenty negenerují.',
                $supplierId,
                $batchId,
            ]);
            $affected = $cancel->rowCount();
            if ($affected > 0) {
                $cancelled += $affected;
                $this->refreshBatch($supplierId, $batchId);
            }
        }

        return $cancelled;
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function items(
        int $supplierId,
        int $batchId,
        int $limit,
        int $offset,
    ): array {
        if ($this->detail($supplierId, $batchId) === null) {
            throw new \OutOfBoundsException('Dávka dokumentů nebyla nalezena.');
        }
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_document_batch_items
              WHERE supplier_id = ? AND batch_id = ?'
        );
        $count->execute([$supplierId, $batchId]);
        $statement = $this->db->pdo()->prepare(
            'SELECT item.id, item.batch_id, item.employee_id,
                    employee.full_name AS employee_name,
                    item.status, item.attempt_count, item.available_at,
                    item.document_id, item.last_error_code,
                    item.last_error_message, item.completed_at, item.updated_at
               FROM payroll_document_batch_items item
               JOIN payroll_employees employee
                 ON employee.supplier_id = item.supplier_id
                AND employee.id = item.employee_id
              WHERE item.supplier_id = ? AND item.batch_id = ?
              ORDER BY employee.full_name, item.employee_id
              LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(2, $batchId, PDO::PARAM_INT);
        $statement->bindValue(3, max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->bindValue(4, max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'batch_id' => (int) $row['batch_id'],
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => (string) $row['employee_name'],
                'status' => (string) $row['status'],
                'attempt_count' => (int) $row['attempt_count'],
                'available_at' => (string) $row['available_at'],
                'document_id' => $row['document_id'] === null
                    ? null : (int) $row['document_id'],
                'last_error_code' => $row['last_error_code'],
                'last_error_message' => $row['last_error_message'],
                'completed_at' => $row['completed_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return ['items' => $items, 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string,mixed>|null */
    public function claimNext(): ?array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_document_claim');
        }
        try {
            $this->recoverStaleLocked();
            $statement = $pdo->query(
                'SELECT item.id, item.supplier_id, item.batch_id,
                        item.employee_id, item.source_snapshot_hash,
                        item.attempt_count, batch.run_id, batch.revision_id,
                        batch.source_snapshot_hash AS revision_snapshot_hash,
                        batch.requested_by
                   FROM payroll_document_batch_items item
                   JOIN payroll_document_batches batch
                     ON batch.supplier_id = item.supplier_id
                    AND batch.id = item.batch_id
                   JOIN payroll_run_revisions revision
                     ON revision.supplier_id = batch.supplier_id
                    AND revision.id = batch.revision_id
                    AND revision.run_id = batch.run_id
                  WHERE item.status IN ("queued", "retry_wait")
                    AND item.available_at <= UTC_TIMESTAMP()
                    AND batch.status <> "completed"
                    -- Odsunutá revize se nerenderuje: kdyby dávka založená
                    -- před opravou dál běžela, zaměstnanec by dostal
                    -- předkorekční pásku. Čekající položky takové dávky
                    -- uzavírá cancelForSupersededRevision(), takže tady
                    -- nezůstane nic viset.
                    AND revision.status = "approved"
                    AND revision.result_snapshot_hash = batch.source_snapshot_hash
                  ORDER BY item.available_at, item.id
                  LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->finish($pdo, $ownsTransaction, 'payroll_document_claim');
                return null;
            }
            $lease = random_bytes(16);
            $attemptNo = (int) $row['attempt_count'] + 1;
            $update = $pdo->prepare(
                'UPDATE payroll_document_batch_items
                    SET status = "processing", attempt_count = ?,
                        lease_token = ?, locked_at = UTC_TIMESTAMP(),
                        last_error_code = NULL, last_error_message = NULL
                  WHERE supplier_id = ? AND id = ?
                    AND status IN ("queued", "retry_wait")'
            );
            $update->execute([
                $attemptNo,
                $lease,
                (int) $row['supplier_id'],
                (int) $row['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Položku fronty se nepodařilo zamknout.');
            }
            $attempt = $pdo->prepare(
                'INSERT INTO payroll_document_batch_attempts
                    (supplier_id, batch_id, item_id, attempt_no, lease_token)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $attempt->execute([
                (int) $row['supplier_id'],
                (int) $row['batch_id'],
                (int) $row['id'],
                $attemptNo,
                $lease,
            ]);
            $pdo->prepare(
                'UPDATE payroll_document_batches
                    SET status = "running", started_at = COALESCE(started_at, UTC_TIMESTAMP())
                  WHERE supplier_id = ? AND id = ? AND status <> "completed"'
            )->execute([(int) $row['supplier_id'], (int) $row['batch_id']]);
            $this->finish($pdo, $ownsTransaction, 'payroll_document_claim');
            $row['attempt_count'] = $attemptNo;
            $row['lease_token'] = bin2hex($lease);
            return $row;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction, 'payroll_document_claim');
            throw $exception;
        }
    }

    public function succeed(array $claim, int $documentId): void
    {
        $lease = $this->lease($claim);
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items item
               JOIN payroll_generated_documents document
                 ON document.supplier_id = item.supplier_id
                AND document.id = ?
                AND document.employee_id = item.employee_id
                AND document.revision_id = ?
                AND document.source_snapshot_hash = item.source_snapshot_hash
                AND document.document_kind = "payslip"
                SET item.status = "succeeded", item.document_id = document.id,
                    item.completed_at = UTC_TIMESTAMP(), item.available_at = UTC_TIMESTAMP(),
                    item.lease_token = NULL, item.locked_at = NULL,
                    item.last_error_code = NULL, item.last_error_message = NULL
              WHERE item.supplier_id = ? AND item.id = ?
                AND item.status = "processing" AND item.lease_token = ?'
        );
        $statement->execute([
            $documentId,
            (int) $claim['revision_id'],
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $lease,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Výsledek workeru neodpovídá pronajaté položce.');
        }
        $this->finishAttempt($claim, 'succeeded', null, null);
        $this->refreshBatch((int) $claim['supplier_id'], (int) $claim['batch_id']);
    }

    public function fail(array $claim, string $errorCode, string $message): void
    {
        $attemptNo = (int) $claim['attempt_count'];
        $retry = $attemptNo < self::MAX_ATTEMPTS;
        $availableAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . min(3600, 30 * (2 ** max(0, $attemptNo - 1))) . ' seconds')
            ->format('Y-m-d H:i:s');
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items
                SET status = ?, available_at = ?, lease_token = NULL,
                    locked_at = NULL, last_error_code = ?, last_error_message = ?
              WHERE supplier_id = ? AND id = ?
                AND status = "processing" AND lease_token = ?'
        );
        $statement->execute([
            $retry ? 'retry_wait' : 'failed',
            $availableAt,
            substr($errorCode, 0, 64),
            mb_substr($message, 0, 500),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $this->lease($claim),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Selhání workeru neodpovídá pronajaté položce.');
        }
        $this->finishAttempt($claim, 'failed', $errorCode, $message);
        $this->refreshBatch((int) $claim['supplier_id'], (int) $claim['batch_id']);
    }

    /** @return array<string,mixed> */
    public function retry(int $supplierId, int $batchId, int $itemId): array
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_items
                SET status = "queued", available_at = UTC_TIMESTAMP(),
                    last_error_code = NULL, last_error_message = NULL,
                    completed_at = NULL
              WHERE supplier_id = ? AND batch_id = ? AND id = ?
                AND status IN ("failed", "retry_wait")'
        );
        $statement->execute([$supplierId, $batchId, $itemId]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException(
                'Opakovat lze pouze neúspěšnou položku této dávky.',
            );
        }
        $this->refreshBatch($supplierId, $batchId);
        return $this->item($supplierId, $batchId, $itemId)
            ?? throw new \RuntimeException('Položku dávky nelze načíst.');
    }

    public function attachBundle(int $supplierId, int $batchId, int $documentId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batches batch
               JOIN payroll_generated_documents document
                 ON document.supplier_id = batch.supplier_id
                AND document.id = ?
                AND document.run_id = batch.run_id
                AND document.revision_id = batch.revision_id
                AND document.document_kind = "monthly_bundle"
                SET batch.bundle_document_id = document.id,
                    batch.status = "completed", batch.completed_at = UTC_TIMESTAMP()
              WHERE batch.supplier_id = ? AND batch.id = ?
                AND batch.status <> "completed"
                AND batch.bundle_document_id IS NULL
                AND batch.succeeded_count = batch.item_count
                AND batch.failed_count = 0'
        );
        $statement->execute([$documentId, $supplierId, $batchId]);
        if ($statement->rowCount() !== 1) {
            $batch = $this->detail($supplierId, $batchId);
            if ($batch !== null
                && (string) $batch['status'] === 'completed'
                && (int) $batch['bundle_document_id'] === $documentId
            ) {
                return;
            }
            throw new \RuntimeException(
                'ZIP lze připojit jen k úplně úspěšné tenantové dávce.',
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public function readyForBundle(int $limit = 10): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, run_id, revision_id, requested_by
               FROM payroll_document_batches
              WHERE status <> "completed" AND bundle_document_id IS NULL
                AND succeeded_count = item_count AND failed_count = 0
              ORDER BY id LIMIT ?'
        );
        $statement->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function item(int $supplierId, int $batchId, int $itemId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_document_batch_items
              WHERE supplier_id = ? AND batch_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $batchId, $itemId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function recoverStaleLocked(): void
    {
        $pdo = $this->db->pdo();
        $staleBatches = $pdo->query(
            'SELECT DISTINCT supplier_id, batch_id
               FROM payroll_document_batch_items
              WHERE status = "processing"
                AND locked_at < UTC_TIMESTAMP() - INTERVAL '
            . self::STALE_AFTER_SECONDS . ' SECOND
              FOR UPDATE'
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec(
            'UPDATE payroll_document_batch_attempts attempt
               JOIN payroll_document_batch_items item
                 ON item.supplier_id = attempt.supplier_id
                AND item.id = attempt.item_id
                AND item.lease_token = attempt.lease_token
                SET attempt.status = "stale", attempt.finished_at = UTC_TIMESTAMP(),
                    attempt.error_code = "worker_lease_expired",
                    attempt.error_message = "Worker lease expired before completion."
              WHERE attempt.status = "running"
                AND item.status = "processing"
                AND item.locked_at < UTC_TIMESTAMP() - INTERVAL '
            . self::STALE_AFTER_SECONDS . ' SECOND'
        );
        $pdo->exec(
            'UPDATE payroll_document_batch_items
                SET status = IF(attempt_count < ' . self::MAX_ATTEMPTS . ', "retry_wait", "failed"),
                    available_at = UTC_TIMESTAMP(), lease_token = NULL, locked_at = NULL,
                    last_error_code = "worker_lease_expired",
                    last_error_message = "Worker lease expired before completion."
              WHERE status = "processing"
                AND locked_at < UTC_TIMESTAMP() - INTERVAL '
            . self::STALE_AFTER_SECONDS . ' SECOND'
        );
        foreach ($staleBatches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            $this->refreshBatch(
                (int) ($batch['supplier_id'] ?? 0),
                (int) ($batch['batch_id'] ?? 0),
            );
        }
    }

    private function refreshBatch(int $supplierId, int $batchId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batches batch
               JOIN (
                 SELECT supplier_id, batch_id,
                        SUM(status = "succeeded") AS succeeded_count,
                        SUM(status = "failed") AS failed_count,
                        SUM(status = "processing") AS processing_count,
                        SUM(status IN ("queued", "retry_wait")) AS waiting_count
                   FROM payroll_document_batch_items
                  WHERE supplier_id = ? AND batch_id = ?
                  GROUP BY supplier_id, batch_id
               ) totals
                 ON totals.supplier_id = batch.supplier_id
                AND totals.batch_id = batch.id
                SET batch.succeeded_count = totals.succeeded_count,
                    batch.failed_count = totals.failed_count,
                    batch.status = CASE
                      WHEN totals.failed_count > 0 THEN "failed"
                      WHEN totals.processing_count > 0 THEN "running"
                      WHEN totals.waiting_count > 0 THEN "retry_wait"
                      ELSE batch.status
                    END
              WHERE batch.supplier_id = ? AND batch.id = ?
                AND batch.status <> "completed"'
        );
        $statement->execute([$supplierId, $batchId, $supplierId, $batchId]);
    }

    private function finishAttempt(
        array $claim,
        string $status,
        ?string $errorCode,
        ?string $message,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_document_batch_attempts
                SET status = ?, error_code = ?, error_message = ?,
                    finished_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND item_id = ? AND attempt_no = ?
                AND lease_token = ? AND status = "running"'
        );
        $statement->execute([
            $status,
            $errorCode === null ? null : substr($errorCode, 0, 64),
            $message === null ? null : mb_substr($message, 0, 500),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            (int) $claim['attempt_count'],
            $this->lease($claim),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function findByRevision(int $supplierId, int $revisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_document_batches
              WHERE supplier_id = ? AND revision_id = ?'
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertSameIdentity(
        array $batch,
        int $runId,
        int $revisionId,
        string $sourceHash,
    ): void {
        if ((int) $batch['run_id'] !== $runId
            || (int) $batch['revision_id'] !== $revisionId
            || !hash_equals((string) $batch['source_snapshot_hash'], $sourceHash)
        ) {
            throw new \RuntimeException(
                'Existující dávka dokumentů neodpovídá schválenému zdroji.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function normalizeBatch(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'run_id', 'revision_id', 'item_count',
            'succeeded_count', 'failed_count',
        ] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['bundle_document_id'] = $row['bundle_document_id'] === null
            ? null : (int) $row['bundle_document_id'];
        unset($row['idempotency_key_hash'], $row['source_snapshot_hash']);
        return $row;
    }

    private function lease(array $claim): string
    {
        $lease = hex2bin((string) ($claim['lease_token'] ?? ''));
        if (!is_string($lease) || strlen($lease) !== 16) {
            throw new \InvalidArgumentException('Worker lease není platný.');
        }
        return $lease;
    }

    private function finish(
        PDO $pdo,
        bool $ownsTransaction,
        string $savepoint,
    ): void
    {
        if ($ownsTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    private function rollback(
        PDO $pdo,
        bool $ownsTransaction,
        string $savepoint,
    ): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $pdo->rollBack();
        } else {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }
}
