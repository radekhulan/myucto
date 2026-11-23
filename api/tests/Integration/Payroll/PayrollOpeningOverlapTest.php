<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Počáteční stav, který pokrývá i počítaný měsíc.
 *
 * Převod z předchozího programu zapsal počáteční stav za leden až říjen, protože
 * mzdy se měly v MyÚčtu vést od listopadu. Firma pak první mzdové období posunula
 * na říjen, aby rozpracovaný říjen spočítalo MyÚčto. Kumulace před říjnem by bez
 * pojistky vzala říjnový počáteční stav a říjen by se do roku započetl dvakrát.
 */
#[Group('integration')]
final class PayrollOpeningOverlapTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollOpeningBalanceService $service;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $service = $container->get(PayrollOpeningBalanceService::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        if (!$db instanceof Connection
            || !$service instanceof PayrollOpeningBalanceService
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
        ) {
            throw new \RuntimeException('Služba počátečních stavů není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        $this->accumulators = $accumulators;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $stmt->execute([$this->supplierId, 'Převzatá osoba']);
        $this->employeeId = (int) $pdo->lastInsertId();

        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            array_map($this->month(...), range(1, 10)),
            'Převod: zpracované mzdy 1. až 10. měsíc 2026',
            null,
        );
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

    /** @return iterable<string,array{string}> */
    public static function kinds(): iterable
    {
        yield 'daň' => ['income_tax'];
        yield 'sociální pojištění' => ['social_insurance'];
        yield 'zdravotní pojištění' => ['health_insurance'];
    }

    #[DataProvider('kinds')]
    public function testOpeningCoveringTheCalculatedMonthIsRefused(string $kind): void
    {
        $this->expectException(PayrollStatutoryAccumulatorUnavailableException::class);
        $this->expectExceptionMessage('pokrývá měsíce až do 10., ale MyÚčto počítá už 10. měsíc');

        $this->accumulators->stateBeforePeriod($this->supplierId, $this->employeeId, 2026, '2026-10-01', $kind);
    }

    #[DataProvider('kinds')]
    public function testOpeningEndingBeforeTheCalculatedMonthIsUsed(string $kind): void
    {
        $state = $this->accumulators->stateBeforePeriod($this->supplierId, $this->employeeId, 2026, '2026-11-01', $kind);

        self::assertSame(
            $this->accumulators->openingBalance($this->supplierId, $this->employeeId, 2026, $kind)['values'],
            $state['totals'],
        );
    }

    public function testWholeYearReadStillIncludesTheOpening(): void
    {
        $state = $this->accumulators->stateForYear($this->supplierId, $this->employeeId, 2026, 'income_tax');

        self::assertSame(10, $state['totals']['completed_months']);
    }

    /** @return array<string,int> */
    private function month(int $month): array
    {
        return [
            'month' => $month,
            'social_assessment_base_minor_units' => 40_000_00,
            'health_assessment_base_minor_units' => 40_000_00,
            'health_employee_contribution_minor_units' => 1_800_00,
            'health_employer_contribution_minor_units' => 3_600_00,
            'health_minimum_top_up_minor_units' => 0,
            'advance_base_minor_units' => 40_000_00,
            'advance_tax_minor_units' => 3_430_00,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 2_570_00,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 40_000_00,
        ];
    }
}
