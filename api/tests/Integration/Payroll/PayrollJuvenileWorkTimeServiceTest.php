<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\Juvenile\JuvenileWorkTimeFinding;
use MyInvoice\Service\Payroll\Time\Juvenile\PayrollJuvenileWorkTimeService;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pracovní doba mladistvého nad záznamy více vztahů téže osoby: doba se
 * sčítá (§ 79a), nález vidí docházka i kontrola mzdového běhu jako varování.
 */
#[Group('integration')]
final class PayrollJuvenileWorkTimeServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-05-01';

    private Connection $db;
    private PayrollJuvenileWorkTimeService $service;
    private PayrollTimeService $time;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(PayrollJuvenileWorkTimeService::class);
        $this->time = $container->get(PayrollTimeService::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testShiftsOfTwoAgreementsAddUpAndReachOverviewAndRunValidation(): void
    {
        $employeeId = $this->employee('2010-03-01');
        $first = $this->employment($employeeId, 'DPP-MLAD-1');
        $second = $this->employment($employeeId, 'DPP-MLAD-2');
        $this->entry($first, '2026-05-04 05:00:00', '2026-05-04 09:00:00');
        $this->entry($second, '2026-05-04 09:30:00', '2026-05-04 13:31:00');

        $findings = $this->service->findingsForMany($this->supplierId, [$first, $second], self::PERIOD);

        self::assertSame([JuvenileWorkTimeFinding::CODE_DAILY], array_map(static fn (JuvenileWorkTimeFinding $f): string => $f->code, $findings[$first]));
        self::assertSame(481, $findings[$second][0]->actualMinutes, 'Druhý vztah vidí tentýž součet osoby.');
        $item = $this->time->overview($this->supplierId, '2026-05', false, employmentId: $first)['items'][0];
        self::assertSame(JuvenileWorkTimeFinding::CODE_DAILY, $item['juvenile_work_time'][0]['code']);
        self::assertSame('warning', $item['juvenile_work_time'][0]['severity']);

        $validations = $this->service->validations($this->supplierId, [
            ['employment_id' => $second, 'employee_id' => $employeeId],
            ['employment_id' => $first, 'employee_id' => $employeeId],
        ], self::PERIOD);
        self::assertCount(1, $validations, 'Osoba dostane nález jednou, ne u každého vztahu.');
        self::assertSame('warning', $validations[0]->severity);
        self::assertFalse($validations[0]->requiresOverride);
        self::assertSame(min($first, $second), $validations[0]->entityId);
    }

    public function testAdultAndMonthFromSummaryAreNotChecked(): void
    {
        $adult = $this->employment($this->employee('2000-01-01'), 'DPP-DOSP');
        $this->entry($adult, '2026-05-04 05:00:00', '2026-05-04 17:00:00');
        $summary = $this->employment($this->employee('2010-03-01'), 'DPP-SOUHRN');
        $this->entry($summary, '2026-05-04 05:00:00', '2026-05-04 17:00:00');
        $this->db->pdo()->prepare('INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, status, work_source) VALUES (?, ?, ?, "open", "manual_summary")')
            ->execute([$this->supplierId, $summary, self::PERIOD]);

        self::assertSame([], $this->service->findingsForMany($this->supplierId, [$adult, $summary], self::PERIOD));
    }

    private function employee(string $birthDate): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, employment_type, tax_declaration_signed,
                tax_credit_taxpayer, child_count, monthly_gross, auto_post, is_active, birth_date)
             VALUES (?, "Syntetický brigádník", "employee", "dpp", 0, 0, 0, 0, 0, 1, ?)',
        )->execute([$this->supplierId, $birthDate]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function employment(int $employeeId, string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date, actual_start_date, is_legacy_projection)
             VALUES (?, ?, ?, "dpp", "active", "2026-01-01", "2026-01-01", 0)',
        )->execute([$this->supplierId, $employeeId, $code]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function entry(int $employmentId, string $startsUtc, string $endsUtc): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries (supplier_id, employment_id, series_key, revision_no, category, starts_at_utc, ends_at_utc,
                timezone_name, break_minutes, source_kind, source_hash)
             VALUES (?, ?, ?, 1, "regular", ?, ?, "Europe/Prague", 0, "manual", ?)',
        )->execute([$this->supplierId, $employmentId, bin2hex(random_bytes(16)), $startsUtc, $endsUtc, random_bytes(32)]);
    }
}
