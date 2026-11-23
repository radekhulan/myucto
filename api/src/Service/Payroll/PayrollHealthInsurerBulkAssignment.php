<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHealthCoverageLaterRecord;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use PDO;

/**
 * Hromadné zadání zdravotní pojišťovny osobám, které ji v evidenci nemají.
 *
 * Hlášení JMHZ kód pojišťovny nenese, takže po převzetí z hlášení ji nemá nikdo
 * a výpočet zdravotního pojištění skončí u každého chybou. Jediná cesta byla
 * karta osoby, člověk po člověku. Tady se zadá v jedné tabulce: osoba → kód
 * pojišťovny od data.
 *
 * Výběr je stejný jako u mzdového běhu: osoba s pracovním vztahem, který
 * v měsíci trvá, bez věty zdravotního pojištění platné k prvnímu dni měsíce.
 * Zápis jde osobu po osobě přes {@see PayrollHealthInsurerWriter}, tedy přes
 * zákonnou evidenci jako karta; chyba jedné osoby ostatní nezastaví.
 */
final class PayrollHealthInsurerBulkAssignment
{
    private const MAX_ASSIGNMENTS = 2000;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollHealthInsurerWriter $writer,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{period_start:string,people:list<array{employee_id:int,full_name:string,suggested_from:string,known_insurer_code:?string,known_from:?string}>}
     */
    public function preview(int $supplierId, string $periodStart): array
    {
        $monthStart = self::monthStart($periodStart);
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
        $statement = $this->db->pdo()->prepare(
            "SELECT employee.id AS employee_id, employee.full_name,
                    MIN(COALESCE(employment.actual_start_date, employment.start_date)) AS first_start
               FROM payroll_employees employee
               JOIN payroll_employments employment
                 ON employment.supplier_id = employee.supplier_id
                AND employment.employee_id = employee.id
              WHERE employee.supplier_id = ?
                AND employment.status NOT IN ('no_show', 'archived')
                AND COALESCE(employment.actual_start_date, employment.start_date) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
                AND NOT EXISTS (
                      SELECT 1 FROM payroll_person_health_coverage_history coverage
                       WHERE coverage.supplier_id = employee.supplier_id
                         AND coverage.employee_id = employee.id
                         AND coverage.effective_from <= ?
                         AND (coverage.effective_to IS NULL OR coverage.effective_to >= ?)
                    )
              GROUP BY employee.id, employee.full_name
              ORDER BY employee.full_name, employee.id"
        );
        $statement->execute([$supplierId, $monthEnd, $monthStart, $monthStart, $monthStart]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        // Pojišťovna zapsaná až od pozdějšího měsíce (typicky s pozdějším
        // vztahem) se nabídne předvyplněná; účetní ji jen potvrdí.
        $later = (new PayrollHealthCoverageLaterRecord($this->db))->firstAfter(
            $supplierId,
            $monthStart,
            array_map(static fn (array $row): int => (int) $row['employee_id'], $rows),
        );
        $people = [];
        foreach ($rows as $row) {
            $known = $later[(int) $row['employee_id']] ?? null;
            $first = is_string($row['first_start']) ? substr($row['first_start'], 0, 7) . '-01' : $monthStart;
            $people[] = [
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                // Pojišťovna platí od nástupu; ohlášení pojišťovnám i roční
                // doklady ji čtou i za převzaté měsíce.
                'suggested_from' => $first,
                'known_insurer_code' => $known['insurer_code'] ?? null,
                'known_from' => $known['effective_from'] ?? null,
            ];
        }

        return ['period_start' => $monthStart, 'people' => $people];
    }

    /**
     * @param list<mixed> $assignments položky {employee_id, insurer_code, effective_from}
     * @return array{counts:array{applied:int,failed:int},applied:list<int>,failed:list<array{employee_id:int,message:string}>}
     */
    public function apply(int $supplierId, array $assignments, ?int $userId, ?string $ip, ?string $userAgent): array
    {
        $normalized = self::normalize($assignments);
        $applied = [];
        $failed = [];
        foreach ($normalized as $item) {
            try {
                $this->writer->assign(
                    $supplierId,
                    $item['employee_id'],
                    $item['insurer_code'],
                    $item['effective_from'],
                    $userId,
                    $ip,
                    $userAgent,
                );
                $applied[] = $item['employee_id'];
            } catch (\InvalidArgumentException|\DomainException $e) {
                $failed[] = ['employee_id' => $item['employee_id'], 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                // Chyba databáze (SQLSTATE …) do dialogu nepatří: účetní s ní nic nenadělá.
                error_log('payroll.health_insurer_bulk: ' . $e->getMessage());
                $failed[] = [
                    'employee_id' => $item['employee_id'],
                    'message' => 'Pojišťovnu se nepodařilo uložit kvůli chybě serveru. Zkuste to znovu; '
                        . 'pokud se chyba opakuje, kontaktujte podporu.',
                ];
            }
        }
        $this->activityLogger->log(
            'payroll.person_statutory_evidence.bulk_insurer',
            $userId,
            null,
            null,
            ['applied_employee_ids' => $applied, 'failed_employee_ids' => array_column($failed, 'employee_id')],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'counts' => ['applied' => count($applied), 'failed' => count($failed)],
            'applied' => $applied,
            'failed' => $failed,
        ];
    }

    /**
     * @param list<mixed> $assignments
     * @return list<array{employee_id:int,insurer_code:string,effective_from:string}>
     */
    public static function normalize(array $assignments): array
    {
        if ($assignments === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jednu osobu a pojišťovnu.');
        }
        if (count($assignments) > self::MAX_ASSIGNMENTS) {
            throw new \InvalidArgumentException('Najednou jde zadat nejvýš ' . self::MAX_ASSIGNMENTS . ' osob.');
        }
        $result = [];
        foreach ($assignments as $index => $item) {
            $row = $index + 1;
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Řádek {$row}: chybí osoba, pojišťovna a datum.");
            }
            $employeeId = filter_var($item['employee_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($employeeId) || $employeeId <= 0) {
                throw new \InvalidArgumentException("Řádek {$row}: osoba není platná.");
            }
            $code = is_string($item['insurer_code'] ?? null) ? trim($item['insurer_code']) : '';
            if (!in_array($code, HealthInsuranceSchemaCatalog::INSURER_CODES, true)) {
                throw new \InvalidArgumentException("Řádek {$row}: vyberte zdravotní pojišťovnu ze seznamu.");
            }
            $from = is_string($item['effective_from'] ?? null) ? $item['effective_from'] : '';
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
            if ($date === false || $date->format('Y-m-d') !== $from) {
                throw new \InvalidArgumentException("Řádek {$row}: platnost od musí být datum RRRR-MM-DD.");
            }
            if (isset($result[$employeeId])) {
                throw new \InvalidArgumentException("Řádek {$row}: osoba je v seznamu dvakrát.");
            }
            $result[$employeeId] = ['employee_id' => $employeeId, 'insurer_code' => $code, 'effective_from' => $from];
        }

        return array_values($result);
    }

    private static function monthStart(string $periodStart): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($date === false || $date->format('Y-m-d') !== $periodStart) {
            throw new \InvalidArgumentException('Období musí být datum RRRR-MM-DD.');
        }

        return $date->format('Y-m-01');
    }
}
