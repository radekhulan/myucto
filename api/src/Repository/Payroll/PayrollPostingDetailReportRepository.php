<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Podklad sestavy podrobného zaúčtování mezd za měsíc.
 *
 * Čte CÍLOVÉ alokace účinné dávky každého běhu měsíce (poslední zaúčtovaná nebo
 * bezezměnová revize, stejné pravidlo jako
 * {@see PayrollDimensionCostReportRepository}) a vstupní snapshot její revize.
 * Cílové alokace účinné dávky jsou zůstatkem všech rozdílových zápisů běhu
 * v deníku, takže součet sestavy sedí na deník za období.
 *
 * Tři dotazy bez ohledu na počet běhů a zaměstnanců: dávky po běhu přes
 * `uq_payroll_run_period`, alokace přes `uq_payroll_posting_allocation`,
 * snapshoty podle primárního klíče revize.
 */
final class PayrollPostingDetailReportRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{batch_id:int,run_id:int,revision_id:int,revision_no:int,journal_entry_id:?int,status:string}>
     */
    public function effectiveBatches(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'WITH effective AS (
                SELECT batch.id AS batch_id,
                       batch.run_id,
                       batch.revision_id,
                       revision.revision_no,
                       batch.journal_entry_id,
                       batch.status,
                       ROW_NUMBER() OVER (
                           PARTITION BY batch.run_id
                           ORDER BY revision.revision_no DESC
                       ) AS position
                  FROM payroll_runs run
                  JOIN payroll_posting_batches batch
                    ON batch.supplier_id = run.supplier_id
                   AND batch.run_id = run.id
                  JOIN payroll_run_revisions revision
                    ON revision.supplier_id = batch.supplier_id
                   AND revision.id = batch.revision_id
                 WHERE run.supplier_id = ?
                   AND run.period_start = ?
                   AND batch.status IN ("posted", "no_change")
             )
             SELECT batch_id, run_id, revision_id, revision_no, journal_entry_id, status
               FROM effective
              WHERE position = 1
              ORDER BY run_id'
        );
        $statement->execute([$supplierId, $periodStart]);

        return array_map(static fn (array $row): array => [
            'batch_id' => (int) $row['batch_id'],
            'run_id' => (int) $row['run_id'],
            'revision_id' => (int) $row['revision_id'],
            'revision_no' => (int) $row['revision_no'],
            'journal_entry_id' => $row['journal_entry_id'] === null ? null : (int) $row['journal_entry_id'],
            'status' => (string) $row['status'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int> $batchIds
     * @return array<int,list<array{allocation_key:string,account_code:string,signed_minor:int,description:string,cost_center?:string,dimensions?:array<int,int>}>>
     */
    public function allocations(int $supplierId, array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT batch_id, allocation_key, account_code, signed_minor, description,
                    cost_center, dimensions
               FROM payroll_posting_allocations
              WHERE supplier_id = ?
                AND batch_id IN ({$placeholders})
              ORDER BY batch_id, allocation_key"
        );
        $statement->execute([$supplierId, ...$batchIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allocation = [
                'allocation_key' => (string) $row['allocation_key'],
                'account_code' => (string) $row['account_code'],
                'signed_minor' => (int) $row['signed_minor'],
                'description' => (string) $row['description'],
            ];
            if ($row['cost_center'] !== null) {
                $allocation['cost_center'] = (string) $row['cost_center'];
            }
            if ($row['dimensions'] !== null) {
                $dimensions = [];
                foreach ((array) json_decode((string) $row['dimensions'], true, flags: JSON_THROW_ON_ERROR) as $typeId => $valueId) {
                    $dimensions[(int) $typeId] = (int) $valueId;
                }
                if ($dimensions !== []) {
                    $allocation['dimensions'] = $dimensions;
                }
            }
            $result[(int) $row['batch_id']][] = $allocation;
        }

        return $result;
    }

    public function companyName(int $supplierId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) FROM supplier WHERE id = ?'
        );
        $statement->execute([$supplierId]);

        return (string) $statement->fetchColumn();
    }

    /**
     * @param list<int> $revisionIds
     * @return array<int,array<string,mixed>> revision_id → vstupní snapshot
     */
    public function inputSnapshots(int $supplierId, array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT id, input_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ?
                AND id IN ({$placeholders})"
        );
        $statement->execute([$supplierId, ...$revisionIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapshot = json_decode((string) $row['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            if (is_array($snapshot)) {
                $result[(int) $row['id']] = $snapshot;
            }
        }

        return $result;
    }
}
