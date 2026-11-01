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
            $container = Bootstrap::buildContainer();
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
        // Chybějící běh je důvod pro pravděpodobný výdělek (§ 355 ZP), takže
        // blokuje jeho ABSENCE v podmínkách vztahu; původní důvod zůstává
        // dohledatelný v `actual_blockers`.
        self::assertContains('probable_earning_not_recorded', $suggestion['blockers']);
        self::assertContains('run_missing', $suggestion['actual_blockers']);
        self::assertNull($suggestion['source_kind']);
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

    /**
     * Nový vztah bez rozhodného období a bez zadaného pravděpodobného výdělku:
     * § 355 odst. 2 ZP — hrubá mzda dosažená od počátku rozhodného období,
     * tedy červnová mzda uzavřeného běhu.
     */
    public function testNewEmploymentGetsProbableEarningFromWageAchievedSinceStart(): void
    {
        $this->startEmploymentOn('2026-06-01');
        $this->insertClosedRun('2026-06-01');

        $suggestion = $this->suggest(2026, 2);

        self::assertTrue($suggestion['ready']);
        self::assertSame([], $suggestion['blockers']);
        self::assertSame('probable', $suggestion['source_kind']);
        self::assertSame('achieved_wage', $suggestion['probable_source']);
        // 45 000 Kč za 60 odpracovaných hodin = 750 Kč/h.
        self::assertSame(75_000, $suggestion['probable_hourly_minor']);
        self::assertStringContainsString('§ 355 odst. 2', (string) $suggestion['probable_rationale']);
    }

    /**
     * Nový vztah bez uzavřeného běhu i bez sjednané měsíční mzdy (dohoda
     * v prvním měsíci): návrh je spodní mez, hodinová minimální mzda. Dřív se
     * zastavil na „pravděpodobný výdělek nezadán" a s ním celé hlášení (10345).
     */
    public function testNewEmploymentWithoutAnyWageGetsMinimumWageProposal(): void
    {
        $this->startEmploymentOn('2026-07-01');

        $suggestion = $this->suggest(2026, 3);

        self::assertTrue($suggestion['ready']);
        self::assertSame([], $suggestion['blockers']);
        self::assertSame('probable', $suggestion['source_kind']);
        self::assertSame('minimum_wage', $suggestion['probable_source']);
        // Minimální mzda 2026: 134,40 Kč za hodinu.
        self::assertSame(13_440, $suggestion['probable_hourly_minor']);
    }

    public function testBulkCreatesAndApprovesTheProposedAverage(): void
    {
        $this->startEmploymentOn('2026-06-01');
        $this->insertClosedRun('2026-06-01');

        $page = $this->call('averageCandidates', 'GET', ['year' => 2026, 'quarter' => 2]);
        self::assertSame(200, $page['status']);
        $items = array_values(array_filter(
            $page['body']['items'],
            fn (array $item): bool => $item['employment_id'] === $this->employmentId,
        ));
        self::assertCount(1, $items);
        self::assertTrue($items[0]['ready']);
        self::assertNull($items[0]['existing']);

        $request = [
            'year' => 2026,
            'quarter' => 2,
            'items' => [[
                'employment_id' => $this->employmentId,
                'input_version' => $items[0]['input_version'],
            ]],
        ];
        $created = $this->call('createAutomaticAverages', 'POST', $request);
        self::assertSame(201, $created['status']);
        $snapshot = $created['body']['averages'][0];
        self::assertSame('approved', $snapshot['status']);
        self::assertSame('supported', $snapshot['support_status']);
        self::assertSame('probable', $snapshot['source_kind']);
        self::assertSame(75_000, (int) $snapshot['average_hourly_minor']);

        // Založený průměr se nepřepisuje: druhé potvrzení téhož návrhu projít nesmí.
        self::assertSame(422, $this->call('createAutomaticAverages', 'POST', $request)['status']);
    }

    public function testBulkRefusesInputsChangedSinceThePreview(): void
    {
        $this->startEmploymentOn('2026-06-01');
        $this->insertClosedRun('2026-06-01');

        $response = $this->call('createAutomaticAverages', 'POST', [
            'year' => 2026,
            'quarter' => 2,
            'items' => [[
                'employment_id' => $this->employmentId,
                'input_version' => str_repeat('0', 64),
            ]],
        ]);

        self::assertSame(409, $response['status']);
    }

    /**
     * Průměr na čtvrtletí, kdy vztah vůbec netrval (vztah od 10. 5. 2026, průměr
     * pro 1. čtvrtletí), je omyl: JMHZ ho nepoužije a u správného vztahu chybí.
     */
    public function testAverageForQuarterBeforeEmploymentStartIsRejected(): void
    {
        $this->startEmploymentOn('2026-05-10');

        $response = $this->call('createAverage', 'POST', $this->averageBody(2026, 1));

        self::assertSame(422, $response['status']);
        self::assertSame('validation_failed', $response['body']['error']['code'] ?? $response['body']['error'] ?? null);
        self::assertStringContainsString('10. 5. 2026', json_encode($response['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('1. 1. 2026', json_encode($response['body'], JSON_UNESCAPED_UNICODE));
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_average_earning_snapshots WHERE employment_id = ?');
        $count->execute([$this->employmentId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testAverageForQuarterAfterEmploymentEndIsRejected(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2025-12-31" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        self::assertSame(422, $this->call('createAverage', 'POST', $this->averageBody(2026, 1))['status']);
        self::assertSame(201, $this->call('createAverage', 'POST', $this->averageBody(2025, 4))['status']);
    }

    public function testBulkSkipsEmploymentThatDoesNotExistInTheQuarter(): void
    {
        $this->startEmploymentOn('2026-05-10');

        $response = $this->call('createAutomaticAverages', 'POST', [
            'year' => 2026,
            'quarter' => 1,
            'items' => [[
                'employment_id' => $this->employmentId,
                'input_version' => str_repeat('0', 64),
            ]],
        ]);

        self::assertSame(201, $response['status']);
        self::assertSame([], $response['body']['averages']);
    }

    /**
     * Chybějící schválený průměr se musí ukázat PŘED během, ne až u zmrazení JMHZ.
     */
    public function testReadinessProbeReportsMissingAverageWithEmployeeName(): void
    {
        $probe = Bootstrap::buildContainer()->get(\MyInvoice\Service\Payroll\Run\PayrollRunJmhzReadinessProbe::class);
        $entry = static fn (int $employmentId, ?array $average): array => [
            'employment' => ['id' => $employmentId],
            'inputs' => [['amount_minor' => 100_000]],
            'average_earning' => $average,
        ];
        $snapshot = ['people' => [[
            'employee' => ['id' => $this->employeeId],
            'employments' => [$entry($this->employmentId, null)],
        ]]];

        $findings = $probe->inspect($this->supplierId, '2026-01-01', $snapshot);
        $found = array_values(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['code'] === 'jmhz_average_hourly_earning_missing',
        ));

        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]['severity']);
        self::assertStringContainsString('Syntetický zaměstnanec', $found[0]['message']);
        self::assertSame(
            '/payroll/absences?tab=averages&year=2026&quarter=1',
            $found[0]['remediation_path'],
        );

        // Existující průměr nález nevyvolá.
        $snapshot['people'][0]['employments'][0] = $entry($this->employmentId, ['id' => 1]);
        $withAverage = $probe->inspect($this->supplierId, '2026-01-01', $snapshot);
        self::assertSame([], array_filter(
            $withAverage,
            static fn (array $finding): bool => $finding['code'] === 'jmhz_average_hourly_earning_missing',
        ));
    }

    /**
     * Formulář `cinnostKS` (scénář 3, společník s. r. o.) pole 10345 nemá a zmrazení
     * ho nežádá. Předběžná kontrola se nesmí ptát na průměr, který hlášení nepotřebuje.
     */
    public function testReadinessProbeSkipsMissingAverageWhenScenarioHasNoSuchField(): void
    {
        $probe = Bootstrap::buildContainer()->get(\MyInvoice\Service\Payroll\Run\PayrollRunJmhzReadinessProbe::class);
        $snapshot = fn (string $activityCode, ?string $detailCode): array => ['people' => [[
            'employee' => ['id' => $this->employeeId],
            'employments' => [[
                'employment' => ['id' => $this->employmentId],
                'term' => ['activity_code' => $activityCode, 'jmhz_relationship_detail_code' => $detailCode],
                'inputs' => [['amount_minor' => 450_000]],
                'average_earning' => null,
            ]],
        ]]];
        $missing = static fn (array $findings): array => array_values(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['code'] === 'jmhz_average_hourly_earning_missing',
        ));

        self::assertSame([], $missing($probe->inspect($this->supplierId, '2026-10-01', $snapshot('S', '1'))));
        self::assertCount(1, $missing($probe->inspect($this->supplierId, '2026-10-01', $snapshot('A', null))));
    }

    /** @return array<string,mixed> */
    private function averageBody(int $year, int $quarter): array
    {
        $from = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1)))->modify('-3 months');

        return [
            'employment_id' => $this->employmentId,
            'applicable_year' => $year,
            'applicable_quarter' => $quarter,
            'decisive_from' => $from->format('Y-m-d'),
            'decisive_to' => $from->modify('+2 months')->modify('last day of this month')->format('Y-m-d'),
            'gross_earnings_minor' => 13_500_000,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 10_800,
            'worked_days' => 24,
        ];
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

    private function startEmploymentOn(string $startDate): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = ? WHERE supplier_id = ? AND id = ?',
        )->execute([$startDate, $this->supplierId, $this->employmentId]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{status:int,body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $params): array
    {
        $request = $this->request()->withMethod($httpMethod);
        $request = $httpMethod === 'GET'
            ? $request->withQueryParams($params)
            : $request->withParsedBody($params);
        $response = $this->action->{$method}($request, new Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
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
