<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollOperationalHealthAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollOperationalHealthActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollOperationalHealthAction $action;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $revisionSequence = 0;
    private string $appTimezone;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->appTimezone = (string) $container->get(Config::class)
            ->get('app.timezone', 'Europe/Prague');
        foreach ([
            'payroll_document_batches',
            'payroll_period_export_jobs',
            'payroll_submissions',
            'payroll_submission_issues',
            'submission_outbox',
            'payroll_payment_liabilities',
            'payroll_payment_allocations',
            'payroll_payment_matches',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->action = $container->get(PayrollOperationalHealthAction::class);
        $this->obligations = $container->get(PayrollObligationService::class);
        $this->submissions = $container->get(PayrollSubmissionService::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
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

    public function testReturnsOnlyTenantAggregatedOperationalCounts(): void
    {
        [$oldestDocumentAt, $oldestDocumentUtc] = $this->queueTimestamp(2);
        [$oldestExportAt, $oldestExportUtc] = $this->queueTimestamp(3);
        foreach (['queued', 'running', 'retry_wait', 'failed'] as $status) {
            $this->documentBatch(
                $this->supplierId,
                $status,
                $status === 'retry_wait' ? $oldestDocumentAt : null,
            );
        }
        $this->documentBatch(
            $this->supplierId,
            'completed',
            '2026-08-31 11:00:00',
            '2026-08-31 12:00:00',
        );
        $this->documentBatch($this->otherSupplierId, 'queued', '2026-01-01 00:00:00');
        $this->documentBatch(
            $this->otherSupplierId,
            'completed',
            '2026-09-30 11:00:00',
            '2026-09-30 12:00:00',
        );
        foreach (['queued', 'processing', 'retry_wait', 'failed'] as $status) {
            $this->periodExportJob(
                $this->supplierId,
                $status,
                $status === 'retry_wait' ? $oldestExportAt : null,
            );
        }
        $this->periodExportJob(
            $this->supplierId,
            'completed',
            '2026-08-30 10:00:00',
            '2026-08-30 11:00:00',
        );
        $this->periodExportJob($this->otherSupplierId, 'queued', '2026-01-01 00:00:00');
        $this->periodExportJob(
            $this->otherSupplierId,
            'completed',
            '2026-09-29 11:00:00',
            '2026-09-29 12:00:00',
        );

        $rejected = $this->submission($this->supplierId, 'rejected', 'rejected');
        $this->submission($this->supplierId, 'correction_required', 'correction');
        $this->submission($this->otherSupplierId, 'rejected', 'other');
        $this->issue($this->supplierId, $rejected, 'blocker', false);
        $this->issue($this->supplierId, $rejected, 'error', false);
        $this->issue($this->supplierId, $rejected, 'warning', false);
        $this->issue($this->supplierId, $rejected, 'error', true);

        $this->outbox($this->supplierId, 'failed', 'unknown', 'failed');
        $this->outbox($this->supplierId, 'send_uncertain', 'unknown', 'uncertain');
        $this->outbox($this->supplierId, 'sent', 'rejected', 'rejected');
        $this->outbox($this->otherSupplierId, 'failed', 'unknown', 'other');

        $this->liability($this->supplierId, 'overdue');
        $fullyPaid = $this->liability($this->supplierId, 'fully-paid');
        $this->matchLiability($this->supplierId, $fullyPaid, 100, 'fully-paid');
        $partiallyPaid = $this->liability($this->supplierId, 'partially-paid');
        $this->matchLiability($this->supplierId, $partiallyPaid, 50, 'partially-paid');
        $reversed = $this->liability($this->supplierId, 'reversed');
        [, $matchedId] = $this->matchLiability($this->supplierId, $reversed, 100, 'reversed');
        $this->reverseMatch($this->supplierId, $matchedId, 'reversed');
        $this->liability($this->otherSupplierId, 'other');

        $response = ($this->action)($this->request(), new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertGreaterThanOrEqual(7_200, $body['document_batches']['oldest_pending_age_seconds']);
        self::assertLessThan(7_260, $body['document_batches']['oldest_pending_age_seconds']);
        self::assertGreaterThanOrEqual(10_800, $body['period_export_jobs']['oldest_pending_age_seconds']);
        self::assertLessThan(10_860, $body['period_export_jobs']['oldest_pending_age_seconds']);
        unset(
            $body['document_batches']['oldest_pending_age_seconds'],
            $body['period_export_jobs']['oldest_pending_age_seconds'],
        );
        self::assertSame([
            'document_batches' => [
                'queued' => 1,
                'running' => 1,
                'retry_wait' => 1,
                'failed' => 1,
                'oldest_pending_at' => $oldestDocumentUtc,
                'last_completed_at' => '2026-08-31T12:00:00Z',
            ],
            'period_export_jobs' => [
                'queued' => 1,
                'processing' => 1,
                'retry_wait' => 1,
                'failed' => 1,
                'oldest_pending_at' => $oldestExportUtc,
                'last_completed_at' => '2026-08-30T11:00:00Z',
            ],
            'submissions' => [
                'rejected' => 1,
                'correction_required' => 1,
                'open_blocker_or_error_issues' => 2,
            ],
            'isds_outbox' => [
                'failed' => 1,
                'send_uncertain' => 1,
                'rejected' => 1,
            ],
            'overdue_unpaid_liabilities' => 3,
        ], $body);
    }

    public function testRequiresSessionAuthentication(): void
    {
        foreach (['bearer', 'unknown'] as $authMethod) {
            $response = ($this->action)(
                $this->request($authMethod),
                new Response(),
            );

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('session_required', $this->json($response)['error']['code']);
        }
    }

    public function testNeverRunQueuesReturnNullObservabilityInsteadOfInventedSuccess(): void
    {
        $response = ($this->action)($this->request(), new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(0, $body['document_batches']['queued']);
        self::assertNull($body['document_batches']['oldest_pending_at']);
        self::assertNull($body['document_batches']['oldest_pending_age_seconds']);
        self::assertNull($body['document_batches']['last_completed_at']);
        self::assertSame(0, $body['period_export_jobs']['queued']);
        self::assertNull($body['period_export_jobs']['oldest_pending_at']);
        self::assertNull($body['period_export_jobs']['oldest_pending_age_seconds']);
        self::assertNull($body['period_export_jobs']['last_completed_at']);
    }

    public function testPendingAgeUsesElapsedUtcTimeAcrossDaylightSavingChanges(): void
    {
        $createdAt = '2026-03-28 12:00:00';
        $this->documentBatch($this->supplierId, 'queued', $createdAt);
        $expectedAge = time() - (new \DateTimeImmutable(
            $createdAt,
            new \DateTimeZone($this->appTimezone),
        ))->getTimestamp();

        $body = $this->json(($this->action)($this->request(), new Response()));

        self::assertEqualsWithDelta(
            $expectedAge,
            $body['document_batches']['oldest_pending_age_seconds'],
            1,
        );
    }

    /** @return array{0:string,1:string} */
    private function queueTimestamp(int $hoursAgo): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT
                DATE_FORMAT(CURRENT_TIMESTAMP() - INTERVAL ? HOUR, "%Y-%m-%d %H:%i:%s"),
                DATE_FORMAT(
                    CONVERT_TZ(
                        CURRENT_TIMESTAMP() - INTERVAL ? HOUR,
                        @@session.time_zone,
                        "+00:00"
                    ),
                    "%Y-%m-%dT%H:%i:%sZ"
                )',
        );
        $statement->execute([$hoursAgo, $hoursAgo]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        if (!is_array($row) || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)) {
            throw new \RuntimeException('Nelze připravit čas fronty pro test.');
        }

        return [$row[0], $row[1]];
    }

    private function documentBatch(
        int $supplierId,
        string $status,
        ?string $createdAt = null,
        ?string $completedAt = null,
    ): void
    {
        [$runId, $revisionId, $hash] = $this->approvedRevision($supplierId, "batch-{$status}");
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_batches
                (supplier_id, run_id, revision_id, status, source_snapshot_hash,
                 idempotency_key_hash, item_count, succeeded_count, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, UNHEX(?), 1, ?, COALESCE(?, UTC_TIMESTAMP()), ?)',
        )->execute([
            $supplierId,
            $runId,
            $revisionId,
            $status,
            $hash,
            hash('sha256', "health-batch:{$supplierId}:{$status}:{$revisionId}"),
            $status === 'completed' ? 1 : 0,
            $createdAt,
            $completedAt,
        ]);
    }

    private function periodExportJob(
        int $supplierId,
        string $status,
        ?string $createdAt = null,
        ?string $completedAt = null,
    ): void
    {
        $lease = $status === 'processing' ? random_bytes(16) : null;
        $periodStart = match ($status) {
            'queued' => '2026-08-01',
            'processing' => '2026-07-01',
            'retry_wait' => '2026-06-01',
            'failed' => '2026-05-01',
            'completed' => '2026-04-01',
            default => throw new \InvalidArgumentException('Neznámý stav exportní fronty.'),
        };
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $exportId = null;
        if ($status === 'completed') {
            $sourceHash = hash('sha256', "health-export-source:{$supplierId}:{$periodStart}");
            $fileHash = hash('sha256', "health-export-file:{$supplierId}:{$periodStart}");
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_period_exports
                    (supplier_id, export_scope, period_start, period_end,
                     source_manifest_hash, manifest_json, file_sha256, size_bytes,
                     storage_key, suggested_filename)
                 VALUES (?, "monthly", ?, ?, ?, "{}", ?, 1, ?, ?)',
            )->execute([
                $supplierId,
                $periodStart,
                $periodEnd,
                $sourceHash,
                $fileHash,
                $fileHash,
                "health-export-{$supplierId}-{$periodStart}.zip",
            ]);
            $exportId = (int) $this->db->pdo()->lastInsertId();
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_period_export_jobs
                (supplier_id, export_scope, period_start, period_end, status, available_at,
                 lease_token, locked_at, export_id, created_at, completed_at)
             VALUES (?, "monthly", ?, ?, ?, UTC_TIMESTAMP(), ?,
                     CASE WHEN ? IS NULL THEN NULL ELSE UTC_TIMESTAMP() END,
                     ?, COALESCE(?, UTC_TIMESTAMP()), ?)',
        )->execute([
            $supplierId,
            $periodStart,
            $periodEnd,
            $status,
            $lease,
            $lease,
            $exportId,
            $createdAt,
            $completedAt,
        ]);
    }

    private function submission(int $supplierId, string $status, string $suffix): int
    {
        $obligation = $this->obligations->register(
            $supplierId,
            'JMHZ',
            'office',
            "health:{$suffix}",
            '2026-08-01',
            '2026-08-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            "run:health:{$suffix}",
            str_repeat('a', 64),
            '2026-08-31',
            '2026-09-20',
            'calendar_days',
            'health-test-ruleset',
            str_repeat('b', 64),
            "health-obligation:{$suffix}",
            environment: 'production',
        );
        $submission = $this->submissions->prepare(
            $supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('c', 64),
            "health-submission:{$suffix}",
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = ?, submitted_at = UTC_TIMESTAMP(), decided_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND id = ?',
        )->execute([$status, $supplierId, $submission['id']]);
        return (int) $submission['id'];
    }

    private function issue(int $supplierId, int $submissionId, string $severity, bool $resolved): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_issues
                (supplier_id, environment, submission_id, severity, validation_stage,
                 issue_code, is_resolved, resolved_at)
             VALUES (?, "production", ?, ?, "remote", ?, ?, ?)',
        )->execute([
            $supplierId,
            $submissionId,
            $severity,
            "health_{$severity}",
            $resolved ? 1 : 0,
            $resolved ? '2026-08-31 12:00:00' : null,
        ]);
    }

    private function outbox(
        int $supplierId,
        string $dispatchState,
        string $acceptanceState,
        string $suffix,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO submission_outbox
                (supplier_id, environment, channel, dispatch_mode, agenda_code,
                 recipient_box_id, subject, artifact_kind, artifact_id,
                 artifact_filename, artifact_sha256, dispatch_state,
                 acceptance_state, acceptance_evidence_kind, idempotency_key_hash,
                 correlation_reference, external_message_id,
                 artifact_validation_status, artifact_validated_at,
                 recipient_box_verified_at, confirmed_by, confirmed_at, sent_at,
                 rejected_at, failed_at, last_error_code)
             VALUES (?, "production", "isds", "channel", "JMHZ", "zzzzzzz",
                 "Synthetic health test", "payroll_submission", 1, "health.xml", ?, ?, ?, ?,
                 UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            str_repeat('d', 64),
            $dispatchState,
            $acceptanceState,
            $acceptanceState === 'rejected' ? 'manual_confirmation' : null,
            hash('sha256', "health-outbox:{$supplierId}:{$suffix}"),
            "health-{$supplierId}-{$suffix}",
            $dispatchState === 'sent' ? "message-{$suffix}" : null,
            in_array($dispatchState, ['send_uncertain', 'sent'], true) ? 'passed' : null,
            in_array($dispatchState, ['send_uncertain', 'sent'], true) ? '2026-08-31 12:00:00' : null,
            in_array($dispatchState, ['send_uncertain', 'sent'], true) ? '2026-08-31 12:00:00' : null,
            $this->userId,
            '2026-08-31 12:00:00',
            $dispatchState === 'sent' ? '2026-08-31 12:00:00' : null,
            $acceptanceState === 'rejected' ? '2026-08-31 12:00:00' : null,
            $dispatchState === 'failed' ? '2026-08-31 12:00:00' : null,
            $dispatchState === 'failed' ? 'synthetic_failed' : null,
        ]);
    }

    private function liability(int $supplierId, string $suffix): int
    {
        [, $revisionId, $hash] = $this->approvedRevision($supplierId, "liability-{$suffix}");
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference, liability_kind,
                 recipient_reference, due_on, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "social_insurance", "synthetic-recipient", "2000-01-01", 100,
                 "{}", ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $revisionId,
            "health-{$suffix}",
            $hash,
            hash('sha256', "health-liability:{$supplierId}:{$suffix}"),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{int,int} allocation ID, matched event ID */
    private function matchLiability(
        int $supplierId,
        int $liabilityId,
        int $amountMinor,
        string $suffix,
    ): array {
        $pdo = $this->db->pdo();
        $reference = "health-payment-{$supplierId}-{$suffix}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format, direction,
                 planned_payment_date, currency_code, payer_reference,
                 declared_total_minor, declared_item_count, snapshot_ciphertext,
                 snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", "2000-01-01", "CZK",
                 "payer:synthetic", ?, 1, "enc:v2:synthetic", ?, UNHEX(?))',
        )->execute([
            $supplierId,
            "{$reference}-batch",
            $amountMinor,
            hash('sha256', "{$reference}-batch"),
            hash('sha256', "{$reference}-batch-key"),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, "enc:v2:synthetic", ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $batchId,
            "{$reference}-item",
            $amountMinor,
            hash('sha256', "{$reference}-item"),
            hash('sha256', "{$reference}-item-key"),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor, idempotency_key_hash)
             VALUES (?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "{$reference}-allocation"),
        ]);
        $allocationId = (int) $pdo->lastInsertId();
        [$statementId, $transactionId] = $this->bankEvidence(
            $supplierId,
            -$amountMinor,
            "{$reference}-matched",
        );
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $allocationId,
            $amountMinor,
            $statementId,
            $transactionId,
            hash('sha256', "{$reference}-matched"),
        ]);
        return [$allocationId, (int) $pdo->lastInsertId()];
    }

    private function reverseMatch(int $supplierId, int $matchId, string $suffix): void
    {
        $pdo = $this->db->pdo();
        $source = $pdo->prepare(
            'SELECT allocation_id, amount_minor FROM payroll_payment_matches
              WHERE supplier_id = ? AND id = ?',
        );
        $source->execute([$supplierId, $matchId]);
        $row = $source->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        [$statementId, $transactionId] = $this->bankEvidence(
            $supplierId,
            (int) $row['amount_minor'],
            "health-reversal-{$supplierId}-{$suffix}",
        );
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, source_match_id, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "reversed", ?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $row['allocation_id'],
            $matchId,
            -(int) $row['amount_minor'],
            $statementId,
            $transactionId,
            hash('sha256', "health-reversal-key:{$supplierId}:{$suffix}"),
        ]);
    }

    /** @return array{int,int} */
    private function bankEvidence(int $supplierId, int $amountMinor, string $suffix): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code,
                 currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2000-01-01")',
        )->execute([
            $supplierId,
            "{$suffix}.gpc",
            hash('sha256', "{$suffix}-statement"),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2000-01-01", ?, "CZK", "Synthetic health payment", ?)',
        )->execute([
            $statementId,
            number_format($amountMinor / 100, 2, '.', ''),
            hash('sha256', "{$suffix}-transaction"),
        ]);
        return [$statementId, (int) $pdo->lastInsertId()];
    }

    /** @return array{int,int,string} */
    private function approvedRevision(int $supplierId, string $suffix): array
    {
        $pdo = $this->db->pdo();
        $period = (new \DateTimeImmutable('2025-01-01'))
            ->modify('+' . $this->revisionSequence++ . ' months');
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")',
        )->execute([
            $supplierId,
            $period->format('Y-m-01'),
            $period->modify('last day of this month')->format('Y-m-d'),
        ]);
        $runId = (int) $pdo->lastInsertId();
        $input = '{"schema_version":"payroll-run-input.v2","people":[]}';
        $result = '{"schema_version":"payroll-run-result.v2","people":[],"totals":[]}';
        $hash = hash('sha256', $result);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('e', 64),
            $input,
            hash('sha256', $input),
            $result,
            $hash,
            hash('sha256', "health-revision:{$supplierId}:{$suffix}:{$runId}"),
        ]);
        return [$runId, (int) $pdo->lastInsertId(), $hash];
    }

    private function request(string $authMethod = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/operational-health')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
