<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Action\Nonprofit\FundingWorkAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkException;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Pracovní evidence čerpání (#403), řádky požadavků R1–R15 (R3 souběh je ve FundingWorkConcurrencyTest,
 * R11 a R13 ve vitest FundingWorkPanel.spec.ts).
 */
#[Group('integration')]
final class FundingWorkTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $c;
    private PDO $pdo;
    private FundingWorkService $work;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $provider;
    private int $sport;
    private int $culture;
    private array $catalog;
    private array $accounts = [];

    protected function setUp(): void
    {
        $this->c = Bootstrap::buildContainer();
        $this->pdo = $this->c->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        [$this->period, $this->catalog, $this->accounts, $this->provider, $this->sport, $this->culture] = $this->company($this->supplier);
        $this->work = $this->c->get(FundingWorkService::class);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    /** R1: jeden náklad nesou dva zdroje; součet nesmí přesáhnout částku řádku. */
    public function testCostLineSplitBetweenTwoSourcesWithinLineCapacity(): void
    {
        $cost = $this->post('518', '800.00');
        $a = $this->source(['title' => 'Synthetic city grant', 'budget_cents' => 100000]);
        $b = $this->source(['title' => 'Synthetic region grant', 'budget_cents' => 100000]);
        $c = $this->source(['title' => 'Synthetic third grant', 'budget_cents' => 100000]);
        $this->work->allocate($this->supplier, $this->period, $a, [['line_id' => $cost, 'amount_cents' => 50000]], $this->actor);
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $a, [['line_id' => $cost, 'amount_cents' => 100]], $this->actor), 'funding_work_line_already_allocated');
        $detail = $this->work->allocate($this->supplier, $this->period, $b, [['line_id' => $cost, 'amount_cents' => 30000, 'note' => 'Synthetic share']], $this->actor);
        self::assertSame(30000, $detail['totals']['documented_cents']);
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $c, [['line_id' => $cost, 'amount_cents' => 1]], $this->actor), 'funding_work_capacity_exceeded', ['capacity_cents' => 0]);
        $split = $this->work->costLineAllocations($this->supplier, $this->period, $cost);
        self::assertSame(80000, $split['allocated_cents']);
        self::assertSame(0, $split['capacity_cents']);
        self::assertSame([$a, $b], array_column($split['allocations'], 'source_id'));
        self::assertSame([], $this->work->costCandidates($this->supplier, $this->period, $c, '', 1)['rows'], 'Plně rozdělený řádek se už nenabízí.');
        // Snížení částky uvolní kapacitu pro třetí zdroj.
        $allocation = $this->work->detail($this->supplier, $this->period, $a)['allocations'][0];
        $this->work->updateAllocation($this->supplier, $this->period, $allocation['id'], 40000, null, $allocation['row_version'], $this->actor);
        $offer = $this->work->costCandidates($this->supplier, $this->period, $c, '', 1)['rows'];
        self::assertSame([[$cost, 10000]], array_map(static fn($r) => [$r['id'], $r['capacity_cents']], $offer));
    }

    /** R2: překročení rozpočtu zdroje vrátí 422 se zůstatkem; nic se nezapíše (vše nebo nic). */
    public function testBudgetOverrunIsRejectedWithRemainingAmountAndNothingWritten(): void
    {
        $first = $this->post('518', '1000.00');
        $second = $this->post('518', '400.00');
        $source = $this->source(['budget_cents' => 70000]);
        $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $first, 'amount_cents' => 40000]], $this->actor);
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $second, 'amount_cents' => 30001]], $this->actor), 'funding_work_budget_exceeded', ['remaining_cents' => 30000]);
        $third = $this->post('518', '10.00');
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $third, 'amount_cents' => 1000], ['line_id' => $second, 'amount_cents' => 29001]], $this->actor), 'funding_work_budget_exceeded');
        $detail = $this->work->detail($this->supplier, $this->period, $source);
        self::assertCount(1, $detail['allocations'], 'Dávka přes rozpočet nezapíše ani svou první položku.');
        self::assertSame(30000, $detail['totals']['remaining_cents']);
        $allocation = $detail['allocations'][0];
        $this->expectError(fn() => $this->work->updateAllocation($this->supplier, $this->period, $allocation['id'], 70001, null, $allocation['row_version'], $this->actor), 'funding_work_budget_exceeded');
        $this->expectError(fn() => $this->work->update($this->supplier, $this->period, $source, $this->input(['budget_cents' => 39999]), $detail['source']['row_version'], '', $this->actor), 'funding_work_budget_below_allocated');
    }

    /** R4: storno zápisu nákladu i výnosu vazbu nesmaže, ukáže ji ke kontrole a navázat znovu nejde. */
    public function testReversedEntryIsShownForReviewAndCannotBeRelinked(): void
    {
        $income = $this->post('682', '1000.00');
        $cost = $this->post('518', '300.00');
        $source = $this->source(['income_line_ids' => [$income]]);
        $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $cost, 'amount_cents' => 30000]], $this->actor);
        $this->reverse($cost);
        $this->reverse($income);
        $detail = $this->work->detail($this->supplier, $this->period, $source);
        self::assertSame('reversed', $detail['allocations'][0]['state']);
        self::assertSame('reversed', $detail['incomes'][0]['state']);
        self::assertSame(0, $detail['totals']['documented_cents']);
        self::assertSame(0, $detail['totals']['received_cents']);
        self::assertSame(30000, $detail['totals']['review_cents']);
        self::assertSame(2, $detail['totals']['review_count']);
        $allocation = $detail['allocations'][0];
        $this->expectError(fn() => $this->work->relinkAllocation($this->supplier, $this->period, $allocation['id'], $cost, null, $allocation['row_version'], $this->actor), 'funding_work_line_reversed');
        $this->expectError(fn() => $this->work->removeAllocation($this->supplier, $this->period, $allocation['id'], $allocation['row_version'], ' ', $this->actor), 'funding_work_invalid');
        $after = $this->work->removeAllocation($this->supplier, $this->period, $allocation['id'], $allocation['row_version'], 'Synthetic reversal', $this->actor);
        self::assertSame([], $after['allocations']);
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $cost, 'amount_cents' => 100]], $this->actor), 'funding_work_line_reversed');
    }

    /** R5: přeúčtování řádek nahradí; vazba je „odstraněno“ a „Navázat znovu“ ji převede na nový řádek. */
    public function testRepostedLineIsDetachedAndCanBeRelinkedToSameEntryAndAccount(): void
    {
        $income = $this->post('682', '1000.00');
        $cost = $this->post('518', '300.00');
        $source = $this->source(['income_line_ids' => [$income]]);
        $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $cost, 'amount_cents' => 25000]], $this->actor);
        [$newCost, $newOther] = $this->repost($cost, '320.00');
        [$newIncome] = $this->repost($income, '1000.00');
        $detail = $this->work->detail($this->supplier, $this->period, $source);
        $allocation = $detail['allocations'][0];
        self::assertSame('removed', $allocation['state']);
        self::assertNull($allocation['line_id']);
        self::assertSame([$newCost], array_column($allocation['relink_candidates'], 'id'), 'Nabídka obsahuje jen řádek téhož zápisu se stejným účtem.');
        self::assertSame('removed', $detail['incomes'][0]['state']);
        $this->expectError(fn() => $this->work->relinkAllocation($this->supplier, $this->period, $allocation['id'], $newOther, null, $allocation['row_version'], $this->actor), 'funding_work_relink_invalid');
        $foreign = $this->post('518', '50.00');
        $this->expectError(fn() => $this->work->relinkAllocation($this->supplier, $this->period, $allocation['id'], $foreign, null, $allocation['row_version'], $this->actor), 'funding_work_relink_invalid');
        $this->expectError(fn() => $this->work->relinkAllocation($this->supplier, $this->period, $allocation['id'], $newCost, null, $allocation['row_version'] + 1, $this->actor), 'funding_work_version_conflict');
        $detail = $this->work->relinkAllocation($this->supplier, $this->period, $allocation['id'], $newCost, null, $allocation['row_version'], $this->actor);
        self::assertSame('ok', $detail['allocations'][0]['state']);
        self::assertSame(32000, $detail['allocations'][0]['line_amount_cents']);
        $incomeRow = $detail['incomes'][0];
        $detail = $this->work->relinkIncome($this->supplier, $this->period, $incomeRow['id'], $newIncome, $incomeRow['row_version'], $this->actor);
        self::assertSame('ok', $detail['incomes'][0]['state']);
        self::assertSame(100000, $detail['totals']['received_cents']);
        // Změna částky řádku bez nového id: stav „změněno“, potvrzení = navázat na týž řádek.
        $this->pdo->prepare('UPDATE journal_entry_lines SET amount = 200.00 WHERE id = ?')->execute([$newCost]);
        $changed = $this->work->detail($this->supplier, $this->period, $source)['allocations'][0];
        self::assertSame('changed', $changed['state']);
        $this->expectError(fn() => $this->work->relinkAllocation($this->supplier, $this->period, $changed['id'], $newCost, null, $changed['row_version'], $this->actor), 'funding_work_capacity_exceeded', ['capacity_cents' => 20000]);
        $fixed = $this->work->relinkAllocation($this->supplier, $this->period, $changed['id'], $newCost, 20000, $changed['row_version'], $this->actor);
        self::assertSame('ok', $fixed['allocations'][0]['state']);
    }

    /** R6 + R8 (firma): cizí řádek, zdroj ani poskytovatel neprozradí existenci a nic nezapíší. */
    public function testForeignCompanyLinesSourcesAndProvidersAreNotFound(): void
    {
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $other = $this->createIsolatedSupplier($this->pdo, $seed);
        [$otherPeriod, , $otherAccounts, $otherProvider] = $this->company($other);
        $saved = [$this->supplier, $this->period, $this->accounts];
        [$this->supplier, $this->period, $this->accounts] = [$other, $otherPeriod, $otherAccounts];
        $foreignCost = $this->post('518', '100.00');
        $foreignIncome = $this->post('682', '100.00');
        [$this->supplier, $this->period, $this->accounts] = $saved;
        $foreignSource = $this->work->create($other, $otherPeriod, $this->input(['provider_id' => $otherProvider, 'activity_kind_ids' => []]), $this->actor)['source']['id'];
        $source = $this->source();
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $foreignCost, 'amount_cents' => 100]], $this->actor), 'funding_work_line_not_found');
        $this->expectError(fn() => $this->work->addIncomes($this->supplier, $this->period, $source, [$foreignIncome], $this->actor), 'funding_work_line_not_found');
        $this->expectError(fn() => $this->work->detail($this->supplier, $this->period, $foreignSource), 'funding_work_not_found');
        $this->expectError(fn() => $this->work->detail($this->supplier, $otherPeriod, $foreignSource), 'funding_work_period_not_found');
        $this->expectError(fn() => $this->work->costLineAllocations($this->supplier, $this->period, $foreignCost), 'funding_work_line_not_found');
        $this->expectError(fn() => $this->work->create($this->supplier, $this->period, $this->input(['provider_id' => $otherProvider]), $this->actor), 'funding_work_invalid');
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_funding_work_allocations WHERE supplier_id = ?');
        $q->execute([$this->supplier]);
        self::assertSame(0, (int) $q->fetchColumn());
    }

    /** R7 + R10: potvrzené zdroje i vstup J6 jsou s pracovní evidencí i bez ní stejné; souběh se jen ukáže. */
    public function testConfirmedSourcesAndJ6InputStayIdentical(): void
    {
        $income = $this->post('682', '1000.00', '2026-06-01', $this->sport);
        $expense = $this->post('518', '800.00');
        $tax = $this->c->get(NonprofitTaxAssessmentService::class);
        $funding = $this->c->get(FundingService::class);
        $snapshot = $tax->read($this->supplier, $this->period)['snapshot'];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Synthetic statutes'], 'activities' => [$this->sport => ['evidence' => 'Synthetic sports activity']],
            'decisions' => ['line:' . $income => ['title' => 'gift', 'evidence' => 'Synthetic gift agreement', 'facts' => ['claim_exemption' => true, 'qualifying_purpose' => true, 'election_evidence' => 'Synthetic irrevocable gift election']],
                'line:' . $expense => ['title' => 'cost', 'evidence' => 'Synthetic expense', 'legal_basis' => 'zdp_24_1', 'deductible_cents' => 80000]], 'funding' => []];
        $tax->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Synthetic review', 'initial-tax', $this->actor);
        $policy = ['kind' => 'gift', 'title' => 'Synthetic sporting gift', 'purpose' => 'Sports for children', 'evidence' => 'Synthetic agreement', 'provider_id' => $this->provider, 'budget_cents' => 70000,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'activity_kind_ids' => [$this->sport], 'account_prefixes' => ['518'], 'source_anchor' => 'line:' . $income, 'source_members' => ['line:' . $income]];
        $preview = $funding->preview($this->supplier, $this->period, 'gift-sport', $policy);
        $funding->confirm($this->supplier, $this->period, 'gift-sport', $policy, $preview['input_hash'], $preview['expected_revision'], 'Synthetic purpose', 'source', $this->actor);
        $beforeFunding = $funding->read($this->supplier);
        $beforeTax = $tax->read($this->supplier, $this->period);
        $choices = $this->work->choices($this->supplier, $this->period, '');
        self::assertSame([['key' => 'gift-sport', 'label' => 'Synthetic sporting gift']], $choices['confirmed_sources']);
        $source = $this->source(['income_line_ids' => [$income], 'legacy_source_key' => 'gift-sport', 'budget_cents' => 100000]);
        $detail = $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $expense, 'amount_cents' => 80000]], $this->actor);
        self::assertSame('gift-sport', $detail['incomes'][0]['confirmed_source_key']);
        self::assertSame($beforeFunding, $funding->read($this->supplier), 'Potvrzené zdroje se pracovní evidencí nemění.');
        $afterTax = $tax->read($this->supplier, $this->period);
        self::assertSame($beforeTax['snapshot']['input_hash'], $afterTax['snapshot']['input_hash'], 'Vstup J6 nezávisí na pracovních vazbách.');
        self::assertSame($beforeTax['latest'], $afterTax['latest']);
        $this->expectError(fn() => $this->work->create($this->supplier, $this->period, $this->input(['legacy_source_key' => 'missing-source']), $this->actor), 'funding_work_invalid');
    }

    /** R8: čtení stačí accounting READ, zápis vyžaduje accounting WRITE z relace; routy mapují stejná práva. */
    public function testPermissionsReadOnlyRoleAndBearerCannotWrite(): void
    {
        $action = $this->c->get(FundingWorkAction::class);
        $reader = new EffectiveRole(91, 'Synthetic reader', 'staff', true, ['accounting' => 1]);
        $writer = new EffectiveRole(92, 'Synthetic writer', 'staff', true, ['accounting' => 2]);
        $body = $this->input();
        $response = $action->create($this->request('POST', $body, $reader), (new ResponseFactory())->createResponse(), ['id' => (string) $this->period]);
        self::assertSame(403, $response->getStatusCode());
        $response = $action->create($this->request('POST', $body, $writer, 'bearer'), (new ResponseFactory())->createResponse(), ['id' => (string) $this->period]);
        self::assertSame(403, $response->getStatusCode(), 'Zápis jen z přihlášené relace.');
        $response = $action->create($this->request('POST', $body, $writer), (new ResponseFactory())->createResponse(), ['id' => (string) $this->period]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $response = $action->overview($this->request('GET', null, $reader), (new ResponseFactory())->createResponse(), ['id' => (string) $this->period]);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, json_decode((string) $response->getBody(), true)['sources']);
        $response = $action->overview($this->request('GET', null, new EffectiveRole(93, 'Synthetic none', 'staff', true, [])), (new ResponseFactory())->createResponse(), ['id' => (string) $this->period]);
        self::assertSame(403, $response->getStatusCode());
        $map = new RoutePermissionMap();
        $base = '/api/accounting/periods/' . $this->period . '/funding-work';
        foreach (['GET' => [$base, $base . '/sources/1', $base . '/sources/1/cost-candidates'], 'POST' => [$base . '/sources', $base . '/allocations/1/remove', $base . '/incomes/1/relink'], 'PUT' => [$base . '/allocations/1']] as $method => $paths) {
            foreach ($paths as $path) {
                $rule = $map->match($method, $path);
                self::assertSame('accounting', $rule?->key, $method . ' ' . $path);
                self::assertSame($method === 'GET' ? 1 : 2, $rule->minimum->value, $method . ' ' . $path);
            }
        }
    }

    /** R9: v uzavřeném období evidenci nelze měnit; čtení zůstává. Zámek podaného DPH ji neblokuje. */
    public function testClosedPeriodBlocksWritesButNotReads(): void
    {
        $cost = $this->post('518', '100.00');
        $source = $this->source();
        $this->pdo->prepare("INSERT INTO accounting_supplier_settings(supplier_id, locked_until) VALUES (?, '2026-12-31') ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)")->execute([$this->supplier]);
        $detail = $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $cost, 'amount_cents' => 5000]], $this->actor);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $allocation = $detail['allocations'][0];
        $this->expectError(fn() => $this->work->updateAllocation($this->supplier, $this->period, $allocation['id'], 4000, null, $allocation['row_version'], $this->actor), 'funding_work_period_closed');
        $this->expectError(fn() => $this->work->create($this->supplier, $this->period, $this->input(), $this->actor), 'funding_work_period_closed');
        $this->expectError(fn() => $this->work->removeAllocation($this->supplier, $this->period, $allocation['id'], $allocation['row_version'], 'Synthetic', $this->actor), 'funding_work_period_closed');
        $read = $this->work->detail($this->supplier, $this->period, $source);
        self::assertFalse($read['editable']);
        self::assertSame(5000, $read['totals']['documented_cents']);
    }

    /** R12: každá změna má záznam v neměnné historii a v auditní stopě. */
    public function testHistoryIsAppendOnlyAndAudited(): void
    {
        $cost = $this->post('518', '100.00');
        $source = $this->source();
        $detail = $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $cost, 'amount_cents' => 5000]], $this->actor);
        $allocation = $detail['allocations'][0];
        $detail = $this->work->updateAllocation($this->supplier, $this->period, $allocation['id'], 4000, 'Synthetic note', $allocation['row_version'], $this->actor);
        $this->expectError(fn() => $this->work->updateAllocation($this->supplier, $this->period, $allocation['id'], 3000, null, $allocation['row_version'], $this->actor), 'funding_work_version_conflict');
        $allocation = $detail['allocations'][0];
        $detail = $this->work->removeAllocation($this->supplier, $this->period, $allocation['id'], $allocation['row_version'], 'Synthetic correction', $this->actor);
        $actions = array_column($detail['history'], 'action');
        self::assertSame(['allocation_removed', 'allocation_updated', 'allocation_created', 'source_created'], $actions);
        self::assertSame('Synthetic correction', $detail['history'][0]['reason']);
        self::assertSame(['amount_cents' => 5000, 'note' => null], $detail['history'][1]['before']);
        $q = $this->pdo->prepare("SELECT action FROM activity_log WHERE supplier_id = ? AND entity_type = 'nonprofit_funding_work_source' AND entity_id = ? ORDER BY id");
        $q->execute([$this->supplier, $source]);
        self::assertSame(['nonprofit_funding_work.source_created', 'nonprofit_funding_work.allocation_created', 'nonprofit_funding_work.allocation_updated', 'nonprofit_funding_work.allocation_removed'], $q->fetchAll(PDO::FETCH_COLUMN));
        foreach (['UPDATE nonprofit_funding_work_history SET reason = NULL WHERE source_id = ?', 'DELETE FROM nonprofit_funding_work_history WHERE source_id = ?'] as $sql) {
            $this->pdo->exec('SAVEPOINT history_guard');
            try {
                $this->pdo->prepare($sql)->execute([$source]);
                self::fail('Historie musí být neměnná: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('nonprofit_funding_work_history_immutable', $e->getMessage());
            } finally {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT history_guard');
            }
        }
    }

    /** R14: výnos i náklad musí být ve stejném účetním období jako zdroj. */
    public function testLinesFromOtherPeriodAreRejected(): void
    {
        $next = $this->c->get(AccountingPeriodRepository::class)->create($this->supplier, 2027, '2027-01-01', '2027-12-31');
        $cost = $this->post('518', '100.00', '2027-02-01', $this->sport, $next);
        $income = $this->post('682', '100.00', '2027-02-01', null, $next);
        $source = $this->source(['valid_to' => '2027-12-31']);
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $cost, 'amount_cents' => 100]], $this->actor), 'funding_work_line_other_period');
        $this->expectError(fn() => $this->work->addIncomes($this->supplier, $this->period, $source, [$income], $this->actor), 'funding_work_line_other_period');
        self::assertSame([], $this->work->costCandidates($this->supplier, $this->period, $source, '', 1)['rows']);
        self::assertSame([], $this->work->incomeCandidates($this->supplier, $this->period, '', 1)['rows']);
        $this->expectError(fn() => $this->work->detail($this->supplier, $next, $source), 'funding_work_not_found');
    }

    /** R15: podmínky zdroje — druh činnosti, účet a datum; nabídka nákladů je respektuje. */
    public function testSourceConditionsActivityAccountAndDate(): void
    {
        $ok = $this->post('518', '100.00', '2026-03-01');
        $wrongActivity = $this->post('518', '100.00', '2026-03-01', $this->culture);
        $wrongAccount = $this->post('501', '100.00', '2026-03-01');
        $tooLate = $this->post('518', '100.00', '2026-11-15');
        $tax = $this->post('591', '100.00', '2026-03-01');
        $credit = $this->post('682', '100.00', '2026-03-01');
        $source = $this->source(['account_prefixes' => ['518'], 'valid_to' => '2026-10-31']);
        $any = $this->source(['account_prefixes' => []]);
        foreach ([[$source, $wrongActivity], [$source, $wrongAccount], [$source, $tooLate], [$any, $tax], [$any, $credit]] as [$target, $line]) {
            $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $target, [['line_id' => $line, 'amount_cents' => 100]], $this->actor), 'funding_work_line_ineligible');
        }
        $this->expectError(fn() => $this->work->create($this->supplier, $this->period, $this->input(['account_prefixes' => ['591']]), $this->actor), 'funding_work_invalid');
        self::assertNotContains($tax, array_column($this->work->costCandidates($this->supplier, $this->period, $any, '', 1)['rows'], 'id'), 'Daň z příjmů (59x) se nenabízí.');
        self::assertSame([$ok], array_column($this->work->costCandidates($this->supplier, $this->period, $source, '', 1)['rows'], 'id'));
        $detail = $this->work->allocate($this->supplier, $this->period, $source, [['line_id' => $ok, 'amount_cents' => 100]], $this->actor);
        // Zúžení podmínek zdroje stávající vazbu nesmaže, ukáže ji ke kontrole.
        $detail = $this->work->update($this->supplier, $this->period, $source, $this->input(['account_prefixes' => ['501']]), $detail['source']['row_version'], 'Synthetic narrowing', $this->actor);
        self::assertSame('changed', $detail['allocations'][0]['state']);
        $this->expectError(fn() => $this->work->addIncomes($this->supplier, $this->period, $source, [$ok], $this->actor), 'funding_work_line_ineligible');
    }

    /** Výnos patří jen jednomu aktivnímu zdroji; zrušení zdroje s náklady odmítne, bez nich výnos uvolní. */
    public function testIncomeBelongsToOneSourceAndCancelRules(): void
    {
        $income = $this->post('682', '1000.00');
        $cost = $this->post('518', '100.00');
        $a = $this->source(['income_line_ids' => [$income]]);
        $b = $this->source();
        $this->expectError(fn() => $this->work->addIncomes($this->supplier, $this->period, $b, [$income], $this->actor), 'funding_work_income_used');
        self::assertSame([], $this->work->incomeCandidates($this->supplier, $this->period, '', 1)['rows']);
        $detail = $this->work->allocate($this->supplier, $this->period, $a, [['line_id' => $cost, 'amount_cents' => 100]], $this->actor);
        $this->expectError(fn() => $this->work->cancel($this->supplier, $this->period, $a, $detail['source']['row_version'], 'Synthetic', $this->actor), 'funding_work_source_has_allocations');
        $allocation = $detail['allocations'][0];
        $detail = $this->work->removeAllocation($this->supplier, $this->period, $allocation['id'], $allocation['row_version'], 'Synthetic', $this->actor);
        $detail = $this->work->cancel($this->supplier, $this->period, $a, $detail['source']['row_version'], 'Synthetic cancel', $this->actor);
        self::assertSame('cancelled', $detail['source']['status']);
        self::assertFalse($detail['editable']);
        $this->expectError(fn() => $this->work->allocate($this->supplier, $this->period, $a, [['line_id' => $cost, 'amount_cents' => 100]], $this->actor), 'funding_work_source_cancelled');
        $detail = $this->work->addIncomes($this->supplier, $this->period, $b, [$income], $this->actor);
        self::assertSame(100000, $detail['totals']['received_cents']);
        $overview = $this->work->overview($this->supplier, $this->period);
        self::assertSame([$b, $a], array_column($overview['sources'], 'id'), 'Aktivní zdroje první.');
    }

    // ------------------------------------------------------------------ pomocníci

    /** @return array{0:int,1:array,2:array<string,int>,3:int,4:int,5:int} */
    private function company(int $supplier): array
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$supplier]);
        $period = $this->c->get(AccountingPeriodRepository::class)->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id, effective_from, accounting_framework) VALUES (?, '2026-01-01', 'cz_504')")->execute([$supplier]);
        $catalog = $this->c->get(SystemDimensionCatalog::class)->ensureForFramework($supplier, '2026-01-01');
        $kinds = [];
        foreach (['SPORT' => 'Synthetic sport', 'CULT' => 'Synthetic culture'] as $code => $name) {
            $this->pdo->prepare('INSERT INTO dimension_values(type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')->execute([$catalog['activity_kind']['id'], $supplier, $code, $name]);
            $kinds[] = (int) $this->pdo->lastInsertId();
        }
        $accounts = [];
        foreach (['221' => 'asset', '501' => 'expense', '518' => 'expense', '591' => 'expense', '682' => 'revenue', '691' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id, account_code, name, account_type) VALUES (?, ?, ?, ?)')->execute([$supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $accounts[(string) $code] = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO clients(supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor) VALUES (?, 'Synthetic provider', 'Test', 'Test', '10000', 203, 'provider@example.test', 'cs', ?, 0, 1)")
            ->execute([$supplier, (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn()]);
        return [$period, $catalog, $accounts, (int) $this->pdo->lastInsertId(), $kinds[0], $kinds[1]];
    }

    /** Zaúčtovaný zápis účet/221; vrací id prvního (sledovaného) řádku. */
    private function post(string $code, string $amount, string $date = '2026-06-01', ?int $activity = -1, ?int $period = null): int
    {
        $activity = $activity === -1 ? (str_starts_with($code, '5') ? $this->sport : null) : $activity;
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id, period_id, entry_date, document_no, source_type) VALUES (?, ?, ?, ?, 'manual')")->execute([$this->supplier, $period ?? $this->period, $date, 'SYN-' . bin2hex(random_bytes(3))]);
        $entry = (int) $this->pdo->lastInsertId();
        $first = $this->lines($entry, $code, $amount, $activity)[0];
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $first;
    }

    /** @return list<int> id nových řádků (sledovaný, protiúčet) */
    private function lines(int $entry, string $code, string $amount, ?int $activity): array
    {
        $expense = str_starts_with($code, '5');
        $ids = [];
        foreach ([[$code, $expense ? 'debit' : 'credit'], ['221', $expense ? 'credit' : 'debit']] as $no => [$account, $side]) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id, entry_id, line_no, account_id, side, amount) VALUES (?, ?, ?, ?, ?, ?)')->execute([$this->supplier, $entry, $no, $this->accounts[$account], $side, $amount]);
            $ids[] = (int) $this->pdo->lastInsertId();
            if ($no === 0 && $activity !== null) {
                foreach (['activity' => $this->catalog['activity']['values']['main'], 'activity_kind' => $activity, 'tax_regime' => $this->catalog['tax_regime']['values']['exempt']] as $axis => $value) {
                    $this->pdo->prepare("INSERT INTO journal_entry_line_dimensions(supplier_id, line_id, dimension_type_id, dimension_value_id, assignment_origin) VALUES (?, ?, ?, ?, 'explicit')")
                        ->execute([$this->supplier, $ids[0], $this->catalog[$axis]['id'], $value]);
                }
            }
        }
        return $ids;
    }

    /** Přeúčtování jako JournalEntryRepository::deleteLines + nové řádky téhož zápisu. @return list<int> */
    private function repost(int $line, string $amount): array
    {
        $q = $this->pdo->prepare('SELECT l.entry_id, a.account_code, d.dimension_value_id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
            LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ? WHERE l.id = ?');
        $q->execute([$this->catalog['activity_kind']['id'], $line]);
        [$entry, $code, $activity] = $q->fetch(PDO::FETCH_NUM);
        $this->pdo->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ?')->execute([$entry]);
        return $this->lines((int) $entry, (string) $code, $amount, $activity === null ? null : (int) $activity);
    }

    /** Storno: opravný zápis s opačnými stranami a `reversed_by` na originálu. */
    private function reverse(int $line): void
    {
        $q = $this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE id = ?');
        $q->execute([$line]);
        $entry = (int) $q->fetchColumn();
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id, period_id, entry_date, source_type, posted_at) VALUES (?, ?, '2026-06-30', 'manual', NOW())")->execute([$this->supplier, $this->period]);
        $storno = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO journal_entry_lines(supplier_id, entry_id, line_no, account_id, side, amount) SELECT supplier_id, ?, line_no, account_id, IF(side = 'debit', 'credit', 'debit'), amount FROM journal_entry_lines WHERE entry_id = ?")->execute([$storno, $entry]);
        $this->pdo->prepare('UPDATE journal_entries SET reversed_by = ? WHERE id = ?')->execute([$storno, $entry]);
    }

    private function input(array $override = []): array
    {
        return $override + ['provider_id' => $this->provider, 'kind' => 'operating_grant', 'title' => 'Synthetic grant', 'contract_ref' => 'SYN-1', 'budget_cents' => 100000,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'activity_kind_ids' => [$this->sport], 'account_prefixes' => [], 'legacy_source_key' => null];
    }

    private function source(array $override = []): int
    {
        return $this->work->create($this->supplier, $this->period, $this->input($override), $this->actor)['source']['id'];
    }

    private function expectError(callable $work, string $code, array $extra = []): void
    {
        try {
            $work();
            self::fail('Očekávána chyba ' . $code);
        } catch (FundingWorkException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            foreach ($extra as $key => $value) self::assertSame($value, $e->extra[$key] ?? null, $key);
        }
    }

    private function request(string $method, ?array $body, EffectiveRole $role, string $auth = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/accounting/periods/' . $this->period . '/funding-work')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'staff'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute('auth.effective_role', $role);
        return $body === null ? $request : $request->withParsedBody($body);
    }
}
