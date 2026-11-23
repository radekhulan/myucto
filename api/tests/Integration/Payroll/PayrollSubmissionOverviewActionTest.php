<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollSubmissionOverviewAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollSubmissionOverviewActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSubmissionOverviewAction $action;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_obligations')) {
            $this->markTestSkipped('Migrace 1279 neproběhla.');
        }
        $this->action = $container->get(
            PayrollSubmissionOverviewAction::class,
        );
        $this->obligations = $container->get(PayrollObligationService::class);
        $this->submissions = $container->get(PayrollSubmissionService::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testListsOnlyTenantAndSelectedPeriodWithLatestSubmission(): void
    {
        $current = $this->obligation(
            $this->supplierId,
            'JMHZ',
            'office:current',
            'overview-current',
            '2026-08-01',
            '2026-08-31',
        );
        $this->submissions->prepare(
            $this->supplierId,
            $current['id'],
            'regular',
            'manual_upload',
            str_repeat('a', 64),
            'overview-submission',
        );
        $this->obligation(
            $this->supplierId,
            'JMHZ',
            'office:old',
            'overview-old',
            '2026-07-01',
            '2026-07-31',
        );
        $this->obligation(
            $this->otherSupplierId,
            'JMHZ',
            'office:other',
            'overview-other',
            '2026-08-01',
            '2026-08-31',
        );

        $response = ($this->action)(
            $this->request()->withQueryParams([
                'environment' => 'production',
                'period' => '2026-08',
            ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('2026-08', $body['period']);
        self::assertSame(1, $body['summary']['total']);
        self::assertSame('JMHZ', $body['items'][0]['agenda_code']);
        self::assertSame(
            'office:current',
            $body['items'][0]['subject_reference'],
        );
        self::assertSame(
            'draft',
            $body['items'][0]['latest_submission']['status'],
        );
        self::assertIsArray($body['items'][0]['deadline']);
        self::assertContains(
            $body['items'][0]['deadline']['phase'],
            [
                'not_open',
                'open',
                'due_soon',
                'due_today',
                'overdue',
            ],
        );
        self::assertIsInt($body['items'][0]['deadline']['days_to_due']);
        self::assertSame(
            1,
            array_sum($body['deadline_summary']),
        );
        self::assertArrayNotHasKey(
            'source_snapshot_hash',
            $body['items'][0]['latest_submission'],
        );
    }

    /**
     * Sloupec „Kanál" u hlášení, které ještě neodešlo, říká cestu, kterou
     * fronta podání opravdu použije (bez podpisového certifikátu datová
     * schránka), ne plán z doby založení povinnosti. Měsíční přehled nad ním
     * to počítá stejně, takže si oba řádky neodporují. Odpověď nese i závoru
     * přípravy hlášení JMHZ pro panel odeslání.
     */
    public function testUnsentJmhzNamesTheChannelTheQueueWillUse(): void
    {
        $this->obligations->register(
            $this->supplierId,
            'JMHZ25',
            'payroll_run',
            'payroll_run:1',
            '2026-08-01',
            '2026-08-31',
            'regular',
            'vrep_apep',
            'jmhz_preparation_snapshot',
            'jmhz_preparation:1',
            str_repeat('b', 64),
            '2026-09-01',
            '2026-09-21',
            'calendar_days',
            'overview-test-ruleset',
            str_repeat('c', 64),
            'overview-jmhz-channel',
            environment: 'production',
        );

        $body = $this->json(($this->action)(
            $this->request()->withQueryParams(['environment' => 'production', 'period' => '2026-08']),
            new Response(),
        ));

        self::assertSame('vrep_apep', $body['items'][0]['preferred_channel']);
        self::assertSame('isds', $body['items'][0]['planned_channel']);
        self::assertArrayHasKey('jmhz_preparation_gate', $body);
    }

    /**
     * Registrace, OZUSPOJ a dávky nemocenského mají předmět
     * `payroll_employment:{id}`. Přehled podání u nich dřív ukázal „—";
     * teď jméno osoby a id pro proklik na její kartu, stejně jako fronta.
     * Vztah cizí firmy se nesmí přeložit na jméno.
     */
    public function testEmploymentSubjectShowsPersonNameAndLink(): void
    {
        [$employeeId, $employmentId] = $this->employment($this->supplierId, 'Syntetický Zaměstnanec');
        [, $foreignEmploymentId] = $this->employment($this->otherSupplierId, 'Cizí Osoba');
        $this->obligation(
            $this->supplierId,
            'REGZEC',
            'payroll_employment:' . $employmentId,
            'overview-employment',
            '2026-08-01',
            '2026-08-31',
        );
        $this->obligation(
            $this->supplierId,
            'REGZEC',
            'payroll_employment:' . $foreignEmploymentId,
            'overview-foreign-employment',
            '2026-08-01',
            '2026-08-31',
        );

        $response = ($this->action)(
            $this->request()->withQueryParams([
                'environment' => 'production',
                'period' => '2026-08',
            ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $bySubject = [];
        foreach ($this->json($response)['items'] as $item) {
            $bySubject[$item['subject_reference']] = $item;
        }
        $own = $bySubject['payroll_employment:' . $employmentId];
        self::assertSame('Syntetický Zaměstnanec', $own['subject_label']);
        self::assertSame($employeeId, $own['subject_employee_id']);
        $foreign = $bySubject['payroll_employment:' . $foreignEmploymentId];
        self::assertNull($foreign['subject_label']);
        self::assertNull($foreign['subject_employee_id']);
    }

    /**
     * Měsíční přehled hlásil schválený únor u JMHZ „Nepřipraveno", souhrn
     * přehledu podání pod ním „Celkem 0". Povinnost bez řádku v evidenci se
     * teď započítá zvlášť podle fáze lhůty a jen do své skupiny agend.
     */
    public function testSummaryCountsUnpreparedDutyOfThePeriodInItsAgendaGroup(): void
    {
        $repository = $this->createStub(\MyInvoice\Repository\Payroll\PayrollMonthlyAgendaDutyRepository::class);
        $repository->method('approvedRunsForPeriod')->willReturn([[
            'run_id' => 3,
            'revision_id' => 7,
            'person_count' => 2,
            'insurer_codes' => ['111'],
            'insured_codes' => ['111'],
        ]]);
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $container->set(Connection::class, $this->db);
        $container->set(\MyInvoice\Repository\Payroll\PayrollMonthlyAgendaDutyRepository::class, $repository);
        $action = $container->get(PayrollSubmissionOverviewAction::class);

        $jmhz = $this->json($action(
            $this->request()->withQueryParams(['environment' => 'production', 'period' => '2026-08', 'agenda_group' => 'jmhz']),
            new Response(),
        ));
        self::assertSame(0, $jmhz['total']);
        self::assertSame(1, array_sum($jmhz['unprepared_deadline_summary']), json_encode($jmhz['unprepared_deadline_summary']));
        self::assertSame(0, array_sum($jmhz['deadline_summary']));

        $all = $this->json($action(
            $this->request()->withQueryParams(['environment' => 'production', 'period' => '2026-08']),
            new Response(),
        ));
        self::assertSame(2, array_sum($all['unprepared_deadline_summary']));

        // Připravené hlášení (řádek v evidenci) se jako nepřipravené nepočítá.
        $this->obligations->register(
            $this->supplierId,
            \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run',
            'payroll_run:3:office:1',
            '2026-08-01',
            '2026-08-31',
            'regular',
            'vrep_apep',
            'jmhz_preparation_snapshot',
            'jmhz_preparation:9',
            str_repeat('b', 64),
            '2026-09-01',
            '2026-09-21',
            'calendar_days',
            'overview-test-ruleset',
            str_repeat('c', 64),
            'overview-unprepared',
            environment: 'production',
        );
        $prepared = $this->json($action(
            $this->request()->withQueryParams(['environment' => 'production', 'period' => '2026-08', 'agenda_group' => 'jmhz']),
            new Response(),
        ));
        self::assertSame(1, $prepared['total']);
        self::assertSame(0, array_sum($prepared['unprepared_deadline_summary']));
    }

    public function testRejectsBearerAndInvalidFilters(): void
    {
        $bearer = ($this->action)(
            $this->request('bearer')->withQueryParams([
                'environment' => 'production',
                'period' => '2026-08',
            ]),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'],
        );

        $invalid = ($this->action)(
            $this->request()->withQueryParams([
                'environment' => 'staging',
                'period' => '08/2026',
            ]),
            new Response(),
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalid)['error']['code'],
        );
    }

    /** @return array{id:int} */
    private function obligation(
        int $supplierId,
        string $agendaCode,
        string $subjectReference,
        string $idempotencyKey,
        string $periodStart,
        string $periodEnd,
    ): array {
        return $this->obligations->register(
            $supplierId,
            $agendaCode,
            'office',
            $subjectReference,
            $periodStart,
            $periodEnd,
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:' . $idempotencyKey,
            str_repeat('b', 64),
            $periodEnd,
            (new \DateTimeImmutable($periodEnd))
                ->modify('+20 days')
                ->format('Y-m-d'),
            'calendar_days',
            'overview-test-ruleset',
            str_repeat('c', 64),
            $idempotencyKey,
            environment: 'production',
        );
    }

    /** @return array{int,int} [employee_id, employment_id] */
    private function employment(int $supplierId, string $name): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor)
             VALUES (?, ?, "SYN-1", "employment", "active", "2026-01-01", 4000000)'
        )->execute([$supplierId, $employeeId]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function request(
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                '/api/payroll/submissions/overview',
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
