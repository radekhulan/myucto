<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Svátek uvnitř nepřítomnosti v hodinách měsíčního hlášení (10275/10276).
 *
 * Pokyny MPSV k 10275 a 10276 zahrnují svátky v jinak pracovní dny mezi
 * neodpracované hodiny, „kdy se měsíční mzda nekrátí". Souhrn měsíce proto
 * musí stát na témž výkladu § 115 odst. 3 ZP jako krácení mzdy: svátek
 * uvnitř dovolené nebo placené překážky mzdu nekrátí a do hlášení patří,
 * svátek uvnitř rodičovské nebo neplaceného volna mzdu krátí a nepatří.
 *
 * Červenec 2026, pondělí 6. 7. je svátek, rozvrh pondělí až pátek po osmi
 * hodinách.
 */
#[Group('integration')]
final class PayrollJmhzWorkMonthHolidayAbsenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollJmhzWorkMonthSummaryBuilder $builder;
    private int $supplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->builder = $container->get(PayrollJmhzWorkMonthSummaryBuilder::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_absences', 'payroll_work_calendars'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->employmentId = $this->createEmployment();
        $this->createWorkCalendar();
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

    /** @return iterable<string,array{string,int}> */
    public static function absences(): iterable
    {
        yield 'dovolená svátek nečerpá' => ['vacation', 8_000];
        yield 'placená překážka mzdu za svátek nekrátí' => ['employer_obstacle', 8_000];
        yield 'rodičovská mzdu za svátek krátí' => ['parental', 0];
        yield 'neplacené volno mzdu za svátek krátí' => ['unpaid_leave', 0];
    }

    #[DataProvider('absences')]
    public function testHolidayInsideAbsenceFollowsTheWageRule(string $absenceType, int $expectedMillihours): void
    {
        $this->absence($absenceType, '2026-07-06', '2026-07-07');

        $preview = $this->builder->preview($this->supplierId, $this->employmentId, '2026-07-01');

        self::assertSame($expectedMillihours, $preview['holiday_millihours']);
    }

    private function absence(string $type, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, compensation_policy, support_status, status, requested_by)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", "none", "supported", "approved", ?)'
        )->execute([$this->supplierId, $this->employmentId, $type, $from, $to, $this->userId]);
    }

    private function createWorkCalendar(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, timezone_name, schedule_type,
                 week_pattern, weekly_minutes, valid_from, created_by)
             VALUES (?, ?, "Test", "Europe/Prague", "regular",
                     ?, 2400, "2026-01-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            '{"1":480,"2":480,"3":480,"4":480,"5":480,"6":0,"7":0}',
            $this->userId,
        ]);
    }

    private function createEmployment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba SVATEK", "employee", "hpp", 1, 1, 0, 40000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "SVATEK-1", "employment", "active",
                     "2026-01-01", "2026-01-01", 4000000, 0)'
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }
}
