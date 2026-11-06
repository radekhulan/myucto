<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Filtr mzdových vstupů a hromadné akce nad ním.
 *
 * Po importu docházky má měsíc stovky vstupů. Seznam uměl zúžit jen vztah,
 * „Schválit vše" počítalo koncepty ze zobrazené stránky a server schválil
 * jedním voláním nejvýš 500 konceptů — zbytek zůstal viset bez hlášky.
 */
#[Group('integration')]
final class PayrollInputsFilterBatchApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PERIOD_START = '2026-06-01';

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private PayrollInputRepository $repository;
    private int $supplierId;
    private int $userId;
    /** @var array{int,int} */
    private array $alfa;
    /** @var array{int,int} */
    private array $beta;
    private int $bonusA;
    private int $bonusB;
    private int $review;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentsAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        $repository = $container->get(PayrollInputRepository::class);
        if (!$db instanceof Connection
            || !$components instanceof PayrollComponentsAction
            || !$inputs instanceof PayrollInputsAction
            || !$repository instanceof PayrollInputRepository
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->components = $components;
        $this->inputs = $inputs;
        $this->repository = $repository;
        foreach (['payroll_inputs', 'payroll_input_imports', 'payroll_run_revisions'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->alfa = $this->employment('Syntetická Alfa', 'SYN-ALFA-1');
        $this->beta = $this->employment('Syntetický Beta', 'SYN-BETA-2');
        $this->bonusA = $this->createComponent('SYN_FLT_A', 'included');
        $this->bonusB = $this->createComponent('SYN_FLT_B', 'included');
        $this->review = $this->createComponent('SYN_FLT_REVIEW', 'manual_review');
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

    public function testEveryFilterParameterNarrowsTheListAndTheSummary(): void
    {
        $alfaA = $this->insertDrafts($this->alfa, $this->bonusA, 1, 10_000)[0];
        $alfaB = $this->insertDrafts($this->alfa, $this->bonusB, 1, 20_000)[0];
        $betaA = $this->insertDrafts($this->beta, $this->bonusA, 1, 40_000)[0];
        $importId = $this->createImport();
        $betaImported = $this->insertDrafts($this->beta, $this->bonusB, 1, 80_000, $importId)[0];
        $this->approve($alfaB);

        $all = $this->listInputs([]);
        self::assertSame(4, $all['total']);
        self::assertSame(
            ['total' => 4, 'draft_total' => 3, 'amount_total_minor' => 150_000, 'draft_amount_total_minor' => 130_000],
            $all['summary'],
        );

        self::assertSame([$alfaA, $alfaB], $this->ids($this->listInputs(['q' => 'Alfa'])));
        self::assertSame([$betaA, $betaImported], $this->ids($this->listInputs(['q' => 'syn-beta'])));
        self::assertSame([], $this->ids($this->listInputs(['q' => '100%'])), 'Zástupné znaky LIKE se neinterpretují.');
        self::assertSame([$alfaA, $betaA], $this->ids($this->listInputs(['component_id' => (string) $this->bonusA])));
        self::assertSame(
            [$alfaA, $alfaB, $betaA, $betaImported],
            $this->ids($this->listInputs(['component_id' => $this->bonusA . ',' . $this->bonusB])),
        );
        self::assertSame([$alfaB, $betaImported], $this->ids($this->listInputs(['component_code' => 'SYN_FLT_B'])));
        self::assertSame([$betaImported], $this->ids($this->listInputs(['source_kind' => 'import'])));
        self::assertSame([$alfaA, $alfaB, $betaA], $this->ids($this->listInputs(['source_kind' => 'manual'])));
        self::assertSame([$betaImported], $this->ids($this->listInputs(['import_id' => (string) $importId])));
        self::assertSame([$alfaB], $this->ids($this->listInputs(['status' => 'approved'])));
        self::assertSame([$alfaA, $betaA, $betaImported], $this->ids($this->listInputs(['status' => 'draft'])));
        self::assertSame([$alfaA, $alfaB], $this->ids($this->listInputs(['employee_id' => (string) $this->alfa[0]])));
        self::assertSame([$betaA, $betaImported], $this->ids($this->listInputs(['employment_id' => (string) $this->beta[1]])));

        // Kombinace: koncepty jedné složky u jednoho člověka.
        self::assertSame(
            [$betaA],
            $this->ids($this->listInputs(['q' => 'Beta', 'status' => 'draft', 'component_code' => 'SYN_FLT_A'])),
        );
    }

    public function testSummaryIsForTheWholeFilterNotForThePage(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 30, 1_000);
        $this->insertDrafts($this->beta, $this->bonusA, 5, 2_000);

        $page = $this->listInputs(['q' => 'Alfa', 'limit' => '10', 'offset' => '20']);
        self::assertCount(10, $page['inputs']);
        self::assertSame(30, $page['total']);
        self::assertSame(30, $page['summary']['draft_total']);
        self::assertSame(30_000, $page['summary']['amount_total_minor']);
    }

    public function testGroupingAndFacets(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000);
        $this->insertDrafts($this->alfa, $this->bonusB, 1, 5_000);
        $importId = $this->createImport();
        $this->insertDrafts($this->beta, $this->bonusA, 3, 2_000, $importId);

        $byEmployee = $this->listInputs(['group_by' => 'employee']);
        self::assertSame([], $byEmployee['inputs']);
        self::assertSame(2, $byEmployee['group_total']);
        self::assertSame(
            [
                ['key' => $this->alfa[0], 'label' => 'Syntetická Alfa', 'secondary' => 'SYN-ALFA-1', 'count' => 3, 'draft_count' => 3, 'amount_minor' => 7_000],
                ['key' => $this->beta[0], 'label' => 'Syntetický Beta', 'secondary' => 'SYN-BETA-2', 'count' => 3, 'draft_count' => 3, 'amount_minor' => 6_000],
            ],
            $byEmployee['groups'],
        );

        $byComponent = $this->listInputs(['group_by' => 'component', 'q' => 'Alfa']);
        self::assertSame(2, $byComponent['group_total']);
        self::assertSame([$this->bonusA, $this->bonusB], array_column($byComponent['groups'], 'key'));
        self::assertSame([2, 1], array_column($byComponent['groups'], 'count'));

        // Nabídka filtru se počítá za celý měsíc, ne za aktuální filtr.
        self::assertSame(
            [$this->bonusA, $this->bonusB],
            array_column($byComponent['facets']['components'], 'id'),
        );
        self::assertSame([$importId], array_column($byComponent['facets']['imports'], 'id'));
        self::assertSame([3], array_column($byComponent['facets']['imports'], 'count'));
    }

    /**
     * Karta zaměstnance čte vstupy jako historii vztahu, ne jako jeden měsíc.
     * Bez `period_to` musí zůstat výpis doslova měsíční — včetně nabídky filtru,
     * která se jinak počítá za celé zvolené období.
     */
    public function testPeriodRangeListsEveryMonthWhileASingleMonthStaysUnchanged(): void
    {
        $april = $this->insertDrafts($this->alfa, $this->bonusA, 1, 1_000, null, '2026-04-01')[0];
        $may = $this->insertDrafts($this->alfa, $this->bonusB, 1, 2_000, null, '2026-05-01')[0];
        $june = $this->insertDrafts($this->alfa, $this->bonusA, 1, 4_000)[0];
        $this->insertDrafts($this->beta, $this->bonusA, 1, 8_000, null, '2026-05-01');

        $single = $this->listInputs([
            'period' => '2026-05',
            'employment_id' => (string) $this->alfa[1],
        ]);
        self::assertSame([$may], $this->ids($single));
        self::assertNull($single['filter']['period_to']);
        self::assertSame(1, $single['summary']['total']);
        self::assertSame(2_000, $single['summary']['amount_total_minor']);
        self::assertSame(
            [$this->bonusB],
            array_column($single['facets']['components'], 'id'),
        );

        $range = $this->listInputs([
            'period' => '2026-04',
            'period_to' => '2026-06',
            'employment_id' => (string) $this->alfa[1],
        ]);
        self::assertSame([$april, $may, $june], $this->ids($range));
        self::assertSame('2026-06', $range['filter']['period_to']);
        self::assertSame(3, $range['total']);
        self::assertSame(3, $range['summary']['draft_total']);
        self::assertSame(7_000, $range['summary']['amount_total_minor']);
        self::assertSame(
            [$this->bonusA, $this->bonusB],
            array_column($range['facets']['components'], 'id'),
            'Nabídka filtru se počítá za celý rozsah, ne za jeho první měsíc.',
        );

        // Ostatní filtry se nad rozsahem chovají stejně jako nad měsícem.
        self::assertSame(
            [$april, $june],
            $this->ids($this->listInputs([
                'period' => '2026-04',
                'period_to' => '2026-06',
                'employment_id' => (string) $this->alfa[1],
                'component_code' => 'SYN_FLT_A',
            ])),
        );
    }

    /**
     * Nad rozsahem se historie čte odzadu — nejnovější období nahoře, stejně
     * jako u seskupení `period`. Dokud se řadilo jen podle jména, vztahu
     * a složky, osm měsíců téže složky se v seznamu promíchalo podle `input.id`
     * a řádek nešlo zařadit do měsíce. Jediný měsíc se řadí dál jako dřív.
     */
    public function testPeriodRangeListsTheNewestMonthFirst(): void
    {
        $april = $this->insertDrafts($this->alfa, $this->bonusA, 1, 1_000, null, '2026-04-01')[0];
        $may = $this->insertDrafts($this->alfa, $this->bonusB, 1, 2_000, null, '2026-05-01')[0];
        $juneA = $this->insertDrafts($this->alfa, $this->bonusA, 1, 4_000)[0];
        $juneB = $this->insertDrafts($this->alfa, $this->bonusB, 1, 5_000)[0];

        $range = $this->listInputs([
            'period' => '2026-04',
            'period_to' => '2026-06',
            'employment_id' => (string) $this->alfa[1],
        ]);
        self::assertSame([$juneA, $juneB, $may, $april], $this->idsInOrder($range));
        self::assertSame(
            ['2026-06-01', '2026-06-01', '2026-05-01', '2026-04-01'],
            array_map(
                static fn (array $row): string => (string) ($row['period_start'] ?? ''),
                PayrollTimeValue::rows((array) $range['inputs'], 'inputs'),
            ),
        );

        // Bez rozsahu zůstává řazení uvnitř měsíce podle složky, ne podle id.
        $single = $this->listInputs([
            'period' => '2026-06',
            'employment_id' => (string) $this->alfa[1],
        ]);
        self::assertSame([$juneA, $juneB], $this->idsInOrder($single));
    }

    public function testGroupingByPeriodReturnsOneRowPerMonthNewestFirst(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000, null, '2026-04-01');
        $this->insertDrafts($this->alfa, $this->bonusB, 1, 5_000, null, '2026-05-01');
        $this->insertDrafts($this->alfa, $this->bonusA, 3, 2_000);
        $this->insertDrafts($this->beta, $this->bonusA, 1, 9_000, null, '2026-05-01');

        $grouped = $this->listInputs([
            'period' => '2026-04',
            'period_to' => '2026-06',
            'group_by' => 'period',
            'employment_id' => (string) $this->alfa[1],
        ]);

        self::assertSame([], $grouped['inputs']);
        self::assertSame('period', $grouped['group_by']);
        self::assertSame(3, $grouped['group_total']);
        self::assertSame(
            [
                ['key' => 202606, 'label' => '2026-06', 'secondary' => null, 'count' => 3, 'draft_count' => 3, 'amount_minor' => 6_000],
                ['key' => 202605, 'label' => '2026-05', 'secondary' => null, 'count' => 1, 'draft_count' => 1, 'amount_minor' => 5_000],
                ['key' => 202604, 'label' => '2026-04', 'secondary' => null, 'count' => 2, 'draft_count' => 2, 'amount_minor' => 2_000],
            ],
            $grouped['groups'],
        );
    }

    public function testInvalidPeriodRangeIsRejected(): void
    {
        foreach ([
            ['period' => '2026-06', 'period_to' => '2026-05'],
            ['period' => '2026-06', 'period_to' => '2026-6'],
            ['period' => '2026-06', 'period_to' => 'letos'],
        ] as $query) {
            $response = $this->inputs->list(
                $this->request('GET', '/api/payroll/inputs')->withQueryParams($query),
                new Response(),
            );
            self::assertSame(422, $response->getStatusCode(), json_encode($query, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Hromadné akce zůstávají měsíční: přes rozsah by jedno kliknutí sáhlo
     * i na měsíce, které má uživatel na obrazovce jen jako historii.
     */
    public function testBatchActionsRefuseAPeriodRange(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000, null, '2026-05-01');
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000);
        $body = ['period' => '2026-05', 'filter' => ['period_to' => '2026-06']];

        $approve = $this->inputs->approveBatch(
            $this->request('POST', '/api/payroll/inputs/approve-batch')->withParsedBody($body),
            new Response(),
        );
        self::assertSame(422, $approve->getStatusCode(), (string) $approve->getBody());

        $cancel = $this->inputs->cancelBatch(
            $this->request('POST', '/api/payroll/inputs/cancel-batch')->withParsedBody($body),
            new Response(),
        );
        self::assertSame(422, $cancel->getStatusCode(), (string) $cancel->getBody());

        self::assertSame(2, $this->countStatus($this->alfa[1], 'draft', '2026-05-01'));
        self::assertSame(2, $this->countStatus($this->alfa[1], 'draft'));
    }

    public function testInvalidFilterValuesAreRejected(): void
    {
        foreach ([
            ['status' => 'cancelled'],
            ['status' => 'draft,unknown'],
            ['source_kind' => 'magic'],
            ['component_id' => 'abc'],
            ['import_id' => '-3'],
            ['group_by' => 'month'],
            ['q' => str_repeat('x', 101)],
        ] as $query) {
            $response = $this->inputs->list(
                $this->request('GET', '/api/payroll/inputs')
                    ->withQueryParams(['period' => self::PERIOD, ...$query]),
                new Response(),
            );
            self::assertSame(422, $response->getStatusCode(), json_encode($query, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Víc než 500 konceptů jedním kliknutím — a neschvalitelný koncept se
     * nepropašuje: schválení jde touž cestou jako jednotlivé.
     */
    public function testApproveByFilterApprovesMoreThanOneBatchAndKeepsApprovalValidation(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 520, 1_000);
        $reviewIds = $this->insertDrafts($this->alfa, $this->review, 3, 1_000);
        $betaIds = $this->insertDrafts($this->beta, $this->bonusA, 2, 1_000);

        $response = $this->inputs->approveBatch(
            $this->request('POST', '/api/payroll/inputs/approve-batch')->withParsedBody([
                'period' => self::PERIOD,
                'filter' => ['q' => 'Alfa'],
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $result = $this->json($response);

        self::assertCount(520, $result['approved']);
        self::assertSame($reviewIds, array_column($result['failed'], 'id'));
        self::assertSame(
            ['input_requires_manual_review'],
            array_values(array_unique(array_column($result['failed'], 'code'))),
        );
        self::assertTrue($result['complete']);
        self::assertSame(3, $result['remaining'], 'Zbývají právě neschvalitelné koncepty.');
        self::assertSame(3, $this->countStatus($this->alfa[1], 'draft'));
        self::assertSame(520, $this->countStatus($this->alfa[1], 'approved'));
        self::assertSame(2, $this->countStatus($this->beta[1], 'draft'), 'Mimo filtr se nic neschválí.');
        self::assertSame($betaIds, $this->ids($this->listInputs(['status' => 'draft', 'q' => 'Beta'])));

        // Schválené vstupy mají zmrazený snímek složky jako při jednotlivém schválení.
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs
              WHERE supplier_id = ? AND status = "approved"
                AND (component_snapshot_json IS NULL OR approved_by IS NULL)'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /** Časový rozpočet vyčerpaný po první dávce: kurzor pokračuje, nic se nepřeskočí. */
    public function testApproveByFilterContinuesFromCursorWhenTheTimeBudgetRunsOut(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 510, 1_000);
        $filter = new PayrollInputFilter(self::PERIOD_START);

        $first = $this->repository->approveByFilter($this->supplierId, $filter, $this->userId, 0, 0.0);
        self::assertCount(500, $first['approved']);
        self::assertFalse($first['complete']);
        self::assertSame(10, $first['remaining']);

        $second = $this->repository->approveByFilter(
            $this->supplierId,
            $filter,
            $this->userId,
            $first['next_after_id'],
            0.0,
        );
        self::assertCount(10, $second['approved']);
        self::assertTrue($second['complete']);
        self::assertSame(0, $second['remaining']);
        self::assertSame([], array_intersect($first['approved'], $second['approved']));
    }

    public function testLegacyPeriodBodyStillApprovesTheWholeMonth(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000);
        $this->insertDrafts($this->beta, $this->bonusB, 2, 1_000);

        $response = $this->inputs->approveBatch(
            $this->request('POST', '/api/payroll/inputs/approve-batch')
                ->withParsedBody(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(4, $this->json($response)['approved']);
        self::assertSame(0, $this->json($response)['remaining']);
    }

    public function testBatchApprovalRequiresApprovePermission(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000);
        $request = $this->request('POST', '/api/payroll/inputs/approve-batch')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                43,
                'Zadavatel vstupů',
                'staff',
                true,
                [
                    'payroll' => AccessLevel::WRITE->value,
                    'payroll.inputs.write' => AccessLevel::WRITE->value,
                ],
            ))
            ->withParsedBody(['period' => self::PERIOD, 'filter' => ['status' => 'draft']]);

        $response = $this->inputs->approveBatch($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(2, $this->countStatus($this->alfa[1], 'draft'));
    }

    public function testCancelByFilterCancelsOnlyMatchingDraftsAndIsAudited(): void
    {
        $keep = $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000);
        $drop = $this->insertDrafts($this->alfa, $this->bonusB, 3, 1_000);
        $approved = $this->insertDrafts($this->beta, $this->bonusB, 1, 1_000)[0];
        $this->approve($approved);

        $response = $this->inputs->cancelBatch(
            $this->request('POST', '/api/payroll/inputs/cancel-batch')->withParsedBody([
                'period' => self::PERIOD,
                'filter' => ['component_id' => [$this->bonusB]],
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $result = $this->json($response);
        self::assertSame($drop, $result['cancelled']);
        self::assertSame(0, $result['remaining']);
        self::assertSame($keep, $this->ids($this->listInputs(['status' => 'draft'])));
        self::assertSame([$approved], $this->ids($this->listInputs(['status' => 'approved'])));

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE action = "payroll.inputs.cancelled_batch" AND supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    /** Hromadné zrušení drží zábrany jednotlivého: vstup zmrazený v revizi běhu zůstane. */
    public function testCancelBatchKeepsTheMovementGuard(): void
    {
        [$frozen, $free] = $this->insertDrafts($this->alfa, $this->bonusA, 2, 1_000);
        $this->freezeInRunRevision($frozen);

        $response = $this->inputs->cancelBatch(
            $this->request('POST', '/api/payroll/inputs/cancel-batch')
                ->withParsedBody(['ids' => [$frozen, $free]]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $result = $this->json($response);
        self::assertSame([$free], $result['cancelled']);
        self::assertSame([$frozen], array_column($result['failed'], 'id'));
        self::assertSame('input_has_movement', $result['failed'][0]['code']);
    }

    /**
     * @param array{int,int} $person
     * @return list<int>
     */
    private function insertDrafts(
        array $person,
        int $componentId,
        int $count,
        int $amountMinor,
        ?int $importId = null,
        string $periodStart = self::PERIOD_START,
    ): array {
        $pdo = $this->db->pdo();
        $values = [];
        $params = [];
        for ($index = 0; $index < $count; ++$index) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $this->supplierId,
                $person[0],
                $person[1],
                $componentId,
                $periodStart,
                $amountMinor,
                $importId === null ? 'manual' : 'import',
                $importId === null ? null : 'syn-import-' . $componentId . '-' . $index,
                $importId,
                $this->userId,
            );
        }
        $before = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM payroll_inputs')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, external_id, import_id, created_by)
             VALUES ' . implode(', ', $values)
        )->execute($params);
        $stmt = $pdo->prepare(
            'SELECT id FROM payroll_inputs
              WHERE supplier_id = ? AND id > ? AND employment_id = ? AND component_id = ?
              ORDER BY id'
        );
        $stmt->execute([$this->supplierId, $before, $person[1], $componentId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function approve(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM payroll_inputs WHERE id = ?');
        $stmt->execute([$id]);
        $response = $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$id}/approve")
                ->withParsedBody(['row_version' => (int) $stmt->fetchColumn()]),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function createImport(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_input_imports
                (supplier_id, period_start, source_kind, source_name, content_hash,
                 status, row_count, accepted_count)
             VALUES (?, ?, "csv", "dochazka-syntetika.csv", UNHEX(SHA2(?, 256)), "accepted", 1, 1)'
        )->execute([$this->supplierId, self::PERIOD_START, 'syntetika-' . microtime(true)]);

        return (int) $pdo->lastInsertId();
    }

    private function countStatus(
        int $employmentId,
        string $status,
        string $periodStart = self::PERIOD_START,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ? AND status = ?'
        );
        $stmt->execute([$this->supplierId, $employmentId, $periodStart, $status]);

        return (int) $stmt->fetchColumn();
    }

    private function freezeInRunRevision(int $inputId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, "2026-07-10", "calculated", 1)'
        )->execute([$this->supplierId, self::PERIOD_START]);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [['employments' => [['inputs' => [['id' => $inputId]]]]]],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated", "payroll-run-input.v2",
                     SHA2(?, 256), ?, SHA2(?, 256), UNHEX(SHA2(?, 256)))'
        )->execute([
            $this->supplierId,
            $runId,
            'manifest-' . $inputId,
            $snapshot,
            $snapshot,
            'idempotency-' . $inputId,
        ]);
    }

    private function createComponent(string $code, string $taxTreatment): int
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody([
                'code' => $code,
                'name' => 'Syntetická složka ' . $code,
                'component_kind' => 'bonus',
                'value_kind' => 'monetary',
                'frequency_kind' => 'one_off',
                'tax_treatment' => $taxTreatment,
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

    /** @return array{int,int} */
    private function employment(string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listInputs(array $query): array
    {
        $response = $this->inputs->list(
            $this->request('GET', '/api/payroll/inputs')
                ->withQueryParams(['period' => self::PERIOD, 'limit' => '200', ...$query]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function idsInOrder(array $payload): array
    {
        return array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            PayrollTimeValue::rows((array) $payload['inputs'], 'inputs'),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function ids(array $payload): array
    {
        $ids = [];
        foreach (PayrollTimeValue::rows((array) $payload['inputs'], 'inputs') as $input) {
            $ids[] = PayrollTimeValue::int($input['id'] ?? null, 'input.id');
        }
        sort($ids);

        return $ids;
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
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
