<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollTakeover;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pracovní podmínky převzaté ze zdroje, které se během roku změnily: stanovená týdenní
 * doba zaměstnavatele (37,5 h v lednu, 40 h od února při stejném úvazku 37,5 h) a forma
 * odměňování (měsíční mzda do února, úkolová od března).
 *
 * Bez rozlišení po měsících dostal leden úvazek dopočtený ze 40 h (93,75 %), tedy
 * stanovenou dobu a fond ze 40 h, a vztah s úkolovou mzdou kdykoli v roce neměl předpis
 * měsíční mzdy ani za měsíce před změnou (tarif 0).
 *
 * Syntetická data v transakci, kterou tearDown vrací.
 */
#[Group('integration')]
final class PayrollTakeoverTermsHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTakeoverEmploymentWriter $writer;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $writer = $container->get(PayrollTakeoverEmploymentWriter::class);
        $components = $container->get(PayrollComponentRepository::class);
        if (!$db instanceof Connection || !$writer instanceof PayrollTakeoverEmploymentWriter || !$components instanceof PayrollComponentRepository) {
            throw new \RuntimeException('Služby převodu nejsou dostupné.');
        }
        $this->db = $db;
        $this->writer = $writer;
        $pdo = $db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $components->ensureDefaults($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testStatedWeeklyHoursOfEachMonthGiveTheWorkloadOfThatMonth(): void
    {
        $employmentId = $this->employment('PDM-1', 37.5, 9375);
        $policy = PohodaPayrollTakeover::policy();

        $january = $this->writer->monthWeeklyHours($this->supplierId, $employmentId, '2026-01', 37.5, true, $this->userId, $policy, 37.5);
        $february = $this->writer->monthWeeklyHours($this->supplierId, $employmentId, '2026-02', 37.5, false, $this->userId, $policy, 40.0);
        $march = $this->writer->monthWeeklyHours($this->supplierId, $employmentId, '2026-03', 37.5, false, $this->userId, $policy, 40.0);

        self::assertSame(['weekly_hours' => 1], $january);
        self::assertSame(['weekly_hours' => 1], $february);
        self::assertSame([], $march, 'Beze změny proti únoru se nic nepíše.');
        self::assertSame([
            ['2021-04-01', '2026-01-31', '37.50', 10000],
            ['2026-02-01', null, '37.50', 9375],
        ], $this->terms($employmentId), 'Leden: plný úvazek při stanovené době 37,5 h; od února 93,75 % ze 40 h.');
    }

    public function testWithoutStatedHoursTheWorkloadKeepsTheVersionRatio(): void
    {
        $employmentId = $this->employment('PDM-2', 37.5, 9375);

        $result = $this->writer->monthWeeklyHours($this->supplierId, $employmentId, '2026-01', 37.5, true, $this->userId, PohodaPayrollTakeover::policy());

        self::assertSame([], $result);
        self::assertSame([['2021-04-01', null, '37.50', 9375]], $this->terms($employmentId));
    }

    public function testMonthlyWagePrescriptionEndsBeforeTheHourlyWageStarts(): void
    {
        $employmentId = $this->employment('PDM-3', 37.5, 9375);
        $employment = new PayrollTakeoverEmployment(
            personalNumber: 'PDM',
            relationKey: '3',
            monthlyWages: [['from' => '2026-01-01', 'amount' => 25_000.0, 'prorated' => false]],
            hourlyWage: true,
            hourlyWageFrom: '2026-03-01',
        );

        $result = $this->writer->recurringWage($this->supplierId, $employmentId, $employment, $this->userId, PohodaPayrollTakeover::policy(), new PayrollTakeoverRunState());

        self::assertSame(['recurring_wage_until_hourly' => 1, 'recurring_wage' => 1], $result);
        $statement = $this->db->pdo()->prepare('SELECT amount_minor, valid_from, valid_to FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ?');
        $statement->execute([$this->supplierId, $employmentId]);
        self::assertSame([[2_500_000, '2026-01-01', '2026-02-28']], array_map(
            static fn (array $r): array => [(int) $r['amount_minor'], (string) $r['valid_from'], $r['valid_to']],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        ));
    }

    public function testRelationHourlyFromTheStartGetsNoMonthlyWagePrescription(): void
    {
        $employmentId = $this->employment('PDM-4', 37.5, 9375);
        $state = new PayrollTakeoverRunState();
        $employment = new PayrollTakeoverEmployment(
            personalNumber: 'PDM',
            relationKey: '4',
            monthlyWages: [['from' => '2026-01-01', 'amount' => 25_000.0, 'prorated' => false]],
            hourlyWage: true,
        );

        self::assertSame(['recurring_wage_hourly' => 1], $this->writer->recurringWage($this->supplierId, $employmentId, $employment, $this->userId, PohodaPayrollTakeover::policy(), $state));
        self::assertSame(1, $state->hourlyWageRelations);
    }

    /** @return list<array{0:string,1:?string,2:string,3:int}> od, do, týdenní doba, úvazek */
    private function terms(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare('SELECT effective_from, effective_to, weekly_hours, workload_basis_points FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from');
        $statement->execute([$this->supplierId, $employmentId]);

        return array_map(
            static fn (array $r): array => [(string) $r['effective_from'], $r['effective_to'] === null ? null : (string) $r['effective_to'], (string) $r['weekly_hours'], (int) $r['workload_basis_points']],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function employment(string $code, float $weeklyHours, int $workload): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, ?, "Syntetická účtárna", 1)')
            ->execute([$this->supplierId, $code]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 25000, 0, 1)',
        )->execute([$this->supplierId, "Syntetická osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, ?, "employment", "active", "2021-04-01", "2021-04-01", 2500000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $officeId, $code]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on,
                 actual_start_on, monthly_gross_minor, weekly_hours, workload_basis_points,
                 social_insurance_participation, health_insurance_participation, tax_regime,
                 other_withholding_eligibility, tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2021-04-01", "2021-04-01", "2021-04-01", 2500000, ?, ?,
                     "automatic", "automatic", "advance", "unverified", 1, 1)',
        )->execute([$this->supplierId, $employmentId, $officeId, $weeklyHours, $workload]);

        return $employmentId;
    }
}
