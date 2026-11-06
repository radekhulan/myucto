<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollStatutoryResultPersistenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollStatutoryResultRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $repository = $container->get(PayrollStatutoryResultRepository::class);
        if (!$db instanceof Connection
            || !$repository instanceof PayrollStatutoryResultRepository
        ) {
            throw new \RuntimeException('Úložiště zákonných výsledků není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_statutory_results',
            'payroll_statutory_person_results',
            'payroll_statutory_relationship_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1255 neproběhla.');
            }
        }
        $this->repository = $repository;

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId, 'A');
        $this->employmentId = $this->createEmployment(
            $pdo,
            $this->supplierId,
            $this->employeeId,
            'A',
        );
        $this->revisionId = $this->createRevisionGraph(
            $pdo,
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
        );
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

    public function testStoresAndReplaysCanonicalThreeLevelResult(): void
    {
        $people = $this->calculatedPeople();
        $id = $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026.1',
            str_repeat('a', 64),
            ['period' => '2026-06', 'currency' => 'CZK'],
            ['employer_total_minor' => 248_000],
            $people,
            null,
        );
        $replayPeople = [[
            'relationships' => [[
                'result_snapshot' => ['employee_minor' => 71_000],
                'input_snapshot' => ['assessment_base_minor' => 1_000_000],
                'result_status' => 'calculated',
                'employment_id' => $this->employmentId,
            ]],
            'result_snapshot' => ['employee_minor' => 71_000],
            'input_snapshot' => ['assessment_base_minor' => 1_000_000],
            'result_status' => 'calculated',
            'employee_id' => $this->employeeId,
        ]];
        $replayedId = $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026.1',
            str_repeat('a', 64),
            ['currency' => 'CZK', 'period' => '2026-06'],
            ['employer_total_minor' => 248_000],
            $replayPeople,
            null,
        );

        self::assertSame($id, $replayedId);
        $stored = $this->repository->find(
            $this->supplierId,
            $this->revisionId,
            'social_insurance',
        );
        self::assertNotNull($stored);
        self::assertSame('calculated', $stored['result_status']);
        self::assertSame('2026-06', $stored['input_snapshot']['period']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored['result_set_hash']);
        self::assertCount(1, $stored['people']);
        self::assertSame($this->employeeId, $stored['people'][0]['employee_id']);
        self::assertCount(1, $stored['people'][0]['relationships']);
        self::assertSame(
            $this->employmentId,
            $stored['people'][0]['relationships'][0]['employment_id'],
        );
        self::assertNull($this->repository->find(
            $this->otherSupplierId,
            $this->revisionId,
            'social_insurance',
        ));
    }

    public function testChangedRelationshipResultCannotOverwriteRevisionScope(): void
    {
        $people = $this->calculatedPeople();
        $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            'calculated',
            'cz-health-2026.1',
            str_repeat('b', 64),
            ['period' => '2026-06'],
            ['employer_total_minor' => 90_000],
            $people,
            null,
        );
        $people[0]['relationships'][0]['result_snapshot']['employee_minor'] = 46_000;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jiný zákonný výsledek');
        $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            'calculated',
            'cz-health-2026.1',
            str_repeat('b', 64),
            ['period' => '2026-06'],
            ['employer_total_minor' => 90_000],
            $people,
            null,
        );
    }

    public function testRelationshipMustBelongToRunPerson(): void
    {
        $otherEmployeeId = $this->createEmployee(
            $this->db->pdo(),
            $this->supplierId,
            'B',
        );
        $otherEmploymentId = $this->createEmployment(
            $this->db->pdo(),
            $this->supplierId,
            $otherEmployeeId,
            'B',
        );
        $people = $this->calculatedPeople();
        $people[0]['relationships'][0]['employment_id'] = $otherEmploymentId;

        $this->expectException(PDOException::class);
        $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'income_tax',
            'payroll-income-tax-result.v1',
            'calculated',
            'cz-income-tax-2026.1',
            str_repeat('c', 64),
            ['period' => '2026-06'],
            ['advance_tax_minor' => 80_000],
            $people,
            null,
        );
    }

    public function testNetPayAllowsPersonResultWithoutRelationshipLayer(): void
    {
        $people = $this->calculatedPeople();
        $people[0]['relationships'] = [];

        $id = $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'net_pay',
            'payroll-net-result.v1',
            'manual_review',
            'cz-payroll-2026.1',
            str_repeat('d', 64),
            ['period' => '2026-06'],
            ['net_payable_minor' => 804_000],
            $people,
            null,
        );

        self::assertGreaterThan(0, $id);
        $stored = $this->repository->find(
            $this->supplierId,
            $this->revisionId,
            'net_pay',
        );
        self::assertNotNull($stored);
        self::assertSame([], $stored['people'][0]['relationships']);
    }

    public function testStoredGraphIsDatabaseImmutable(): void
    {
        $id = $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026.1',
            str_repeat('e', 64),
            ['period' => '2026-06'],
            ['employer_total_minor' => 248_000],
            $this->calculatedPeople(),
            null,
        );

        $this->expectException(PDOException::class);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE payroll_statutory_results
                SET result_status = 'error'
              WHERE supplier_id = ? AND id = ?"
        );
        $stmt->execute([$this->supplierId, $id]);
    }

    public function testStoredGraphCannotBeDeleted(): void
    {
        $id = $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'income_tax',
            'payroll-income-tax-result.v1',
            'calculated',
            'cz-income-tax-2026.1',
            str_repeat('f', 64),
            ['period' => '2026-06'],
            ['advance_tax_minor' => 80_000],
            $this->calculatedPeople(),
            null,
        );

        $this->expectException(PDOException::class);
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_statutory_results
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $id]);
    }

    public function testCalculatedRootCannotHideManualRelationship(): void
    {
        $people = $this->calculatedPeople();
        $people[0]['relationships'][0]['result_status'] = 'manual_review';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stav osoby nesmí skrýt');
        $this->repository->store(
            $this->supplierId,
            $this->revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026.1',
            str_repeat('0', 64),
            ['period' => '2026-06'],
            ['employer_total_minor' => 248_000],
            $people,
            null,
        );
    }

    /** @return list<array<string,mixed>> */
    private function calculatedPeople(): array
    {
        return [[
            'employee_id' => $this->employeeId,
            'result_status' => 'calculated',
            'input_snapshot' => ['assessment_base_minor' => 1_000_000],
            'result_snapshot' => ['employee_minor' => 71_000],
            'relationships' => [[
                'employment_id' => $this->employmentId,
                'result_status' => 'calculated',
                'input_snapshot' => ['assessment_base_minor' => 1_000_000],
                'result_snapshot' => ['employee_minor' => 71_000],
            ]],
        ]];
    }

    private function createEmployee(PDO $pdo, int $supplierId, string $suffix): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId, 'Syntetická osoba ' . $suffix]);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployment(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        string $suffix,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01")'
        )->execute([$supplierId, $employeeId, 'synthetic-' . $suffix]);

        return (int) $pdo->lastInsertId();
    }

    private function createRevisionGraph(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        int $employmentId,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-06-30")'
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v1", ?, "{}", ?, ?)'
        )->execute([
            $supplierId,
            $runId,
            str_repeat('1', 64),
            str_repeat('2', 64),
            hash('sha256', 'synthetic-statutory-revision-' . $supplierId, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")'
        )->execute([$supplierId, $revisionId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, "{}", ?, "calculated")'
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            str_repeat('3', 64),
        ]);

        return $revisionId;
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
