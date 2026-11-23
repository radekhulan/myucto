<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kdy počáteční stavy zamrznou (PAM-14).
 *
 * Dřív je zamykal PRVNÍ schválený mzdový běh roku. U firmy, která přešla na
 * MyÚčto v průběhu roku, se ale převzatá čísla dolaďují — chyba nalezená
 * v listopadu už nešla opravit vůbec, protože běh za leden byl dávno schválený.
 *
 * Zámek se proto váže na to, co odešlo VEN: podané hlášení dotčeného období
 * a vydaný roční doklad zaměstnance. Schválený běh opravu jen zviditelní
 * (`approved_periods`), protože se sám nepřepočítá.
 */
#[Group('integration')]
final class PayrollOpeningBalanceLockTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollOpeningBalanceService $service;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $service = $container->get(PayrollOpeningBalanceService::class);
        if (!$db instanceof Connection || !$service instanceof PayrollOpeningBalanceService) {
            throw new \RuntimeException('Služba počátečních stavů není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        $pdo = $db->pdo();
        $source = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceSupplierId = $source === false ? 0 : (int) $source->fetchColumn();
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo);
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

    /**
     * Jádro PAM-14: schválený běh roku počáteční stav NEZAMYKÁ. Účetní musí
     * převzatá čísla opravit i po lednové výplatě — jinak je migrace k ničemu.
     */
    public function testApprovedRunAloneKeepsTheOpeningEditable(): void
    {
        $revisionId = $this->createApprovedRevision('2026-01-01');
        $this->createAccumulatorEntry($revisionId, '2026-01-01');

        $before = $this->service->current($this->supplierId, $this->employeeId, 2026);
        self::assertFalse($before['locked'], 'Schválený běh není podané hlášení.');
        self::assertSame(['2026-01'], $before['approved_periods']);

        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 4_000_000)],
            'Oprava převzaté rekapitulace',
            null,
        );

        self::assertNotNull($saved['openings']['social_insurance']);
        self::assertNull($saved['lock_reason']);
        // Už spočítaný měsíc se sám nepřepočítá — proto ho odpověď vyjmenuje.
        self::assertSame(['2026-01'], $saved['approved_periods']);
    }

    /** Co je podané, se zpětně nepřepisuje. */
    public function testSubmittedReportFreezesTheOpening(): void
    {
        $revisionId = $this->createApprovedRevision('2026-02-01');
        $this->createSubmittedSubmission($revisionId, '2026-02-01', '2026-02-28');

        $current = $this->service->current($this->supplierId, $this->employeeId, 2026);
        self::assertTrue($current['locked']);
        self::assertStringContainsString('2026-02', (string) $current['lock_reason']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('podané hlášení');
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 4_000_000)],
            '',
            null,
        );
    }

    /** Roční doklad je neměnný otisk celého roku včetně převzatých měsíců. */
    public function testIssuedAnnualDocumentFreezesTheOpening(): void
    {
        $this->createAnnualDocument(2026, 'taxable_income_advance_certificate');

        $current = $this->service->current($this->supplierId, $this->employeeId, 2026);
        self::assertTrue($current['locked']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('roční doklad');
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 4_000_000)],
            '',
            null,
        );
    }

    /** Podání za jinou osobu se téhle nesmí dotknout. */
    public function testSubmissionForAnotherPersonLeavesTheOpeningEditable(): void
    {
        $otherEmployeeId = $this->createEmployee($this->db->pdo());
        $revisionId = $this->createApprovedRevision('2026-02-01', $otherEmployeeId);
        $this->createSubmittedSubmission($revisionId, '2026-02-01', '2026-02-28');

        self::assertFalse(
            $this->service->current($this->supplierId, $this->employeeId, 2026)['locked'],
        );
    }

    /** @return array<string,int> */
    private function month(int $month, int $socialBase): array
    {
        return [
            'month' => $month,
            'social_assessment_base_minor_units' => $socialBase,
            'health_assessment_base_minor_units' => $socialBase,
            'health_employee_contribution_minor_units' => 180_000,
            'health_employer_contribution_minor_units' => 360_000,
            'health_minimum_top_up_minor_units' => 0,
            'advance_base_minor_units' => $socialBase,
            'advance_tax_minor_units' => 216_300,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257_000,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => $socialBase,
        ];
    }

    private function createEmployee(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $stmt->execute([$this->supplierId, 'Převzatá osoba']);

        return (int) $pdo->lastInsertId();
    }

    private function createApprovedRevision(string $periodStart, ?int $employeeId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, $periodStart, substr($periodStart, 0, 8) . '15']);
        $runId = (int) $pdo->lastInsertId();

        $snapshot = CanonicalJson::encode(['schema_version' => 'payroll-run-result.v2', 'people' => []]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            hash('sha256', $snapshot),
            $snapshot,
            hash('sha256', $snapshot . $periodStart),
            random_bytes(32),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $person = CanonicalJson::encode(['employee_id' => $employeeId ?? $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId ?? $this->employeeId,
            $person,
            hash('sha256', $person),
        ]);

        return $revisionId;
    }

    private function createAccumulatorEntry(int $revisionId, string $periodStart): void
    {
        $values = CanonicalJson::encode(['assessment_base_minor_units' => 4_000_000]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_statutory_accumulator_entries
                (supplier_id, employee_id, tax_year, period_start, revision_id,
                 calculation_kind, values_json, source_result_hash, record_hash)
             VALUES (?, ?, ?, ?, ?, "social_insurance", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            (int) substr($periodStart, 0, 4),
            $periodStart,
            $revisionId,
            $values,
            hash('sha256', 'result' . $revisionId),
            hash('sha256', $values . $revisionId),
        ]);
    }

    private function createSubmittedSubmission(int $revisionId, string $from, string $to): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference,
                 period_start, period_end, obligation_kind, preferred_channel, status,
                 source_event_type, source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash)
             VALUES (?, "production", "jmhz", "employer", "employer:1", ?, ?, "regular",
                     "vrep_apep", "submitted", "payroll_run", "run:1", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $from,
            $to,
            hash('sha256', 'event' . $from),
            hash('sha256', 'fingerprint' . $from),
            random_bytes(32),
        ]);
        $obligationId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel, status,
                 source_revision_id, source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash, submitted_at)
             VALUES (?, "production", ?, "regular", "vrep_apep", "submitted", ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $obligationId,
            $revisionId,
            hash('sha256', 'snapshot' . $obligationId),
            hash('sha256', 'request' . $obligationId),
            random_bytes(32),
        ]);
    }

    private function createAnnualDocument(int $year, string $purpose): void
    {
        $manifest = CanonicalJson::encode(['sources' => []]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $year,
            $purpose,
            'synteticky-sifrovany-snapshot',
            hash('sha256', 'snapshot' . $year),
            $manifest,
            hash('sha256', $manifest),
        ]);
    }
}
