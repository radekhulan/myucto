<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Issue #113 — oprava časového zápisu (nová revize přes `supersedes_id`):
 * chybějící `difficulty_factor_count` a `timezone` se přebírají z opravované
 * revize, výslovný null počet vlivů maže.
 */
#[Group('integration')]
final class PayrollTimeEntryPartialCorrectionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTimeAction $action;
    private PayrollTimeService $service;
    private int $supplierId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollTimeAction::class);
        $this->service = $container->get(PayrollTimeService::class);
        if (!$this->db->hasTable('payroll_time_entries')) {
            $this->markTestSkipped('Migrace MZ-06 neproběhly.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická zaměstnankyně", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'SYN-TIME-PART', 'employment', 'active', '2026-01-01', 4200000, 0)"
        )->execute([$this->supplierId, $employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
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

    public function testCorrectionWithoutKeysKeepsStoredValuesAndNullClears(): void
    {
        $first = $this->entry([
            'timezone' => 'Europe/Bratislava',
            'difficulty_factor_count' => 3,
            'row_version' => 0,
            'month_row_version' => 0,
            'supersedes_id' => null,
        ]);

        $second = $this->entry([
            'ends_at' => '2026-05-04T15:00:00+02:00',
            'row_version' => $first['entry']['row_version'],
            'month_row_version' => $first['month']['row_version'],
            'supersedes_id' => $first['entry']['id'],
        ]);
        self::assertSame(2, $second['entry']['revision_no']);
        self::assertSame([3, 'Europe/Bratislava'], $this->stored((int) $second['entry']['id']));

        $third = $this->entry([
            'timezone' => 'Europe/Prague',
            'difficulty_factor_count' => null,
            'row_version' => $second['entry']['row_version'],
            'month_row_version' => $second['month']['row_version'],
            'supersedes_id' => $second['entry']['id'],
        ]);
        self::assertSame([null, 'Europe/Prague'], $this->stored((int) $third['entry']['id']));
    }

    public function testCategoryChangeDoesNotCarryFactorCountOver(): void
    {
        $first = $this->entry([
            'timezone' => 'Europe/Prague',
            'difficulty_factor_count' => 2,
            'row_version' => 0,
            'month_row_version' => 0,
            'supersedes_id' => null,
        ]);
        $second = $this->entry([
            'category' => 'regular',
            'row_version' => $first['entry']['row_version'],
            'month_row_version' => $first['month']['row_version'],
            'supersedes_id' => $first['entry']['id'],
        ]);
        self::assertSame([null, 'Europe/Prague'], $this->stored((int) $second['entry']['id']));
    }

    public function testCorrectionWithoutInstantsKeepsStoredIntervalInNonUtcTimezone(): void
    {
        $first = $this->entry([
            'timezone' => 'Europe/Prague',
            'difficulty_factor_count' => 2,
            'row_version' => 0,
            'month_row_version' => 0,
            'supersedes_id' => null,
        ]);
        $before = $this->storedInterval((int) $first['entry']['id']);

        $second = $this->entry([
            'employment_id' => $this->employmentId,
            'difficulty_factor_count' => 4,
            'row_version' => $first['entry']['row_version'],
            'month_row_version' => $first['month']['row_version'],
            'supersedes_id' => $first['entry']['id'],
        ], false);

        self::assertSame([4, 'Europe/Prague'], $this->stored((int) $second['entry']['id']));
        self::assertSame($before, $this->storedInterval((int) $second['entry']['id']));
    }

    public function testBatchDefaultTimezoneDoesNotOverrideStoredTimezoneOfCorrection(): void
    {
        $first = $this->entry([
            'timezone' => 'Europe/Bratislava',
            'difficulty_factor_count' => 3,
            'row_version' => 0,
            'month_row_version' => 0,
            'supersedes_id' => null,
        ]);
        $before = $this->storedInterval((int) $first['entry']['id']);

        $result = $this->service->saveEntryBatch(
            $this->supplierId,
            [
                'timezone' => 'Europe/Prague',
                'cells' => [[
                    'employment_id' => $this->employmentId,
                    'row_version' => $first['entry']['row_version'],
                    'month_row_version' => $first['month']['row_version'],
                    'supersedes_id' => $first['entry']['id'],
                ]],
            ],
            $this->userId,
        );

        self::assertSame([], $result['failures']);
        self::assertSame(1, $result['saved']);
        $id = (int) $this->db->pdo()->query(
            'SELECT MAX(id) FROM payroll_time_entries WHERE supplier_id = ' . $this->supplierId
        )->fetchColumn();
        self::assertSame([3, 'Europe/Bratislava'], $this->stored($id));
        self::assertSame($before, $this->storedInterval($id));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function entry(array $overrides, bool $withDefaults = true): array
    {
        $defaults = $withDefaults ? [
            'employment_id' => $this->employmentId,
            'starts_at' => '2026-05-04T08:00:00+02:00',
            'ends_at' => '2026-05-04T16:00:00+02:00',
            'category' => 'difficult_environment',
            'break_minutes' => 30,
        ] : [];
        $response = $this->action->entry(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/payroll/time/entries')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withParsedBody([...$defaults, ...$overrides]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array{?int,string} */
    private function stored(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT difficulty_factor_count, timezone_name
               FROM payroll_time_entries WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            $row['difficulty_factor_count'] === null ? null : (int) $row['difficulty_factor_count'],
            (string) $row['timezone_name'],
        ];
    }

    /** @return array<string,mixed> */
    private function storedInterval(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT category, starts_at_utc, ends_at_utc, break_minutes
               FROM payroll_time_entries WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
