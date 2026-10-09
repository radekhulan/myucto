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
 * Opakovaný převod srovná částku předpisu měsíční mzdy, který zapsal dřívější převod
 * téhož zdroje. Dřívější převod z PAMICA bral sjednanou mzdu z krácené základní mzdy
 * a předpis tak vyplácel méně; bez opravy by chyba zůstala i po opraveném převodu,
 * protože existující předpis se dřív jen přeskočil.
 *
 * Syntetická data v transakci, kterou tearDown vrací.
 */
#[Group('integration')]
final class PayrollTakeoverRecurringWageRepairTest extends TestCase
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

    public function testRerunCorrectsTheAmountOfItsOwnPrescription(): void
    {
        $employmentId = $this->employment('OPR-1');
        $policy = PohodaPayrollTakeover::policy();

        $first = $this->writer->recurringWage($this->supplierId, $employmentId, self::takeover(51_421.0), $this->userId, $policy, new PayrollTakeoverRunState());
        self::assertSame(['recurring_wage' => 1], $first);
        self::assertSame([5_142_100], $this->amounts($employmentId));

        $second = $this->writer->recurringWage($this->supplierId, $employmentId, self::takeover(55_100.0), $this->userId, $policy, new PayrollTakeoverRunState());

        self::assertSame(['recurring_wage_corrected' => 1], $second);
        self::assertSame([5_510_000], $this->amounts($employmentId));
    }

    /** Zvýšení mzdy v srpnu: jeden dřívější předpis se ukončí 31. 7. a od 1. 8. vznikne nový. */
    public function testRaiseDuringTheYearSplitsTheOwnPrescription(): void
    {
        $employmentId = $this->employment('OPR-3');
        $policy = PohodaPayrollTakeover::policy();
        $this->writer->recurringWage($this->supplierId, $employmentId, self::takeover(55_000.0), $this->userId, $policy, new PayrollTakeoverRunState());

        $raised = new PayrollTakeoverEmployment(
            personalNumber: 'OPR',
            relationKey: '3',
            monthlyWages: [
                ['from' => '2026-01-01', 'amount' => 55_000.0, 'prorated' => false],
                ['from' => '2026-08-01', 'amount' => 62_500.0, 'prorated' => false],
            ],
        );
        $result = $this->writer->recurringWage($this->supplierId, $employmentId, $raised, $this->userId, $policy, new PayrollTakeoverRunState());

        self::assertSame(['recurring_wage_corrected' => 2], $result);
        $statement = $this->db->pdo()->prepare('SELECT amount_minor, valid_from, valid_to FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? ORDER BY valid_from');
        $statement->execute([$this->supplierId, $employmentId]);
        self::assertSame([
            ['amount_minor' => 5_500_000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-07-31'],
            ['amount_minor' => 6_250_000, 'valid_from' => '2026-08-01', 'valid_to' => null],
        ], array_map(static fn (array $r): array => ['amount_minor' => (int) $r['amount_minor'], 'valid_from' => (string) $r['valid_from'], 'valid_to' => $r['valid_to']], $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * N9: převod jde rok po roce a zdroj roku 2026 o zvýšení v roce 2027 neví. Opakovaný
     * převod obou let nesmí předpis přepisovat tam a zpátky: hodnoty i `row_version`
     * zůstanou a protokol opravu nehlásí.
     */
    public function testRepeatedMultiYearTakeoverLeavesPrescriptionsUntouched(): void
    {
        $employmentId = $this->employment('OPR-4');
        $policy = PohodaPayrollTakeover::policy();
        $year2026 = new PayrollTakeoverEmployment(
            personalNumber: 'OPR',
            relationKey: '4',
            monthlyWages: [['from' => '2026-01-01', 'amount' => 48_000.0, 'prorated' => false]],
        );
        $year2027 = new PayrollTakeoverEmployment(
            personalNumber: 'OPR',
            relationKey: '4',
            monthlyWages: [
                ['from' => '2026-01-01', 'amount' => 48_000.0, 'prorated' => false],
                ['from' => '2027-04-01', 'amount' => 52_000.0, 'prorated' => false],
            ],
        );
        $this->writer->recurringWage($this->supplierId, $employmentId, $year2026, $this->userId, $policy, new PayrollTakeoverRunState());
        $this->writer->recurringWage($this->supplierId, $employmentId, $year2027, $this->userId, $policy, new PayrollTakeoverRunState());
        $before = $this->rows($employmentId);
        self::assertSame([
            [4_800_000, '2026-01-01', '2027-03-31'],
            [5_200_000, '2027-04-01', null],
        ], array_map(static fn (array $r): array => [$r[0], $r[1], $r[2]], $before));

        $again2026 = $this->writer->recurringWage($this->supplierId, $employmentId, $year2026, $this->userId, $policy, new PayrollTakeoverRunState());
        $again2027 = $this->writer->recurringWage($this->supplierId, $employmentId, $year2027, $this->userId, $policy, new PayrollTakeoverRunState());

        self::assertSame([], $again2026);
        self::assertSame([], $again2027);
        self::assertSame($before, $this->rows($employmentId));
    }

    /** @return list<array{0:int,1:string,2:?string,3:int}> částka, od, do, row_version */
    private function rows(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare('SELECT amount_minor, valid_from, valid_to, row_version FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? ORDER BY valid_from');
        $statement->execute([$this->supplierId, $employmentId]);

        return array_map(
            static fn (array $r): array => [(int) $r['amount_minor'], (string) $r['valid_from'], $r['valid_to'] === null ? null : (string) $r['valid_to'], (int) $r['row_version']],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function testPrescriptionEditedByTheAccountantIsLeftAlone(): void
    {
        $employmentId = $this->employment('OPR-2');
        $policy = PohodaPayrollTakeover::policy();
        $this->writer->recurringWage($this->supplierId, $employmentId, self::takeover(51_421.0), $this->userId, $policy, new PayrollTakeoverRunState());
        $this->db->pdo()->prepare("UPDATE payroll_recurring_components SET note = 'Upraveno účetní.' WHERE supplier_id = ? AND employment_id = ?")
            ->execute([$this->supplierId, $employmentId]);

        $second = $this->writer->recurringWage($this->supplierId, $employmentId, self::takeover(55_100.0), $this->userId, $policy, new PayrollTakeoverRunState());

        self::assertSame([], $second);
        self::assertSame([5_142_100], $this->amounts($employmentId));
    }

    private static function takeover(float $wage): PayrollTakeoverEmployment
    {
        return new PayrollTakeoverEmployment(
            personalNumber: 'OPR',
            relationKey: '1',
            monthlyWages: [['from' => '2026-01-01', 'amount' => $wage, 'prorated' => false]],
        );
    }

    /** @return list<int> */
    private function amounts(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare('SELECT amount_minor FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? ORDER BY valid_from');
        $statement->execute([$this->supplierId, $employmentId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function employment(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 40000, 0, 1)',
        )->execute([$this->supplierId, "Syntetická osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2020-01-01", "2020-01-01", 4000000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }
}
