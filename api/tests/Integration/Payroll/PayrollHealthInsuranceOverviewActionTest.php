<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollHealthInsuranceOverviewActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollHealthInsuranceOverviewAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollHealthInsuranceOverviewAction::class);
        if (!$db instanceof Connection
            || !$action instanceof PayrollHealthInsuranceOverviewAction
        ) {
            throw new \RuntimeException(
                'Zdravotní přehled není dostupný v DI kontejneru.',
            );
        }
        $this->db = $db;
        $this->action = $action;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_statutory_results',
            'payroll_statutory_person_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1,
                    ic = "12345678",
                    company_name = "Syntetický HTTP plátce s.r.o.",
                    street = "Zkušební",
                    street_number_pop = "12",
                    zip = "110 00",
                    city = "Praha 1",
                    phone = "+420111222333"
              WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $employeeId = $this->employee($pdo);
        $this->revisionId = $this->revision($pdo, $employeeId);
        $this->healthResult($employeeId);
        $this->healthInsurerAccount($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testIndexReturnsDeterministicTenantOverview(): void
    {
        $response = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['electronic_submission']['supported']);
        self::assertSame(
            'health_insurance_transport_unavailable',
            $body['electronic_submission']['reason_code'],
        );
        self::assertCount(1, $body['items']);
        self::assertSame('111', $body['items'][0]['insurer']['code']);
        self::assertSame(
            'Syntetická HTTP osoba',
            $body['items'][0]['people'][0]['display_name'],
        );
        self::assertSame(
            135_000,
            $body['items'][0]['totals']['total_contribution_minor_units'],
        );
        self::assertSame(
            'missing',
            $body['items'][0]['payment_reconciliation']['state'],
        );
        self::assertTrue(
            $body['items'][0]['payment_reconciliation']['closing_blocked'],
        );
        self::assertSame(
            ['liability_missing', 'liability_difference'],
            $body['items'][0]['payment_reconciliation']['blockers'],
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $body['items'][0]['sha256'],
        );
        self::assertStringNotContainsString(
            'Syntetická',
            $body['items'][0]['filename'],
        );
    }

    public function testDownloadReturnsOfficialInsurerArtifactAndSafeFilename(): void
    {
        $response = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}/111/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $this->revisionId,
                'insurerCode' => '111',
            ],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/pdf',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            'private, no-store',
            $response->getHeaderLine('Cache-Control'),
        );
        self::assertSame('nosniff', $response->getHeaderLine(
            'X-Content-Type-Options',
        ));
        self::assertSame(
            'attachment; filename="zp-prehled-2026-06-111-revize-'
                . $this->revisionId . '.pdf"',
            $response->getHeaderLine('Content-Disposition'),
        );
        $bytes = (string) $response->getBody();
        self::assertSame((string) strlen($bytes), $response->getHeaderLine(
            'Content-Length',
        ));
        self::assertSame(
            hash('sha256', $bytes),
            $response->getHeaderLine('Content-SHA256'),
        );
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringNotContainsString(
            'payroll-health-payment-overview.v1',
            $bytes,
        );
    }

    public function testIndexShowsPartialHealthPaymentAndBlocksClosing(): void
    {
        $liabilityId = $this->healthLiability(
            $this->db->pdo(),
            $this->revisionId,
            135_000,
        );
        $this->settleLiability(
            $this->db->pdo(),
            $liabilityId,
            135_000,
            60_000,
        );

        $response = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );

        self::assertSame(200, $response->getStatusCode());
        $payment = $this->json($response)['items'][0]['payment_reconciliation'];
        self::assertSame('partially_settled', $payment['state']);
        self::assertSame(135_000, $payment['liability_minor']);
        self::assertSame(60_000, $payment['bank_settled_minor']);
        self::assertSame(75_000, $payment['outgoing_remaining_minor']);
        self::assertSame(0, $payment['incoming_remaining_minor']);
        self::assertTrue($payment['closing_blocked']);
        self::assertSame(['bank_unsettled'], $payment['blockers']);
    }

    public function testCorrectionRefundUsesDirectIncomingEvidence(): void
    {
        $outgoingId = $this->healthLiability(
            $this->db->pdo(),
            $this->revisionId,
            135_000,
        );
        $this->settleLiability($this->db->pdo(), $outgoingId, 135_000, 135_000);

        $correctionId = $this->correctionRevision($this->db->pdo());
        $this->healthResult(
            $this->employeeIdForRevision($this->db->pdo(), $correctionId),
            $correctionId,
            totalContributionMinor: 100_000,
        );
        $incomingId = $this->healthLiability(
            $this->db->pdo(),
            $correctionId,
            35_000,
            'incoming',
            $outgoingId,
        );
        $this->settleIncomingLiability($this->db->pdo(), $incomingId, 35_000);

        $response = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$correctionId}",
            ),
            new Response(),
            ['revisionId' => (string) $correctionId],
        );

        self::assertSame(200, $response->getStatusCode());
        $payment = $this->json($response)['items'][0]['payment_reconciliation'];
        self::assertSame('settled', $payment['state']);
        self::assertSame(100_000, $payment['liability_minor']);
        self::assertSame(100_000, $payment['bank_settled_minor']);
        self::assertSame(0, $payment['outgoing_remaining_minor']);
        self::assertSame(0, $payment['incoming_remaining_minor']);
        self::assertFalse($payment['closing_blocked']);
        self::assertSame([], $payment['blockers']);
    }

    public function testDownloadReturnsValidatedXmlForXmlInsurer(): void
    {
        $pdo = $this->db->pdo();
        $employeeId = $this->employee($pdo, 'Syntetická XML osoba');
        $revisionId = $this->revision(
            $pdo,
            $employeeId,
            '2026-05-01',
            '2026-06-10',
        );
        $this->healthResult(
            $employeeId,
            $revisionId,
            '205',
            '2026-05-31',
            'Syntetická XML osoba',
        );
        $this->healthInsurerAccount($pdo, '205', 'ČPZP');

        $response = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$revisionId}/205/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $revisionId,
                'insurerCode' => '205',
            ],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/xml',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            'attachment; filename="zp-prehled-2026-05-205-revize-'
                . $revisionId . '.xml"',
            $response->getHeaderLine('Content-Disposition'),
        );
        $bytes = (string) $response->getBody();
        self::assertStringContainsString(
            '<prehledPlatbyZamestnavatele',
            $bytes,
        );
        self::assertStringContainsString(
            '<kodZdravotniPojistovny>205</kodZdravotniPojistovny>',
            $bytes,
        );
        self::assertStringNotContainsString(
            'payroll-health-payment-overview.v1',
            $bytes,
        );
        self::assertSame(
            hash('sha256', $bytes),
            $response->getHeaderLine('Content-SHA256'),
        );
    }

    public function testSessionPermissionAndPayrollSwitchAreFailClosed(): void
    {
        $bearer = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
                null,
                'bearer',
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'],
        );

        $deniedRole = new EffectiveRole(
            901,
            'Bez mzdových podání',
            'staff',
            true,
            ['payroll.submissions' => AccessLevel::NONE->value],
        );
        $forbidden = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
                null,
                'session',
                $deniedRole,
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?',
        )->execute([$this->supplierId]);
        $disabled = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame(
            'payroll_disabled',
            $this->json($disabled)['error']['code'],
        );
    }

    public function testTenantMissingAndInvalidRouteValuesDoNotLeakData(): void
    {
        $foreign = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
                $this->otherSupplierId,
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(422, $foreign->getStatusCode());
        self::assertSame(
            'health_insurance_result_not_found',
            $this->json($foreign)['error']['code'],
        );

        $invalidRevision = $this->action->index(
            $this->request(
                'GET',
                '/api/payroll/submissions/health-overviews/not-a-number',
            ),
            new Response(),
            ['revisionId' => 'not-a-number'],
        );
        self::assertSame(422, $invalidRevision->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalidRevision)['error']['code'],
        );

        $missingInsurer = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}/201/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $this->revisionId,
                'insurerCode' => '201',
            ],
        );
        self::assertSame(404, $missingInsurer->getStatusCode());
        self::assertSame(
            'not_found',
            $this->json($missingInsurer)['error']['code'],
        );

        $invalidInsurer = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}/11/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $this->revisionId,
                'insurerCode' => '11',
            ],
        );
        self::assertSame(422, $invalidInsurer->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalidInsurer)['error']['code'],
        );
    }

    private function employee(
        PDO $pdo,
        string $fullName = 'Syntetická HTTP osoba',
    ): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function revision(
        PDO $pdo,
        int $employeeId,
        string $periodStart = '2026-06-01',
        string $paymentDate = '2026-07-10',
    ): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$this->supplierId, $periodStart, $paymentDate]);
        $runId = (int) $pdo->lastInsertId();
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-health-http:{$this->supplierId}:{$runId}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$this->supplierId, $revisionId, $employeeId]);

        return $revisionId;
    }

    private function healthResult(
        int $employeeId,
        ?int $revisionId = null,
        string $insurerCode = '111',
        string $calculationDate = '2026-06-30',
        string $fullName = 'Syntetická HTTP osoba',
        int $totalContributionMinor = 135_000,
    ): void
    {
        $employeeContributionMinor = intdiv($totalContributionMinor, 3);
        $employerContributionMinor = $totalContributionMinor - $employeeContributionMinor;
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $revisionId ?? $this->revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            'calculated',
            'cz-health-2026',
            str_repeat('b', 64),
            ['schema_version' => 'payroll-run-input.v2'],
            [
                'calculation_date' => $calculationDate,
                'status' => 'calculated',
                'assessment_base_minor_units' => 1_000_000,
                'employee_contribution_minor_units' => $employeeContributionMinor,
                'employer_contribution_minor_units' => $employerContributionMinor,
                'total_contribution_minor_units' => $totalContributionMinor,
                'insurer_liabilities' => [[
                    'insurer_code' => $insurerCode,
                    'person_count' => 1,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => $employeeContributionMinor,
                    'employer_contribution_minor_units' => $employerContributionMinor,
                    'total_contribution_minor_units' => $totalContributionMinor,
                ]],
                'issues' => [],
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            [[
                'employee_id' => $employeeId,
                'result_status' => 'calculated',
                'input_snapshot' => [
                    'employee' => [
                        'id' => $employeeId,
                        'full_name' => $fullName,
                    ],
                ],
                'result_snapshot' => [
                    'person_id' => "employee:{$employeeId}",
                    'status' => 'calculated',
                    'insurer_status' => 'verified',
                    'insurer_code' => $insurerCode,
                    'ppz_counted' => true,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => $employeeContributionMinor,
                    'employer_contribution_minor_units' => $employerContributionMinor,
                    'total_contribution_minor_units' => $totalContributionMinor,
                ],
                'relationships' => [],
            ]],
            null,
        );
    }

    private function healthInsurerAccount(
        PDO $pdo,
        string $insurerCode = '111',
        string $insurerName = 'VZP',
    ): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_institutions
                (supplier_id, institution_type, institution_code)
             VALUES (?, "health_insurer", ?)',
        )->execute([$this->supplierId, $insurerCode]);
        $institutionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, currency_code, variable_symbol,
                 valid_from, source_kind, source_reference, verified_on,
                 verified_by, created_by, updated_by)
             VALUES (?, ?, ?, "synthetic", ?, "synthetic",
                     "CZK", "1234567800", "2026-01-01",
                     "user_verified", "synthetic-http-test", "2026-01-01",
                     ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $institutionId,
            $insurerName,
            hash('sha256', "synthetic-http-{$insurerCode}-account", true),
            $this->userId,
            $this->userId,
            $this->userId,
        ]);
    }

    private function healthLiability(
        PDO $pdo,
        int $revisionId,
        int $amountMinor,
        string $direction = 'outgoing',
        ?int $previousLiabilityId = null,
    ): int {
        $source = '{"schema":"synthetic-health-liability.v1"}';
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, previous_liability_id,
                 source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "health-insurance:i111", "health_insurance",
                     ?, "institution:synthetic", "2026-07-20",
                     "CZK", ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $direction,
            $amountMinor,
            $previousLiabilityId,
            $source,
            hash('sha256', $source),
            hash('sha256', "health-liability:{$this->supplierId}:{$revisionId}:{$direction}", true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function correctionRevision(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'SELECT run_id FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$this->supplierId, $this->revisionId]);
        $runId = (int) $stmt->fetchColumn();
        $pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2 WHERE id = ?',
        )->execute([$runId]);
        $input = '{"schema_version":"payroll-run-input.v2","correction":true}';
        $result = '{"schema_version":"payroll-run-result.v2","correction":true}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind,
                 previous_revision_id, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 2, "correction", ?, "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            $this->revisionId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "health-correction:{$this->supplierId}:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $employeeId = $this->employeeIdForRevision($pdo, $this->revisionId);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$this->supplierId, $revisionId, $employeeId]);
        return $revisionId;
    }

    private function employeeIdForRevision(PDO $pdo, int $revisionId): int
    {
        $stmt = $pdo->prepare(
            'SELECT employee_id FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        return (int) $stmt->fetchColumn();
    }

    private function settleIncomingLiability(PDO $pdo, int $liabilityId, int $amountMinor): void
    {
        $reference = "health-refund-{$this->supplierId}-{$liabilityId}";
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2026-07-21")',
        )->execute([
            $this->supplierId,
            "{$reference}.gpc",
            hash('sha256', "{$reference}-statement"),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2026-07-21", ?, "CZK",
                     "Syntetická vratka zdravotního pojištění", ?)',
        )->execute([
            $statementId,
            number_format($amountMinor / 100, 2, '.', ''),
            hash('sha256', "{$reference}-transaction"),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, liability_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, actual_payment_date,
                 evidence_amount_minor, evidence_currency_code,
                 evidence_fact_hash, idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, "2026-07-21", ?, "CZK", ?, ?)',
        )->execute([
            $this->supplierId,
            $liabilityId,
            $amountMinor,
            $statementId,
            $transactionId,
            $amountMinor,
            hash('sha256', "{$reference}-evidence"),
            hash('sha256', "{$reference}-match", true),
        ]);
    }

    private function settleLiability(
        PDO $pdo,
        int $liabilityId,
        int $allocatedMinor,
        int $settledMinor,
    ): void {
        $reference = "health-payment-{$this->supplierId}-{$liabilityId}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", "2026-07-20",
                     "CZK", "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "{$reference}-batch",
            $allocatedMinor,
            'enc:v2:synthetic-health-batch',
            hash('sha256', "{$reference}-batch"),
            hash('sha256', "{$reference}-batch", true),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "institution:synthetic", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "{$reference}-item",
            $allocatedMinor,
            'enc:v2:synthetic-health-instruction',
            hash('sha256', "{$reference}-item"),
            hash('sha256', "{$reference}-item", true),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $allocatedMinor,
            hash('sha256', "{$reference}-allocation", true),
        ]);
        $allocationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2026-07-20")',
        )->execute([
            $this->supplierId,
            "{$reference}.gpc",
            hash('sha256', "{$reference}-statement"),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2026-07-20", ?, "CZK",
                     "Syntetická úhrada zdravotního pojištění", ?)',
        )->execute([
            $statementId,
            number_format(-$settledMinor / 100, 2, '.', ''),
            hash('sha256', "{$reference}-transaction"),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $evidenceHash = hash('sha256', "{$reference}-evidence");
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, actual_payment_date,
                 evidence_amount_minor, evidence_currency_code,
                 evidence_fact_hash, idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, "2026-07-20", ?, "CZK", ?, ?)',
        )->execute([
            $this->supplierId,
            $allocationId,
            $settledMinor,
            $statementId,
            $transactionId,
            $settledMinor,
            $evidenceHash,
            hash('sha256', "{$reference}-match", true),
        ]);
    }

    private function firstId(PDO $pdo, string $table): int
    {
        $statement = $pdo->query(
            "SELECT id FROM {$table} ORDER BY id LIMIT 1",
        );
        if ($statement === false) {
            return 0;
        }

        return (int) $statement->fetchColumn();
    }

    private function request(
        string $method,
        string $uri,
        ?int $supplierId = null,
        string $authMethod = 'session',
        ?EffectiveRole $role = null,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }

        return $request;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }
}
