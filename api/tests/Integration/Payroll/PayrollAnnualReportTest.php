<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAnnualReportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Report\PayrollAnnualReportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollAnnualReportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAnnualReportService $reports;
    private PayrollAnnualReportAction $action;
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
            $this->reports = $container->get(PayrollAnnualReportService::class);
            $this->action = $container->get(PayrollAnnualReportAction::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        foreach (['payroll_runs', 'payroll_run_revisions', 'payroll_statutory_results'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Tabulka {$table} není dostupná.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí syntetický zdroj firmy nebo uživatel.');
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
        }
    }

    public function testReportUsesOnlyCurrentApprovedRevisionAndNeverLeaksPeople(): void
    {
        $runId = $this->createRun($this->supplierId, '2026-01-01', 2);
        $this->insertRevision($this->supplierId, $runId, 1, 5_000, 2, 500, 250);
        $this->insertRevision($this->supplierId, $runId, 2, 7_000, 3, 700, 350);
        $marchRunId = $this->createRun($this->supplierId, '2026-03-01', 1);
        $this->insertRevision($this->supplierId, $marchRunId, 1, 3_000, 1, null, null);

        $otherRunId = $this->createRun($this->otherSupplierId, '2026-01-01', 1);
        $this->insertRevision($this->otherSupplierId, $otherRunId, 1, 99_000, 9, 900, 450);

        $report = $this->reports->report($this->supplierId, 2026);

        self::assertSame(2026, $report['year']);
        self::assertSame(2, $report['totals']['approved_revision_count']);
        self::assertSame(4, $report['totals']['headcount_person_months']);
        self::assertSame(10_000, $report['totals']['gross_minor']);
        self::assertNull($report['totals']['employer_cost_minor']);
        self::assertSame([
            [
                'period' => '2026-01',
                'approved_revision_count' => 1,
                'headcount' => 3,
                'gross_minor' => 7_000,
                'employer_cost_minor' => 8_050,
            ],
            [
                'period' => '2026-03',
                'approved_revision_count' => 1,
                'headcount' => 1,
                'gross_minor' => 3_000,
                'employer_cost_minor' => null,
            ],
        ], $report['months']);
        self::assertArrayNotHasKey('employee_id', $report['months'][0]);
        self::assertArrayNotHasKey('people', $report['months'][0]);

        $other = $this->reports->report($this->otherSupplierId, 2026);
        self::assertSame(99_000, $other['totals']['gross_minor']);
        self::assertSame(1, $other['totals']['approved_revision_count']);
    }

    public function testApiIsSessionOnlyRequiresReportsPermissionAndReturnsTenantAggregate(): void
    {
        $runId = $this->createRun($this->supplierId, '2026-01-01', 1);
        $this->insertRevision($this->supplierId, $runId, 1, 12_000, 2, 1_200, 600);

        $bearer = $this->action->show(
            $this->request('bearer', 'admin'), new Response(), ['year' => '2026'],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $forbidden = $this->action->show(
            $this->request('session', 'staff'), new Response(), ['year' => '2026'],
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $allowed = $this->action->show(
            $this->request('session', 'admin'), new Response(), ['year' => '2026'],
        );
        self::assertSame(200, $allowed->getStatusCode());
        $payload = $this->json($allowed);
        self::assertSame(12_000, $payload['report']['totals']['gross_minor']);
        self::assertArrayNotHasKey('employee_id', $payload['report']['months'][0]);
    }

    private function createRun(int $supplierId, string $periodStart, int $currentRevisionNo): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no, created_by, updated_by)
             VALUES (?, ?, ?, "approved", ?, ?, ?)',
        )->execute([
            $supplierId,
            $periodStart,
            substr($periodStart, 0, 8) . '15',
            $currentRevisionNo,
            $this->userId,
            $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertRevision(
        int $supplierId,
        int $runId,
        int $revisionNo,
        int $grossMinor,
        int $headcount,
        ?int $employerSocialMinor,
        ?int $employerHealthMinor,
    ): void {
        $result = json_encode([
            'schema_version' => 'payroll-run-result.v2',
            'totals' => ['source_amount_minor' => $grossMinor],
            'people' => array_fill(0, $headcount, []),
        ], JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, "regular", "approved", "payroll-run-input.v2",
                     ?, "{}", ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            $revisionNo,
            str_repeat('a', 64),
            hash('sha256', "annual-report-input:{$supplierId}:{$runId}:{$revisionNo}"),
            $result,
            hash('sha256', $result),
            hash('sha256', "annual-report-revision:{$supplierId}:{$runId}:{$revisionNo}", true),
        ]);
        $revisionId = (int) $this->db->pdo()->lastInsertId();
        if ($employerSocialMinor !== null) {
            $this->insertStatutoryResult($supplierId, $revisionId, 'social_insurance', $employerSocialMinor);
        }
        if ($employerHealthMinor !== null) {
            $this->insertStatutoryResult($supplierId, $revisionId, 'health_insurance', $employerHealthMinor);
        }
    }

    private function insertStatutoryResult(
        int $supplierId,
        int $revisionId,
        string $kind,
        int $employerContributionMinor,
    ): void {
        $result = json_encode([
            'employer_contribution_minor_units' => $employerContributionMinor,
        ], JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind, schema_version, result_status,
                 ruleset_id, ruleset_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, result_set_hash, created_by)
             VALUES (?, ?, ?, "annual-report-test.v1", "calculated", "test", ?, "{}", ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            $kind,
            str_repeat('b', 64),
            hash('sha256', "annual-report-statutory-input:{$revisionId}:{$kind}"),
            $result,
            hash('sha256', $result),
            hash('sha256', "annual-report-statutory-result:{$revisionId}:{$kind}"),
            $this->userId,
        ]);
    }

    private function request(string $method, string $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/reports/annual/2026')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
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
