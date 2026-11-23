<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Service\Payroll\Component\PayrollRecurringMaterializer;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Měsíc s uzavřenou mzdou se ručním zadáním nemění.
 *
 * Nález: rychlý měsíční vstup za září, jehož mzdový běh byl uzavřený (JMHZ
 * podané, výplaty odeslané), dovolil vyplnit přesčas a odměnu a uložil je jako
 * NOVÉ vstupy. Do výplaty se nedostaly, v přehledu ale vypadaly, že ano.
 *
 * Rozhoduje aktuální revize běhu a stav běhu: `approved` a dál je uzavřené,
 * `correction_pending` je přesně stav, ve kterém se oprava zadává — ten
 * zapisovat musí.
 */
#[Group('integration')]
final class PayrollClosedRunGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-09';

    private Connection $db;
    private PayrollQuickInputsAction $quickAction;
    private PayrollQuickInputRepository $quickInputs;
    private PayrollInputsAction $inputsAction;
    private PayrollRecurringMaterializer $recurring;
    private PayrollClosedRunGuard $guard;
    private int $supplierId;
    private int $userId;
    /** @var array<string,array{employee:int,employment:int}> */
    private array $people = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->quickAction = $container->get(PayrollQuickInputsAction::class);
            $this->quickInputs = $container->get(PayrollQuickInputRepository::class);
            $this->inputsAction = $container->get(PayrollInputsAction::class);
            $this->recurring = $container->get(PayrollRecurringMaterializer::class);
            $this->guard = $container->get(PayrollClosedRunGuard::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_inputs', 'payroll_run_employments'] as $table) {
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

        $this->seed('Syntetická Uzavřená', 'SYN-CR-CLOSED');
        $this->seed('Syntetický Opravovaný', 'SYN-CR-CORR');
        $this->seed('Syntetický Mimo běh', 'SYN-CR-OUTSIDE');
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

    public function testSaveIntoClosedRunIsRefusedPerRow(): void
    {
        $this->payrollRun('closed', ['SYN-CR-CLOSED']);

        $failures = null;
        $this->quickInputs->save(
            $this->supplierId,
            self::PERIOD,
            [
                $this->row('SYN-CR-CLOSED', bonus: 500_000),
                $this->row('SYN-CR-OUTSIDE', bonus: 300_000),
            ],
            $this->userId,
            failures: $failures,
        );

        $closed = $this->people['SYN-CR-CLOSED']['employment'];
        self::assertIsArray($failures);
        self::assertCount(1, $failures, json_encode($failures, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($closed, $failures[0]['employment_id']);
        self::assertSame('payroll_run_closed', $failures[0]['code']);
        self::assertStringContainsString('9/2026', $failures[0]['message']);
        self::assertSame(0, $this->inputCount('SYN-CR-CLOSED'), 'Do uzavřeného měsíce nesmí vzniknout vstup.');
        self::assertGreaterThan(0, $this->inputCount('SYN-CR-OUTSIDE'), 'Vztah mimo běh se uloží.');
    }

    public function testCorrectionPendingRunStaysWritable(): void
    {
        $this->payrollRun('correction_pending', ['SYN-CR-CORR']);

        $failures = null;
        $this->quickInputs->save(
            $this->supplierId,
            self::PERIOD,
            [$this->row('SYN-CR-CORR', bonus: 250_000)],
            $this->userId,
            failures: $failures,
        );

        self::assertSame([], $failures ?? []);
        self::assertGreaterThan(0, $this->inputCount('SYN-CR-CORR'));
    }

    public function testActionTurnsAllClosedRowsIntoConflict(): void
    {
        $this->payrollRun('paid', ['SYN-CR-CLOSED']);

        $response = $this->quickAction->save(
            $this->request('PUT', '/api/payroll/quick-inputs')->withParsedBody([
                'period' => self::PERIOD,
                'rows' => [$this->row('SYN-CR-CLOSED', bonus: 100_000)],
            ]),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $payload = $this->json($response);
        self::assertSame('payroll_run_closed', $payload['error']['code'] ?? $payload['code'] ?? null, json_encode($payload) ?: '');
        self::assertSame(0, $this->inputCount('SYN-CR-CLOSED'));
    }

    public function testListMarksClosedRows(): void
    {
        $runId = $this->payrollRun('approved', ['SYN-CR-CLOSED']);

        $response = $this->quickAction->list(
            $this->request('GET', '/api/payroll/quick-inputs')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $byEmployment = [];
        foreach ((array) $this->json($response)['month']['items'] as $item) {
            self::assertIsArray($item);
            $byEmployment[(int) $item['employment_id']] = $item;
        }

        self::assertSame(
            ['run_id' => $runId, 'status' => 'approved', 'period' => self::PERIOD],
            $byEmployment[$this->people['SYN-CR-CLOSED']['employment']]['closed_run'],
        );
        self::assertNull($byEmployment[$this->people['SYN-CR-OUTSIDE']['employment']]['closed_run']);
    }

    /** Jen AKTUÁLNÍ revize běhu: vztah z nahrazené revize už v běhu není. */
    public function testOnlyCurrentRevisionCounts(): void
    {
        $runId = $this->payrollRun('closed', ['SYN-CR-CLOSED']);
        $this->revision($runId, 2, ['SYN-CR-OUTSIDE']);

        $closing = $this->guard->closingRuns($this->supplierId, self::PERIOD);

        self::assertArrayNotHasKey($this->people['SYN-CR-CLOSED']['employment'], $closing);
        self::assertArrayHasKey($this->people['SYN-CR-OUTSIDE']['employment'], $closing);
    }

    public function testManualInputIntoClosedRunIsRefused(): void
    {
        $this->payrollRun('posted', ['SYN-CR-CLOSED']);
        $this->quickInputs->month($this->supplierId, self::PERIOD);
        $componentId = (int) $this->db->pdo()->query(
            'SELECT id FROM payroll_component_definitions WHERE supplier_id = '
            . $this->supplierId . " AND code = 'ODMENA' ORDER BY valid_from DESC LIMIT 1",
        )->fetchColumn();
        self::assertGreaterThan(0, $componentId);

        $body = static fn (array $person): array => [
            'employee_id' => $person['employee'],
            'employment_id' => $person['employment'],
            'component_id' => $componentId,
            'period' => self::PERIOD,
            'amount_minor' => 100_000,
        ];
        $refused = $this->inputsAction->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody($body($this->people['SYN-CR-CLOSED'])),
            new Response(),
        );
        $allowed = $this->inputsAction->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody($body($this->people['SYN-CR-OUTSIDE'])),
            new Response(),
        );

        self::assertSame(409, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertStringContainsString('payroll_run_closed', (string) $refused->getBody());
        self::assertSame(201, $allowed->getStatusCode(), (string) $allowed->getBody());
    }

    public function testRecurringMaterializationSkipsClosedEmployment(): void
    {
        $this->payrollRun('closed', ['SYN-CR-CLOSED']);
        $this->quickInputs->month($this->supplierId, self::PERIOD);
        $pdo = $this->db->pdo();
        $componentId = (int) $pdo->query(
            'SELECT id FROM payroll_component_definitions WHERE supplier_id = '
            . $this->supplierId . " AND code = 'ODMENA' ORDER BY valid_from DESC LIMIT 1",
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, amount_minor, valid_from)
             VALUES (?, ?, ?, 100000, "2026-01-01")'
        )->execute([$this->supplierId, $this->people['SYN-CR-CLOSED']['employment'], $componentId]);

        $result = $this->recurring->materialize($this->supplierId, self::PERIOD, $this->userId);

        $blocked = array_column($result['manual_review'], 'reason', 'employment_id');
        self::assertArrayHasKey($this->people['SYN-CR-CLOSED']['employment'], $blocked);
        self::assertStringContainsString('uzavřené', $blocked[$this->people['SYN-CR-CLOSED']['employment']]);
        self::assertSame(0, $this->inputCount('SYN-CR-CLOSED'));
    }

    /**
     * @param list<string> $codes
     */
    private function payrollRun(string $status, array $codes): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                 (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, ?, 1, 1)'
        )->execute([$this->supplierId, self::PERIOD . '-01', '2026-10-15', $status]);
        $runId = (int) $pdo->lastInsertId();
        $this->revision($runId, 1, $codes);

        return $runId;
    }

    /** @param list<string> $codes */
    private function revision(int $runId, int $revisionNo, array $codes): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                  idempotency_key_hash)
             VALUES (?, ?, ?, 'approved', 'test', ?, '{}', ?, ?)"
        )->execute([
            $this->supplierId,
            $runId,
            $revisionNo,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE payroll_runs SET current_revision_no = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$revisionNo, $this->supplierId, $runId]);
        foreach ($codes as $code) {
            $pdo->prepare(
                "INSERT INTO payroll_run_employments
                     (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
                 VALUES (?, ?, ?, ?, '{}', ?)"
            )->execute([
                $this->supplierId,
                $revisionId,
                $this->people[$code]['employee'],
                $this->people[$code]['employment'],
                str_repeat('c', 64),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function row(string $code, int $bonus): array
    {
        $employmentId = $this->people[$code]['employment'];
        $statement = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $employmentId]);

        return [
            'employment_id' => $employmentId,
            'employment_row_version' => (int) $statement->fetchColumn(),
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => $bonus,
            'overtime_average_snapshot_id' => null,
            'overtime_average_snapshot_version' => null,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ];
    }

    private function inputCount(string $code): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status <> "cancelled"',
        );
        $statement->execute([$this->supplierId, $this->people[$code]['employment'], self::PERIOD . '-01']);

        return (int) $statement->fetchColumn();
    }

    private function seed(string $fullName, string $code): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $fullName]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 3000000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);
        $this->people[$code] = ['employee' => $employeeId, 'employment' => (int) $pdo->lastInsertId()];
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
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
