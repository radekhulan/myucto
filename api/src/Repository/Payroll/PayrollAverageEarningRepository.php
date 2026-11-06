<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\AverageEarningResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use PDO;

final class PayrollAverageEarningRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAverageEarningDeletionRepository $deletion,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT snapshot.*
               FROM payroll_average_earning_snapshots snapshot
              WHERE snapshot.supplier_id = ? AND snapshot.employment_id = ?
              ORDER BY applicable_year DESC, applicable_quarter DESC, revision_no DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = self::cast($row);
        }

        return $this->deletion->decorate($supplierId, $rows);
    }

    /**
     * Důvod, proč vztah v daném čtvrtletí neexistuje (null = existuje).
     *
     * JEDINÉ místo té kontroly: volá ji ruční založení průměru i hromadné
     * založení za čtvrtletí. Skutečný začátek bere stejně jako přehled
     * hromadných průměrů: `COALESCE(actual_start_date, start_date)`. Průměr
     * navázaný na čtvrtletí, kdy vztah netrval, JMHZ nikdy nepoužije
     * (typicky omylem založený na jiném vztahu téže osoby) a chybí pak u
     * vztahu, který ho potřebuje. Neexistující vztah vrací null, tu chybu
     * hlásí {@see self::create()}.
     */
    public function quarterMismatch(int $supplierId, int $employmentId, int $year, int $quarter): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(actual_start_date, start_date) AS started, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1));
        $to = $from->modify('+2 months')->modify('last day of this month');
        $started = is_string($row['started'] ?? null) ? $row['started'] : null;
        $ended = is_string($row['end_date'] ?? null) ? $row['end_date'] : null;
        $label = sprintf(
            'Průměr nejde založit na %d. čtvrtletí %d (%s až %s)',
            $quarter,
            $year,
            $from->format('j. n. Y'),
            $to->format('j. n. Y'),
        );
        if ($started !== null && $started > $to->format('Y-m-d')) {
            return sprintf(
                '%s: pracovní vztah začíná až %s. Vyberte vztah, který v tomto čtvrtletí trval.',
                $label,
                (new \DateTimeImmutable($started))->format('j. n. Y'),
            );
        }
        if ($ended !== null && $ended < $from->format('Y-m-d')) {
            return sprintf(
                '%s: pracovní vztah skončil %s. Vyberte vztah, který v tomto čtvrtletí trval.',
                $label,
                (new \DateTimeImmutable($ended))->format('j. n. Y'),
            );
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function create(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
        string $decisiveFrom,
        string $decisiveTo,
        int $grossMinor,
        int $allocatedMinor,
        int $workedMinutes,
        int $workedDays,
        ?string $rationale,
        AverageEarningResult $result,
        PayrollRulesetVersion $ruleset,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->lockEmployment($supplierId, $employmentId);
            $revision = $this->nextRevision($supplierId, $employmentId, $year, $quarter);
            $traceJson = CanonicalJson::encode($result->trace);
            $inputHash = hash('sha256', CanonicalJson::encode([
                'allocated_minor' => $allocatedMinor,
                'decisive_from' => $decisiveFrom,
                'decisive_to' => $decisiveTo,
                'gross_minor' => $grossMinor,
                'rationale' => $rationale,
                'worked_days' => $workedDays,
                'worked_minutes' => $workedMinutes,
            ]), true);
            $insert = $pdo->prepare(
                'INSERT INTO payroll_average_earning_snapshots
                    (supplier_id, employment_id, applicable_year, applicable_quarter,
                     revision_no, source_kind, decisive_from, decisive_to,
                     gross_earnings_minor, longer_period_allocated_minor,
                     worked_minutes, worked_days, average_hourly_minor, rationale,
                     support_status, status, ruleset_id, ruleset_hash,
                     input_hash, input_trace, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $employmentId,
                $year,
                $quarter,
                $revision,
                $result->sourceKind,
                $decisiveFrom,
                $decisiveTo,
                $grossMinor,
                $allocatedMinor,
                $workedMinutes,
                $workedDays,
                $result->averageHourlyMinor,
                $rationale,
                $result->supportStatus,
                'manual_review',
                $ruleset->id,
                $ruleset->canonicalHash,
                $inputHash,
                $traceJson,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Uložený snapshot průměru nebyl nalezen.');
    }

    /** @return array<string,mixed> */
    public function approve(
        int $supplierId,
        int $id,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare(
                'SELECT employment_id, applicable_year, applicable_quarter, row_version, status
                   FROM payroll_average_earning_snapshots
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE'
            );
            $lock->execute([$supplierId, $id]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Snapshot průměru nebyl nalezen.');
            }
            if ((int) $row['row_version'] !== $expectedVersion) {
                throw new PayrollAbsenceConflictException((int) $row['row_version']);
            }
            if ((string) $row['status'] !== 'manual_review') {
                throw new \InvalidArgumentException('Schválit lze pouze snapshot čekající na ruční kontrolu.');
            }

            $pdo->prepare(
                "UPDATE payroll_average_earning_snapshots
                    SET status = 'superseded', row_version = row_version + 1
                  WHERE supplier_id = ? AND employment_id = ?
                    AND applicable_year = ? AND applicable_quarter = ?
                    AND status = 'approved' AND id <> ?"
            )->execute([
                $supplierId,
                (int) $row['employment_id'],
                (int) $row['applicable_year'],
                (int) $row['applicable_quarter'],
                $id,
            ]);

            $update = $pdo->prepare(
                "UPDATE payroll_average_earning_snapshots
                    SET status = 'approved', support_status = 'supported',
                        approved_by = ?, approved_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?"
            );
            $update->execute([$userId, $supplierId, $id, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollAbsenceConflictException($expectedVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Schválený snapshot průměru nebyl nalezen.');
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? $this->deletion->decorateOne($supplierId, self::cast($row))
            : null;
    }

    /**
     * Nejnovější schválený snapshot průměru pro dané rozhodné čtvrtletí, nebo
     * null, pokud pro toto období žádný schválený snapshot neexistuje.
     *
     * @return array<string,mixed>|null
     */
    public function findApproved(
        int $supplierId,
        int $employmentId,
        int $applicableYear,
        int $applicableQuarter,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status = 'approved'
              ORDER BY revision_no DESC
              LIMIT 1"
        );
        $stmt->execute([
            $supplierId,
            $employmentId,
            $applicableYear,
            $applicableQuarter,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    private function lockEmployment(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
    }

    private function nextRevision(int $supplierId, int $employmentId, int $year, int $quarter): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(revision_no), 0) + 1
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ?
                AND applicable_year = ? AND applicable_quarter = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $year, $quarter]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employment_id', 'applicable_year',
            'applicable_quarter', 'revision_no', 'gross_earnings_minor',
            'longer_period_allocated_minor', 'worked_minutes', 'worked_days',
            'average_hourly_minor', 'row_version',
        ] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['input_trace'] = json_decode((string) $row['input_trace'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['input_hash']);
        return $row;
    }
}
