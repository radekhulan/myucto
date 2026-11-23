<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Počáteční stav ZDRAVOTNÍHO pojištění.
 *
 * Kumulace znala jen sociální pojištění a daň, takže zákazníkovi, který přešel
 * uprostřed roku, chyběla za rok přechodu celá zdravotní část — roční přehledy
 * pojišťoven neměly proti čemu sednout.
 */
#[Group('integration')]
final class PayrollHealthOpeningBalanceTest extends TestCase
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
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
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

    public function testHealthOpeningIsStoredAndReadBackAsYearlyTotal(): void
    {
        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1), $this->month(2)],
            'Mzdová rekapitulace 1–2/2026 z předchozího programu',
            null,
        );

        self::assertNotNull(
            $saved['openings']['health_insurance'] ?? null,
            'Uložení počátečních stavů musí založit i zdravotní kumulaci.',
        );

        $opening = $this->accumulators->openingBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'health_insurance',
        );
        self::assertSame([
            'assessment_base_minor_units' => 8_000_00,
            'employee_contribution_minor_units' => 360_00,
            'employer_contribution_minor_units' => 720_00,
            'minimum_top_up_minor_units' => 24_00,
        ], $opening['values']);

        $state = $this->accumulators->stateForYear(
            $this->supplierId,
            $this->employeeId,
            2026,
            'health_insurance',
        );
        self::assertSame($opening['values'], $state['totals']);
        self::assertSame('whole_year', $state['scope']);
    }

    /**
     * Roční úhrn za rok přechodu = převzatá část roku + měsíce spočítané tady.
     * Právě tenhle součet dřív nešel sestavit vůbec.
     */
    public function testYearlyHealthTotalAddsApprovedMonthsToTheOpening(): void
    {
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1), $this->month(2)],
            'Mzdová rekapitulace 1–2/2026',
            null,
        );

        $revisionId = $this->createApprovedRevision('2026-03-01');
        $resultHash = $this->createStatutoryPersonResult(
            $revisionId,
            'health_insurance',
            'calculated',
        );
        $this->accumulators->appendApprovedResult(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            'health_insurance',
            [
                'assessment_base_minor_units' => 4_100_00,
                'employee_contribution_minor_units' => 185_00,
                'employer_contribution_minor_units' => 369_00,
                'minimum_top_up_minor_units' => 0,
            ],
            $resultHash,
        );

        $state = $this->accumulators->stateForYear(
            $this->supplierId,
            $this->employeeId,
            2026,
            'health_insurance',
        );
        self::assertSame([
            'assessment_base_minor_units' => 12_100_00,
            'employee_contribution_minor_units' => 545_00,
            'employer_contribution_minor_units' => 1_089_00,
            'minimum_top_up_minor_units' => 24_00,
        ], $state['totals']);
    }

    /**
     * Zdravotní kumulace sdílí zámek s ostatními druhy (PAM-14): schválený
     * výsledek opravu ještě pustí a jen vyjmenuje už spočítané měsíce, vydaný
     * roční doklad ji zamkne. Zpětný přepis něčeho, co už odešlo ven, nesmí
     * projít ani u zdravotního pojištění.
     */
    public function testHealthOpeningIsEditableUntilTheYearIsReported(): void
    {
        $revisionId = $this->createApprovedRevision('2026-03-01');
        $resultHash = $this->createStatutoryPersonResult(
            $revisionId,
            'health_insurance',
            'calculated',
        );
        $this->accumulators->appendApprovedResult(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            'health_insurance',
            [
                'assessment_base_minor_units' => 4_100_00,
                'employee_contribution_minor_units' => 185_00,
                'employer_contribution_minor_units' => 369_00,
                'minimum_top_up_minor_units' => 0,
            ],
            $resultHash,
        );

        $corrected = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1)],
            'Pozdní oprava',
            null,
        );
        self::assertFalse($corrected['locked']);
        self::assertSame(['2026-03'], $corrected['approved_periods']);

        $this->issueAnnualDocument(2026);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('roční doklad');
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1), $this->month(2)],
            'Oprava po vydání dokladu',
            null,
        );
    }

    /** Potvrzení o zdanitelných příjmech — neměnný otisk celého roku. */
    private function issueAnnualDocument(int $year): void
    {
        $manifest = '{"sources":[]}';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, ?, "taxable_income_advance_certificate", 1, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $year,
            'synteticky-sifrovany-snapshot',
            hash('sha256', 'snapshot' . $year),
            $manifest,
            hash('sha256', $manifest),
        ]);
    }

    /** @return array<string,int> */
    private function month(int $month): array
    {
        return [
            'month' => $month,
            'social_assessment_base_minor_units' => 4_000_00,
            'health_assessment_base_minor_units' => 4_000_00,
            'health_employee_contribution_minor_units' => 180_00,
            'health_employer_contribution_minor_units' => 360_00,
            'health_minimum_top_up_minor_units' => 12_00,
            'advance_base_minor_units' => 900_00,
            'advance_tax_minor_units' => 135_00,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257_00,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 0,
        ];
    }

    private function createStatutoryPersonResult(
        int $revisionId,
        string $calculationKind,
        string $resultStatus,
    ): string {
        $pdo = $this->db->pdo();
        $inputJson = '{"synthetic_input":true}';
        $resultJson = '{"synthetic_result":true}';
        $inputHash = hash('sha256', $inputJson);
        $resultHash = hash('sha256', $resultJson);
        $pdo->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind, schema_version,
                 result_status, ruleset_id, ruleset_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, result_set_hash)
             VALUES (?, ?, ?, "synthetic.v1", ?, "synthetic-ruleset", ?,
                     ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $revisionId,
            $calculationKind,
            $resultStatus,
            str_repeat('2', 64),
            $inputJson,
            $inputHash,
            $resultJson,
            $resultHash,
            str_repeat('3', 64),
        ]);
        $statutoryResultId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_statutory_person_results
                (supplier_id, statutory_result_id, revision_id,
                 calculation_kind, employee_id, result_status,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $statutoryResultId,
            $revisionId,
            $calculationKind,
            $this->employeeId,
            $resultStatus,
            $inputJson,
            $inputHash,
            $resultJson,
            $resultHash,
        ]);

        return $resultHash;
    }

    private function createApprovedRevision(string $periodStart): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, LAST_DAY(?), "approved", 1)'
        )->execute([$this->supplierId, $periodStart, $periodStart]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, idempotency_key_hash,
                 approved_at)
             VALUES (?, ?, 1, NULL, "regular", "approved", "payroll-run-input.v1", ?,
                     "{}", ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('e', 64),
            str_repeat('f', 64),
            hash('sha256', implode(':', [
                'synthetic-health-revision',
                $periodStart,
                (string) random_int(1, PHP_INT_MAX),
            ]), true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")'
        )->execute([$this->supplierId, $revisionId, $this->employeeId]);

        return $revisionId;
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $stmt->execute([$supplierId, 'Převzatá osoba']);

        return (int) $pdo->lastInsertId();
    }
}
