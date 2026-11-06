<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Tenant-scoped persistence historizovaného účetního rámce. */
final class AccountingFrameworkRepository
{
    public function __construct(private readonly Connection $db) {}

    public function forDate(int $supplierId, string $date): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT accounting_framework
               FROM supplier_accounting_frameworks
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $date]);
        $framework = $stmt->fetchColumn();

        return is_string($framework) && $framework !== '' ? $framework : null;
    }

    /** @param list<int> $supplierIds @return array<int,string> */
    public function forDateForSuppliers(array $supplierIds, string $date): array
    {
        if ($supplierIds === []) return [];
        $marks = implode(',', array_fill(0, count($supplierIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT supplier_id, accounting_framework FROM (
                SELECT supplier_id, accounting_framework,
                       ROW_NUMBER() OVER (PARTITION BY supplier_id ORDER BY effective_from DESC, id DESC) AS position
                  FROM supplier_accounting_frameworks
                 WHERE supplier_id IN ({$marks}) AND effective_from <= ?
            ) ranked WHERE position = 1"
        );
        $stmt->execute([...$supplierIds, $date]);
        $frameworks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $frameworks[(int) $row['supplier_id']] = (string) $row['accounting_framework'];
        }
        return $frameworks;
    }

    /** @return list<array{id:int,supplier_id:int,effective_from:string,accounting_framework:string,created_at:string,created_by:?int}> */
    public function listForTenant(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, effective_from, accounting_framework, created_at, created_by
               FROM supplier_accounting_frameworks
              WHERE supplier_id = ?
              ORDER BY effective_from, id'
        );
        $stmt->execute([$supplierId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listForTenantForUpdate(int $supplierId): array
    {
        $this->assertTransaction();
        $stmt = $this->db->pdo()->prepare('SELECT id,supplier_id,effective_from,accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id=? ORDER BY effective_from,id FOR UPDATE');
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Current read rámce k datu, odolný proti staršímu REPEATABLE READ snapshotu. */
    public function forDateForUpdate(int $supplierId, string $date): ?string
    {
        $this->assertTransaction();
        // Zamčený řádek drží transakce; čte se znovu jen po zápisu ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}).
        return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db, 'framework|' . $supplierId . '|' . $date, fn (): ?string => $this->readForDateForUpdate($supplierId, $date));
    }

    private function readForDateForUpdate(int $supplierId, string $date): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT accounting_framework
               FROM supplier_accounting_frameworks
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $date]);
        $framework = $stmt->fetchColumn();

        return is_string($framework) && $framework !== '' ? $framework : null;
    }

    /** Current read začátku následujícího rámce, který ukončuje dotčený interval. */
    public function nextChangeForUpdate(int $supplierId, string $after): ?string
    {
        $this->assertTransaction();
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from
               FROM supplier_accounting_frameworks
              WHERE supplier_id = ? AND effective_from > ?
              ORDER BY effective_from, id
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $after]);
        $date = $stmt->fetchColumn();

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * Zamkne v chronologickém pořadí všechna období překrývající interval
     * [from, toExclusive). Stejný řádek období zamyká PostingService jako první.
     *
     * @return list<array{id:int,starts_on:string,ends_on:string}>
     */
    public function periodsOverlappingForUpdate(int $supplierId, string $from, ?string $toExclusive): array
    {
        $this->assertTransaction();
        $sql = 'SELECT id, starts_on, ends_on
                  FROM accounting_periods
                 WHERE supplier_id = ? AND ends_on >= ?';
        $params = [$supplierId, $from];
        if ($toExclusive !== null) {
            $sql .= ' AND starts_on < ?';
            $params[] = $toExclusive;
        }
        $sql .= ' ORDER BY starts_on, id FOR UPDATE';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Current read zaúčtovaných zápisů podle entry_date, nikoli period_id. Tím
     * pokrývá i legacy řádky navázané na chybné období. NOWAIT zabrání cyklu
     * period -> journal proti stornu, které již drží journal -> FK period.
     */
    public function hasPostedEntriesInIntervalForUpdate(
        int $supplierId,
        string $from,
        ?string $toExclusive,
    ): bool {
        $this->assertTransaction();
        $sql = 'SELECT id
                  FROM journal_entries
                 WHERE supplier_id = ? AND entry_date >= ? AND posted_at IS NOT NULL';
        $params = [$supplierId, $from];
        if ($toExclusive !== null) {
            $sql .= ' AND entry_date < ?';
            $params[] = $toExclusive;
        }
        $sql .= ' ORDER BY entry_date, id LIMIT 1 FOR UPDATE NOWAIT';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Current read účetní historie firmy: jakýkoli zápis v deníku (i nezaúčtovaný)
     * nebo jakýkoli účet v osnově. Obojí jde po indexu začínajícím supplier_id
     * a končí na prvním nalezeném řádku.
     */
    public function hasAccountingHistoryForUpdate(int $supplierId): bool
    {
        $this->assertTransaction();
        foreach ([
            'SELECT id FROM journal_entries WHERE supplier_id = ? ORDER BY supplier_id, id LIMIT 1 FOR UPDATE',
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? LIMIT 1 FOR UPDATE',
        ] as $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }

        return false;
    }

    private function assertTransaction(): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Zamykající čtení účetního rámce vyžaduje transakci.');
        }
    }
}
