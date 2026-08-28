<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Posting\PayrollPostingPreview;
use PDO;

final class PayrollPostingBatchRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_posting_' . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $callback();
            if ($ownTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->rollBack($ownTransaction, $savepoint);
            throw $exception;
        }
    }

    /**
     * @return array{
     *   run_id:int,
     *   revision_no:int,
     *   revision_status:string,
     *   current_revision_no:int,
     *   period_start:string,
     *   input_snapshot_hash:string,
     *   result_snapshot_hash:string
     * }|null
     */
    public function lockRevisionContext(int $supplierId, int $revisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.run_id, revision.revision_no,
                    revision.status AS revision_status,
                    revision.input_snapshot_hash,
                    revision.result_snapshot_hash,
                    run.current_revision_no, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný kontext mzdové revize.',
            );
        }

        return [
            'run_id' => self::databaseInt($row['run_id'] ?? null, 'run_id'),
            'revision_no' => self::databaseInt(
                $row['revision_no'] ?? null,
                'revision_no',
            ),
            'revision_status' => self::databaseString(
                $row['revision_status'] ?? null,
                'revision_status',
            ),
            'current_revision_no' => self::databaseInt(
                $row['current_revision_no'] ?? null,
                'current_revision_no',
            ),
            'period_start' => self::databaseString(
                $row['period_start'] ?? null,
                'period_start',
            ),
            'input_snapshot_hash' => self::databaseHash(
                $row['input_snapshot_hash'] ?? null,
                'input_snapshot_hash',
            ),
            'result_snapshot_hash' => self::databaseHash(
                $row['result_snapshot_hash'] ?? null,
                'result_snapshot_hash',
            ),
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   status:string,
     *   target_hash:string,
     *   journal_entry_id:?int
     * }|null
     */
    public function findByRevisionForUpdate(
        int $supplierId,
        int $revisionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, target_hash, journal_entry_id
               FROM payroll_posting_batches
              WHERE supplier_id = ? AND revision_id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatnou účetní dávku.',
            );
        }

        return [
            'id' => self::databaseInt($row['id'] ?? null, 'id'),
            'status' => self::databaseString($row['status'] ?? null, 'status'),
            'target_hash' => self::databaseString(
                $row['target_hash'] ?? null,
                'target_hash',
            ),
            'journal_entry_id' => self::nullableDatabaseInt(
                $row['journal_entry_id'] ?? null,
                'journal_entry_id',
            ),
        ];
    }

    public function resolveEntryDate(
        int $supplierId,
        string $payrollPeriodEnd,
    ): string {
        $periodEnd = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $payrollPeriodEnd,
        );
        if ($periodEnd === false
            || $periodEnd->format('Y-m-d') !== $payrollPeriodEnd
        ) {
            throw new \InvalidArgumentException(
                'Konec mzdového období není platné datum.',
            );
        }

        $lock = $this->db->pdo()->prepare(
            'SELECT locked_until
               FROM accounting_supplier_settings
              WHERE supplier_id = ?'
        );
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $minimum = $periodEnd;
        if (is_string($lockedUntil) && $lockedUntil !== '') {
            $lockedDate = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $lockedUntil,
            );
            if ($lockedDate === false
                || $lockedDate->format('Y-m-d') !== $lockedUntil
            ) {
                throw new \UnexpectedValueException(
                    'Účetní zámek firmy nemá platné datum.',
                );
            }
            $afterLock = $lockedDate->modify('+1 day');
            if ($afterLock > $minimum) {
                $minimum = $afterLock;
            }
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT starts_on, ends_on, fiscal_year
               FROM accounting_periods
              WHERE supplier_id = ?
                AND status = "open"
                AND ends_on >= ?
              ORDER BY
                CASE WHEN ? BETWEEN starts_on AND ends_on THEN 0 ELSE 1 END,
                starts_on,
                id
              LIMIT 1
              FOR UPDATE'
        );
        $minimumDate = $minimum->format('Y-m-d');
        $statement->execute([$supplierId, $minimumDate, $minimumDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException(
                'Pro mzdový předpis není dostupné otevřené účetní období.',
            );
        }
        $startsOn = self::databaseString(
            $row['starts_on'] ?? null,
            'starts_on',
        );
        $endsOn = self::databaseString($row['ends_on'] ?? null, 'ends_on');
        $entryDate = $startsOn > $minimumDate ? $startsOn : $minimumDate;
        if ($entryDate > $endsOn) {
            throw new \DomainException(
                'Pro mzdový předpis není dostupné otevřené účetní datum.',
            );
        }
        $this->assertSameFiscalYear(
            $supplierId,
            $payrollPeriodEnd,
            self::databaseInt($row['fiscal_year'] ?? null, 'fiscal_year'),
            $entryDate,
        );

        return $entryDate;
    }

    /**
     * Posun na první otevřené období NESMÍ přejít hranici účetního roku.
     *
     * Zamčené nebo uzavřené prosincové období dosud znamenalo, že se lednový
     * (a jakýkoli jiný) mzdový náklad tiše přesunul do PRVNÍHO otevřeného
     * období — klidně do dalšího roku. Náklad by se tím vykázal v období, se
     * kterým věcně ani časově nesouvisí (§ 3 odst. 1 ZoÚ), zkreslil by výsledek
     * hospodaření obou let a základ daně z příjmů.
     *
     * Uvnitř JEDNOHO účetního roku je posun v pořádku — tam jde jen o volbu
     * dne, ne o změnu období, se kterým náklad souvisí. Přes hranici roku se
     * proto zaúčtování ODMÍTNE a účetní musí vědomě rozhodnout: otevřít
     * období, nebo mzdu zaúčtovat jako dohadnou položku minulého roku.
     *
     * Kontroluje se jen tehdy, když účetní rok mzdového období vůbec známe.
     * Firma, která na mzdové období nemá založené účetní období, dosavadní
     * chování nemění — není proti čemu měřit.
     */
    private function assertSameFiscalYear(
        int $supplierId,
        string $payrollPeriodEnd,
        int $entryFiscalYear,
        string $entryDate,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'SELECT fiscal_year
               FROM accounting_periods
              WHERE supplier_id = ?
                AND ? BETWEEN starts_on AND ends_on
              ORDER BY id
              LIMIT 1'
        );
        $statement->execute([$supplierId, $payrollPeriodEnd]);
        $payrollFiscalYear = $statement->fetchColumn();
        if ($payrollFiscalYear === false || $payrollFiscalYear === null) {
            return;
        }
        if ((int) $payrollFiscalYear === $entryFiscalYear) {
            return;
        }

        throw new \DomainException(
            "Mzdový předpis za období do {$payrollPeriodEnd} by se zaúčtoval "
            . "k {$entryDate}, tedy do jiného účetního roku ({$entryFiscalYear} "
            . "místo {$payrollFiscalYear}). Časová souvislost nákladu podle § 3 "
            . 'odst. 1 zákona o účetnictví to nedovoluje — otevřete účetní '
            . 'období mzdy, nebo mzdu zaúčtujte ručně jako dohadnou položku.',
        );
    }

    /**
     * @return array{
     *   id:int,
     *   allocations:list<array{
     *     allocation_key:string,
     *     account_code:string,
     *     signed_minor:int,
     *     description:string
     *   }>
     * }|null
     */
    public function latestEffectiveBefore(
        int $supplierId,
        int $runId,
        int $revisionNo,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT batch.id
               FROM payroll_posting_batches batch
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = batch.supplier_id
                AND revision.id = batch.revision_id
              WHERE batch.supplier_id = ?
                AND batch.run_id = ?
                AND revision.revision_no < ?
                AND batch.status IN ("posted", "no_change")
              ORDER BY revision.revision_no DESC
              LIMIT 1
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $runId, $revisionNo]);
        $batchId = $statement->fetchColumn();
        if ($batchId === false) {
            return null;
        }

        return [
            'id' => (int) $batchId,
            'allocations' => $this->allocations($supplierId, (int) $batchId),
        ];
    }

    public function insertPrepared(
        int $supplierId,
        int $runId,
        int $revisionId,
        ?int $previousBatchId,
        string $entryDate,
        PayrollPostingPreview $preview,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, previous_batch_id,
                 entry_date, status, target_hash, delta_hash, created_by)
             VALUES (?, ?, ?, ?, ?, "prepared", ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $runId,
            $revisionId,
            $previousBatchId,
            $entryDate,
            $preview->targetHash,
            $preview->deltaHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string,
     *   cost_center?:string
     * }> $allocations
     */
    public function insertAllocations(
        int $supplierId,
        int $batchId,
        array $allocations,
    ): void {
        if ($allocations === []) {
            return;
        }
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_allocations
                (supplier_id, batch_id, allocation_key, account_code,
                 signed_minor, description, cost_center)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($allocations as $allocation) {
            $statement->execute([
                $supplierId,
                $batchId,
                $allocation['allocation_key'],
                $allocation['account_code'],
                $allocation['signed_minor'],
                $allocation['description'],
                $allocation['cost_center'] ?? null,
            ]);
        }
    }

    public function markPosted(
        int $supplierId,
        int $batchId,
        int $journalEntryId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_posting_batches
                SET status = "posted", journal_entry_id = ?, posted_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "prepared"'
        );
        $statement->execute([$journalEntryId, $supplierId, $batchId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Účetní dávku se nepodařilo označit jako zaúčtovanou.');
        }
    }

    public function markNoChange(int $supplierId, int $batchId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_posting_batches
                SET status = "no_change", posted_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "prepared"'
        );
        $statement->execute([$supplierId, $batchId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Bezezměnovou účetní dávku se nepodařilo dokončit.');
        }
    }

    /**
     * @return list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string,
     *   cost_center?:string
     * }>
     */
    private function allocations(int $supplierId, int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT allocation_key, account_code, signed_minor, description,
                    cost_center
               FROM payroll_posting_allocations
              WHERE supplier_id = ? AND batch_id = ?
              ORDER BY allocation_key'
        );
        $statement->execute([$supplierId, $batchId]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatnou účetní alokaci.',
                );
            }
            $allocation = [
                'allocation_key' => self::databaseString(
                    $row['allocation_key'] ?? null,
                    'allocation_key',
                ),
                'account_code' => self::databaseString(
                    $row['account_code'] ?? null,
                    'account_code',
                ),
                'signed_minor' => self::databaseInt(
                    $row['signed_minor'] ?? null,
                    'signed_minor',
                ),
                'description' => self::databaseString(
                    $row['description'] ?? null,
                    'description',
                ),
            ];
            // Klíč se dosazuje jen když středisko opravdu je — alokace dávek
            // bez střediska tak zůstávají tvarově shodné s tím, co postavil
            // PayrollPostingLineBuilder, a `target_hash` se nerozejde.
            if (($row['cost_center'] ?? null) !== null) {
                $allocation['cost_center'] = self::databaseString(
                    $row['cost_center'],
                    'cost_center',
                );
            }
            $result[] = $allocation;
        }

        return $result;
    }

    private static function databaseInt(mixed $value, string $field): int
    {
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

    private static function nullableDatabaseInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : self::databaseInt($value, $field);
    }

    private static function databaseString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není text.",
            );
        }

        return $value;
    }

    private static function databaseHash(mixed $value, string $field): string
    {
        $hash = self::databaseString($value, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $hash;
    }

    private function rollBack(
        bool $ownTransaction,
        ?string $savepoint,
    ): void
    {
        $pdo = $this->db->pdo();
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif ($savepoint !== null && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }
}
