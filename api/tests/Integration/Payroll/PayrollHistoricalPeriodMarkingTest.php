<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Období PŘED prvním mzdovým obdobím firmy se OZNAČÍ jako historické.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Převod mezd z předchozího programu naimportuje docházku i mzdové vstupy i za
 * měsíce, které MyÚčto vůbec nepočítá. Neschválený docházkový měsíc a koncept
 * vstupu za takové období pak visely v aplikaci jako rozdělaná práce, přestože
 * mzdový běh za ně nejde ani založit.
 *
 * ── Co se tím NEMĚNÍ ────────────────────────────────────────────────────────
 * Nic se nemaže ani neschovává. Řádky zůstávají ve výpisech i v databázi — jsou
 * podkladem pro srovnávací sestavu a pro počáteční stavy kumulací. Test proto
 * u každého historického období zároveň trvá na tom, že data jsou pořád vidět.
 */
#[Group('integration')]
final class PayrollHistoricalPeriodMarkingTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** První měsíc, který mzdy počítá MyÚčto. */
    private const START_PERIOD = '2026-06';

    /** Měsíc, který zpracoval předchozí program. */
    private const HISTORICAL_PERIOD = '2026-05';

    private Connection $db;
    private PayrollTimeAction $time;
    private PayrollInputsAction $inputs;
    private PayrollQuickInputsAction $quickInputs;
    private PayrollComponentsAction $components;
    private PayrollRunReadinessService $readiness;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $employmentId;
    private int $componentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            if ($container === null) {
                throw new \RuntimeException('DI kontejner není dostupný.');
            }
            $this->db = $container->get(Connection::class);
            $this->time = $container->get(PayrollTimeAction::class);
            $this->inputs = $container->get(PayrollInputsAction::class);
            $this->quickInputs = $container->get(PayrollQuickInputsAction::class);
            $this->components = $container->get(PayrollComponentsAction::class);
            $this->readiness = $container->get(PayrollRunReadinessService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_module_state',
            'payroll_employments',
            'payroll_time_months',
            'payroll_inputs',
        ] as $table) {
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
        $this->setStartPeriod(self::START_PERIOD);
        $this->seedEmployment();
        $this->componentId = $this->createComponent();

        // Převzatý měsíc i první počítaný měsíc vypadají v datech stejně:
        // neschválená docházka a koncept vstupu. Rozdíl dělá jedině hranice.
        foreach ([self::HISTORICAL_PERIOD, self::START_PERIOD] as $period) {
            $this->seedOpenTimeMonth($period);
            $this->seedDraftInput($period);
        }
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

    /**
     * Přehled docházky za převzatý měsíc hlásí historii — a pořád ho vypisuje.
     */
    public function testAttendanceOverviewMarksTheMonthBeforeTheFirstPayrollMonth(): void
    {
        $historical = $this->timeMonth(self::HISTORICAL_PERIOD);

        self::assertTrue(
            $historical['historical'],
            'Měsíc před začátkem vedení mezd musí být označený jako historický.',
        );
        self::assertSame(self::START_PERIOD, $historical['payroll_start_period']);
        self::assertNotSame([], $historical['items'], 'Označení není skrytí — řádky zůstávají.');
        self::assertSame(
            'open',
            (string) $historical['items'][0]['month']['status'],
            'Stav měsíce se nepřepisuje, jen se jinak čte.',
        );

        // Měsíc, kterým vedení mezd začíná, je normální práce, ne historie.
        $first = $this->timeMonth(self::START_PERIOD);
        self::assertFalse($first['historical']);
        self::assertSame(self::START_PERIOD, $first['payroll_start_period']);

        self::assertFalse($this->timeMonth('2026-07')['historical']);
    }

    /**
     * Historie jde přes víc měsíců naráz, takže se posílá hranice a výpis si
     * značku dělá řádek po řádku sám.
     */
    public function testAttendanceHistoryCarriesTheBoundary(): void
    {
        $response = $this->time->history(
            $this->request('GET', '/api/payroll/time/history')->withQueryParams([
                'employment_id' => (string) $this->employmentId,
                'from' => self::HISTORICAL_PERIOD,
                'to' => self::START_PERIOD,
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $history = $this->json($response);

        self::assertSame(self::START_PERIOD, $history['payroll_start_period']);
        self::assertSame(
            [self::START_PERIOD, self::HISTORICAL_PERIOD],
            array_map(
                static fn (array $item): string => (string) $item['period'],
                PayrollTimeValue::rows((array) $history['items'], 'items'),
            ),
            'Historický měsíc z výpisu nezmizel.',
        );
    }

    /** Koncepty vstupů za převzatý měsíc jsou historie, ne nedodělek. */
    public function testDraftInputsBeforeTheFirstPayrollMonthAreMarkedHistorical(): void
    {
        $historical = $this->listInputs(self::HISTORICAL_PERIOD);

        self::assertTrue($historical['historical']);
        self::assertSame(self::START_PERIOD, $historical['payroll_start_period']);
        self::assertSame(
            1,
            (int) $historical['summary']['draft_total'],
            'Koncept se nemaže ani nepřepočítává — jen se jinak popisuje.',
        );

        self::assertFalse($this->listInputs(self::START_PERIOD)['historical']);

        /*
         * Rozsah, který sahá i do počítaných měsíců, historie NENÍ. Kdyby se
         * rozhodovalo podle prvního měsíce rozsahu, schoval by se pod hlavičku
         * historie i zbytek roku, který MyÚčto počítá.
         */
        self::assertFalse(
            $this->listInputs(self::HISTORICAL_PERIOD, self::START_PERIOD)['historical'],
        );
    }

    /** Rychlé zadání stojí na témže měsíci, takže hranici musí znát taky. */
    public function testQuickInputsMonthIsMarkedHistorical(): void
    {
        self::assertTrue($this->quickInputsMonth(self::HISTORICAL_PERIOD)['historical']);
        self::assertSame(
            self::START_PERIOD,
            $this->quickInputsMonth(self::HISTORICAL_PERIOD)['payroll_start_period'],
        );
        self::assertFalse($this->quickInputsMonth(self::START_PERIOD)['historical']);
    }

    /**
     * Bez nastaveného prvního mzdového období není podle čeho historii poznat,
     * takže se neoznačuje nic — modul se tak choval odjakživa.
     */
    public function testWithoutAFirstPayrollMonthNothingIsMarked(): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM payroll_module_state WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        foreach ([self::HISTORICAL_PERIOD, self::START_PERIOD] as $period) {
            $month = $this->timeMonth($period);
            self::assertFalse($month['historical'], $period);
            self::assertNull($month['payroll_start_period'], $period);

            $inputs = $this->listInputs($period);
            self::assertFalse($inputs['historical'], $period);
            self::assertNull($inputs['payroll_start_period'], $period);

            self::assertFalse($this->quickInputsMonth($period)['historical'], $period);
        }
    }

    /**
     * Kontrola před během za historický měsíc končí jediným nálezem.
     *
     * Pojistka k přesunu pravidla do `PayrollHistoricalPeriodService`: kdyby se
     * kontrola s hranicí rozešla, vypsala by za převzatý měsíc zase dvě stě
     * nálezů o chybějící docházce a vstupech.
     */
    public function testRunReadinessStillRefusesTheHistoricalMonthWithASingleFinding(): void
    {
        $historical = $this->readiness->inspect(
            $this->supplierId,
            self::HISTORICAL_PERIOD . '-01',
            self::HISTORICAL_PERIOD . '-15',
        );
        self::assertSame(
            ['period_before_module_start'],
            array_column($historical['findings'], 'code'),
        );

        $first = $this->readiness->inspect(
            $this->supplierId,
            self::START_PERIOD . '-01',
            self::START_PERIOD . '-15',
        );
        self::assertNotContains(
            'period_before_module_start',
            array_column($first['findings'], 'code'),
            'Měsíc, kterým vedení mezd začíná, se kontroluje normálně.',
        );
    }

    /** @return array<string,mixed> */
    private function timeMonth(string $period): array
    {
        $response = $this->time->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => $period, 'limit' => '50']),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function listInputs(string $period, ?string $periodTo = null): array
    {
        $response = $this->inputs->list(
            $this->request('GET', '/api/payroll/inputs')->withQueryParams([
                'period' => $period,
                'limit' => '50',
                ...($periodTo === null ? [] : ['period_to' => $periodTo]),
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function quickInputsMonth(string $period): array
    {
        $response = $this->quickInputs->list(
            $this->request('GET', '/api/payroll/quick-inputs')
                ->withQueryParams(['period' => $period]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function setStartPeriod(string $period): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status),
                                     start_period = VALUES(start_period)'
        )->execute([$this->supplierId, $period . '-01', $this->userId]);
    }

    private function seedEmployment(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický Historik", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "SYN-HIST-PAM15", "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    private function seedOpenTimeMonth(string $period): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, last_changed_by)
             VALUES (?, ?, ?, "open", ?)'
        )->execute([$this->supplierId, $this->employmentId, $period . '-01', $this->userId]);
    }

    private function seedDraftInput(string $period): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, created_by)
             VALUES (?, ?, ?, ?, ?, 100000, "manual", ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $this->componentId,
            $period . '-01',
            $this->userId,
        ]);
    }

    private function createComponent(): int
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody([
                'code' => 'SYN_PAM15_BONUS',
                'name' => 'Syntetická složka PAM-15',
                'component_kind' => 'bonus',
                'value_kind' => 'monetary',
                'frequency_kind' => 'one_off',
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'included',
                'jmhz_treatment' => 'included',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $component = PayrollTimeValue::row($this->json($response)['component'] ?? null, 'component');

        return PayrollTimeValue::int($component['id'] ?? null, 'component.id');
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
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
