<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Historie sazby zákonného pojištění odpovědnosti zaměstnavatele
 * (vyhláška č. 125/1993 Sb.). Append-only: sazba se v čase mění, proto se
 * ukládá jako nová datovaná položka, ne přepisem jediného sloupce.
 */
final class PayrollAccidentInsuranceRateRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{id:int,institution_code:string,rate_per_mille:string,effective_from:string,created_at:string}> */
    public function list(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, institution_code, rate_per_mille, effective_from, created_at
               FROM payroll_accident_insurance_rates
              WHERE supplier_id = ?
              ORDER BY effective_from DESC, id DESC'
        );
        $statement->execute([$supplierId]);

        return array_map(
            self::row(...),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Sazba účinná k danému dni — poslední řádek s `effective_from <= $date`.
     *
     * @return array{id:int,institution_code:string,rate_per_mille:string,effective_from:string,created_at:string}|null
     */
    public function effectiveOn(int $supplierId, string $date): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, institution_code, rate_per_mille, effective_from, created_at
               FROM payroll_accident_insurance_rates
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::row($row);
    }

    public function insert(
        int $supplierId,
        string $institutionCode,
        string $ratePerMille,
        string $effectiveFrom,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_accident_insurance_rates
                (supplier_id, institution_code, rate_per_mille,
                 effective_from, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $institutionCode,
            $ratePerMille,
            $effectiveFrom,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $row */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'institution_code' => (string) $row['institution_code'],
            'rate_per_mille' => (string) $row['rate_per_mille'],
            'effective_from' => (string) $row['effective_from'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
