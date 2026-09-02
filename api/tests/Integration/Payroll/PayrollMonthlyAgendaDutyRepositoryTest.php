<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollMonthlyAgendaDutyRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kódy pojišťoven vytahuje z vstupního snímku MariaDB, ne PHP — snímek nese
 * osobní údaje všech lidí běhu a tahat je do paměti kvůli třem číslicím na
 * osobu by bylo zbytečné i nebezpečné. Cesta v JSON i chování zástupného znaku
 * `[*]` jsou ale věci, které unit test se zdvojníkem repozitáře neověří vůbec:
 * ten dostane hotové pole. Proto tenhle test mluví s databází.
 */
#[Group('integration')]
final class PayrollMonthlyAgendaDutyRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD_START = '2026-08-01';

    private Connection $db;
    private PayrollMonthlyAgendaDutyRepository $repository;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach (['payroll_runs', 'payroll_run_revisions'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->repository = new PayrollMonthlyAgendaDutyRepository($this->db);

        $pdo = $this->db->pdo();
        $statement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        if ($statement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $statement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    public function testApprovedRunReportsPeopleAndDistinctInsurerCodes(): void
    {
        $runId = $this->createRun('closed');
        $this->revision($runId, 1, 'approved', ['111', '201', '111']);

        $runs = $this->repository->approvedRunsForPeriod(
            $this->supplierId,
            self::PERIOD_START,
        );

        self::assertCount(1, $runs);
        self::assertSame($runId, $runs[0]['run_id']);
        self::assertSame(3, $runs[0]['person_count']);
        // Řetězce, ne inty — striktní porovnání s ověřenými účty jinak nesedne.
        self::assertSame(['111', '201'], $runs[0]['insurer_codes']);
    }

    /** Neschválená revize povinnost nezakládá — není z čeho hlášení sestavit. */
    public function testRunWithoutApprovedRevisionIsNotReported(): void
    {
        $runId = $this->createRun('calculated');
        $this->revision($runId, 1, 'calculated', ['111']);

        self::assertSame([], $this->repository->approvedRunsForPeriod(
            $this->supplierId,
            self::PERIOD_START,
        ));
    }

    /**
     * Opravná revize odsune předchozí do `superseded`; bere se ta s nejvyšším
     * číslem, aby se kódy pojišťoven četly z platného stavu, ne z původního.
     */
    public function testLatestApprovedRevisionWins(): void
    {
        $runId = $this->createRun('closed');
        $first = $this->revision($runId, 1, 'superseded', ['111']);
        $second = $this->revision($runId, 2, 'approved', ['201'], $first);

        $runs = $this->repository->approvedRunsForPeriod(
            $this->supplierId,
            self::PERIOD_START,
        );

        self::assertCount(1, $runs);
        self::assertSame($second, $runs[0]['revision_id']);
        self::assertSame(['201'], $runs[0]['insurer_codes']);
    }

    /** Zrušený běh povinnost nezakládá. */
    public function testCancelledRunIsNotReported(): void
    {
        $runId = $this->createRun('cancelled');
        $this->revision($runId, 1, 'approved', ['111']);

        self::assertSame([], $this->repository->approvedRunsForPeriod(
            $this->supplierId,
            self::PERIOD_START,
        ));
    }

    private function createRun(string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, ?, "2026-09-10", ?, 1)',
        )->execute([$this->supplierId, self::PERIOD_START, $status]);

        return (int) $pdo->lastInsertId();
    }

    /** @param list<string> $insurerCodes */
    private function revision(
        int $runId,
        int $revisionNo,
        string $status,
        array $insurerCodes,
        ?int $previousRevisionId = null,
    ): int {
        $snapshot = json_encode(
            [
                'schema_version' => 'payroll-run-input-snapshot.test',
                'people' => array_map(
                    static fn (string $code): array => [
                        'statutory_evidence' => [
                            'health' => ['coverage' => ['insurer_code' => $code]],
                        ],
                    ],
                    $insurerCodes,
                ),
            ],
            JSON_THROW_ON_ERROR,
        );
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, idempotency_key_hash,
                 superseded_at)
             VALUES (?, ?, ?, ?, "regular", ?, "test.v1", ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionNo,
            $previousRevisionId,
            $status,
            str_repeat('a', 64),
            $snapshot,
            hash('sha256', $snapshot),
            random_bytes(32),
            in_array($status, ['superseded', 'abandoned'], true)
                ? '2026-09-01 00:00:00'
                : null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
