<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollOperationalReconciliationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollOperationalReconciliationActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollOperationalReconciliationAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        foreach ([
            'payroll_operational_reconciliation_issues',
            'payroll_operational_reconciliation_issue_events',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->action = $container->get(PayrollOperationalReconciliationAction::class);
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
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
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

    public function testReadEndpointIsSessionOnlyNoStoreAndTenantScoped(): void
    {
        $response = $this->action->get(
            $this->request('GET', '2096-01'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame('not_materialized', $body['overall_status']);
        self::assertSame('payroll:run', $body['axes'][0]['key']);
        self::assertSame([], $body['issues']);

        $bearer = $this->action->get(
            $this->request('GET', '2096-01', 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('no-store, private', $bearer->getHeaderLine('Cache-Control'));
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    public function testSweepPersistsBlockersIdempotentlyAndDetailCannotCrossTenant(): void
    {
        $this->approvedRevision('2096-02-01');
        $first = $this->action->sweep(
            $this->request('POST', '2096-02'),
            new Response(),
        );
        self::assertSame(200, $first->getStatusCode());
        $firstBody = $this->json($first);
        self::assertSame('blocked', $firstBody['overall_status']);
        self::assertNotEmpty($firstBody['issues']);

        $second = $this->action->sweep(
            $this->request('POST', '2096-02'),
            new Response(),
        );
        $secondBody = $this->json($second);
        self::assertCount(count($firstBody['issues']), $secondBody['issues']);
        $issueId = (int) $secondBody['issues'][0]['id'];

        $detail = $this->action->detail(
            $this->request('GET', '2096-02'),
            new Response(),
            ['issueId' => (string) $issueId],
        );
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame(['detected'], array_column(
            $this->json($detail)['events'],
            'transition_kind',
        ));

        $foreign = $this->action->detail(
            $this->request('GET', '2096-02', 'session', $this->otherSupplierId),
            new Response(),
            ['issueId' => (string) $issueId],
        );
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('no-store, private', $foreign->getHeaderLine('Cache-Control'));
    }

    private function approvedRevision(string $period): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$this->supplierId, $period, substr($period, 0, 8) . '10']);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'people' => [],
            'totals' => [],
        ]);
        $hash = hash('sha256', $snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash,
                 approved_at)
             VALUES (?, ?, 1, "approved", "mz27.synthetic.v1", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $hash,
            $snapshot,
            $hash,
            hash('sha256', "mz27-action-{$this->supplierId}-{$period}", true),
        ]);
    }

    private function request(
        string $method,
        string $period,
        string $authMethod = 'session',
        ?int $supplierId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/operational-reconciliation?period=' . $period,
            )
            ->withQueryParams(['period' => $period])
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded['data'] ?? $decoded;
    }
}

