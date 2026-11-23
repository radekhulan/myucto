<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Kdo v docházce za období je a kdo tam nemá co dělat.
 *
 * Filtr `employment.status <> 'cancelled'` byl po migraci 1195 mrtvý — hodnota
 * z enumu zmizela, podmínka tedy nevyloučila nic a docházka vypisovala i vztahy
 * `no_show` (nenastoupil) a `archived`. Tenhle test drží opravené chování.
 */
#[Group('integration')]
final class PayrollTimeEmploymentVisibilityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTimeAction $action;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollTimeAction::class);
        if (!$db instanceof Connection || !$action instanceof PayrollTimeAction) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->action = $action;
        foreach (['payroll_employments', 'payroll_time_months'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, 'Syntetický vícevztahový člověk']);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$this->supplierId, $this->employeeId]);
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

    public function testArchivedAndNoShowRelationsStayOutOfAttendance(): void
    {
        $active = $this->employment('VIS-ACTIVE', 'active', '2026-01-01');
        $archived = $this->employment('VIS-ARCHIVED', 'archived', '2026-01-01');
        $noShow = $this->employment('VIS-NOSHOW', 'no_show', '2026-01-01');

        $visible = $this->visibleEmploymentIds('2026-05');

        self::assertContains($active, $visible, 'Aktivní vztah v docházce chybí.');
        self::assertNotContains(
            $archived,
            $visible,
            'Archivovaný vztah se do docházky nesmí dostat.',
        );
        self::assertNotContains(
            $noShow,
            $visible,
            'Vztah, do kterého nikdo nenastoupil, se do docházky nesmí dostat.',
        );
    }

    public function testPlannedRelationAppearsOnlyOnceItsStartFallsIntoThePeriod(): void
    {
        $startsInPeriod = $this->employment('VIS-PLAN-IN', 'planned', '2026-05-20');
        $startsLater = $this->employment('VIS-PLAN-LATER', 'planned', '2026-07-01');

        $visible = $this->visibleEmploymentIds('2026-05');

        self::assertContains(
            $startsInPeriod,
            $visible,
            'Plánovaný vztah s nástupem v období do docházky patří.',
        );
        self::assertNotContains(
            $startsLater,
            $visible,
            'Kdo nastupuje až po období, v docházce za období nemá co dělat.',
        );
    }

    /**
     * Stav se čte K OBDOBÍ, ne k dnešku.
     *
     * Vztah archivovaný až po skončení období byl v tom období pořád aktivní a
     * docházka za něj se musí dát zpětně otevřít.
     */
    public function testStatusIsReadForThePeriodAndNotForToday(): void
    {
        $employmentId = $this->employment('VIS-LATE-ARCHIVE', 'archived', '2026-01-01');
        $this->lifecycleEvent($employmentId, 'created', null, 'active', '2026-01-01');
        $this->lifecycleEvent($employmentId, 'status_changed', 'active', 'archived', '2026-08-01');

        self::assertContains(
            $employmentId,
            $this->visibleEmploymentIds('2026-05'),
            'V květnu byl vztah aktivní — archivace ze srpna nesmí docházku zpětně vyprázdnit.',
        );
        self::assertNotContains(
            $employmentId,
            $this->visibleEmploymentIds('2026-09'),
            'Po archivaci už vztah do docházky nepatří.',
        );
    }

    /** @return list<int> */
    private function visibleEmploymentIds(string $period): array
    {
        $response = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => $period, 'incomplete' => 0]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $items = $body['items'] ?? null;
        self::assertIsArray($items);

        $ids = [];
        foreach (PayrollTimeValue::rows($items, 'time_items') as $item) {
            $employment = PayrollTimeValue::row($item['employment'] ?? null, 'employment');
            $ids[] = PayrollTimeValue::int($employment['id'] ?? null, 'employment.id');
        }

        return $ids;
    }

    private function employment(string $code, string $status, string $startDate): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", ?, ?, NULL, 4200000, 0)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $code,
            $status,
            $startDate,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function lifecycleEvent(
        int $employmentId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        string $effectiveOn,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status,
                 effective_on)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $employmentId,
            $eventType,
            $fromStatus,
            $toStatus,
            $effectiveOn,
        ]);
    }

    private function request(
        string $method,
        string $uri,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return PayrollTimeValue::row(
            json_decode((string) $response->getBody(), true),
            'response',
        );
    }
}
