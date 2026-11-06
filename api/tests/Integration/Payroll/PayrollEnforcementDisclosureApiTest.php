<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEnforcementAction;
use MyInvoice\Action\Payroll\PayrollPeopleAction;
use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Security\PayrollEnforcementDisclosure;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Detail mzdového běhu a seznam zaměstnanců čte obecné `payroll` READ. Exekuční
 * registr (pohledávky, vyživovací povinnost, insolvence) ani blokátor smazání,
 * který exekuci jmenuje, ale volající bez `payroll.enforcement` nedostane, a to
 * ani přes API token. S oprávněním zůstává odpověď beze změny.
 */
#[Group('integration')]
final class PayrollEnforcementDisclosureApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunsAction $runs;
    private PayrollPeopleAction $people;
    private PayrollEnforcementAction $enforcement;
    private int $userId;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $runs = $container->get(PayrollRunsAction::class);
        $people = $container->get(PayrollPeopleAction::class);
        $enforcement = $container->get(PayrollEnforcementAction::class);
        if (
            !$db instanceof Connection
            || !$runs instanceof PayrollRunsAction
            || !$people instanceof PayrollPeopleAction
            || !$enforcement instanceof PayrollEnforcementAction
        ) {
            throw new \RuntimeException('Mzdové služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->runs = $runs;
        $this->people = $people;
        $this->enforcement = $enforcement;
        if (!$db->hasTable('payroll_enforcement_cases')) {
            $this->markTestSkipped('Migrace 1240 neproběhla.');
        }
        $pdo = $db->pdo();
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
             VALUES (?, "Syntetický povinný", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testRunDetailHidesClaimRegisterFromSessionAndTokenWithoutEnforcementPermission(): void
    {
        $runId = $this->runWithEnforcementSnapshot();

        foreach (['session', 'bearer'] as $method) {
            $response = $this->runs->detail(
                $this->request('GET', "/api/payroll/runs/{$runId}", $this->payrollReader(), $method),
                new Response(),
                ['id' => (string) $runId],
            );
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $body = (string) $response->getBody();
            self::assertStringNotContainsString('maintenance_arrears', $body, $method);
            self::assertStringNotContainsString('eligible_dependants', $body, $method);
            $person = $this->json($response)['run']['result_snapshot']['people'][0];
            self::assertTrue($person['enforcement']['redacted']);
            self::assertSame(1051400, $person['enforcement']['result']['total_withheld_minor_units']);
            self::assertSame(30000, $person['payable_after_enforcement_minor']);
        }

        $full = $this->runs->detail(
            $this->request('GET', "/api/payroll/runs/{$runId}", $this->enforcementReader()),
            new Response(),
            ['id' => (string) $runId],
        );
        self::assertSame(200, $full->getStatusCode());
        $person = $this->json($full)['run']['result_snapshot']['people'][0];
        self::assertSame('maintenance_arrears', $person['enforcement']['input']['claims'][0]['category']);

        // Seznam běhů: součet srážek jen s právem na exekuce.
        foreach ([[$this->payrollReader(), null], [$this->enforcementReader(), 1051400]] as [$role, $expected]) {
            $list = $this->runs->list(
                $this->request('GET', '/api/payroll/runs', $role)->withQueryParams(['period' => '2026-06']),
                new Response(),
            );
            self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
            $row = array_values(array_filter(
                $this->json($list)['runs'],
                static fn (array $item): bool => (int) $item['id'] === $runId,
            ))[0] ?? self::fail('Běh v seznamu chybí.');
            self::assertSame($expected, $row['result_snapshot']['totals']['enforcement_withheld_minor']);
        }
    }

    public function testDeleteBlockerDoesNotNameEnforcementWithoutPermission(): void
    {
        $case = $this->enforcement->create(
            $this->request('POST', '/api/payroll/enforcement/cases')->withParsedBody([
                'employee_id' => $this->employeeId,
                'case_kind' => 'enforcement',
                'effective_from' => '2026-05-20',
            ]),
            new Response(),
        );
        self::assertSame(201, $case->getStatusCode(), (string) $case->getBody());

        $list = $this->people->list(
            $this->request('GET', '/api/payroll/people', $this->payrollReader())
                ->withQueryParams(['filter' => 'all']),
            new Response(),
        );
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $row = array_values(array_filter(
            $this->json($list)['items'],
            fn (array $item): bool => (int) $item['id'] === $this->employeeId,
        ))[0] ?? self::fail('Zaměstnanec v seznamu chybí.');
        self::assertFalse($row['can_delete']);
        self::assertSame(PayrollEnforcementDisclosure::RESTRICTED_DELETE_BLOCKER, $row['delete_blocker']['code']);

        $detail = $this->people->detail(
            $this->request('GET', "/api/payroll/people/{$this->employeeId}", $this->payrollReader(), 'bearer'),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(
            PayrollEnforcementDisclosure::RESTRICTED_DELETE_BLOCKER,
            $this->json($detail)['person']['delete_blocker']['code'],
        );
        self::assertStringNotContainsString('exekuce', (string) $detail->getBody());

        $allowed = $this->people->detail(
            $this->request('GET', "/api/payroll/people/{$this->employeeId}", $this->enforcementReader()),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(
            'payroll_employee_has_enforcement',
            $this->json($allowed)['person']['delete_blocker']['code'],
        );
    }

    private function runWithEnforcementSnapshot(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-06-30")'
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = json_encode([
            'schema_version' => 'payroll-run-result.v1',
            'totals' => ['enforcement_withheld_minor' => 1051400],
            'people' => [[
                'employee_id' => $this->employeeId,
                'payable_after_enforcement_minor' => 30000,
                'enforcement' => [
                    'input' => [
                        'claims' => [['category' => 'maintenance_arrears', 'outstanding_minor_units' => 18765400]],
                        'evidence' => ['eligible_dependants' => 1],
                        'insolvency' => ['mode' => 'none'],
                    ],
                    'result' => ['insolvency_applied' => false, 'total_withheld_minor_units' => 1051400],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v1", ?, "{}", ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', 'synthetic-disclosure-revision', true),
        ]);
        $pdo->prepare('UPDATE payroll_runs SET current_revision_no = 1 WHERE id = ?')->execute([$runId]);

        return $runId;
    }

    private function payrollReader(): EffectiveRole
    {
        return new EffectiveRole(401, 'Účetní bez exekucí', 'staff', true, ['payroll' => 1]);
    }

    private function enforcementReader(): EffectiveRole
    {
        return new EffectiveRole(
            402,
            'Mzdová účetní s exekucemi',
            'staff',
            true,
            ['payroll' => 1, 'payroll.enforcement' => 1, 'payroll.insolvency' => 1],
        );
    }

    private function request(
        string $method,
        string $uri,
        ?EffectiveRole $role = null,
        string $authMethod = 'session',
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $role === null ? $request : $request->withAttribute('auth.effective_role', $role);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
