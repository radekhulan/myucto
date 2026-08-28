<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollOperationalReconciliationRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollOperationalReconciliationRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollOperationalReconciliationRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $runId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_operational_reconciliation_issues')) {
            $this->markTestSkipped('Chybí migrace MZ-27 reconciliation.');
        }
        $this->repository = $container->get(
            PayrollOperationalReconciliationRepository::class,
        );
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        [$this->runId, $this->revisionId] = $this->approvedRevision(
            $this->supplierId,
            '2097-06-01',
            'primary',
        );
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

    public function testSweepIsIdempotentAndRecordsOpenResolvedReopenedHistory(): void
    {
        $diff = $this->finding('posting:journal:income_tax', 'posting', 'income_tax', 'diff', 900, 850);
        $this->repository->synchronize(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            '2097-06-01',
            [$diff],
        );
        $this->repository->synchronize(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            '2097-06-01',
            [$diff],
        );

        $open = $this->repository->forPeriod($this->supplierId, '2097-06');
        self::assertCount(1, $open);
        self::assertSame('open', $open[0]['status']);
        self::assertSame(50, $open[0]['difference_minor']);
        $detail = $this->repository->detail($this->supplierId, $open[0]['id']);
        self::assertNotNull($detail);
        self::assertSame(['detected'], array_column($detail['events'], 'transition_kind'));

        $match = $this->finding('posting:journal:income_tax', 'posting', 'income_tax', 'match', 900, 900);
        $this->repository->synchronize(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            '2097-06-01',
            [$match],
        );
        $resolved = $this->repository->detail($this->supplierId, $open[0]['id']);
        self::assertNotNull($resolved);
        self::assertSame('resolved', $resolved['status']);
        self::assertSame(
            ['detected', 'resolved'],
            array_column($resolved['events'], 'transition_kind'),
        );

        $this->repository->synchronize(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            '2097-06-01',
            [$diff],
        );
        $reopened = $this->repository->detail($this->supplierId, $open[0]['id']);
        self::assertNotNull($reopened);
        self::assertSame('open', $reopened['status']);
        self::assertSame(
            ['detected', 'resolved', 'reopened'],
            array_column($reopened['events'], 'transition_kind'),
        );
    }

    public function testIssueDetailAndSummaryAreTenantScoped(): void
    {
        $this->repository->synchronize(
            $this->supplierId,
            $this->runId,
            $this->revisionId,
            '2097-06-01',
            [$this->finding('jmhz:production', 'jmhz', 'production', 'blocked')],
        );
        $mine = $this->repository->forPeriod($this->supplierId, '2097-06');
        self::assertCount(1, $mine);
        self::assertNull($this->repository->detail($this->otherSupplierId, $mine[0]['id']));
        self::assertSame([], $this->repository->forPeriod($this->otherSupplierId, '2097-06'));
        self::assertSame(1, $this->repository->summary($this->supplierId)['open']);
        self::assertSame(0, $this->repository->summary($this->otherSupplierId)['open']);
    }

    /** @return array<string,mixed> */
    private function finding(
        string $key,
        string $scope,
        string $category,
        string $status,
        ?int $expected = null,
        ?int $actual = null,
    ): array {
        $snapshot = [
            'key' => $key,
            'status' => $status,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
        ];
        $json = CanonicalJson::encode($snapshot);

        return [
            'key' => $key,
            'scope' => $scope,
            'category' => $category,
            'status' => $status,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'difference_minor' => $expected === null || $actual === null
                ? null
                : $expected - $actual,
            'source_snapshot_json' => $json,
            'source_hash' => hash('sha256', $json),
        ];
    }

    /** @return array{int,int} */
    private function approvedRevision(int $supplierId, string $period, string $tag): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$supplierId, $period, substr($period, 0, 8) . '10']);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = CanonicalJson::encode(['schema' => 'mz27.synthetic.v1', 'tag' => $tag]);
        $hash = hash('sha256', $snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash,
                 approved_at)
             VALUES (?, ?, 1, "approved", "mz27.synthetic.v1", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $hash,
            $snapshot,
            $hash,
            hash('sha256', "mz27-{$supplierId}-{$tag}", true),
        ]);

        return [$runId, (int) $pdo->lastInsertId()];
    }
}

