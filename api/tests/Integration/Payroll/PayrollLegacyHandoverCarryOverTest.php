<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Service\Payroll\PayrollLegacyHandoverInputCarrier;
use MyInvoice\Service\Payroll\PayrollLegacyRecapitulationService;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Předání měsíce od ruční rekapitulace s převodem hrubé mzdy do mzdových vstupů.
 *
 * Nález z provozní zkoušky: předání vztah bez importovaných vstupů (společník
 * s pevnou měsíční částkou) nechalo v běhu za předaný měsíc na
 * `payroll_component_missing`, ačkoli hrubá mzda ležela v odloženém listu.
 */
#[Group('integration')]
final class PayrollLegacyHandoverCarryOverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const GROSS = 30_000;

    private Connection $db;
    private PayrollLegacyRecapitulationService $handover;
    private PayrollMonthlyRecordRepository $records;
    private PayrollPeriodOwnershipService $ownership;
    private PayrollOpeningBalanceService $openings;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $handover = $container->get(PayrollLegacyRecapitulationService::class);
        $records = $container->get(PayrollMonthlyRecordRepository::class);
        $ownership = $container->get(PayrollPeriodOwnershipService::class);
        $openings = $container->get(PayrollOpeningBalanceService::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $components = $container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollLegacyRecapitulationService::class, $handover);
        self::assertInstanceOf(PayrollMonthlyRecordRepository::class, $records);
        self::assertInstanceOf(PayrollPeriodOwnershipService::class, $ownership);
        self::assertInstanceOf(PayrollOpeningBalanceService::class, $openings);
        self::assertInstanceOf(PayrollStatutoryAccumulatorRepository::class, $accumulators);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $this->db = $connection;
        $this->handover = $handover;
        $this->records = $records;
        $this->ownership = $ownership;
        $this->openings = $openings;
        $this->accumulators = $accumulators;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí zdrojový tenant nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $components->ensureDefaults($this->supplierId);
        $this->employeeId = $this->createEmployee('Syntetický společník');
        $this->employmentId = $this->createEmployment($this->employeeId, 'SPOL-1', 'partner_dependent');
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

    public function testCarryOverCreatesDraftBaseInputWithLegacyGross(): void
    {
        $this->seedLegacyHalfYear();

        $result = $this->handOver(carryOver: true);

        self::assertSame(1, $result['carried_over_inputs']);
        self::assertSame([], $result['skipped']);
        $inputs = $this->liveInputs($this->employmentId);
        self::assertCount(1, $inputs);
        self::assertSame('MZDA_MESICNI', $inputs[0]['code']);
        self::assertSame(self::GROSS * 100, (int) $inputs[0]['amount_minor']);
        self::assertSame('manual', $inputs[0]['source_kind']);
        self::assertSame('draft', $inputs[0]['status']);
        self::assertSame(
            PayrollLegacyHandoverInputCarrier::externalId(2026, 6, $this->employmentId),
            $inputs[0]['external_id'],
        );
        self::assertSame([(int) $inputs[0]['id']], $result['carried_input_ids']);
    }

    public function testApprovedCarryOverFreezesComponentSnapshot(): void
    {
        $this->seedLegacyHalfYear();

        $this->handOver(carryOver: true, approve: true);

        $inputs = $this->liveInputs($this->employmentId);
        self::assertCount(1, $inputs);
        self::assertSame('approved', $inputs[0]['status']);
        self::assertNotNull($inputs[0]['component_snapshot_json']);
    }

    /** Výchozí chování se nemění: bez volby předání žádný vstup nezaloží. */
    public function testWithoutOptionNoInputIsCreated(): void
    {
        $this->seedLegacyHalfYear();

        $result = $this->handover->handOverToModule(
            $this->supplierId,
            2026,
            6,
            $this->userId,
            'Období přebírá modul Mzdy.',
        );

        self::assertSame(0, $result['carried_over_inputs']);
        self::assertSame([], $result['skipped']);
        self::assertSame(1, $result['retired_records']);
        self::assertSame([], $this->liveInputs($this->employmentId));
    }

    public function testRepeatedHandOverDoesNotDuplicate(): void
    {
        $this->seedLegacyHalfYear();

        $this->handOver(carryOver: true);
        $second = $this->handOver(carryOver: true);

        self::assertSame(0, $second['carried_over_inputs']);
        self::assertSame(
            [['employee_id' => $this->employeeId, 'reason' => 'inputs_exist']],
            self::reasons($second['skipped']),
        );
        self::assertCount(1, $this->liveInputs($this->employmentId));
    }

    /**
     * Měsíc předaný dřív, než volba existovala (provozní případ), jde dodatečně
     * doplnit druhým předáním s volbou — převod čte odložené listy.
     */
    public function testLateCarryOverAfterPlainHandOver(): void
    {
        $this->seedLegacyHalfYear();
        $this->handOver(carryOver: false);
        self::assertSame([], $this->liveInputs($this->employmentId));

        $late = $this->handOver(carryOver: true);

        self::assertSame(1, $late['carried_over_inputs']);
        self::assertSame([], $late['skipped']);
        self::assertSame(self::GROSS * 100, (int) $this->liveInputs($this->employmentId)[0]['amount_minor']);
    }

    public function testExistingInputSkipsTheEmployment(): void
    {
        $this->seedLegacyHalfYear();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, external_id)
             VALUES (?, ?, ?, ?, "2026-06-01", 500000, "manual", "synthetic:bonus")',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $this->componentId('ODMENA'),
        ]);

        $result = $this->handOver(carryOver: true);

        self::assertSame(0, $result['carried_over_inputs']);
        self::assertSame(
            [['employee_id' => $this->employeeId, 'reason' => 'inputs_exist']],
            self::reasons($result['skipped']),
        );
        $inputs = $this->liveInputs($this->employmentId);
        self::assertCount(1, $inputs);
        self::assertSame('ODMENA', $inputs[0]['code']);
    }

    public function testMultipleEmploymentsSkipTheEmployee(): void
    {
        $this->seedLegacyHalfYear();
        $second = $this->createEmployment($this->employeeId, 'SPOL-2', 'dpp');

        $result = $this->handOver(carryOver: true);

        self::assertSame(0, $result['carried_over_inputs']);
        self::assertSame(
            [['employee_id' => $this->employeeId, 'reason' => 'multiple_employments']],
            self::reasons($result['skipped']),
        );
        self::assertSame([], $this->liveInputs($this->employmentId));
        self::assertSame([], $this->liveInputs($second));
    }

    /**
     * Roční úhrny: předání ubere červen z počátečního stavu a vstup ho vrátí
     * přes běh. Součet opening + převedený vstup musí dát přesně původních
     * šest měsíců — ne pět (měsíc by chyběl) ani sedm (dvojí započtení).
     */
    public function testAnnualTotalsCountTheMonthExactlyOnce(): void
    {
        $this->seedLegacyHalfYear();
        $monthMinor = self::GROSS * 100;
        self::assertSame(6 * $monthMinor, $this->openingValue('social_insurance', 'assessment_base_minor_units'));
        self::assertSame(6 * $monthMinor, $this->openingValue('income_tax', 'advance_base_minor_units'));

        $this->handOver(carryOver: true);

        $carried = array_sum(array_map(
            static fn (array $input): int => (int) $input['amount_minor'],
            $this->liveInputs($this->employmentId),
        ));
        self::assertSame(5, $this->openingValue('income_tax', 'completed_months'));
        self::assertSame(
            6 * $monthMinor,
            $this->openingValue('social_insurance', 'assessment_base_minor_units') + $carried,
        );
        self::assertSame(
            6 * $monthMinor,
            $this->openingValue('income_tax', 'advance_base_minor_units') + $carried,
        );
    }

    /**
     * Vstup, který by vážil jinak než měsíc v počátečním stavu, by roční úhrny
     * posunul — převod se proto odmítne a účetní rozhodne sama.
     */
    public function testOpeningMismatchSkipsTheEmployee(): void
    {
        $this->seedLegacyHalfYear(openingJuneMinor: 2_900_000);

        $result = $this->handOver(carryOver: true);

        self::assertSame(0, $result['carried_over_inputs']);
        self::assertSame(
            [['employee_id' => $this->employeeId, 'reason' => 'opening_balance_mismatch']],
            self::reasons($result['skipped']),
        );
        self::assertSame([], $this->liveInputs($this->employmentId));
    }

    /** Lumpsum bez rozpisu měsíců předání ubrat neumí, převod by měsíc zdvojil. */
    public function testNonItemizedOpeningSkipsTheEmployee(): void
    {
        $this->seedLegacyRecords();
        foreach (['social_insurance', 'income_tax'] as $kind) {
            $this->accumulators->appendOpeningBalance(
                $this->supplierId,
                $this->employeeId,
                2026,
                $kind,
                $kind === 'social_insurance'
                    ? ['assessment_base_minor_units' => 6 * self::GROSS * 100]
                    : [
                        'completed_months' => 6,
                        'advance_base_minor_units' => 6 * self::GROSS * 100,
                        'withholding_base_minor_units' => 0,
                        'advance_tax_minor_units' => 0,
                        'withholding_tax_minor_units' => 0,
                        'applied_non_refundable_credits_minor_units' => 0,
                        'applied_child_credit_minor_units' => 0,
                        'tax_bonus_minor_units' => 0,
                        'bonus_qualifying_income_minor_units' => 0,
                    ],
                'Syntetický souhrn bez rozpisu',
                [],
                'synthetic-lumpsum:' . $kind,
            );
        }
        $this->claimLegacy(6);

        $result = $this->handOver(carryOver: true);

        self::assertSame(
            [['employee_id' => $this->employeeId, 'reason' => 'opening_balance_not_itemized']],
            self::reasons($result['skipped']),
        );
        self::assertSame([], $this->liveInputs($this->employmentId));
    }

    /** @return array<string,mixed> */
    private function handOver(bool $carryOver, bool $approve = false): array
    {
        return $this->handover->handOverToModule(
            $this->supplierId,
            2026,
            6,
            $this->userId,
            'Období přebírá modul Mzdy.',
            null,
            null,
            null,
            $carryOver,
            $approve,
        );
    }

    /** Leden až červen ručně, počáteční stav z týchž listů, červen v legacy rezervaci. */
    private function seedLegacyHalfYear(?int $openingJuneMinor = null): void
    {
        $this->seedLegacyRecords();
        $months = [];
        for ($month = 1; $month <= 6; ++$month) {
            $weight = $month === 6 && $openingJuneMinor !== null
                ? $openingJuneMinor
                : self::GROSS * 100;
            $months[] = [
                'month' => $month,
                'social_assessment_base_minor_units' => $weight,
                'advance_base_minor_units' => $weight,
                'advance_tax_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ];
        }
        $this->openings->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            $months,
            'Syntetická rekapitulace (payroll_monthly_records)',
            $this->userId,
        );
        $this->claimLegacy(6);
    }

    private function seedLegacyRecords(): void
    {
        for ($month = 1; $month <= 6; ++$month) {
            $this->records->upsert(
                $this->supplierId,
                $this->employeeId,
                2026,
                $month,
                ['gross' => self::GROSS, 'social_base' => self::GROSS, 'net' => 22_000],
                ['taxpayer' => 0, 'children' => 0, 'total' => 0],
                2_500,
                22_000,
                null,
            );
        }
    }

    private function claimLegacy(int $month): void
    {
        $this->ownership->claimLegacy(
            $this->supplierId,
            2026,
            $month,
            2026 * 100 + $month,
            $this->userId,
        );
    }

    private function createEmployee(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "managing_partner", "hpp", 0, 0, 0, NULL, 0, 1)',
        )->execute([$this->supplierId, $name]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createEmployment(int $employeeId, string $code, string $relationType): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, ?, "active", "2026-01-01", "2026-01-01", 0)',
        )->execute([$this->supplierId, $employeeId, $code, $relationType]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function componentId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND valid_from <= "2026-06-01"
                AND (valid_to IS NULL OR valid_to >= "2026-06-01")
              ORDER BY valid_from DESC LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $code]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "Chybí složka {$code}.");

        return (int) $id;
    }

    /** @return list<array<string,mixed>> */
    private function liveInputs(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.id, input.amount_minor, input.source_kind, input.status,
                    input.external_id, input.component_snapshot_json, component.code
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = "2026-06-01"
                AND input.status <> "cancelled"
              ORDER BY input.id',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function openingValue(string $kind, string $field): int
    {
        $opening = $this->accumulators->openingBalance($this->supplierId, $this->employeeId, 2026, $kind);
        self::assertIsArray($opening);

        return (int) ($opening['values'][$field] ?? 0);
    }

    /**
     * @param list<array{employee_id:int,reason:string,message:string}> $skipped
     * @return list<array{employee_id:int,reason:string}>
     */
    private static function reasons(array $skipped): array
    {
        return array_map(
            static fn (array $row): array => [
                'employee_id' => $row['employee_id'],
                'reason' => $row['reason'],
            ],
            $skipped,
        );
    }
}
