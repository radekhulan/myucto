<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollLeaveEntitlementCandidatePaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAbsenceAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $firstEmploymentId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAbsenceAction::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        foreach (['payroll_employer_policies', 'payroll_employment_terms',
            'payroll_leave_entitlement_snapshots', 'payroll_time_months'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->seedEmployments($this->supplierId, 502);
        $this->seedEmployments($this->otherSupplierId, 1);
        $pdo->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-01-01", "approved", 3, 7, ?, ?, NOW())',
        )->execute([$this->supplierId, $this->firstEmploymentId, $this->userId, $this->userId]);
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

    public function testMoreThanFiveHundredRelationsArePagedTenantScopedAndReadOnly(): void
    {
        $first = $this->page(0, 10000);
        $last = $this->page(500, 100);

        self::assertSame(502, $first['total']);
        self::assertSame(100, $first['limit']);
        self::assertCount(100, $first['items']);
        self::assertSame(502, $last['total']);
        self::assertCount(2, $last['items']);
        foreach ([...$first['items'], ...$last['items']] as $item) {
            self::assertStringStartsWith('TENANT-', (string) $item['employment_code']);
        }

        $stored = $this->db->pdo()->prepare(
            'SELECT status, revision_no, row_version FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-01-01"',
        );
        $stored->execute([$this->supplierId, $this->firstEmploymentId]);
        self::assertSame(
            ['status' => 'approved', 'revision_no' => 3, 'row_version' => 7],
            $stored->fetch(\PDO::FETCH_ASSOC),
            'Náhled dávky smí schválený měsíc pouze číst.',
        );
    }

    /** @return array<string,mixed> */
    private function page(int $offset, int $limit): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/time/leave-entitlement-candidates')
            ->withQueryParams([
                'year' => '2026',
                'through' => '2026-01-31',
                'limit' => (string) $limit,
                'offset' => (string) $offset,
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $response = $this->action->leaveEntitlementCandidates($request, new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function seedEmployments(int $supplierId, int $count): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([$supplierId, "Syntetická osoba {$supplierId}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 0)',
        );
        for ($index = 1; $index <= $count; $index++) {
            $prefix = $supplierId === $this->supplierId ? 'TENANT' : 'OTHER';
            $insert->execute([$supplierId, $employeeId, sprintf('%s-%04d', $prefix, $index)]);
            if ($supplierId === $this->supplierId && $index === 1) {
                $this->firstEmploymentId = (int) $pdo->lastInsertId();
            }
        }
    }
}
