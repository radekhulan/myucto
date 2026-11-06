<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Trvalé skrytí mzdových varování — zápis, přehled a obnovení.
 *
 * Firma s 500 zaměstnanci, z nichž padesát prohlášení poplatníka nepodepsalo
 * a je to tak správně, viděla tutéž větu u každého běhu každý měsíc. Skrytí
 * je konfigurace firmy (tabulka `payroll_warning_suppressions`): po osobě
 * (vztahu), nebo celý typ ve firmě. Co skrýt jde, určuje výhradně
 * {@see PayrollWarningSuppressionCatalog}; blokující chyby nikdy.
 *
 * Kdo, kdy a proč skryl, nese řádek; obnovení řádek smaže a obojí jde do
 * auditního logu, takže historie zůstane i po obnovení.
 */
final class PayrollWarningSuppressionService
{
    /** Strop jedné dávky — víc osob jedno varování nemá ani u velké firmy. */
    public const BULK_LIMIT = 5000;

    private const TABLE = 'payroll_warning_suppressions';

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
        private readonly PayrollPeopleRepository $people,
    ) {}

    /**
     * Platná skrytí firmy. Bez tabulky (instalace před migrací) prázdná sada —
     * seznam běhů kvůli tomu padat nesmí.
     */
    public function activeSet(int $supplierId): PayrollWarningSuppressionSet
    {
        if (!$this->db->hasTable(self::TABLE)) {
            return PayrollWarningSuppressionSet::empty();
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, subject_type, subject_id
               FROM payroll_warning_suppressions
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'code' => (string) $row['code'],
                'subject_type' => (string) $row['subject_type'],
                'subject_id' => (int) $row['subject_id'],
            ];
        }
        return PayrollWarningSuppressionSet::fromRows($rows);
    }

    /**
     * Přehled skrytí s lidskými popisky subjektů a jménem toho, kdo skryl.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT suppression.id, suppression.code, suppression.subject_type,
                    suppression.subject_id, suppression.reason,
                    suppression.created_by, suppression.created_at,
                    actor.name AS created_by_name
               FROM payroll_warning_suppressions suppression
          LEFT JOIN users actor ON actor.id = suppression.created_by
              WHERE suppression.supplier_id = ?
              ORDER BY suppression.code, suppression.subject_type, suppression.id'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $employeeIds = [];
        $employmentIds = [];
        foreach ($rows as $row) {
            if ($row['subject_type'] === PayrollWarningSuppressionCatalog::SUBJECT_EMPLOYEE) {
                $employeeIds[] = (int) $row['subject_id'];
            } elseif ($row['subject_type'] === PayrollWarningSuppressionCatalog::SUBJECT_EMPLOYMENT) {
                $employmentIds[] = (int) $row['subject_id'];
            }
        }
        $employeeLabels = $this->people->personLabels($supplierId, $employeeIds);
        $employmentLabels = $this->people->employmentLabels($supplierId, $employmentIds);

        return array_map(static function (array $row) use ($employeeLabels, $employmentLabels): array {
            $type = (string) $row['subject_type'];
            $subjectId = (int) $row['subject_id'];
            return [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'subject_type' => $type,
                'subject_id' => $type === PayrollWarningSuppressionCatalog::SUBJECT_SUPPLIER ? null : $subjectId,
                'subject_label' => match ($type) {
                    PayrollWarningSuppressionCatalog::SUBJECT_EMPLOYEE => $employeeLabels[$subjectId] ?? null,
                    PayrollWarningSuppressionCatalog::SUBJECT_EMPLOYMENT => $employmentLabels[$subjectId] ?? null,
                    default => null,
                },
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
                'created_by_name' => $row['created_by_name'] === null ? null : (string) $row['created_by_name'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * Skryje varování. `$subjectIds === null` = celý typ ve firmě, jinak
     * jen u vyjmenovaných osob (vztahů). Opakované skrytí nic nezdvojí.
     *
     * @param list<int>|null $subjectIds
     * @return array{created:int,skipped:int}
     */
    public function hide(
        int $supplierId,
        string $code,
        ?array $subjectIds,
        mixed $reason,
        int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $code = trim($code);
        $subjectType = PayrollWarningSuppressionCatalog::subjectType($code);
        if ($subjectType === null) {
            throw new \DomainException(
                'Tuto kontrolu skrýt nejde. Skrýt lze jen varování, o kterých účetní '
                . 'může vědět, že jsou v pořádku; blokující chyby a zákonné limity ne.',
            );
        }
        $reason = self::reason($reason);
        if ($subjectIds !== null) {
            $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
            if ($subjectIds === []) {
                throw new \InvalidArgumentException('Vyberte aspoň jednu osobu, u které se má varování skrýt.');
            }
            if (count($subjectIds) > self::BULK_LIMIT || min($subjectIds) <= 0) {
                throw new \InvalidArgumentException('Seznam osob ke skrytí není platný.');
            }
            $this->assertSubjectsBelong($supplierId, $subjectType, $subjectIds);
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_warning_suppressions
                (supplier_id, code, subject_type, subject_id, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $created = 0;
        $targets = $subjectIds === null
            ? [[PayrollWarningSuppressionCatalog::SUBJECT_SUPPLIER, 0]]
            : array_map(static fn (int $id): array => [$subjectType, $id], $subjectIds);
        foreach ($targets as [$type, $id]) {
            $insert->execute([$supplierId, $code, $type, $id, $reason, $userId]);
            $created += $insert->rowCount();
        }

        $this->activity->log(
            'payroll.warning_suppression.hidden',
            $userId,
            'payroll_warning_suppression',
            null,
            [
                'code' => $code,
                'scope' => $subjectIds === null ? PayrollWarningSuppressionCatalog::SUBJECT_SUPPLIER : $subjectType,
                'subject_ids' => $subjectIds,
                'reason' => $reason,
                'created_count' => $created,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return ['created' => $created, 'skipped' => count($targets) - $created];
    }

    /**
     * Obnoví skrytá varování (smaže skrytí). Cizí nebo neexistující id se
     * tiše přeskočí — počet obnovených to řekne.
     *
     * @param list<int> $ids
     */
    public function restore(
        int $supplierId,
        array $ids,
        int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): int {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > self::BULK_LIMIT || min($ids) <= 0) {
            throw new \InvalidArgumentException('Seznam skrytých varování k obnovení není platný.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $select = $this->db->pdo()->prepare(
            'SELECT id, code, subject_type, subject_id, reason
               FROM payroll_warning_suppressions
              WHERE supplier_id = ? AND id IN (' . $placeholders . ')'
        );
        $select->execute([$supplierId, ...$ids]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_warning_suppressions
              WHERE supplier_id = ? AND id IN (' . $placeholders . ')'
        );
        $delete->execute([$supplierId, ...$ids]);
        $restored = $delete->rowCount();

        $this->activity->log(
            'payroll.warning_suppression.restored',
            $userId,
            'payroll_warning_suppression',
            null,
            [
                'restored_count' => $restored,
                'suppressions' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['code'],
                    'subject_type' => (string) $row['subject_type'],
                    'subject_id' => (int) $row['subject_id'],
                    'reason' => $row['reason'],
                ], $rows),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $restored;
    }

    private static function reason(mixed $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        if (!is_string($reason)) {
            throw new \InvalidArgumentException('Důvod skrytí musí být text.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Důvod skrytí může mít nejvýš 500 znaků.');
        }
        return $reason;
    }

    /** @param list<int> $ids */
    private function assertSubjectsBelong(int $supplierId, string $subjectType, array $ids): void
    {
        $table = $subjectType === PayrollWarningSuppressionCatalog::SUBJECT_EMPLOYEE
            ? 'payroll_employees'
            : 'payroll_employments';
        $found = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM ' . $table . '
                  WHERE supplier_id = ? AND id IN (' . $placeholders . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            $found += (int) $stmt->fetchColumn();
        }
        if ($found !== count($ids)) {
            throw new \InvalidArgumentException('Některá z vybraných osob do firmy nepatří.');
        }
    }
}
