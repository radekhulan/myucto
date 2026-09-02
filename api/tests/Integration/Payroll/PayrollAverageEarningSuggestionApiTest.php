<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Návrh vstupů průměrného výdělku nad SKUTEČNÝMI tabulkami mzdových běhů.
 *
 * Rozhodovací logika má vlastní unit test; tady jde o to, že dotaz sedí na
 * schéma (`payroll_run_employments.period_start`, revize, stav běhu) a že
 * routa i skládání závislostí drží. Bez toho by zelený unit test tvrdil něco
 * o kódu, který v aplikaci nikdy nedostane data.
 */
#[Group('integration')]
final class PayrollAverageEarningSuggestionApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAbsenceAction $action;
    private int $userId;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

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
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_employments', 'payroll_runs', 'payroll_run_revisions',
            'payroll_run_employments', 'payroll_average_earning_snapshots',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);

        $employee = $pdo->prepare(
            "INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, 'Syntetický zaměstnanec', 'employee', 1)",
        );
        $employee->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $employment = $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, is_legacy_projection)
             VALUES (?, ?, 'SYNTH-HPP', 'employment', 'active', '2025-01-01', 0)",
        );
        $employment->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testSuggestionSumsClosedRunsOfTheDecisiveQuarter(): void
    {
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $periodStart) {
            $this->insertClosedRun($periodStart);
        }

        $suggestion = $this->suggest(2026, 2);

        self::assertSame([], $suggestion['blockers']);
        self::assertTrue($suggestion['ready']);
        self::assertSame('2026-01-01', $suggestion['decisive_from']);
        self::assertSame('2026-03-31', $suggestion['decisive_to']);
        // 3 × 45 000 Kč započitatelné mzdy ze základu průměrného výdělku.
        self::assertSame(13_500_000, $suggestion['gross_earnings_minor']);
        // 3 × 8 směn po 7,5 hodinách = 3 × 3 600 minut, 24 odpracovaných dnů.
        self::assertSame(10_800, $suggestion['worked_minutes']);
        self::assertSame(24, $suggestion['worked_days']);
        // § 358 ZP aplikace v datech nerozlišuje, poměrnou část zadává účetní.
        self::assertNull($suggestion['longer_period_allocated_minor']);
        self::assertCount(3, $suggestion['months']);
    }

    public function testMissingMonthSuppressesTheWholeProposal(): void
    {
        $this->insertClosedRun('2026-01-01');
        $this->insertClosedRun('2026-02-01');

        $suggestion = $this->suggest(2026, 2);

        self::assertFalse($suggestion['ready']);
        self::assertContains('run_missing', $suggestion['blockers']);
        self::assertNull($suggestion['gross_earnings_minor']);
        self::assertNull($suggestion['worked_minutes']);
        self::assertNull($suggestion['worked_days']);
    }

    public function testRunUnderCorrectionIsNotUsed(): void
    {
        $this->insertClosedRun('2026-01-01');
        $this->insertClosedRun('2026-02-01');
        $this->insertClosedRun('2026-03-01', runStatus: 'correction_pending');

        $suggestion = $this->suggest(2026, 2);

        self::assertFalse($suggestion['ready']);
        self::assertContains('run_not_approved', $suggestion['blockers']);
    }

    public function testUnknownEmploymentIsRejected(): void
    {
        $response = $this->action->averageSuggestion(
            $this->request()->withQueryParams([
                'employment_id' => $this->employmentId + 100_000,
                'applicable_year' => 2026,
                'applicable_quarter' => 2,
            ]),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    /** @return array<string,mixed> */
    private function suggest(int $year, int $quarter): array
    {
        $response = $this->action->averageSuggestion(
            $this->request()->withQueryParams([
                'employment_id' => $this->employmentId,
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['suggestion']);

        return $decoded['suggestion'];
    }

    /**
     * Uzavřený běh za jeden měsíc se schválenou revizí, zmrazeným pracovním
     * souhrnem a spočítaným výsledkem vztahu — tedy přesně to, z čeho návrh
     * čte.
     */
    private function insertClosedRun(
        string $periodStart,
        string $runStatus = 'closed',
        string $revisionStatus = 'approved',
    ): void {
        $pdo = $this->db->pdo();
        $run = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, ?, 1)',
        );
        $run->execute([
            $this->supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))->modify('+10 days')->format('Y-m-d'),
            $runStatus,
        ]);
        $runId = (int) $pdo->lastInsertId();

        $revision = $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", ?, "payroll-run-input.v2", ?, ?, ?, ?, ?, ?)',
        );
        $snapshotJson = (string) json_encode(['schema_version' => 'payroll-run-input.v2']);
        $resultJson = (string) json_encode(['schema_version' => 'payroll-run-result.v1']);
        $revision->execute([
            $this->supplierId,
            $runId,
            $revisionStatus,
            hash('sha256', 'manifest' . $periodStart),
            $snapshotJson,
            hash('sha256', $snapshotJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', 'idempotency' . $periodStart, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $entries = [];
        for ($day = 5; $day <= 12; $day++) {
            $date = sprintf('%s-%02d', substr($periodStart, 0, 7), $day);
            $entries[] = [
                'id' => $day,
                'category' => 'regular',
                // 06:00–14:00 UTC minus 30 minut přestávky = 450 minut.
                'starts_at_utc' => $date . ' 06:00:00',
                'ends_at_utc' => $date . ' 14:00:00',
                'timezone_name' => 'Europe/Prague',
                'break_minutes' => 30,
            ];
        }
        $sourceJson = CanonicalJson::encode([
            'schema_version' => 'jmhz-work-month.v2',
            'period_start' => $periodStart,
            'time_entries' => $entries,
        ]);
        $inputJson = (string) json_encode([
            'employment' => ['id' => $this->employmentId],
            'time_month' => [
                'id' => 1,
                'status' => 'approved',
                'revision_no' => 1,
                'row_version' => 2,
                'jmhz_work_summary' => [
                    'id' => 1,
                    'derivation_version' => 'jmhz-work-month.v2',
                    'source_snapshot_json' => $sourceJson,
                    'source_snapshot_sha256' => hash('sha256', $sourceJson),
                    'summary_sha256' => str_repeat('b', 64),
                    // 8 × 450 minut = 3 600 minut = 60 hodin = 60 000 milihodin.
                    'values' => ['evidence_days' => 28, 'worked_millihours' => 60_000],
                ],
            ],
        ]);
        $resultRowJson = (string) json_encode([
            'employment_id' => $this->employmentId,
            'totals' => ['average_earning_base_minor' => 4_500_000],
        ]);

        $result = $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "calculated")',
        );
        $result->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $this->employmentId,
            $inputJson,
            hash('sha256', $inputJson),
            $resultRowJson,
            hash('sha256', $resultRowJson),
        ]);
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/time/averages/suggestion')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
