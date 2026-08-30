<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Fronta ROČNÍCH mzdových dokumentů (mzdový list, potvrzení o zdanitelných
 * příjmech) za zdaňovací období.
 *
 * Mechanika je záměrně tatáž jako u měsíční fronty výplatních pásek
 * ({@see PayrollDocumentBatchRepository}): jedna položka na osobu, pronájem
 * přes `lease_token`, exponenciální odklad, historie pokusů a obnova po
 * spadlém workeru. Liší se jen rozsah — roční dávka nevisí na běhu ani na
 * revizi, protože roční dokumenty žádnou nemají.
 */
final class PayrollAnnualDocumentBatchRepository
{
    public const MAX_ATTEMPTS = 3;
    public const STALE_AFTER_SECONDS = 900;

    public const KINDS = [
        'payroll_sheet',
        'taxable_income_advance_certificate',
        'taxable_income_withholding_certificate',
    ];

    public const SCOPES = ['selected', 'all'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Zařazení roční dávky.
     *
     * Idempotence má dvě patra. Dokud dávka téhož rozsahu BĚŽÍ, vrací se ta
     * stávající — dvojklik ani zopakovaný požadavek novou nezaloží. Jakmile
     * doběhne, smí účetní tentýž rozsah spustit znovu (přibyl člověk, opravila
     * se data), takže vznikne dávka nová.
     *
     * @return array<string,mixed>
     */
    public function enqueue(
        int $supplierId,
        int $taxYear,
        string $documentKind,
        string $scope,
        ?int $employeeId,
        string $idempotencyKey,
        ?int $requestedBy,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_annual_document_enqueue');
        }
        try {
            $batch = $this->enqueueLocked(
                $supplierId,
                $taxYear,
                $documentKind,
                $scope,
                $employeeId,
                $idempotencyKey,
                $requestedBy,
            );
            $this->finish($pdo, $ownsTransaction, 'payroll_annual_document_enqueue');
            return $batch;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction, 'payroll_annual_document_enqueue');
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function enqueueLocked(
        int $supplierId,
        int $taxYear,
        string $documentKind,
        string $scope,
        ?int $employeeId,
        string $idempotencyKey,
        ?int $requestedBy,
    ): array {
        $pdo = $this->db->pdo();
        $scopeHash = hash('sha256', trim($idempotencyKey), true);

        $open = $this->findOpenByScope($supplierId, $scopeHash);
        if ($open !== null) {
            return $this->detail($supplierId, (int) $open['id'])
                ?? throw new \RuntimeException('Roční dávku dokumentů nelze načíst.');
        }

        $targets = $this->targets($supplierId, $taxYear, $employeeId);
        if ($targets === []) {
            throw new \DomainException(
                'Za zvolený rok není schválený mzdový výsledek žádné osoby.',
            );
        }

        $sequence = $this->nextSequence($supplierId, $scopeHash);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $runKey = hash(
                'sha256',
                trim($idempotencyKey) . '#' . ($sequence + $attempt),
                true,
            );
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_annual_document_batches
                        (supplier_id, tax_year, document_kind, scope, status,
                         scope_key_hash, idempotency_key_hash, item_count,
                         requested_by)
                     VALUES (?, ?, ?, ?, "queued", ?, ?, ?, ?)'
                );
                $insert->execute([
                    $supplierId,
                    $taxYear,
                    $documentKind,
                    $scope,
                    $scopeHash,
                    $runKey,
                    count($targets),
                    $requestedBy,
                ]);
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                // Souběžný požadavek se trefil do téhož pořadí. Buď už otevřenou
                // dávku vidíme, pak je to ta pravá, nebo zkusíme další pořadí.
                $open = $this->findOpenByScope($supplierId, $scopeHash);
                if ($open !== null) {
                    return $this->detail($supplierId, (int) $open['id'])
                        ?? throw new \RuntimeException(
                            'Roční dávku dokumentů nelze načíst.',
                        );
                }
                continue;
            }

            $batchId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO payroll_annual_document_batch_items
                    (supplier_id, batch_id, employee_id, available_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            foreach ($targets as $target) {
                $insertItem->execute([$supplierId, $batchId, $target]);
            }

            return $this->detail($supplierId, $batchId)
                ?? throw new \RuntimeException('Roční dávku dokumentů nelze načíst.');
        }

        throw new \RuntimeException('Roční dávku dokumentů se nepodařilo založit.');
    }

    /**
     * Cílové osoby dávky.
     *
     * Ne „všichni aktivní": renderer potřebuje SCHVÁLENÝ mzdový výsledek v tom
     * roce. Kdo ho nemá, by skončil jako selhání s nesrozumitelným důvodem —
     * proto se do dávky vůbec nedostane.
     *
     * @return list<int>
     */
    private function targets(
        int $supplierId,
        int $taxYear,
        ?int $employeeId,
    ): array {
        $sql =
            'SELECT DISTINCT person.employee_id
               FROM payroll_run_persons person
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = person.supplier_id
                AND revision.id = person.revision_id
              WHERE person.supplier_id = ?
                AND person.period_start >= ?
                AND person.period_start < ?
                AND person.status = "calculated"
                AND person.result_hash IS NOT NULL
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status IN ("approved", "superseded")
                       AND newer.result_snapshot_hash IS NOT NULL
                )';
        $parameters = [
            $supplierId,
            sprintf('%04d-01-01', $taxYear),
            sprintf('%04d-01-01', $taxYear + 1),
        ];
        if ($employeeId !== null) {
            $sql .= ' AND person.employee_id = ?';
            $parameters[] = $employeeId;
        }
        $sql .= ' ORDER BY person.employee_id';

        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);

        $targets = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $target) {
            $targets[] = (int) $target;
        }

        return $targets;
    }

    /** @return array<string,mixed>|null */
    private function findOpenByScope(int $supplierId, string $scopeHash): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_batches
              WHERE supplier_id = ? AND scope_key_hash = ?
                AND status NOT IN ("completed", "failed")
              ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$supplierId, $scopeHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function nextSequence(int $supplierId, string $scopeHash): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_annual_document_batches
              WHERE supplier_id = ? AND scope_key_hash = ?'
        );
        $statement->execute([$supplierId, $scopeHash]);
        return ((int) $statement->fetchColumn()) + 1;
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $batchId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_batches
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeBatch($row) : null;
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function items(
        int $supplierId,
        int $batchId,
        int $limit,
        int $offset,
    ): array {
        if ($this->detail($supplierId, $batchId) === null) {
            throw new \OutOfBoundsException('Roční dávka dokumentů nebyla nalezena.');
        }
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_annual_document_batch_items
              WHERE supplier_id = ? AND batch_id = ?'
        );
        $count->execute([$supplierId, $batchId]);
        $statement = $this->db->pdo()->prepare(
            'SELECT item.id, item.batch_id, item.employee_id,
                    employee.full_name AS employee_name,
                    item.status, item.attempt_count, item.available_at,
                    item.document_id, item.last_error_code,
                    item.last_error_message, item.completed_at, item.updated_at
               FROM payroll_annual_document_batch_items item
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
            $items[] = $this->normalizeItem($row);
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
            $pdo->exec('SAVEPOINT payroll_annual_document_claim');
        }
        try {
            $this->recoverStaleLocked();
            $statement = $pdo->query(
                'SELECT item.id, item.supplier_id, item.batch_id,
                        item.employee_id, item.attempt_count,
                        batch.tax_year, batch.document_kind, batch.requested_by
                   FROM payroll_annual_document_batch_items item
                   JOIN payroll_annual_document_batches batch
                     ON batch.supplier_id = item.supplier_id
                    AND batch.id = item.batch_id
                  WHERE item.status IN ("queued", "retry_wait")
                    AND item.available_at <= UTC_TIMESTAMP()
                    AND batch.status <> "completed"
                  ORDER BY item.available_at, item.id
                  LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->finish($pdo, $ownsTransaction, 'payroll_annual_document_claim');
                return null;
            }
            $lease = random_bytes(16);
            $attemptNo = (int) $row['attempt_count'] + 1;
            $update = $pdo->prepare(
                'UPDATE payroll_annual_document_batch_items
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
                'INSERT INTO payroll_annual_document_batch_attempts
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
                'UPDATE payroll_annual_document_batches
                    SET status = "running",
                        started_at = COALESCE(started_at, UTC_TIMESTAMP())
                  WHERE supplier_id = ? AND id = ? AND status <> "completed"'
            )->execute([(int) $row['supplier_id'], (int) $row['batch_id']]);
            $this->finish($pdo, $ownsTransaction, 'payroll_annual_document_claim');
            $row['attempt_count'] = $attemptNo;
            $row['lease_token'] = bin2hex($lease);
            return $row;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction, 'payroll_annual_document_claim');
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claim */
    public function succeed(array $claim, int $documentId): void
    {
        $lease = $this->lease($claim);
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_batch_items item
               JOIN payroll_generated_documents document
                 ON document.supplier_id = item.supplier_id
                AND document.id = ?
                AND document.employee_id = item.employee_id
                AND document.document_kind = ?
               JOIN payroll_annual_document_revisions annual
                 ON annual.supplier_id = document.supplier_id
                AND annual.id = document.annual_revision_id
                AND annual.tax_year = ?
                SET item.status = "succeeded", item.document_id = document.id,
                    item.completed_at = UTC_TIMESTAMP(),
                    item.available_at = UTC_TIMESTAMP(),
                    item.lease_token = NULL, item.locked_at = NULL,
                    item.last_error_code = NULL, item.last_error_message = NULL
              WHERE item.supplier_id = ? AND item.id = ?
                AND item.status = "processing" AND item.lease_token = ?'
        );
        $statement->execute([
            $documentId,
            (string) $claim['document_kind'],
            (int) $claim['tax_year'],
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

    /**
     * Osoba se přeskakuje, ne generuje. Není to selhání a nesmí to blokovat
     * dokončení dávky — proto vlastní koncový stav.
     *
     * @param array<string,mixed> $claim
     */
    public function skip(array $claim, string $reasonCode, string $message): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_batch_items
                SET status = "skipped", available_at = UTC_TIMESTAMP(),
                    completed_at = UTC_TIMESTAMP(),
                    lease_token = NULL, locked_at = NULL,
                    last_error_code = ?, last_error_message = ?
              WHERE supplier_id = ? AND id = ?
                AND status = "processing" AND lease_token = ?'
        );
        $statement->execute([
            substr($reasonCode, 0, 64),
            mb_substr($message, 0, 500),
            (int) $claim['supplier_id'],
            (int) $claim['id'],
            $this->lease($claim),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Přeskočení neodpovídá pronajaté položce.');
        }
        $this->finishAttempt($claim, 'skipped', $reasonCode, $message);
        $this->refreshBatch((int) $claim['supplier_id'], (int) $claim['batch_id']);
    }

    /** @param array<string,mixed> $claim */
    public function fail(array $claim, string $errorCode, string $message): void
    {
        $attemptNo = (int) $claim['attempt_count'];
        $retry = $attemptNo < self::MAX_ATTEMPTS;
        $availableAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . min(3600, 30 * (2 ** max(0, $attemptNo - 1))) . ' seconds')
            ->format('Y-m-d H:i:s');
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_batch_items
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
            'UPDATE payroll_annual_document_batch_items
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
        // Dávka se vrací mezi rozpracované: `completed_at` by jinak tvrdilo, že
        // je hotová, přestože se právě jedna položka generuje znovu.
        $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_batches
                SET status = "queued", completed_at = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$supplierId, $batchId]);
        $this->refreshBatch($supplierId, $batchId);
        return $this->item($supplierId, $batchId, $itemId)
            ?? throw new \RuntimeException('Položku dávky nelze načíst.');
    }

    /** @return array<string,mixed>|null */
    private function item(int $supplierId, int $batchId, int $itemId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item.*, employee.full_name AS employee_name
               FROM payroll_annual_document_batch_items item
               JOIN payroll_employees employee
                 ON employee.supplier_id = item.supplier_id
                AND employee.id = item.employee_id
              WHERE item.supplier_id = ? AND item.batch_id = ? AND item.id = ?'
        );
        $statement->execute([$supplierId, $batchId, $itemId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeItem($row) : null;
    }

    /**
     * Má osoba za rok už dokument toho druhu? Rozhoduje SERVER nad úplnými daty,
     * ne prohlížeč nad načtenou stránkou.
     */
    public function hasAnnualDocument(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $documentKind,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_generated_documents document
               JOIN payroll_annual_document_revisions annual
                 ON annual.supplier_id = document.supplier_id
                AND annual.id = document.annual_revision_id
              WHERE document.supplier_id = ? AND document.employee_id = ?
                AND annual.tax_year = ? AND document.document_kind = ?
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $taxYear, $documentKind]);
        return $statement->fetchColumn() !== false;
    }

    private function recoverStaleLocked(): void
    {
        $pdo = $this->db->pdo();
        $staleBatches = $pdo->query(
            'SELECT DISTINCT supplier_id, batch_id
               FROM payroll_annual_document_batch_items
              WHERE status = "processing"
                AND locked_at < UTC_TIMESTAMP() - INTERVAL '
            . self::STALE_AFTER_SECONDS . ' SECOND
              FOR UPDATE'
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec(
            'UPDATE payroll_annual_document_batch_attempts attempt
               JOIN payroll_annual_document_batch_items item
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
            'UPDATE payroll_annual_document_batch_items
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

    /**
     * Přepočet hlavičky dávky.
     *
     * Roční dávka nemá ZIP jako měsíční, takže se uzavírá sama: jakmile nezbývá
     * co dělat, je `completed` (i když část položek selhala — `failed_count` to
     * říká) a dostane `completed_at`. Bez toho by prohlížeč poléval server
     * dotazy navždy.
     */
    private function refreshBatch(int $supplierId, int $batchId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_batches batch
               JOIN (
                 SELECT supplier_id, batch_id,
                        SUM(status = "succeeded") AS succeeded_count,
                        SUM(status = "failed") AS failed_count,
                        SUM(status = "skipped") AS skipped_count,
                        SUM(status = "processing") AS processing_count,
                        SUM(status IN ("queued", "retry_wait")) AS waiting_count
                   FROM payroll_annual_document_batch_items
                  WHERE supplier_id = ? AND batch_id = ?
                  GROUP BY supplier_id, batch_id
               ) totals
                 ON totals.supplier_id = batch.supplier_id
                AND totals.batch_id = batch.id
                SET batch.succeeded_count = totals.succeeded_count,
                    batch.failed_count = totals.failed_count,
                    batch.skipped_count = totals.skipped_count,
                    batch.status = CASE
                      WHEN totals.processing_count > 0 THEN "running"
                      WHEN totals.waiting_count > 0 THEN "retry_wait"
                      ELSE "completed"
                    END,
                    batch.completed_at = CASE
                      WHEN totals.processing_count = 0 AND totals.waiting_count = 0
                        THEN COALESCE(batch.completed_at, UTC_TIMESTAMP())
                      ELSE NULL
                    END
              WHERE batch.supplier_id = ? AND batch.id = ?'
        );
        $statement->execute([$supplierId, $batchId, $supplierId, $batchId]);
    }

    /** @param array<string,mixed> $claim */
    private function finishAttempt(
        array $claim,
        string $status,
        ?string $errorCode,
        ?string $message,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_batch_attempts
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeBatch(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'tax_year', 'item_count',
            'succeeded_count', 'failed_count', 'skipped_count',
        ] as $key) {
            $row[$key] = (int) $row[$key];
        }
        unset($row['idempotency_key_hash'], $row['scope_key_hash']);
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'batch_id' => (int) $row['batch_id'],
            'employee_id' => (int) $row['employee_id'],
            'employee_name' => (string) ($row['employee_name'] ?? ''),
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

    /** @param array<string,mixed> $claim */
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
    ): void {
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
    ): void {
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
