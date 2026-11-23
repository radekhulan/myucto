<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Uložené vazby osoby z cizího systému (osobní číslo nebo jméno) na pracovní vztah.
 */
final class PayrollImportLinkRepository
{
    public const KIND_PERSONAL_NUMBER = 'personal_number';
    public const KIND_NAME = 'name';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,array{employee_id:int,employment_id:int}> klíč `kind\0external_key` */
    public function all(int $supplierId, string $sourceSystem): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT key_kind, external_key, employee_id, employment_id
               FROM payroll_import_links
              WHERE supplier_id = ? AND source_system = ?'
        );
        $stmt->execute([$supplierId, $sourceSystem]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_import_links') as $row) {
            $result[self::key((string) $row['key_kind'], (string) $row['external_key'])] = [
                'employee_id' => PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                'employment_id' => PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id'),
            ];
        }

        return $result;
    }

    /** @return bool true, když vazba vznikla nebo se změnila */
    public function save(
        int $supplierId,
        string $sourceSystem,
        string $keyKind,
        string $externalKey,
        int $employeeId,
        int $employmentId,
        ?int $userId,
    ): bool {
        $externalKey = mb_substr(trim($externalKey), 0, 191);
        if ($externalKey === '') {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id, employment_id FROM payroll_import_links
              WHERE supplier_id = ? AND source_system = ? AND key_kind = ? AND external_key = ?'
        );
        $stmt->execute([$supplierId, $sourceSystem, $keyKind, $externalKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)
            && (int) $existing['employment_id'] === $employmentId
            && (int) $existing['employee_id'] === $employeeId) {
            return false;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_import_links
                (supplier_id, source_system, key_kind, external_key, employee_id, employment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                employee_id = VALUES(employee_id),
                employment_id = VALUES(employment_id)'
        )->execute([$supplierId, $sourceSystem, $keyKind, $externalKey, $employeeId, $employmentId, $userId]);

        return true;
    }

    /** Porovnání klíčů je bez ohledu na velikost písmen — stejně jako collation sloupce. */
    public static function key(string $keyKind, string $externalKey): string
    {
        return $keyKind . "\0" . mb_strtolower(trim($externalKey), 'UTF-8');
    }
}
