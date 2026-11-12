<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollMonthGuideAction;
use MyInvoice\Action\Payroll\PayrollMonthlyChecklistAction;
use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollMonthGuideActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-03';
    private const STEPS = ['inputs', 'people', 'registrations', 'check', 'run', 'submissions', 'payments'];

    private Connection $db;
    private PayrollMonthGuideAction $action;
    private PayrollRunsAction $runs;
    private PayrollMonthlyChecklistAction $checklist;
    private PayrollRunCommandService $commands;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        foreach (['payroll_runs', 'payroll_run_revisions', 'payroll_submissions', 'payroll_attendance_imports'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace úplných mezd neproběhly.');
            }
        }
        $this->action = $container->get(PayrollMonthGuideAction::class);
        $this->runs = $container->get(PayrollRunsAction::class);
        $this->checklist = $container->get(PayrollMonthlyChecklistAction::class);
        $this->commands = $container->get(PayrollRunCommandService::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
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

    public function testGuideWithoutRunMirrorsReadinessOfRunsScreen(): void
    {
        $guide = $this->guide();

        self::assertSame(self::PERIOD, $guide['period']);
        self::assertSame([], $guide['runs']);
        self::assertSame(self::STEPS, array_column($guide['steps'], 'key'));
        $steps = array_column($guide['steps'], null, 'key');
        self::assertSame('create_run', $steps['run']['action']);
        self::assertContains($steps['run']['state'], ['todo', 'waiting']);
        self::assertSame('waiting', $steps['submissions']['state']);
        self::assertSame('waiting', $steps['payments']['state']);

        $readiness = $this->runsList()['readiness'];
        self::assertIsArray($readiness);
        $byImpact = ['blocking' => 0, 'approval' => 0, 'revision' => 0, 'anytime' => 0];
        foreach ($readiness['findings'] as $finding) {
            ++$byImpact[$finding['impact']];
        }
        self::assertSame('readiness', $steps['check']['facts']['source']);
        self::assertSame($byImpact, $steps['check']['facts']['by_impact']);
        self::assertSame($byImpact['blocking'] + $byImpact['approval'], $steps['check']['remaining']);
    }

    public function testDraftRunKeepsReadinessAndReportsRunStatus(): void
    {
        $this->commands->createRun($this->supplierId, self::PERIOD . '-01', '2026-04-15', null, $this->userId);

        $guide = $this->guide();
        $steps = array_column($guide['steps'], null, 'key');

        self::assertCount(1, $guide['runs']);
        self::assertSame('draft', $guide['runs'][0]['status']);
        self::assertSame('todo', $steps['run']['state']);
        self::assertSame('runs', $steps['run']['action']);
        self::assertSame('draft', $steps['run']['facts']['status']);
        $readiness = $this->runsList()['readiness'];
        self::assertIsArray($readiness);
        self::assertSame(count($readiness['findings']), array_sum($steps['check']['facts']['by_impact']));
    }

    public function testSubmissionStepCountsMatchMonthlyChecklist(): void
    {
        $guide = $this->guide();
        $steps = array_column($guide['steps'], null, 'key');

        $response = ($this->checklist)(
            $this->request('/api/payroll/submissions/monthly-checklist', ['environment' => 'production', 'period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $summary = $this->json($response)['summary'];
        self::assertSame($summary, $steps['submissions']['facts']['summary']);
        self::assertSame($summary['total'] - $summary['done'], $steps['submissions']['remaining']);
    }

    public function testSectionsWithoutPermissionAreUnavailable(): void
    {
        $guide = $this->guide(new EffectiveRole(911, 'Jen mzdy', 'staff', true, ['payroll' => AccessLevel::READ->value]));
        $steps = array_column($guide['steps'], null, 'key');

        self::assertSame('unavailable', $steps['registrations']['state']);
        self::assertSame('unavailable', $steps['submissions']['state']);
        self::assertNull($steps['inputs']['facts']['attendance_batches']);
        self::assertNull($steps['payments']['facts']['documents_total']);
    }

    public function testRejectsInvalidPeriodAndBearerToken(): void
    {
        $invalid = ($this->action)($this->request('/api/payroll/month-guide', ['period' => '2026-13']), new Response());
        self::assertSame(422, $invalid->getStatusCode());

        $bearer = ($this->action)(
            $this->request('/api/payroll/month-guide', ['period' => self::PERIOD])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'),
            new Response(),
        );
        self::assertNotSame(200, $bearer->getStatusCode());

        $forbidden = ($this->action)(
            $this->request('/api/payroll/month-guide', ['period' => self::PERIOD], new EffectiveRole(912, 'Bez mezd', 'staff', true, [])),
            new Response(),
        );
        self::assertSame(403, $forbidden->getStatusCode());
    }

    /** @return array<string,mixed> */
    private function guide(?EffectiveRole $role = null): array
    {
        $response = ($this->action)(
            $this->request('/api/payroll/month-guide', ['period' => self::PERIOD], $role),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function runsList(): array
    {
        $response = $this->runs->list(
            $this->request('/api/payroll/runs', ['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /** @param array<string,string> $query */
    private function request(string $uri, array $query, ?EffectiveRole $role = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role ?? new EffectiveRole(
                913,
                'Průvodce měsícem',
                'staff',
                true,
                [
                    'payroll' => AccessLevel::READ->value,
                    'payroll.submissions' => AccessLevel::READ->value,
                    'payroll.inputs.write' => AccessLevel::WRITE->value,
                    'payroll.documents' => AccessLevel::READ->value,
                ],
            ));
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
