<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Opakovaný převod sjednané mzdy s historií sazeb nesmí přepsat verze podmínek:
 * každá sazba se porovná s verzí platnou k jejímu datu, ne s nejnovější verzí.
 * Izolovaná firma v transakci, syntetická osoba i částky.
 */
#[Group('integration')]
final class PayrollTakeoverMonthlyWageRepeatTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollTakeoverEmploymentWriter $writer;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->writer = $this->container->get(PayrollTakeoverEmploymentWriter::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2025-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "PRV", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2025-01-01", "1234567890", "synthetic:takeover-wage")',
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testRepeatedTakeoverKeepsWageHistory(): void
    {
        $employmentId = $this->createEmployment();
        $employment = new PayrollTakeoverEmployment('101', 'synthetic:101', '2025-01-01', monthlyWages: [
            ['from' => '2025-01-01', 'amount' => 30000.0, 'prorated' => false],
            ['from' => '2025-06-01', 'amount' => 32000.0, 'prorated' => false],
            ['from' => '2026-01-01', 'amount' => 33000.0, 'prorated' => false],
        ]);
        $policy = new PayrollTakeoverPolicy('synthetic', 'Syntetický zdroj');

        $first = $this->writer->monthlyWage($this->supplierId, $employmentId, $employment, $this->userId, $policy);
        self::assertSame(3, $first['monthly_wage'] ?? 0);
        $history = [
            ['2025-01-01', 3_000_000],
            ['2025-06-01', 3_200_000],
            ['2026-01-01', 3_300_000],
        ];
        self::assertSame($history, $this->terms($employmentId));
        $events = $this->correctedEvents($employmentId);

        $second = $this->writer->monthlyWage($this->supplierId, $employmentId, $employment, $this->userId, $policy);
        self::assertArrayNotHasKey('monthly_wage', $second);
        self::assertSame($history, $this->terms($employmentId));
        self::assertSame($events, $this->correctedEvents($employmentId), 'opakovaný převod nesmí zapsat terms_corrected');
    }

    /**
     * Z první verze už bylo zúčtováno, první sazba proto dostala novou verzi za uzavřeným
     * obdobím. Opakovaný převod nesmí první sazbou přepsat pozdější verzi.
     */
    public function testRepeatedTakeoverKeepsWageHistoryAfterSettledVersion(): void
    {
        $employmentId = $this->createEmployment();
        $this->insertSettledRun('2025-03-01', $employmentId);
        $employment = new PayrollTakeoverEmployment('101', 'synthetic:101', '2025-01-01', monthlyWages: [
            ['from' => '2025-01-01', 'amount' => 30000.0, 'prorated' => false],
            ['from' => '2025-06-01', 'amount' => 32000.0, 'prorated' => false],
        ]);
        $policy = new PayrollTakeoverPolicy('synthetic', 'Syntetický zdroj');

        $this->writer->monthlyWage($this->supplierId, $employmentId, $employment, $this->userId, $policy);
        $history = $this->terms($employmentId);
        self::assertSame([['2025-01-01', null], ['2025-04-01', 3_000_000], ['2025-06-01', 3_200_000]], $history);
        $events = $this->correctedEvents($employmentId);

        $second = $this->writer->monthlyWage($this->supplierId, $employmentId, $employment, $this->userId, $policy);
        self::assertArrayNotHasKey('monthly_wage', $second);
        self::assertSame($history, $this->terms($employmentId));
        self::assertSame($events, $this->correctedEvents($employmentId));
    }

    private function insertSettledRun(string $periodStart, int $employmentId): void
    {
        $pdo = $this->db->pdo();
        $employeeId = (int) $pdo->query('SELECT employee_id FROM payroll_employments WHERE id = ' . $employmentId)->fetchColumn();
        $pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, 'posted', 1, 1)",
        )->execute([$this->supplierId, $periodStart, $periodStart]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version, ruleset_manifest_hash,
                  input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)",
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_employments (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, '{}', ?)",
        )->execute([$this->supplierId, $revisionId, $employeeId, $employmentId, str_repeat('c', 64)]);
    }

    private function createEmployment(): int
    {
        $service = $this->container->get(AttendanceImportService::class);
        $result = $service->persons($this->supplierId, '2025-01', [[
            'person_key' => 'mzda opakovaná',
            'full_name' => 'Mzda Opakovaná',
            'first_name' => 'Mzda',
            'last_name' => 'Opakovaná',
            'birth_number' => null,
            'relation_type' => 'employment',
            'weekly_hours' => '40',
            'planned_start_on' => '2025-01-01',
            'monthly_gross' => null,
            'activate' => true,
        ]], $this->userId, null, null)['results'][0];
        self::assertSame('created', $result['status'], (string) $result['message']);

        return (int) $result['employment_id'];
    }

    /** @return list<array{0:string,1:?int}> */
    private function terms(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, monthly_gross_minor FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from, id',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return array_map(
            static fn (array $row): array => [(string) $row['effective_from'], $row['monthly_gross_minor'] === null ? null : (int) $row['monthly_gross_minor']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function correctedEvents(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'terms_corrected'",
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }
}
