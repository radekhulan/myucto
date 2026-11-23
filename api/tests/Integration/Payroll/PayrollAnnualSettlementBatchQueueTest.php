<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentBatchRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPerformer;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\Document\AnnualPayrollSheetService;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateService;
use MyInvoice\Service\Payroll\Document\PayrollAnnualDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hromadné roční zúčtování (§ 38ch ZDP) přes frontu ročních dokumentů.
 *
 * Samotné `settle()` tu neběží: otevírá si vlastní transakci a roční revize
 * nejdou smazat (viz {@see AnnualSettlementIntegrationTest}). Fronta proto
 * dostává zástupce, který vrací přesně ty tři tvary odpovědi, které `settle()`
 * vrací — provedeno s dokladem, odmítnuto s překážkami, už zúčtováno — a test
 * hlídá, co s nimi fronta udělá.
 */
#[Group('integration')]
final class PayrollAnnualSettlementBatchQueueTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;

    private Connection $db;
    private PayrollAnnualDocumentBatchQueueService $queue;
    private int $supplierId;

    /** @var array<int,'performed'|'blocked'|'settled'> */
    private array $script = [];

    /** @var list<int> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $performer = $this->performer();
        $this->queue = new PayrollAnnualDocumentBatchQueueService(
            $container->get(PayrollAnnualDocumentBatchRepository::class),
            $container->get(AnnualPayrollSheetService::class),
            $container->get(AnnualTaxCertificateService::class),
            $performer,
        );
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testOnlyPeopleWhoRequestedTheSettlementAreQueued(): void
    {
        [$requester, $declined, $silent] = $this->approvedYear(3);
        $this->request($requester, 'requested');
        $this->request($declined, 'not_requested');
        $this->request($silent, 'requested', self::YEAR - 1);

        $batch = $this->enqueue();

        self::assertSame(PayrollAnnualDocumentBatchRepository::ANNUAL_SETTLEMENT, $batch['document_kind']);
        self::assertSame(1, $batch['item_count']);
        $items = $this->queue->items($this->supplierId, (int) $batch['id'], 10, 0)['items'];
        self::assertSame([$requester], array_column($items, 'employee_id'));
    }

    public function testYearWithoutRequestersIsRefusedWithAClearReason(): void
    {
        [$employee] = $this->approvedYear(1);
        $this->request($employee, 'not_requested');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nikdo o roční zúčtování nepožádal');
        $this->enqueue();
    }

    public function testRequesterWithoutApprovedYearResultIsNotQueued(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Žadatel bez mezd", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->request((int) $pdo->lastInsertId(), 'requested');

        $this->expectException(\DomainException::class);
        $this->enqueue();
    }

    /**
     * Provedené zúčtování ukazuje na archivovaný doklad, odmítnuté a už
     * provedené se přeskakují. Ani jedno z nich není selhání: dávka doběhne
     * bez chyb a opakovat se nemá co.
     */
    public function testOutcomesMapToSucceededAndSkippedItemsWithoutFailures(): void
    {
        [$performed, $blocked, $settled] = $this->approvedYear(3);
        foreach ([$performed, $blocked, $settled] as $employeeId) {
            $this->request($employeeId, 'requested');
        }
        $this->script = [
            $performed => 'performed',
            $blocked => 'blocked',
            $settled => 'settled',
        ];

        $batch = $this->enqueue();
        $result = $this->queue->processAvailable(10);

        self::assertSame(
            ['processed' => 3, 'succeeded' => 1, 'failed' => 0, 'skipped' => 2],
            $result,
        );
        $items = [];
        foreach ($this->queue->items($this->supplierId, (int) $batch['id'], 10, 0)['items'] as $item) {
            $items[$item['employee_id']] = $item;
        }

        self::assertSame('succeeded', $items[$performed]['status']);
        self::assertSame($this->documentFor($performed), $items[$performed]['document_id']);
        self::assertNull($items[$performed]['last_error_code']);

        self::assertSame('skipped', $items[$blocked]['status']);
        self::assertSame('annual_settlement_blocked', $items[$blocked]['last_error_code']);
        self::assertSame(
            PayrollAnnualDocumentBatchQueueService::BLOCKED_MESSAGE_PREFIX
                . 'declaration_not_signed, settlement_deadline_passed',
            $items[$blocked]['last_error_message'],
        );
        self::assertNull($items[$blocked]['document_id']);

        self::assertSame('skipped', $items[$settled]['status']);
        self::assertSame('annual_settlement_exists', $items[$settled]['last_error_code']);
        self::assertNull($items[$settled]['document_id']);

        $detail = $this->queue->detail($this->supplierId, (int) $batch['id']);
        self::assertSame(1, $detail['succeeded_count']);
        self::assertSame(2, $detail['skipped_count']);
        self::assertSame(0, $detail['failed_count']);
        self::assertSame('completed', $detail['status']);

        // Zablokovaný člověk se dalším během NEZKOUŠÍ znovu: chybějící
        // prohlášení ani zmeškanou lhůtu opakování nespraví.
        self::assertSame(['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0], $this->queue->processAvailable(10));
        self::assertSame(1, $items[$blocked]['attempt_count']);
        self::assertSame(1, array_count_values($this->calls)[$blocked]);
        try {
            $this->queue->retry($this->supplierId, (int) $batch['id'], $items[$blocked]['id']);
            self::fail('Přeskočenou položku nesmí jít zařadit znovu.');
        } catch (\DomainException) {
        }
    }

    private function performer(): AnnualSettlementPerformer
    {
        $test = $this;

        return new class ($test) implements AnnualSettlementPerformer {
            public function __construct(private readonly PayrollAnnualSettlementBatchQueueTest $test) {}

            public function settle(
                int $supplierId,
                int $employeeId,
                int $taxYear,
                ?int $actorUserId,
                ?DateTimeImmutable $today = null,
            ): array {
                return $this->test->settleFake($supplierId, $employeeId, $taxYear);
            }
        };
    }

    /**
     * Tvar odpovědi `AnnualTaxSettlementService::settle()` pro jednu osobu.
     *
     * @return array{result:AnnualSettlementResult,outcome:?array<string,mixed>,document:?array<string,mixed>,created:bool}
     */
    public function settleFake(int $supplierId, int $employeeId, int $taxYear): array
    {
        self::assertSame($this->supplierId, $supplierId);
        self::assertSame(self::YEAR, $taxYear);
        $this->calls[] = $employeeId;

        return match ($this->script[$employeeId] ?? 'blocked') {
            'performed' => [
                'result' => AnnualSettlementResult::performed(
                    $taxYear,
                    AnnualSettlementOutcome::Overpayment,
                    0, 0, 0, 0, 0, 0, 0, 0, 12_300, 0, 12_300, 12_300,
                    true,
                    [],
                ),
                'outcome' => ['id' => 1],
                'document' => ['id' => $this->archivedSettlement($employeeId, $taxYear)],
                'created' => true,
            ],
            'settled' => [
                'result' => AnnualSettlementResult::refused(
                    $taxYear,
                    [AnnualSettlementBlocker::AlreadySettled],
                ),
                'outcome' => ['id' => 2],
                'document' => null,
                'created' => false,
            ],
            default => [
                'result' => AnnualSettlementResult::refused(
                    $taxYear,
                    [
                        AnnualSettlementBlocker::DeclarationNotSigned,
                        AnnualSettlementBlocker::SettlementDeadlinePassed,
                    ],
                ),
                'outcome' => null,
                'document' => null,
                'created' => false,
            ],
        };
    }

    /** @return array<string,mixed> */
    private function enqueue(): array
    {
        return $this->queue->enqueue(
            $this->supplierId,
            self::YEAR,
            PayrollDocumentKind::AnnualSettlementResult,
            'all',
            null,
            null,
        );
    }

    private function request(int $employeeId, string $status, int $taxYear = self::YEAR): void
    {
        $requested = $status === 'requested';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_settlement_requests
                (supplier_id, employee_id, tax_year, request_status,
                 requested_on, request_evidence_reference)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $employeeId,
            $taxYear,
            $status,
            $requested ? sprintf('%04d-02-01', $taxYear + 1) : null,
            $requested ? 'Písemná žádost (syntetická)' : null,
        ]);
    }

    /**
     * Schválený mzdový běh s výsledky za rok — tentýž tvar jako
     * v {@see PayrollAnnualDocumentBatchQueueServiceTest}.
     *
     * @return list<int>
     */
    private function approvedYear(int $personCount): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")'
        )->execute([
            $this->supplierId,
            sprintf('%04d-03-01', self::YEAR),
            sprintf('%04d-03-31', self::YEAR),
        ]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, "{}", ?, "{}", ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            hash('sha256', "settlement-queue-input-{$runId}"),
            hash('sha256', "settlement-queue-result-{$runId}"),
            hash('sha256', "settlement-queue-key-{$runId}"),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $person = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, period_start, status,
                 result_json, result_hash)
             VALUES (?, ?, ?, ?, "calculated", "{}", ?)'
        );
        $employees = [];
        for ($index = 0; $index < $personCount; $index++) {
            $employee->execute([$this->supplierId, sprintf('Zúčtování %d', $index + 1)]);
            $employeeId = (int) $pdo->lastInsertId();
            $employees[] = $employeeId;
            $person->execute([
                $this->supplierId,
                $revisionId,
                $employeeId,
                sprintf('%04d-03-01', self::YEAR),
                hash('sha256', "settlement-queue-person-{$revisionId}-{$employeeId}"),
            ]);
        }

        return $employees;
    }

    /** Archivovaný doklad o ročním zúčtování, jak ho zakládá `settle()`. */
    private function archivedSettlement(int $employeeId, int $taxYear): int
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', "annual-settlement-{$employeeId}-{$taxYear}");
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, ?, "annual_settlement_result", 1,
                     "-", ?, "{}", ?, UTC_TIMESTAMP())'
        )->execute([$this->supplierId, $employeeId, $taxYear, $hash, $hash]);
        $annualRevisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, annual_revision_id, employee_id, document_kind,
                 revision_snapshot_hash, source_snapshot_hash, template_version,
                 renderer_version, file_sha256, size_bytes, mime_type,
                 storage_key, suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, "annual_settlement_result", ?, ?, "v1", "v1",
                     ?, 1024, "application/pdf", ?, "zuctovani.pdf", UNHEX(?))'
        )->execute([
            $this->supplierId,
            $annualRevisionId,
            $employeeId,
            $hash,
            $hash,
            $hash,
            $hash,
            $hash,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function documentFor(int $employeeId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_generated_documents
              WHERE supplier_id = ? AND employee_id = ?
                AND document_kind = "annual_settlement_result"'
        );
        $statement->execute([$this->supplierId, $employeeId]);

        return (int) $statement->fetchColumn();
    }
}
