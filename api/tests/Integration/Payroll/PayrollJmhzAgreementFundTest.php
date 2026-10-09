<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sjednaný fond 10260 u dohody bez pracovního kalendáře.
 *
 * Pokyny MH 1.4.14 kap. 3.2.7 k 10260: u dohody „se uvede předpokládaný rozsah
 * pracovní doby v příslušném měsíci včetně plánované dovolené". Dohoda bez
 * rozvrhu dostává návrh z odpracované doby; bez dovolené by fond byl o dobu
 * dovolené menší a neplatilo by 10268 + 10275 = 10260 (reálná data, červen 2026:
 * 10 h práce a 15 h dovolené, PAMICA 25 h).
 */
#[Group('integration')]
final class PayrollJmhzAgreementFundTest extends TestCase
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
        foreach ([
            'payroll_employments', 'payroll_absences', 'payroll_shifts', 'payroll_time_months',
            'payroll_time_month_import_summaries', 'payroll_attendance_imports',
        ] as $table) {
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
        $this->employmentId = $this->createAgreement();
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

    /** Souhrn importu: 10 h práce a 15 h dovolené dávají fond 25 h, ne 10 h. */
    public function testImportSummaryAgreementFundIncludesVacation(): void
    {
        $this->importSummaryMonth(['worked_hours' => 10_000, 'vacation_hours' => 15_000]);

        $preview = $this->builder->preview($this->supplierId, $this->employmentId, '2026-06-01');

        self::assertSame('25', $preview['suggestions']['agreed_fund_hours']);
        self::assertSame('10', $preview['suggestions']['worked_hours']);
        self::assertSame('15', $preview['suggestions']['vacation_hours']);
    }

    /**
     * Totéž u měsíce z intervalů: dohoda bez kalendáře s osmihodinovou
     * dovolenou na publikované směně a bez odpracované doby má fond 8 h.
     */
    public function testEntriesAgreementFundIncludesVacation(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, compensation_policy, support_status, status, requested_by)
             VALUES (?, ?, "vacation", "2026-06-15", "2026-06-15", "Europe/Prague", "none", "supported", "approved", ?)'
        )->execute([$this->supplierId, $this->employmentId, $this->userId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, "2026-06-15 06:00:00", "2026-06-15 14:30:00", "Europe/Prague", 30, "published", ?, NOW())'
        )->execute([$this->supplierId, $this->employmentId, md5('2026-06-15'), $this->userId]);

        $preview = $this->builder->preview($this->supplierId, $this->employmentId, '2026-06-01');

        self::assertSame('8', $preview['suggestions']['agreed_fund_hours']);
        self::assertSame('8', $preview['suggestions']['vacation_hours']);
    }

    /** @param array<string,int> $values millihodiny podle významu */
    private function importSummaryMonth(array $values): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, work_source, revision_no)
             VALUES (?, ?, "2026-06-01", "open", "import_summary", 1)'
        )->execute([$this->supplierId, $this->employmentId]);
        $timeMonthId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_attendance_imports
                (supplier_id, period_start, source_system, content_sha256,
                 files_json, rules_json, person_count, metric_count, created_by)
             VALUES (?, "2026-06-01", "giriton", ?, "[]", "{}", 1, 1, ?)'
        )->execute([$this->supplierId, random_bytes(32), $this->userId]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_time_month_import_summaries
                (supplier_id, time_month_id, time_month_revision_no, employment_id,
                 period_start, attendance_import_id, values_json, worked_days,
                 sources_json, content_sha256, created_by)
             VALUES (?, ?, 1, ?, "2026-06-01", ?, ?, NULL, "{}", ?, ?)'
        )->execute([
            $this->supplierId,
            $timeMonthId,
            $this->employmentId,
            $importId,
            (string) json_encode($values),
            str_repeat('a', 64),
            $this->userId,
        ]);
    }

    private function createAgreement(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba DPP", "employee", "dpp", 0, 0, 0, 0, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "DPP-1", "dpp", "active", "2026-01-01", "2026-01-01", 0, 0)'
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }
}
