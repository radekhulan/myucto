<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Sample\SamplePayrollPurger;
use PDO;

/**
 * Úklid souhrnů pracovního měsíce pro JMHZ (`payroll_jmhz_work_month_revisions`).
 *
 * Souhrn vzniká každým schválením docházky, ne podáním. Co z aplikace k JMHZ
 * skutečně odešlo, drží snímky řádného a ELDP hlášení nad revizí mzdového běhu;
 * ty sloučení i smazání vztahu blokují samy. Souhrn bez nich je jen historie
 * schválení měsíce a sloučení vztahu i smazání vztahu ho zahazuje touto cestou.
 *
 * Souhrn i událost schválení, která na něj ukazuje, jsou append-only. Triggery
 * pustí smazání jen řádku výslovně označeného v `sample_data_entries` a jen při
 * nastavené `@payroll_sample_purge_supplier_id` (migrace 1995). Značky vzniknou
 * a zmizí v téže transakci.
 */
final class PayrollJmhzWorkMonthRevisionPurger
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param 'employment_id'|'time_month_id' $column
     * @param list<int> $ids
     * @return list<array{id:int,period_start:string,revision_no:int,summary_sha256:string}> pro auditní záznam
     */
    public function drop(int $supplierId, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, period_start, time_month_revision_no, summary_sha256
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND {$column} IN ({$placeholders})
              ORDER BY id"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $revisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($revisions === []) {
            return [];
        }
        $revisionIds = array_map(static fn (array $row): int => (int) $row['id'], $revisions);
        $placeholders = implode(', ', array_fill(0, count($revisionIds), '?'));
        $events = $pdo->prepare(
            "SELECT id FROM payroll_time_month_events
              WHERE supplier_id = ? AND jmhz_work_summary_revision_id IN ({$placeholders})"
        );
        $events->execute([$supplierId, ...$revisionIds]);
        $eventIds = array_map('intval', $events->fetchAll(PDO::FETCH_COLUMN));

        $rows = ['payroll_time_month_events' => $eventIds, 'payroll_jmhz_work_month_revisions' => $revisionIds];
        $mark = $pdo->prepare('INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)');
        foreach ($rows as $table => $tableIds) {
            foreach ($tableIds as $id) {
                $mark->execute([$supplierId, SamplePayrollPurger::ownershipType($table), $id]);
            }
        }
        $pdo->prepare('SET @payroll_sample_purge_supplier_id = ?')->execute([$supplierId]);
        try {
            foreach ($rows as $table => $tableIds) {
                if ($tableIds === []) {
                    continue;
                }
                $in = implode(', ', array_fill(0, count($tableIds), '?'));
                $pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ? AND id IN ({$in})")
                    ->execute([$supplierId, ...$tableIds]);
            }
        } finally {
            $pdo->exec('SET @payroll_sample_purge_supplier_id = NULL');
        }
        foreach ($rows as $table => $tableIds) {
            if ($tableIds === []) {
                continue;
            }
            $in = implode(', ', array_fill(0, count($tableIds), '?'));
            $pdo->prepare("DELETE FROM sample_data_entries WHERE supplier_id = ? AND entity_type = ? AND entity_id IN ({$in})")
                ->execute([$supplierId, SamplePayrollPurger::ownershipType($table), ...$tableIds]);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'period_start' => (string) $row['period_start'],
            'revision_no' => (int) $row['time_month_revision_no'],
            'summary_sha256' => (string) $row['summary_sha256'],
        ], $revisions);
    }
}
