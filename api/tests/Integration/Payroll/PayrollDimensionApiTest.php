<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDimensionAction;
use MyInvoice\Action\Payroll\PayrollEmploymentDimensionAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollDimensionApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDimensionAction $dimensionAction;
    private PayrollEmploymentDimensionAction $assignmentAction;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $dimensionAction = $container->get(PayrollDimensionAction::class);
        $assignmentAction = $container->get(PayrollEmploymentDimensionAction::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDimensionAction::class, $dimensionAction);
        self::assertInstanceOf(PayrollEmploymentDimensionAction::class, $assignmentAction);
        $this->db = $connection;
        $this->dimensionAction = $dimensionAction;
        $this->assignmentAction = $assignmentAction;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

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
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testCreateListUpdateAndTenantIsolation(): void
    {
        $created = $this->createDimension($this->supplierId, ['code' => 'STR-1', 'name' => 'Vývoj']);
        self::assertSame(1, $created['row_version']);
        self::assertSame('cost_center', $created['dimension_type']);

        $list = $this->dimensionAction->list(
            $this->request('GET', $this->supplierId),
            new Response(),
        );
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $this->rows($this->json($list)['dimensions'] ?? null));

        $foreignList = $this->dimensionAction->list(
            $this->request('GET', $this->otherSupplierId),
            new Response(),
        );
        self::assertSame(200, $foreignList->getStatusCode());
        self::assertCount(0, $this->rows($this->json($foreignList)['dimensions'] ?? null));

        $update = $this->dimensionAction->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($this->dimensionPayload([
                'row_version' => 1,
                'name' => 'Vývoj a podpora',
            ])),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(200, $update->getStatusCode());
        $updated = $this->row($this->json($update)['dimension'] ?? null);
        self::assertSame(2, $updated['row_version']);
        self::assertSame('Vývoj a podpora', $updated['name']);
    }

    public function testOverlapAndStaleVersionHaveExactConflictCodes(): void
    {
        $created = $this->createDimension($this->supplierId, [
            'code' => 'STR-2',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-06-30',
        ]);

        $overlap = $this->dimensionAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody($this->dimensionPayload([
                'code' => 'STR-2',
                'valid_from' => '2026-06-30',
                'valid_to' => null,
            ])),
            new Response(),
        );
        self::assertSame(409, $overlap->getStatusCode());
        self::assertSame(
            'dimension_interval_overlap',
            $this->row($this->json($overlap)['error'] ?? null)['code'],
        );

        $stale = $this->dimensionAction->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($this->dimensionPayload([
                'code' => 'STR-2',
                'row_version' => 1,
                'name' => 'Přejmenováno jednou',
            ])),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(200, $stale->getStatusCode());

        $conflict = $this->dimensionAction->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($this->dimensionPayload([
                'code' => 'STR-2',
                'row_version' => 1,
                'name' => 'Přejmenováno podruhé se starou verzí',
            ])),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(409, $conflict->getStatusCode());
        $conflictError = $this->row($this->json($conflict)['error'] ?? null);
        self::assertSame('row_version_conflict', $conflictError['code']);
        self::assertSame(2, $conflictError['current_row_version']);
    }

    public function testAssignmentForbidsOverlapOfSameTypeAndReportsRowVersionConflict(): void
    {
        $employmentId = $this->createEmployment($this->supplierId);
        $ccOne = $this->createDimension($this->supplierId, ['code' => 'CC-A']);
        $ccTwo = $this->createDimension($this->supplierId, ['code' => 'CC-B']);

        $first = $this->assignmentAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody([
                'dimension_id' => $ccOne['id'],
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-06-30',
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(201, $first->getStatusCode());
        $firstAssignment = $this->row($this->json($first)['dimension'] ?? null);
        self::assertSame('cost_center', $firstAssignment['dimension_type'] ?? null);
        self::assertSame('CC-A', $firstAssignment['dimension_code'] ?? null);
        self::assertSame($ccOne['name'], $firstAssignment['dimension_name'] ?? null);

        $overlap = $this->assignmentAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody([
                'dimension_id' => $ccTwo['id'],
                'valid_from' => '2026-06-01',
                'valid_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(409, $overlap->getStatusCode());
        self::assertSame(
            'employment_dimension_interval_overlap',
            $this->row($this->json($overlap)['error'] ?? null)['code'],
        );

        $list = $this->assignmentAction->list(
            $this->request('GET', $this->supplierId),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $this->rows($this->json($list)['dimensions'] ?? null));

        $staleUpdate = $this->assignmentAction->update(
            $this->request('PUT', $this->supplierId)->withParsedBody([
                'dimension_id' => $ccOne['id'],
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'row_version' => 999,
            ]),
            new Response(),
            ['id' => (string) $employmentId, 'assignmentId' => (string) $firstAssignment['id']],
        );
        self::assertSame(409, $staleUpdate->getStatusCode());
        $staleError = $this->row($this->json($staleUpdate)['error'] ?? null);
        self::assertSame('row_version_conflict', $staleError['code']);
        self::assertSame(1, $staleError['current_row_version']);
    }

    public function testAssignmentRejectsInactiveOrIneffectiveDimension(): void
    {
        $employmentId = $this->createEmployment($this->supplierId);
        $future = $this->createDimension($this->supplierId, [
            'code' => 'CC-FUTURE',
            'valid_from' => '2027-01-01',
            'valid_to' => null,
        ]);

        $response = $this->assignmentAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody([
                'dimension_id' => $future['id'],
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->row($this->json($response)['error'] ?? null)['code'],
        );
    }

    public function testDeleteIsBlockedOnlyWhenDimensionIsUsedInApprovedRevision(): void
    {
        $unused = $this->createDimension($this->supplierId, ['code' => 'CC-UNUSED']);
        $deleteUnused = $this->dimensionAction->delete(
            $this->request('DELETE', $this->supplierId),
            new Response(),
            ['id' => (string) $unused['id']],
        );
        self::assertSame(200, $deleteUnused->getStatusCode());
        self::assertTrue($this->json($deleteUnused)['deleted'] ?? false);

        $employmentId = $this->createEmployment($this->supplierId);
        $used = $this->createDimension($this->supplierId, ['code' => 'CC-USED']);
        $assignment = $this->assignmentAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody([
                'dimension_id' => $used['id'],
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(201, $assignment->getStatusCode());
        $this->approveRevisionFor($this->supplierId, $employmentId, '2026-07-01');

        $blockedDelete = $this->dimensionAction->delete(
            $this->request('DELETE', $this->supplierId),
            new Response(),
            ['id' => (string) $used['id']],
        );
        self::assertSame(409, $blockedDelete->getStatusCode());
        self::assertSame(
            'dimension_in_use',
            $this->row($this->json($blockedDelete)['error'] ?? null)['code'],
        );

        $terminate = $this->dimensionAction->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($this->dimensionPayload([
                'code' => 'CC-USED',
                'row_version' => 1,
                'valid_to' => '2026-12-31',
            ])),
            new Response(),
            ['id' => (string) $used['id']],
        );
        self::assertSame(200, $terminate->getStatusCode());
        self::assertSame(
            '2026-12-31',
            $this->row($this->json($terminate)['dimension'] ?? null)['valid_to'],
        );
    }

    public function testTaxEvidenceCompanyCanFullyUseDimensions(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = "tax_evidence" WHERE id = ?')
            ->execute([$this->supplierId]);

        $dimension = $this->createDimension($this->supplierId, ['code' => 'TE-CC-1']);
        $employmentId = $this->createEmployment($this->supplierId);

        $assign = $this->assignmentAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody([
                'dimension_id' => $dimension['id'],
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(201, $assign->getStatusCode());
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function createDimension(int $supplierId, array $overrides = []): array
    {
        $response = $this->dimensionAction->create(
            $this->request('POST', $supplierId)->withParsedBody($this->dimensionPayload($overrides)),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->row($this->json($response)['dimension'] ?? null);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function dimensionPayload(array $overrides = []): array
    {
        return array_replace([
            'row_version' => 0,
            'dimension_type' => 'cost_center',
            'code' => 'STR-SYNTH',
            'name' => 'Syntetické středisko',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'default_account_code' => null,
        ], $overrides);
    }

    private function createEmployment(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba dimenze", "employee", 1)'
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, "dimension-synthetic", "employment", "active", "2026-01-01")'
        )->execute([$supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function approveRevisionFor(int $supplierId, int $employmentId, string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $employeeId = $this->fetchEmployeeId($supplierId, $employmentId);

        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, LAST_DAY(?), "approved")'
        )->execute([$supplierId, $periodStart, $periodStart]);
        $runId = (int) $pdo->lastInsertId();

        $inputJson = '{}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, ?, ?, UNHEX(?))'
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            hash('sha256', 'synthetic-dimension-approval-' . $employmentId . '-' . $periodStart),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $employmentInput = '{}';
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, "calculated")'
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            $employmentInput,
            hash('sha256', $employmentInput),
        ]);
    }

    private function fetchEmployeeId(int $supplierId, int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,string> $query
     */
    private function request(
        string $method,
        int $supplierId,
        string $role = 'admin',
        string $authMethod = 'session',
        array $query = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/settings/dimensions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => $role,
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $query === [] ? $request : $request->withQueryParams($query);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return $this->row($decoded);
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Testovací HTTP DTO není pole.');
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Testovací HTTP DTO nemá textové klíče.');
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Testovací seznam HTTP DTO není pole.');
        }
        $result = [];
        foreach ($value as $row) {
            $result[] = $this->row($row);
        }

        return $result;
    }
}
