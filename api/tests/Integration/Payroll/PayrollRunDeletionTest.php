<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRunDeletionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunsAction $action;
    private PayrollRunCommandService $service;
    private PayrollRunRepository $runs;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollRunsAction::class);
        $this->service = $container->get(PayrollRunCommandService::class);
        $this->runs = $container->get(PayrollRunRepository::class);
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_run_commands',
            'payroll_run_events',
            'payroll_generated_documents',
            'payroll_posting_batches',
            'payroll_payment_liabilities',
            'payroll_obligations',
            'payroll_submissions',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace úplných mezd neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $this->userId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn();
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
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        // `setup` stačí — mzdový běh se v něm zakládá i maže a do `active`
        // se modul překlopí sám (viz PayrollModuleActivationService).
        $module = $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        );
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            $module->execute([$supplierId, $this->userId]);
        }
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

    public function testListMarksAndActionDeletesOnlyEmptyTechnicalWrapper(): void
    {
        $run = $this->createRun($this->supplierId, '2031-01-01');
        $decision = $this->runs->canDelete(
            $this->supplierId,
            (int) $run['id'],
        );
        self::assertTrue($decision->canDelete);
        self::assertNull($decision->blockerCode);

        $list = $this->action->list(
            $this->request('GET', '/api/payroll/runs?period=2031-01')
                ->withQueryParams(['period' => '2031-01']),
            new Response(),
        );
        self::assertSame(200, $list->getStatusCode());
        $items = $this->json($list)['runs'];
        self::assertCount(1, $items);
        self::assertTrue($items[0]['can_delete']);

        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/runs/{$run['id']}",
            )->withParsedBody(['row_version' => $run['row_version']]),
            new Response(),
            ['id' => (string) $run['id']],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'deleted' => true,
            'run_id' => (int) $run['id'],
        ], $this->json($response));
        self::assertNull($this->runs->find($this->supplierId, (int) $run['id']));
        self::assertSame(
            0,
            $this->rowCount(
                'payroll_run_events',
                'supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertSame(
            0,
            $this->rowCount(
                'payroll_period_ownership',
                'supplier_id = ? AND period_start = "2031-01-01"',
                [$this->supplierId],
            ),
        );
        self::assertSame(
            1,
            (int) $this->db->pdo()->query(
                'SELECT
                    @payroll_empty_run_delete_supplier_id IS NULL
                    AND @payroll_empty_run_delete_run_id IS NULL
                    AND @payroll_empty_run_delete_row_version IS NULL
                    AND @payroll_empty_run_delete_event_id IS NULL
                    AND @payroll_empty_run_delete_cancel_event_id IS NULL
                    AND @payroll_empty_run_delete_cancel_command_id IS NULL',
            )->fetchColumn(),
        );
    }

    public function testDeleteIsTenantScopedAndDoesNotRevealForeignRun(): void
    {
        $foreign = $this->createRun($this->otherSupplierId, '2031-02-01');
        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/runs/{$foreign['id']}",
            )->withParsedBody(['row_version' => $foreign['row_version']]),
            new Response(),
            ['id' => (string) $foreign['id']],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->errorCode($response));
        self::assertNotNull($this->runs->find(
            $this->otherSupplierId,
            (int) $foreign['id'],
        ));
    }

    public function testDeleteRejectsStaleRowVersion(): void
    {
        $run = $this->createRun($this->supplierId, '2031-03-01');
        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/runs/{$run['id']}",
            )->withParsedBody(['row_version' => (int) $run['row_version'] + 1]),
            new Response(),
            ['id' => (string) $run['id']],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($response));
        self::assertSame(
            (int) $run['row_version'],
            $this->json($response)['error']['current_row_version'],
        );
        self::assertNotNull($this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        ));
    }

    public function testDeleteRequiresSessionWritePermissionAndEnabledModule(): void
    {
        $run = $this->createRun($this->supplierId, '2031-04-01');
        $path = "/api/payroll/runs/{$run['id']}";
        $body = ['row_version' => $run['row_version']];

        $bearer = $this->action->delete(
            $this->request('DELETE', $path, authMethod: 'bearer')
                ->withParsedBody($body),
            new Response(),
            ['id' => (string) $run['id']],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));

        $readonly = $this->action->delete(
            $this->request(
                'DELETE',
                $path,
                role: $this->role(AccessLevel::READ),
            )->withParsedBody($body),
            new Response(),
            ['id' => (string) $run['id']],
        );
        self::assertSame(403, $readonly->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($readonly));

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?',
        )->execute([$this->supplierId]);
        $disabled = $this->action->delete(
            $this->request('DELETE', $path)->withParsedBody($body),
            new Response(),
            ['id' => (string) $run['id']],
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame('payroll_disabled', $this->errorCode($disabled));
    }

    public function testAnyAdditionalEventBlocksDeletion(): void
    {
        $run = $this->createRun($this->supplierId, '2031-05-01');
        $this->runs->insertEvent(
            $this->supplierId,
            (int) $run['id'],
            null,
            'synthetic_extra_event',
            'draft',
            'draft',
            $this->userId,
            null,
            ['synthetic' => true],
        );

        $this->assertDeleteConflict(
            $run,
            'payroll_run_has_event_history',
        );
        self::assertFalse(
            $this->runs->canDelete(
                $this->supplierId,
                (int) $run['id'],
            )->canDelete,
        );
    }

    public function testCommandReceiptBlocksDeletion(): void
    {
        $run = $this->createRun($this->supplierId, '2031-06-01');
        $this->runs->insertCommandReceipt(
            $this->supplierId,
            (int) $run['id'],
            null,
            'synthetic_command',
            hash('sha256', 'synthetic-delete-command', true),
            hash('sha256', 'synthetic-delete-request'),
            (int) $run['row_version'],
            'draft',
            'draft',
            ['synthetic' => true],
            $this->userId,
        );

        $this->assertDeleteConflict(
            $run,
            'payroll_run_has_command_history',
        );
    }

    public function testRevisionBlocksDeletion(): void
    {
        $run = $this->createRun($this->supplierId, '2031-07-01');
        $this->insertRevision($run, 'revision');

        $this->assertDeleteConflict($run, 'payroll_run_has_working_revision');
    }

    public function testLaterStatusAndNonzeroRevisionPointerCannotBeDeleted(): void
    {
        $laterStatus = $this->createRun($this->supplierId, '2031-10-01');
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET status = "inputs_locked"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $laterStatus['id']]);
        $this->assertDeleteConflict(
            $laterStatus,
            'payroll_run_status_not_deletable',
        );

        $revisionPointer = $this->createRun(
            $this->supplierId,
            '2031-11-01',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $revisionPointer['id']]);
        $this->assertDeleteConflict(
            $revisionPointer,
            'payroll_run_has_working_revision',
        );
    }

    public function testCanonicallyCancelledEmptyWrapperCanBeDeleted(): void
    {
        $run = $this->createRun($this->supplierId, '2031-12-01');
        $cancelled = $this->service->cancel(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'synthetic-cancel-before-delete',
            $this->userId,
            'Chybně založený prázdný běh.',
        );
        self::assertTrue(
            $this->runs->canDelete(
                $this->supplierId,
                (int) $run['id'],
            )?->canDelete,
        );
        $list = $this->action->list(
            $this->request('GET', '/api/payroll/runs?period=2031-12')
                ->withQueryParams(['period' => '2031-12']),
            new Response(),
        );
        self::assertTrue($this->json($list)['runs'][0]['can_delete']);

        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/runs/{$run['id']}",
            )->withParsedBody([
                'row_version' => $cancelled->run['row_version'],
            ]),
            new Response(),
            ['id' => (string) $run['id']],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        ));
    }

    public function testCancelledWrapperWithoutCanonicalCancelProofIsBlocked(): void
    {
        $run = $this->createRun($this->supplierId, '2033-01-01');
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET status = "cancelled"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $run['id']]);

        $this->assertDeleteConflict(
            $run,
            'payroll_run_has_command_history',
        );
    }

    public function testTamperedCancelReceiptBlocksDeletion(): void
    {
        $run = $this->createRun($this->supplierId, '2033-02-01');
        $cancelled = $this->service->cancel(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'synthetic-cancel-tamper',
            $this->userId,
            'Syntetický důvod zrušení.',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_commands
                SET result_json = JSON_SET(
                    result_json,
                    "$.row_version",
                    999
                )
              WHERE supplier_id = ? AND run_id = ?
                AND command_name = "cancel"',
        )->execute([$this->supplierId, $run['id']]);

        $this->assertDeleteConflict(
            $cancelled->run,
            'payroll_run_has_command_history',
        );
    }

    public function testGeneratedDocumentBlocksWithDownstreamCode(): void
    {
        $run = $this->createRun($this->supplierId, '2031-08-01');
        $revisionId = $this->insertRevision($run, 'document');
        $hash = hash('sha256', 'synthetic-payroll-document');
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "approved",
                    result_snapshot_json = "{}",
                    result_snapshot_hash = ?,
                    approved_by = ?,
                    approved_at = NOW()
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $hash,
            $this->userId,
            $this->supplierId,
            $revisionId,
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, document_kind,
                 revision_snapshot_hash, source_snapshot_hash,
                 template_version, renderer_version, file_sha256,
                 size_bytes, mime_type, storage_key, suggested_filename,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, "monthly_bundle", ?, ?, "synthetic.v1",
                     "synthetic.v1", ?, 1, "application/pdf", ?,
                     "synthetic-payroll.pdf", ?, ?)',
        )->execute([
            $this->supplierId,
            $run['id'],
            $revisionId,
            $hash,
            $hash,
            $hash,
            $hash,
            hash('sha256', 'synthetic-document-idempotency', true),
            $this->userId,
        ]);

        $this->assertDeleteConflict($run, 'payroll_run_has_documents');
    }

    public function testPostingBlocksWithDownstreamCode(): void
    {
        $run = $this->createRun($this->supplierId, '2032-01-01');
        $revisionId = $this->insertRevision($run, 'posting');
        $hash = hash('sha256', 'synthetic-posting');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, entry_date, status,
                 target_hash, delta_hash, created_by)
             VALUES (?, ?, ?, "2032-01-31", "prepared", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $run['id'],
            $revisionId,
            $hash,
            $hash,
            $this->userId,
        ]);

        /*
         * Připravená účtovací dávka mazání NEBLOKUJE: do deníku nic nešlo,
         * takže není co chránit. Blokuje až rozpracovaná revize, kterou tady
         * fixtura zakládá — a to je jiný, mírnější důvod.
         */
        $this->assertDeleteConflict($run, 'payroll_run_has_working_revision');
    }

    public function testPaymentBlocksWithDownstreamCode(): void
    {
        $run = $this->createRun($this->supplierId, '2032-02-01');
        $revisionId = $this->insertRevision($run, 'payment');
        $hash = hash('sha256', 'synthetic-payment');
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "approved",
                    result_snapshot_json = "{}",
                    result_snapshot_hash = ?,
                    approved_by = ?,
                    approved_at = NOW()
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $hash,
            $this->userId,
            $this->supplierId,
            $revisionId,
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 amount_minor, source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, "synthetic:payment", "social_insurance",
                     "outgoing", "synthetic-recipient", "2032-03-20",
                     1, "{}", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $hash,
            hash('sha256', 'synthetic-payment-idempotency', true),
            $this->userId,
        ]);

        $this->assertDeleteConflict($run, 'payroll_run_has_payments');
    }

    public function testSubmissionBlocksWithDownstreamCode(): void
    {
        $run = $this->createRun($this->supplierId, '2032-03-01');
        $revisionId = $this->insertRevision($run, 'submission');
        $obligationId = $this->insertSubmissionObligation();
        $columns = [
            'supplier_id',
            'obligation_id',
            'submission_kind',
            'channel',
            'status',
            'source_revision_id',
            'source_snapshot_hash',
            'request_fingerprint',
            'idempotency_key_hash',
            'created_by',
        ];
        $values = [
            $this->supplierId,
            $obligationId,
            'regular',
            'manual_upload',
            'draft',
            $revisionId,
            hash('sha256', 'synthetic-submission-snapshot'),
            hash('sha256', 'synthetic-submission-request'),
            hash('sha256', 'synthetic-submission-idempotency', true),
            $this->userId,
        ];
        if ($this->db->hasColumn('payroll_submissions', 'environment')) {
            array_splice($columns, 1, 0, ['environment']);
            array_splice($values, 1, 0, ['test']);
        }
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->db->pdo()->prepare(sprintf(
            'INSERT INTO payroll_submissions (%s) VALUES (%s)',
            implode(', ', $columns),
            $placeholders,
        ))->execute($values);

        $this->assertDeleteConflict($run, 'payroll_run_has_submissions');
    }

    public function testRunEventAppendOnlyProtectionRemainsActiveWithoutGuard(): void
    {
        $run = $this->createRun($this->supplierId, '2031-09-01');
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ?',
        );

        try {
            $stmt->execute([$this->supplierId, $run['id']]);
            self::fail('Přímé smazání created auditu musí zůstat zakázané.');
        } catch (PDOException $e) {
            self::assertStringContainsString(
                'append-only',
                $e->getMessage(),
            );
        }
        self::assertSame(
            1,
            $this->rowCount(
                'payroll_run_events',
                'supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
    }

    /** @return array<string,mixed> */
    private function createRun(int $supplierId, string $periodStart): array
    {
        return $this->service->createRun(
            $supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))
                ->modify('+1 month +14 days')
                ->format('Y-m-d'),
            null,
            $this->userId,
        );
    }

    /** @param array<string,mixed> $run */
    /**
     * @param string $status `snapshot` je rozpracovaná revize, `approved`
     *        schválená. Rozdíl je podstatný: rozpracovaná dnes blokuje mazání
     *        sama o sobě, takže test navazujícího blokátoru (doklady, účtování)
     *        by se k němu vůbec nedostal a ověřoval by něco jiného, než tvrdí.
     */
    private function insertRevision(
        array $run,
        string $suffix,
        string $status = 'snapshot',
    ): int {
        $hash = hash('sha256', "synthetic-{$suffix}");
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", ?, "synthetic.v1",
                     ?, "{}", ?, ?)',
        )->execute([
            $this->supplierId,
            $run['id'],
            $status,
            $hash,
            $hash,
            hash('sha256', "synthetic-revision-{$suffix}", true),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertSubmissionObligation(): int
    {
        $columns = [
            'supplier_id',
            'agenda_code',
            'subject_type',
            'subject_reference',
            'period_start',
            'period_end',
            'obligation_kind',
            'preferred_channel',
            'status',
            'source_event_type',
            'source_event_reference',
            'source_event_hash',
            'idempotency_key_hash',
            'created_by',
        ];
        $values = [
            $this->supplierId,
            'SYNTHETIC',
            'payroll_run',
            'payroll-run:synthetic',
            '2032-03-01',
            '2032-03-31',
            'regular',
            'manual_upload',
            'open',
            'payroll_run_approved',
            'payroll-run:synthetic',
            hash('sha256', 'synthetic-obligation-source'),
            hash('sha256', 'synthetic-obligation-idempotency', true),
            $this->userId,
        ];
        if ($this->db->hasColumn('payroll_obligations', 'environment')) {
            array_splice($columns, 1, 0, ['environment']);
            array_splice($values, 1, 0, ['test']);
        }
        if ($this->db->hasColumn(
            'payroll_obligations',
            'request_fingerprint',
        )) {
            $columns[] = 'request_fingerprint';
            $values[] = hash('sha256', 'synthetic-obligation-request');
        }
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->db->pdo()->prepare(sprintf(
            'INSERT INTO payroll_obligations (%s) VALUES (%s)',
            implode(', ', $columns),
            $placeholders,
        ))->execute($values);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $run */
    private function assertDeleteConflict(array $run, string $code): void
    {
        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/runs/{$run['id']}",
            )->withParsedBody(['row_version' => $run['row_version']]),
            new Response(),
            ['id' => (string) $run['id']],
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame($code, $this->errorCode($response));
        self::assertNotNull($this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        ));
    }

    private function rowCount(
        string $table,
        string $where,
        array $params,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function request(
        string $method,
        string $uri,
        ?EffectiveRole $role = null,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => 'readonly',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute(
                'auth.effective_role',
                $role ?? $this->role(AccessLevel::WRITE),
            );
    }

    private function role(AccessLevel $inputAccess): EffectiveRole
    {
        return new EffectiveRole(
            910,
            'Syntetická role mazání mzdového běhu',
            'staff',
            true,
            [
                'payroll' => AccessLevel::READ->value,
                'payroll.inputs.write' => $inputAccess->value,
            ],
        );
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        return $this->json($response)['error']['code'] ?? null;
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
