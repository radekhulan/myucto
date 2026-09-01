<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPaymentExportRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    public function hasActiveTransaction(): bool
    {
        return $this->db->pdo()->inTransaction();
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_payment_export_'
                . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{
     *   id:int,
     *   batch_reference:string,
     *   channel:string,
     *   export_format:string,
     *   direction:string,
     *   planned_payment_date:string,
     *   currency_code:string,
     *   payer_reference:string,
     *   declared_total_minor:int,
     *   declared_item_count:int,
     *   snapshot_ciphertext:string,
     *   snapshot_hash:string,
     *   items:list<array{
     *     id:int,
     *     item_reference:string,
     *     recipient_reference:string,
     *     amount_minor:int,
     *     instruction_ciphertext:string,
     *     instruction_hash:string
     *   }>
     * }|null
     */
    public function lockBatchWithItems(
        int $supplierId,
        int $batchId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, batch_reference, channel, export_format, direction,
                    planned_payment_date, currency_code, payer_reference,
                    declared_total_minor, declared_item_count,
                    snapshot_ciphertext, snapshot_hash
               FROM payroll_payment_batches
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'platební dávku');

        $itemStatement = $this->db->pdo()->prepare(
            'SELECT id, item_reference, recipient_reference, amount_minor,
                    instruction_ciphertext, instruction_hash
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?
              ORDER BY id
              FOR UPDATE',
        );
        $itemStatement->execute([$supplierId, $batchId]);
        $items = [];
        foreach ($itemStatement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $item = self::associativeRow($item, 'položku platební dávky');
            $items[] = [
                'id' => self::integer($item, 'id'),
                'item_reference' => self::string(
                    $item,
                    'item_reference',
                ),
                'recipient_reference' => self::string(
                    $item,
                    'recipient_reference',
                ),
                'amount_minor' => self::integer($item, 'amount_minor'),
                'instruction_ciphertext' => self::string(
                    $item,
                    'instruction_ciphertext',
                ),
                'instruction_hash' => self::hash(
                    $item,
                    'instruction_hash',
                ),
            ];
        }

        return [
            'id' => self::integer($row, 'id'),
            'batch_reference' => self::string($row, 'batch_reference'),
            'channel' => self::string($row, 'channel'),
            'export_format' => self::string($row, 'export_format'),
            'direction' => self::string($row, 'direction'),
            'planned_payment_date' => self::string(
                $row,
                'planned_payment_date',
            ),
            'currency_code' => self::string($row, 'currency_code'),
            'payer_reference' => self::string($row, 'payer_reference'),
            'declared_total_minor' => self::integer(
                $row,
                'declared_total_minor',
            ),
            'declared_item_count' => self::integer(
                $row,
                'declared_item_count',
            ),
            'snapshot_ciphertext' => self::string(
                $row,
                'snapshot_ciphertext',
            ),
            'snapshot_hash' => self::hash($row, 'snapshot_hash'),
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *   export_id:int,
     *   batch_id:int,
     *   export_format:string,
     *   export_revision_no:int,
     *   source_snapshot_hash:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string
     * }|null
     */
    public function findByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT payment_export.id, payment_export.batch_id,
                    payment_export.export_format,
                    payment_export.export_revision_no,
                    payment_export.source_snapshot_hash,
                    payment_export.file_sha256,
                    payment_export.size_bytes,
                    payment_export.mime_type,
                    payment_export.storage_key,
                    payment_export.suggested_filename
               FROM payroll_payment_export_idempotency_keys payment_key
               JOIN payroll_payment_exports payment_export
                 ON payment_export.supplier_id = payment_key.supplier_id
                AND payment_export.batch_id = payment_key.batch_id
                AND payment_export.id = payment_key.export_id
              WHERE payment_key.supplier_id = ?
                AND payment_key.idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::exportRow(
                self::associativeRow($row, 'platební export'),
            );
    }

    /**
     * @return array{
     *   export_id:int,
     *   batch_id:int,
     *   export_format:string,
     *   export_revision_no:int,
     *   source_snapshot_hash:string,
     *   exporter_version:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string
     * }|null
     */
    public function lockLatestRevision(
        int $supplierId,
        int $batchId,
        string $exportFormat,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, batch_id, export_format, export_revision_no,
                    source_snapshot_hash, exporter_version, file_sha256,
                    size_bytes, mime_type, storage_key, suggested_filename
               FROM payroll_payment_exports
              WHERE supplier_id = ? AND batch_id = ? AND export_format = ?
              ORDER BY export_revision_no DESC
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $batchId, $exportFormat]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'revizi platebního exportu');

        $export = self::exportRow($row);

        return [
            ...$export,
            'exporter_version' => self::string(
                $row,
                'exporter_version',
            ),
        ];
    }

    public function insert(
        int $supplierId,
        int $batchId,
        string $exportFormat,
        int $revisionNo,
        ?int $supersedesExportId,
        string $sourceSnapshotHash,
        string $exporterVersion,
        string $fileSha256,
        int $sizeBytes,
        string $mimeType,
        string $storageKey,
        string $suggestedFilename,
        string $manifestJson,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_exports
                (supplier_id, batch_id, export_format,
                 export_revision_no, supersedes_export_id,
                 source_snapshot_hash, exporter_version, file_sha256,
                 size_bytes, mime_type, storage_key, suggested_filename,
                 manifest_json, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $batchId,
            $exportFormat,
            $revisionNo,
            $supersedesExportId,
            $sourceSnapshotHash,
            $exporterVersion,
            $fileSha256,
            $sizeBytes,
            $mimeType,
            $storageKey,
            $suggestedFilename,
            $manifestJson,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertIdempotencyAlias(
        int $supplierId,
        int $batchId,
        int $exportId,
        string $idempotencyKeyHash,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_export_idempotency_keys
                (supplier_id, batch_id, export_id,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $batchId,
            $exportId,
            $idempotencyKeyHash,
        ]);
    }

    /**
     * @return array{
     *   export_id:int,
     *   batch_id:int,
     *   export_format:string,
     *   export_revision_no:int,
     *   source_snapshot_hash:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string
     * }|null
     */
    /**
     * Mzdové období, kterého se dávka týká.
     *
     * Dávka sama období nenese - má jen datum splatnosti, a to bývá až
     * v následujícím měsíci (příkaz za srpen se platí v září). Účetní ale
     * na dokladu potřebuje vidět, ZA CO se platí, ne kdy. Období se proto
     * dotáhne přes závazky, ze kterých dávka vznikla.
     *
     * Když dávka sahá do víc měsíců (doplatky, opravy), vrátí se krajní
     * hodnoty a doklad je vypíše jako rozsah - slučovat je do jednoho
     * měsíce by tvrdilo něco, co není pravda.
     *
     * @return array{first:string,last:string}|null
     */
    public function periodRangeForBatch(int $supplierId, int $batchId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT MIN(run.period_start) AS first_period,
                    MAX(run.period_start) AS last_period
               FROM payroll_payment_items item
               JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = item.supplier_id
                AND allocation.item_id = item.id
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE item.supplier_id = ? AND item.batch_id = ?',
        );
        $statement->execute([$supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !is_string($row['first_period'] ?? null)
            || !is_string($row['last_period'] ?? null)
        ) {
            return null;
        }

        return [
            'first' => $row['first_period'],
            'last' => $row['last_period'],
        ];
    }

    /**
     * Skryje starou revizi exportu ze seznamu.
     *
     * Řádek exportu se nemaže a mazat nepůjde - tabulka je záměrně neměnná
     * (triggery zakazují UPDATE i DELETE), protože je to doklad o tom, co se
     * skutečně poslalo do banky. Skrytí se proto vede vedle, ve vlastní
     * tabulce; seznam pak ukazuje jen platnou revizi.
     *
     * Skrýt jde JEN revizi, kterou už nahradila novější. Poslední revize je
     * ta platná a ta zmizet nesmí - jinak by u dávky nezbylo nic a účetní by
     * si myslela, že export neexistuje.
     *
     * @return array{export_id:int,batch_id:int,export_format:string,export_revision_no:int}
     */
    public function hide(int $supplierId, int $exportId, ?int $userId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT export.id, export.batch_id, export.export_format,
                    export.export_revision_no,
                    EXISTS (
                        SELECT 1 FROM payroll_payment_exports newer
                         WHERE newer.supplier_id = export.supplier_id
                           AND newer.supersedes_export_id = export.id
                    ) AS superseded
               FROM payroll_payment_exports export
              WHERE export.supplier_id = ? AND export.id = ?',
        );
        $statement->execute([$supplierId, $exportId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Platební export nebyl nalezen.');
        }
        if (!(bool) $row['superseded']) {
            throw new \DomainException(
                'Skrýt jde jen revizi, kterou nahradila novější. '
                . 'Tahle je poslední platná.',
            );
        }
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_export_hidden
                (supplier_id, export_id, hidden_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE export_id = export_id',
        );
        $insert->execute([$supplierId, $exportId, $userId]);

        return [
            'export_id' => (int) $row['id'],
            'batch_id' => (int) $row['batch_id'],
            'export_format' => (string) $row['export_format'],
            'export_revision_no' => (int) $row['export_revision_no'],
        ];
    }

    public function lockById(int $supplierId, int $exportId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, batch_id, export_format, export_revision_no,
                    source_snapshot_hash, file_sha256, size_bytes,
                    mime_type, storage_key, suggested_filename
               FROM payroll_payment_exports
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $exportId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::exportRow(
                self::associativeRow($row, 'platební export'),
            );
    }

    public function countStorageReferences(
        int $supplierId,
        string $storageKey,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_payment_exports
              WHERE supplier_id = ? AND storage_key = ?',
        );
        $statement->execute([$supplierId, $storageKey]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   export_id:int,
     *   batch_id:int,
     *   export_format:string,
     *   export_revision_no:int,
     *   source_snapshot_hash:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string
     * }
     */
    private static function exportRow(array $row): array
    {
        return [
            'export_id' => self::integer($row, 'id'),
            'batch_id' => self::integer($row, 'batch_id'),
            'export_format' => self::string($row, 'export_format'),
            'export_revision_no' => self::integer(
                $row,
                'export_revision_no',
            ),
            'source_snapshot_hash' => self::hash(
                $row,
                'source_snapshot_hash',
            ),
            'file_sha256' => self::hash($row, 'file_sha256'),
            'size_bytes' => self::integer($row, 'size_bytes'),
            'mime_type' => self::string($row, 'mime_type'),
            'storage_key' => self::hash($row, 'storage_key'),
            'suggested_filename' => self::string(
                $row,
                'suggested_filename',
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(
        mixed $value,
        string $context,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový {$context} nemá textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
