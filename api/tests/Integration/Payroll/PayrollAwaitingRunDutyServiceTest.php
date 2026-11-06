<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\PayrollAwaitingRunDutyService;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Měsíc vedení mezd bez schváleného běhu musí mít v přehledech povinnost
 * „čeká na běh" (C-2). Dřív Měsíční přehled tvrdil „žádná otevřená položka"
 * a hlídač termínů o hlášení JMHZ za měsíc mlčel, dokud běh nikdo neschválil.
 */
#[Group('integration')]
final class PayrollAwaitingRunDutyServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private PayrollAwaitingRunDutyService $service;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period, row_version)
             VALUES (?, 'active', '2026-09-01', 1)",
        )->execute([$this->supplierId]);

        $this->service = new PayrollAwaitingRunDutyService(
            $this->db,
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            new PayrollDeadlineAssessmentService(self::clock()),
            new PayrollHistoricalPeriodService($container->get(PayrollModuleStateRepository::class)),
            self::clock(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testMonthWithPeopleAndNoApprovedRunIsReported(): void
    {
        $this->seedEmployment('2026-01-01');

        $missing = $this->service->missing($this->supplierId);

        self::assertSame(['2026-09'], array_column($missing, 'period'));
        self::assertSame(1, $missing[0]['employment_count']);
        self::assertSame('2026-10-20', $missing[0]['due_on']);
        self::assertSame([], $this->service->missing($this->supplierId, '2026-08'), 'Převzatý měsíc hlídá jiná služba.');
    }

    public function testCoveredOrEmptyMonthIsNotReported(): void
    {
        self::assertSame([], $this->service->missing($this->supplierId), 'Firma bez lidí nemá co hlásit.');

        $this->seedEmployment('2026-01-01');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, run_kind, status)
             VALUES (?, '2026-09-01', '2026-10-15', 'takeover', 'closed')",
        )->execute([$this->supplierId]);

        self::assertSame([], $this->service->missing($this->supplierId, '2026-09'));
    }

    private function seedEmployment(string $start): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický čekající", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-AW-1", "employment", "active", ?, 3000000, 0)',
        )->execute([$this->supplierId, $employeeId, $start]);
    }

    private static function clock(): ClockInterface
    {
        return new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-27 08:00:00', new \DateTimeZone('Europe/Prague'));
            }
        };
    }
}
