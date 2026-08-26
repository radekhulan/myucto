<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollEmployeeCardsScaleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-08';

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private int $supplierId;
    private int $userId;
    /** @var list<int> */
    private array $employmentIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollQuickInputsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employees', 'payroll_employments', 'payroll_absences'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
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

    public function testCardsKeepFiveHundredAndOneEmploymentsReachable(): void
    {
        $this->seedEmployments(501);

        $first = $this->cards(['limit' => '1000', 'offset' => '0', 'status' => 'all']);
        self::assertCount(25, $first['items']);
        self::assertSame(501, $first['total']);
        self::assertSame(501, $first['company_headcount']);
        self::assertSame(501, $first['summary']['people']);
        self::assertSame(1_503_000_000, $first['summary']['gross_preview_minor']);

        $last = $this->cards(['limit' => '25', 'offset' => '500', 'status' => 'all']);
        self::assertCount(1, $last['items']);
        self::assertSame($this->employmentIds[500], $last['items'][0]['employment_id']);
    }

    public function testSearchAndAwayFilterReachAnEmploymentBeyondTheFirstPage(): void
    {
        $this->seedEmployments(501);
        $lastEmploymentId = $this->employmentIds[500];
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 support_status, status, requested_by)
             VALUES (?, ?, "vacation", "2026-08-10", "2026-08-14",
                     "manual_review", "approved", ?)'
        )->execute([$this->supplierId, $lastEmploymentId, $this->userId]);

        $searched = $this->cards(['search' => '501', 'status' => 'all']);
        self::assertSame(1, $searched['total']);
        self::assertSame($lastEmploymentId, $searched['items'][0]['employment_id']);

        $away = $this->cards(['status' => 'away']);
        self::assertSame(1, $away['total']);
        self::assertSame(1, $away['summary']['away']);
        self::assertSame($lastEmploymentId, $away['items'][0]['employment_id']);
        self::assertCount(1, $away['items'][0]['absences']);
        self::assertSame('vacation', $away['items'][0]['absences'][0]['absence_type']);
    }

    /** @param array<string,string> $query @return array<string,mixed> */
    private function cards(array $query): array
    {
        $response = $this->action->list(
            $this->request()->withQueryParams([
                'period' => self::PERIOD,
                'view' => 'cards',
                ...$query,
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = $this->json($response);
        self::assertIsArray($payload['month'] ?? null);

        return $payload['month'];
    }

    private function seedEmployments(int $count): void
    {
        $pdo = $this->db->pdo();
        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $employment = $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 3000000, 0)'
        );
        for ($ordinal = 1; $ordinal <= $count; ++$ordinal) {
            $employee->execute([
                $this->supplierId,
                sprintf('Syntetický zaměstnanec %03d', $ordinal),
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $employment->execute([
                $this->supplierId,
                $employeeId,
                'SYN-CARD-' . $ordinal,
            ]);
            $this->employmentIds[] = (int) $pdo->lastInsertId();
        }
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/quick-inputs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
