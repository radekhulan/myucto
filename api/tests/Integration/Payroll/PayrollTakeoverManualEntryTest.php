<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverLayerCheck;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverManualEntryService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ruční zadání převzatých mezd plní obě vrstvy převzatého měsíce jedním
 * zápisem: převzatou mzdu (ELDP, převzatý běh) po vztazích a počáteční stav
 * (roční zúčtování, vyúčtování daně) jako součet za osobu.
 */
#[Group('integration')]
final class PayrollTakeoverManualEntryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTakeoverManualEntryService $service;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private PayrollTakeoverReader $reader;
    private PayrollTakeoverLayerCheck $layers;
    private PayrollInputRepository $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $mainEmploymentId;
    private int $agreementId;

    protected function setUp(): void
    {
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(PayrollTakeoverManualEntryService::class);
            $this->accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
            $this->reader = $container->get(PayrollTakeoverReader::class);
            $this->layers = $container->get(PayrollTakeoverLayerCheck::class);
            $this->inputs = $container->get(PayrollInputRepository::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-04-01")')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Převzatá osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $this->mainEmploymentId = $this->employment('HPP-M', 'employment', '2024-01-01', null, 1);
        // Dohoda jen v únoru a březnu — souběh dvou vztahů v převzatých měsících.
        $this->agreementId = $this->employment('DPP-M', 'dpp', '2026-02-01', '2026-03-31', 0);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testFormOffersTakeoverMonthsPerEmployment(): void
    {
        $form = $this->service->form($this->supplierId, $this->employeeId, 2026);

        self::assertSame([1, 2, 3], $form['takeover_months']);
        self::assertSame([1, 2, 3], $form['employments'][0]['months']);
        self::assertSame([2, 3], $form['employments'][1]['months']);
        self::assertCount(5, $form['rows']);
    }

    public function testOneEntryFillsBothLayersConsistently(): void
    {
        $rows = [
            $this->row($this->mainEmploymentId, 1, 40_000_00),
            $this->row($this->mainEmploymentId, 2, 40_000_00),
            $this->row($this->mainEmploymentId, 3, 42_000_00),
            $this->row($this->agreementId, 2, 5_000_00),
            $this->zero($this->agreementId, 3),
        ];

        $saved = $this->service->save($this->supplierId, $this->employeeId, 2026, $rows, '', null);

        // (A) počáteční stav je součet vztahů za osobu.
        $tax = $this->accumulators->openingBalance($this->supplierId, $this->employeeId, 2026, 'income_tax');
        self::assertSame(3, $tax['values']['completed_months']);
        self::assertSame(127_000_00, $tax['values']['advance_base_minor_units']);
        self::assertSame(45_000_00, $tax['evidence']['months'][1]['advance_base_minor_units']);
        // (B) převzatá mzda je po vztazích.
        $wages = $this->reader->forEmployee($this->supplierId, $this->employeeId, 2026)->months;
        self::assertCount(5, $wages);
        // A obě vrstvy se shodují.
        self::assertSame([], $this->layers->check($this->supplierId, 2026)['differences']);
        self::assertTrue($saved['rows'][0]['stored']);
    }

    /** Po podaném hlášení se smí doplnit zdravotní pojistné, základ SP ani daň ne. */
    public function testFiledReportLocksOnlyWhatItTookFromOpenings(): void
    {
        $stored = [[
            'month' => 1,
            'social_assessment_base_minor_units' => 450_000,
            'advance_tax_minor_units' => 67_500,
            'health_employee_contribution_minor_units' => 0,
        ]];
        $healthFilled = [['health_employee_contribution_minor_units' => 261_900, 'health_assessment_base_minor_units' => 2_240_000] + $stored[0]];
        $socialChanged = [['social_assessment_base_minor_units' => 500_000] + $stored[0]];
        $taxChanged = [['advance_tax_minor_units' => 70_000] + $stored[0]];

        self::assertEquals(
            PayrollOpeningBalanceService::reportedColumns($stored),
            PayrollOpeningBalanceService::reportedColumns($healthFilled),
        );
        self::assertNotEquals(
            PayrollOpeningBalanceService::reportedColumns($stored),
            PayrollOpeningBalanceService::reportedColumns($socialChanged),
        );
        self::assertNotEquals(
            PayrollOpeningBalanceService::reportedColumns($stored),
            PayrollOpeningBalanceService::reportedColumns($taxChanged),
        );
    }

    public function testEmptyMonthMustBeConfirmedAndMissingMonthIsRejected(): void
    {
        $rows = [
            $this->row($this->mainEmploymentId, 1, 40_000_00),
            $this->row($this->mainEmploymentId, 2, 40_000_00),
            $this->row($this->mainEmploymentId, 3, 42_000_00),
            $this->row($this->agreementId, 2, 5_000_00),
            ['confirmed_zero' => false] + $this->zero($this->agreementId, 3),
        ];
        try {
            $this->service->save($this->supplierId, $this->employeeId, 2026, $rows, '', null);
            self::fail('Nepotvrzený prázdný měsíc se nesmí uložit.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Měsíc 3 vztahu DPP-M', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DPP-M: 3');
        $this->service->save($this->supplierId, $this->employeeId, 2026, array_slice($rows, 0, 4), '', null);
    }

    public function testExistingImportedMonthIsOverwrittenUnderItsSource(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, gross_minor, advance_tax_minor)
             VALUES (?, "pamica", "2026-01-01", "X-1", "X-1-1", ?, ?, 1000, 0)',
        )->execute([$this->supplierId, $this->employeeId, $this->mainEmploymentId]);

        $this->service->save($this->supplierId, $this->employeeId, 2026, [
            $this->row($this->mainEmploymentId, 1, 40_000_00),
            $this->row($this->mainEmploymentId, 2, 40_000_00),
            $this->row($this->mainEmploymentId, 3, 42_000_00),
            $this->row($this->agreementId, 2, 5_000_00),
            $this->zero($this->agreementId, 3),
        ], '', null);

        $january = array_values(array_filter(
            $this->reader->forEmployee($this->supplierId, $this->employeeId, 2026)->months,
            static fn ($month): bool => $month->period === '2026-01',
        ));
        self::assertCount(1, $january, 'Ruční zadání nesmí k převzatému měsíci založit druhý řádek.');
        self::assertSame('pamica', $january[0]->source);
        self::assertSame(40_000_00, $january[0]->grossMinor);
    }

    /**
     * Firma, která začne vést mzdy v průběhu roku, převezme i čerpání ročních košů
     * benefitů (§ 6 odst. 9 písm. d) a m) ZDP) — jinak se limit ve zbytku roku
     * čerpá od nuly. Úprava měsíce bez polí košů převzatou hodnotu neztratí.
     */
    public function testTakenOverBenefitBasketsCountTowardsAnnualLimit(): void
    {
        $january = $this->row($this->mainEmploymentId, 1, 40_000_00);
        $january['non_cash_health_benefit_minor'] = 3_000_00;
        $january['non_cash_leisure_benefit_minor'] = 2_000_00;
        $january['old_age_savings_minor'] = 1_000_00;
        $february = $this->row($this->mainEmploymentId, 2, 40_000_00);
        $february['non_cash_leisure_benefit_minor'] = 500_00;
        $rows = [
            $january,
            $february,
            $this->row($this->mainEmploymentId, 3, 42_000_00),
            $this->row($this->agreementId, 2, 5_000_00),
            $this->zero($this->agreementId, 3),
        ];
        $this->service->save($this->supplierId, $this->employeeId, 2026, $rows, '', null);

        $inputs = $this->inputs;
        $total = fn (PayrollBenefitExemptionBasket $basket): int
            => $inputs->annualBasketTotal($this->supplierId, $this->employeeId, $basket, 2026);
        self::assertSame(3_000_00, $total(PayrollBenefitExemptionBasket::NonCashHealth));
        self::assertSame(2_500_00, $total(PayrollBenefitExemptionBasket::NonCashLeisure));
        self::assertSame(1_000_00, $total(PayrollBenefitExemptionBasket::OldAgeSavings));

        // Klient, který pole košů neposílá, převzaté čerpání nesmaže.
        unset($january['non_cash_health_benefit_minor'], $january['non_cash_leisure_benefit_minor'], $january['old_age_savings_minor']);
        $rows[0] = $january;
        $this->service->save($this->supplierId, $this->employeeId, 2026, $rows, '', null);
        self::assertSame(3_000_00, $total(PayrollBenefitExemptionBasket::NonCashHealth));
        self::assertSame(1_000_00, $total(PayrollBenefitExemptionBasket::OldAgeSavings));
        $form = $this->service->form($this->supplierId, $this->employeeId, 2026);
        self::assertSame(3_000_00, $form['rows'][0]['non_cash_health_benefit_minor']);
    }

    /** @return array<string,int|bool|null> */
    private function row(int $employmentId, int $month, int $gross): array
    {
        $advance = intdiv($gross * 15, 100);

        return [
            'employment_id' => $employmentId,
            'month' => $month,
            'gross_minor' => $gross,
            'net_minor' => $gross - $advance,
            'deductions_minor' => 0,
            'net_payable_minor' => $gross - $advance,
            'social_base_minor' => $gross,
            'health_base_minor' => $gross,
            'employee_social_minor' => 0,
            'employee_health_minor' => 0,
            'employer_social_minor' => 0,
            'employer_health_minor' => 0,
            'health_minimum_top_up_minor' => 0,
            'advance_base_minor' => $gross,
            'advance_tax_minor' => $advance,
            'withholding_base_minor' => 0,
            'withholding_tax_minor' => 0,
            'applied_credits_minor' => 0,
            'applied_child_credit_minor' => 0,
            'tax_bonus_minor' => 0,
            'insurance_days' => 28,
            'excluded_days' => 0,
            'worked_days_hundredths' => 2000,
            'worked_minutes' => 9600,
            'pension_participation' => true,
            'payout_date' => null,
        ];
    }

    /** @return array<string,int|bool|null> */
    private function zero(int $employmentId, int $month): array
    {
        $row = $this->row($employmentId, $month, 0);
        $row['insurance_days'] = 0;
        $row['worked_days_hundredths'] = 0;
        $row['worked_minutes'] = 0;
        $row['confirmed_zero'] = true;

        return $row;
    }

    private function employment(string $code, string $type, string $start, ?string $end, int $primary): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 4000000, 0, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $code,
            $type,
            $end === null ? 'active' : 'ended',
            $start,
            $start,
            $end,
            $primary,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
