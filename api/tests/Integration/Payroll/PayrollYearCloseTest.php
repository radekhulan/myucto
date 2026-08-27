<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollYearCloseAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\Payroll\PayrollYearCloseBlockedException;
use MyInvoice\Service\Payroll\PayrollYearCloseService;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollYearCloseTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollYearCloseService $service;
    private PayrollYearCloseAction $action;
    private PayrollModuleStateRepository $moduleState;
    private PayrollAbsenceRepository $absences;
    private PayrollEnforcementRepository $enforcement;
    private PayrollRunCommandService $runs;
    private PayrollSubmissionRepository $submissions;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(PayrollYearCloseService::class);
            $this->action = $container->get(PayrollYearCloseAction::class);
            $this->moduleState = $container->get(PayrollModuleStateRepository::class);
            $this->absences = $container->get(PayrollAbsenceRepository::class);
            $this->enforcement = $container->get(PayrollEnforcementRepository::class);
            $this->runs = $container->get(PayrollRunCommandService::class);
            $this->submissions = $container->get(PayrollSubmissionRepository::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }

        if (!$this->db->hasTable('payroll_year_closures')) {
            $this->markTestSkipped('Migrace 1597 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí zdrojový tenant nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testMissingMonthFailsClosed(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026, [12]);

        try {
            $this->service->close($this->supplierId, 2026, 0, $this->userId);
            self::fail('Roční uzávěrka musí odmítnout chybějící prosinec.');
        } catch (PayrollYearCloseBlockedException $exception) {
            self::assertSame('missing_months', $exception->blockers[0]['code']);
            self::assertSame(['2026-12'], $exception->blockers[0]['months']);
        }
    }

    public function testMonthsBeforeModuleStartAreNotRequired(): void
    {
        $this->moduleState->setActivation(
            $this->supplierId,
            true,
            '2026-06-01',
            0,
            $this->userId,
        );
        $this->seedClosedMonths($this->supplierId, 2026, [1, 2, 3, 4, 5]);

        $status = $this->service->status($this->supplierId, 2026);
        $missing = array_values(array_filter(
            $status['blockers'],
            static fn (array $blocker): bool => $blocker['code'] === 'missing_months',
        ));

        self::assertSame([], $missing);
    }

    public function testClosedYearBlocksRunMutationUntilReopened(): void
    {
        $this->moduleState->setActivation(
            $this->supplierId,
            true,
            '2026-01-01',
            0,
            $this->userId,
        );
        $this->seedClosedMonths($this->supplierId, 2026);
        $closed = $this->service->close($this->supplierId, 2026, 0, $this->userId);
        $runIdStatement = $this->db->pdo()->prepare(
            "SELECT id
               FROM payroll_runs
              WHERE supplier_id = ? AND period_start = '2026-01-01'",
        );
        $runIdStatement->execute([$this->supplierId]);
        $runId = (int) $runIdStatement->fetchColumn();
        self::assertGreaterThan(0, $runId);
        $this->db->pdo()->prepare(
            "DELETE FROM payroll_runs
              WHERE supplier_id = ? AND period_start = '2026-12-01'",
        )->execute([$this->supplierId]);

        $this->assertYearClosed(function (): void {
            $this->runs->createRun(
                $this->supplierId,
                '2026-12-01',
                '2026-12-15',
                null,
                $this->userId,
            );
        });
        $this->assertYearClosed(function () use ($runId): void {
            $this->runs->reopen(
                $this->supplierId,
                $runId,
                1,
                'year-close-reopen',
                $this->userId,
                'Oprava uzavřeného měsíce.',
            );
        });
        $this->assertYearClosed(function () use ($runId): void {
            $this->runs->requestCorrection(
                $this->supplierId,
                $runId,
                1,
                'year-close-correction',
                $this->userId,
                'Oprava uzavřeného měsíce.',
            );
        });

        $this->service->reopen(
            $this->supplierId,
            2026,
            $closed['row_version'],
            $this->userId,
            'Oprava uzávěrky pro dokončení měsíce.',
        );
        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-12-01',
            '2026-12-15',
            null,
            $this->userId,
        );
        self::assertSame('2026-12-01', $run['period_start']);
    }

    public function testProductionObligationWithoutSubmissionBlocksClose(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, 'production', 'JMHZ', 'employer', 'employer:synthetic',
                     '2026-12-01', '2026-12-31', 'regular', 'isds', 'open',
                     'synthetic_year_close', 'event:synthetic', ?, ?, ?, ?)"
        )->execute([
            $this->supplierId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            hash('sha256', 'synthetic-year-close', true),
            $this->userId,
        ]);

        try {
            $this->service->close($this->supplierId, 2026, 0, $this->userId);
            self::fail('Nesplněná produkční povinnost bez podání musí blokovat uzávěrku.');
        } catch (PayrollYearCloseBlockedException $exception) {
            $blockers = array_column($exception->blockers, null, 'code');
            self::assertSame(1, $blockers['open_submissions']['count'] ?? null);
        }
    }

    public function testClosedYearRejectsNewAbsence(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        [, $employmentId] = $this->seedEmployment($this->supplierId);
        $this->service->close($this->supplierId, 2026, 0, $this->userId);

        $this->expectException(PayrollYearClosedException::class);
        $this->absences->create($this->supplierId, [
            'employment_id' => $employmentId,
            'absence_type' => 'vacation',
            'date_from' => '2026-07-07',
            'date_to' => '2026-07-07',
            'timezone_name' => 'Europe/Prague',
            'partial_first_minutes' => null,
            'partial_last_minutes' => null,
            'note' => null,
            'compensation_policy' => 'average_100',
            'compensation_rate_basis_points' => 10_000,
            'average_snapshot_id' => null,
        ], $this->userId);
    }

    public function testClosedYearAllowsTestObligationAndSubmission(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $this->service->close($this->supplierId, 2026, 0, $this->userId);

        $obligationId = $this->submissions->insertObligation(
            $this->supplierId,
            'test',
            'TEST_YEAR_CLOSE',
            'other',
            'test:year-close',
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'test',
            'test:year-close',
            hash('sha256', 'test-year-close-source'),
            hash('sha256', 'test-year-close-request'),
            hash('sha256', 'test-year-close-idempotency', true),
            $this->userId,
            $this->userId,
        );
        $submissionId = $this->submissions->insertSubmission(
            $this->supplierId,
            'test',
            $obligationId,
            null,
            'regular',
            'manual_upload',
            null,
            hash('sha256', 'test-year-close-snapshot'),
            hash('sha256', 'test-year-close-submission'),
            hash('sha256', 'test-year-close-submission-key', true),
            $this->userId,
        );

        self::assertGreaterThan(0, $obligationId);
        self::assertGreaterThan(0, $submissionId);
    }

    public function testClosedYearRejectsRetroactiveEnforcementMonthFact(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $this->service->close($this->supplierId, 2026, 0, $this->userId);

        $this->expectException(PayrollYearClosedException::class);
        $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            1,
            '2026-07',
            [],
            $this->userId,
            null,
        );
    }

    public function testUnresolvedEnforcementCaseBlocksClose(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba s exekucí", "employee", "hpp", 0, 0, 0, NULL, 0, 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from)
             VALUES (?, ?, ?, "enforcement", "received", "2026-12-01")',
        )->execute([$this->supplierId, $employeeId, 'year-close-' . bin2hex(random_bytes(12))]);

        try {
            $this->service->close($this->supplierId, 2026, 0, $this->userId);
            self::fail('Nevyřešená exekuce musí blokovat roční uzávěrku.');
        } catch (PayrollYearCloseBlockedException $exception) {
            $blockers = array_column($exception->blockers, null, 'code');
            self::assertSame(1, $blockers['open_enforcement']['count'] ?? null);
        }

        $this->db->pdo()->prepare(
            "UPDATE payroll_enforcement_cases
                SET status = 'remit', evidence_complete = 1, recipient_verified = 1
              WHERE supplier_id = ? AND employee_id = ?",
        )->execute([$this->supplierId, $employeeId]);
        $blockers = array_column($this->service->status($this->supplierId, 2026)['blockers'], null, 'code');
        self::assertArrayNotHasKey(
            'open_enforcement',
            $blockers,
            'Řádně nastavená pokračující exekuce bez deponovaného zůstatku sama o sobě uzávěrku neblokuje.',
        );
    }

    public function testOpenCorrectionBlocksClose(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $this->db->pdo()->prepare(
            "UPDATE payroll_runs SET status = 'correction_pending'
              WHERE supplier_id = ? AND period_start = '2026-07-01'",
        )->execute([$this->supplierId]);

        $blockers = array_column($this->service->status($this->supplierId, 2026)['blockers'], null, 'code');
        self::assertSame(1, $blockers['open_corrections']['count'] ?? null);
    }

    public function testMalformedApprovedSnapshotBlocksCloseAsReconciliationDifference(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $run = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_runs
              WHERE supplier_id = ? AND period_start = '2026-07-01'",
        );
        $run->execute([$this->supplierId]);
        $runId = (int) $run->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, calculated_by, approved_at)
             VALUES (?, ?, 1, 'regular', 'approved', 'payroll-run-input.v2', ?, ?, ?, ?, ?, ?, ?, NOW())",
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('f', 64),
            $input = '{"schema_version":"payroll-run-input.v2"}',
            hash('sha256', $input),
            $malformedResult = '{}',
            hash('sha256', $malformedResult),
            random_bytes(32),
            $this->userId,
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 1 WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $runId]);

        $blockers = array_column($this->service->status($this->supplierId, 2026)['blockers'], null, 'code');
        self::assertSame(1, $blockers['reconciliation_differences']['count'] ?? null);
    }

    public function testPendingVacationBlocksClose(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        [, $employmentId] = $this->seedEmployment($this->supplierId);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to, status)
             VALUES (?, ?, 'vacation', '2026-12-20', '2026-12-24', 'requested')",
        )->execute([$this->supplierId, $employmentId]);

        $blockers = array_column($this->service->status($this->supplierId, 2026)['blockers'], null, 'code');
        self::assertSame(1, $blockers['open_leave']['count'] ?? null);
    }

    public function testUnpaidLiabilityBlocksClose(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $run = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_runs
              WHERE supplier_id = ? AND period_start = '2026-11-01'",
        );
        $run->execute([$this->supplierId]);
        $runId = (int) $run->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, calculated_by, approved_at)
             VALUES (?, ?, 1, 'regular', 'approved', 'payroll-run-input.v2', ?, ?, ?, ?, ?, ?, ?, NOW())",
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input = '{"schema_version":"payroll-run-input.v2"}',
            hash('sha256', $input),
            $result = '{"schema_version":"payroll-run-result.v2"}',
            hash('sha256', $result),
            random_bytes(32),
            $this->userId,
        ]);
        $revisionId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 1 WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $runId]);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference, liability_kind, recipient_reference,
                 due_on, amount_minor, source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, 'health_insurance', 'synthetic-insurer', '2026-12-20', 10000,
                     '{}', ?, ?, ?)",
        )->execute([
            $this->supplierId,
            $revisionId,
            'year-close-liability-' . bin2hex(random_bytes(8)),
            str_repeat('c', 64),
            random_bytes(32),
            $this->userId,
        ]);

        $blockers = array_column($this->service->status($this->supplierId, 2026)['blockers'], null, 'code');
        self::assertSame(1, $blockers['open_liabilities']['count'] ?? null);
    }

    public function testSettledIncomingLiabilityDoesNotBlockClose(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $run = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_runs
              WHERE supplier_id = ? AND period_start = '2026-11-01'",
        );
        $run->execute([$this->supplierId]);
        $runId = (int) $run->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, calculated_by, approved_at)
             VALUES (?, ?, 1, 'regular', 'approved', 'payroll-run-input.v2', ?, ?, ?, ?, ?, ?, ?, NOW())",
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('d', 64),
            $input = '{"schema_version":"payroll-run-input.v2"}',
            hash('sha256', $input),
            $result = '{"schema_version":"payroll-run-result.v2"}',
            hash('sha256', $result),
            random_bytes(32),
            $this->userId,
        ]);
        $revisionId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 1 WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $runId]);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference, liability_kind,
                 direction, recipient_reference, due_on, amount_minor,
                 source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, 'health_insurance', 'incoming', 'synthetic-insurer',
                     '2026-12-20', 10000, '{}', ?, ?, ?)",
        )->execute([
            $this->supplierId,
            $revisionId,
            'year-close-refund-' . bin2hex(random_bytes(8)),
            str_repeat('e', 64),
            random_bytes(32),
            $this->userId,
        ]);
        $liabilityId = (int) $this->db->pdo()->lastInsertId();
        $reference = 'year-close-refund-' . bin2hex(random_bytes(8));
        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2026-12-21")',
        )->execute([$this->supplierId, "{$reference}.gpc", hash('sha256', $reference)]);
        $statementId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description, import_fingerprint)
             VALUES (?, "2026-12-21", 100.00, "CZK", "Syntetická vratka", ?)',
        )->execute([$statementId, hash('sha256', "{$reference}-transaction")]);
        $transactionId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, liability_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "matched", 10000, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $liabilityId,
            $statementId,
            $transactionId,
            hash('sha256', "{$reference}-match", true),
        ]);

        $blockers = array_column($this->service->status($this->supplierId, 2026)['blockers'], null, 'code');
        self::assertArrayNotHasKey('open_liabilities', $blockers);
    }

    public function testCloseIsTenantScopedAndAudited(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);
        $this->seedClosedMonths($this->otherSupplierId, 2026);
        $this->db->pdo()->prepare(
            "UPDATE payroll_runs SET status = 'correction_pending'
              WHERE supplier_id = ? AND period_start = '2026-07-01'"
        )->execute([$this->otherSupplierId]);

        $closed = $this->service->close($this->supplierId, 2026, 0, $this->userId);

        self::assertSame('closed', $closed['status']);
        self::assertSame(1, $closed['row_version']);
        self::assertNull($this->service->status($this->otherSupplierId, 2026)['closure']['id']);

        $audit = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = 'payroll.year_closed'"
        );
        $audit->execute([$this->supplierId]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testApiRequiresSessionAndUsesOptimisticVersionForReopen(): void
    {
        $this->seedClosedMonths($this->supplierId, 2026);

        $bearerResponse = $this->action->close(
            $this->request('POST', 'bearer')->withParsedBody(['row_version' => 0]),
            new Response(),
            ['year' => '2026'],
        );
        self::assertSame(403, $bearerResponse->getStatusCode());
        self::assertSame('session_required', $this->json($bearerResponse)['error']['code']);

        $closedResponse = $this->action->close(
            $this->request('POST', 'session')->withParsedBody(['row_version' => 0]),
            new Response(),
            ['year' => '2026'],
        );
        self::assertSame(200, $closedResponse->getStatusCode());
        self::assertSame('private, no-store', $closedResponse->getHeaderLine('Cache-Control'));
        self::assertSame('closed', $this->json($closedResponse)['closure']['status']);

        $reopenedResponse = $this->action->reopen(
            $this->request('POST', 'session')->withParsedBody(['row_version' => 1, 'reason' => 'Oprava roční uzávěrky.']),
            new Response(),
            ['year' => '2026'],
        );
        self::assertSame(200, $reopenedResponse->getStatusCode());
        self::assertSame('private, no-store', $reopenedResponse->getHeaderLine('Cache-Control'));
        self::assertSame('open', $this->json($reopenedResponse)['closure']['status']);

        $conflictResponse = $this->action->close(
            $this->request('POST', 'session')->withParsedBody(['row_version' => 1]),
            new Response(),
            ['year' => '2026'],
        );
        self::assertSame(409, $conflictResponse->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($conflictResponse)['error']['code']);
    }

    /** @param list<int> $missingMonths */
    private function seedClosedMonths(int $supplierId, int $year, array $missingMonths = []): void
    {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, created_by, updated_by)
             VALUES (?, ?, ?, 'closed', ?, ?)"
        );
        for ($month = 1; $month <= 12; ++$month) {
            if (in_array($month, $missingMonths, true)) {
                continue;
            }
            $statement->execute([
                $supplierId,
                sprintf('%04d-%02d-01', $year, $month),
                sprintf('%04d-%02d-15', $year, $month),
                $this->userId,
                $this->userId,
            ]);
        }
    }

    /** @return array{0:int,1:int} */
    private function seedEmployment(int $supplierId): array
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 0, 0, 0, NULL, 0, 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, ?, 'employment', 'active', '2026-01-01')",
        )->execute([$supplierId, $employeeId, 'year-close-' . bin2hex(random_bytes(6))]);
        return [$employeeId, (int) $this->db->pdo()->lastInsertId()];
    }

    private function request(string $method, string $authMethod): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/year-close/2026')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @param callable():void $operation */
    private function assertYearClosed(callable $operation): void
    {
        try {
            $operation();
            self::fail('Uzavřený rok musí odmítnout změnu mzdového běhu.');
        } catch (PayrollYearClosedException) {
            self::addToAssertionCount(1);
        }
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }
}
