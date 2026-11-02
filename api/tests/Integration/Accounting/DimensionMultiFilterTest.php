<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\DimensionCashFlowAction;
use MyInvoice\Action\Accounting\Reports\TrialBalanceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Filtr sestav na víc dimenzí (např. Projekt = akce a zároveň Středisko = dotace):
 * různé typy musí platit na TÉMŽ řádku, hodnoty stejného typu se sčítají, rozpad
 * řádku se promítne součinem dílů. Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionMultiFilterTest extends TestCase
{
    private const YEAR = 2094;
    private const DATE = self::YEAR . '-05-10';
    private const FROM = self::YEAR . '-01-01';
    private const TO = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private DimensionService $dimensions;
    private FinancialStatementService $statements;
    private TrialBalanceService $trialBalance;
    private GeneralLedgerService $generalLedger;
    private JournalEntryRepository $journal;
    private ContainerInterface $container;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $centerType = 0;
    private int $projectType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->generalLedger = $container->get(GeneralLedgerService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = (int) $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, self::FROM, self::TO);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko', 'projekt']);
        $this->centerType = $types['cost_center'];
        $this->projectType = $types['project'];
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testReportsTakeOnlyLinesCarryingAllDimensionsWithProductOfSplitShares(): void
    {
        $action = $this->value($this->projectType, 'MF-AKCE');
        $other = $this->value($this->projectType, 'MF-JINA');
        $grant = $this->value($this->centerType, 'MF-DOTACE');
        $own = $this->value($this->centerType, 'MF-VLASTNI');

        // akce + dotace celé
        $this->post([['518', 'debit', 100.00, ['dimensions' => [$this->projectType => $action, $this->centerType => $grant]]], ['321', 'credit', 100.00]]);
        // akce z vlastních zdrojů, jiná akce z dotace: do průniku nepatří
        $this->post([['518', 'debit', 50.00, ['dimensions' => [$this->projectType => $action, $this->centerType => $own]]], ['321', 'credit', 50.00]]);
        $this->post([['518', 'debit', 30.00, ['dimensions' => [$this->projectType => $other, $this->centerType => $grant]]], ['321', 'credit', 30.00]]);
        // rozpad jen v projektu: 60 % akce, celé z dotace → 60
        $this->post([['518', 'debit', 100.00, [
            'dimensions' => [$this->centerType => $grant],
            'dimension_splits' => [$this->projectType => [$action => 0.6, $other => 0.4]],
        ]], ['321', 'credit', 100.00]]);
        // rozpad v obou typech: 50 % × 50 % z 10 → 2,50
        $this->post([['518', 'debit', 10.00, ['dimension_splits' => [
            $this->projectType => [$action => 0.5, $other => 0.5],
            $this->centerType => [$grant => 0.5, $own => 0.5],
        ]]], ['321', 'credit', 10.00]]);

        $both = $this->dimensions->filters($this->supplierId, [$action, $grant]);
        self::assertCount(2, $both->criteria());

        $tb = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, false, $both);
        $tbRows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(162.50, $tbRows['518']['turnover_md'], 0.001, 'Akce z dotace = 100 + 60 % ze 100 + 50 % × 50 % z 10.');
        self::assertArrayNotHasKey('321', $tbRows, 'Protistrana bez dimenzí do průniku nepatří.');
        self::assertEqualsWithDelta(162.50, $tb['checks']['journal_turnover_md'], 0.001);

        $gl = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['dimension' => $both]);
        $glRows = array_column($gl['accounts'], null, 'account_code');
        self::assertEqualsWithDelta(162.50, $glRows['518']['turnover_md'], 0.001);

        self::assertEqualsWithDelta(-162.50, $this->incomeProfit($both), 0.001);

        // jen projekt: všechny řádky akce bez ohledu na středisko
        self::assertEqualsWithDelta(-215.00, $this->incomeProfit($this->dimensions->filters($this->supplierId, [$action])), 0.001);
        // pořadí hodnot v parametru na výsledek nemá vliv
        self::assertEqualsWithDelta(-162.50, $this->incomeProfit($this->dimensions->filters($this->supplierId, [$grant, $action])), 0.001);
    }

    public function testValuesOfTheSameTypeAddUp(): void
    {
        $action = $this->value($this->projectType, 'MF-OR-A');
        $other = $this->value($this->projectType, 'MF-OR-B');
        $grant = $this->value($this->centerType, 'MF-OR-D');
        $this->post([['518', 'debit', 40.00, ['dimensions' => [$this->projectType => $action, $this->centerType => $grant]]], ['321', 'credit', 40.00]]);
        $this->post([['518', 'debit', 25.00, ['dimensions' => [$this->projectType => $other, $this->centerType => $grant]]], ['321', 'credit', 25.00]]);
        // rozpad mezi obě vybrané hodnoty se počítá jednou celý, ne dvakrát
        $this->post([['518', 'debit', 10.00, [
            'dimensions' => [$this->centerType => $grant],
            'dimension_splits' => [$this->projectType => [$action => 0.3, $other => 0.7]],
        ]], ['321', 'credit', 10.00]]);

        $filter = $this->dimensions->filters($this->supplierId, [$action, $other, $grant]);
        self::assertCount(2, $filter->criteria(), 'Dvě hodnoty projektu tvoří jednu podmínku.');
        self::assertEqualsWithDelta(-75.00, $this->incomeProfit($filter), 0.001);
        $label = (string) $filter->toArray()['label'];
        self::assertStringContainsString('MF-OR-A', $label);
        self::assertStringContainsString('MF-OR-B', $label);
        self::assertStringContainsString('MF-OR-D', $label);
    }

    public function testJournalFilterRequiresAllDimensionsOnTheSameLine(): void
    {
        $action = $this->value($this->projectType, 'MF-J-A');
        $grant = $this->value($this->centerType, 'MF-J-D');
        $own = $this->value($this->centerType, 'MF-J-V');
        $other = $this->value($this->projectType, 'MF-J-B');

        $match = $this->post([
            ['518', 'debit', 10.00, ['dimension_splits' => [$this->projectType => [$action => 0.5, $other => 0.5]], 'dimensions' => [$this->centerType => $grant]]],
            ['321', 'credit', 10.00],
        ]);
        // akce a dotace jsou na různých řádcích téhož zápisu → nepatří
        $split = $this->post([
            ['518', 'debit', 10.00, ['dimensions' => [$this->projectType => $action, $this->centerType => $own]]],
            ['321', 'credit', 10.00, ['dimensions' => [$this->projectType => $other, $this->centerType => $grant]]],
        ]);

        $found = $this->journal->paginate($this->supplierId, ['dimension' => $this->dimensions->filters($this->supplierId, [$action, $grant])], 50, 0);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $found['items']);
        self::assertContains($match, $ids);
        self::assertNotContains($split, $ids);
    }

    public function testEndpointAcceptsCommaSeparatedValuesAndRejectsInvalidOnes(): void
    {
        $action = $this->value($this->projectType, 'MF-API-A');
        $grant = $this->value($this->centerType, 'MF-API-D');
        $this->post([['518', 'debit', 70.00, ['dimensions' => [$this->projectType => $action, $this->centerType => $grant]]], ['321', 'credit', 70.00]]);
        $this->post([['518', 'debit', 5.00, ['dimensions' => [$this->projectType => $action]]], ['321', 'credit', 5.00]]);

        $tb = $this->container->get(TrialBalanceAction::class);
        $response = $tb->get($this->request(['period_id' => (string) $this->periodId, 'dimension_value_id' => "{$action},{$grant}"]), new Psr7Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $data = $body['data'] ?? $body;
        self::assertEqualsWithDelta(70.00, array_column($data['rows'], null, 'account_code')['518']['turnover_md'], 0.001);
        self::assertCount(2, $data['dimension']['criteria']);
        self::assertStringContainsString('MF-API-A', (string) $data['dimension']['label']);
        self::assertStringContainsString('MF-API-D', (string) $data['dimension']['label']);

        foreach (["{$action},abc", "{$action},,{$grant}", '-1', implode(',', range(1, 11))] as $invalid) {
            $bad = $tb->get($this->request(['period_id' => (string) $this->periodId, 'dimension_value_id' => $invalid]), new Psr7Response());
            self::assertSame(422, $bad->getStatusCode(), $invalid);
        }
    }

    public function testRedStornoAndCostCentreTextAndBalanceSheetUseTheSameLineRule(): void
    {
        $action = $this->value($this->projectType, 'MF-RS-A');
        $other = $this->value($this->projectType, 'MF-RS-B');
        $grant = $this->value($this->centerType, 'MF-RS-D');
        $split = ['dimension_splits' => [$this->projectType => [$action => 0.5, $other => 0.5]], 'dimensions' => [$this->centerType => $grant]];
        $this->post([['518', 'debit', 100.00, $split], ['321', 'credit', 100.00, ['dimensions' => [$this->projectType => $action, $this->centerType => $grant]]]]);
        // červené storno části: −20 na řádku s rozpadem → −10 za akci
        $this->post([
            ['518', 'debit', 20.00, $split + ['is_red_storno' => true]],
            ['321', 'credit', 20.00, ['is_red_storno' => true]],
        ]);
        // středisko jen textovým kódem (mzdy, starší zápisy), projekt jako dimenze
        $this->post([['521', 'debit', 30.00, ['cost_center' => 'MF-RS-D', 'dimensions' => [$this->projectType => $action]]], ['331', 'credit', 30.00]]);

        $filter = $this->dimensions->filters($this->supplierId, [$action, $grant]);
        self::assertEqualsWithDelta(-70.00, $this->incomeProfit($filter), 0.001, '50 − 10 za storno + 30 mzdy s textovým střediskem.');

        $tb = array_column($this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, false, $filter)['rows'], null, 'account_code');
        self::assertEqualsWithDelta(40.00, $tb['518']['turnover_md'], 0.001);
        self::assertEqualsWithDelta(30.00, $tb['521']['turnover_md'], 0.001);

        self::assertEqualsWithDelta(100.00, $tb['321']['turnover_d'], 0.001, 'Závazek nese akci i dotaci celý, storno bez dimenzí ne.');

        // rozvaha čte zůstatky od počátku historie: pasiva = závazek 100 + výsledek −70
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::TO, 'full', $filter);
        self::assertEqualsWithDelta(30.00, $bs['checks']['liabilities_total'], 0.001);
        self::assertEqualsWithDelta(-70.00, $bs['checks']['profit_current'], 0.001);

        $found = $this->journal->paginate($this->supplierId, ['dimension' => $filter], 50, 0);
        self::assertCount(3, $found['items'], 'Textové středisko splní podmínku na témže řádku i v deníku.');
    }

    /**
     * Druh „středisko" smí mít víc typů firmy (createType hlídá jen kód), a hodnoty
     * každého z nich nesou kód navázaného střediska. Filtr přes dva takové typy tak
     * nemá podmínku bez textových středisek, podle které by deník hledal po indexu,
     * a jde jinou větví {@see DimensionFilter::entrySql()}. I ta musí vybrat přesně
     * zápisy, jejichž řádky sestavy započtou ({@see DimensionFilter::lineSource()}).
     */
    public function testJournalFilterWhenEveryCriterionCarriesCostCentreText(): void
    {
        $center = $this->value($this->centerType, 'MF-CC-S');
        $secondType = (int) $this->dimensions->createType($this->supplierId, ['code' => 'mf_cc2', 'name' => 'Druhé středisko', 'kind' => 'cost_center'])['id'];
        $project = $this->value($secondType, 'MF-CC-P');

        $filter = $this->dimensions->filters($this->supplierId, [$center, $project]);
        self::assertCount(2, $filter->criteria());
        foreach ($filter->criteria() as $criterion) {
            self::assertNotSame([], $criterion->costCenterCodes, 'Obě podmínky nesou textové středisko.');
        }

        // středisko textem, akce dimenzí
        $byCenterText = $this->post([['521', 'debit', 30.00, ['cost_center' => 'MF-CC-S', 'dimensions' => [$secondType => $project]]], ['331', 'credit', 30.00]]);
        // středisko dimenzí, akce textem
        $byProjectText = $this->post([['518', 'debit', 20.00, ['cost_center' => 'MF-CC-P', 'dimensions' => [$this->centerType => $center]]], ['321', 'credit', 20.00]]);
        // jen středisko textem, akce chybí
        $centerOnly = $this->post([['521', 'debit', 7.00, ['cost_center' => 'MF-CC-S']], ['331', 'credit', 7.00]]);
        // středisko a akce na různých řádcích téhož zápisu
        $apart = $this->post([
            ['521', 'debit', 5.00, ['cost_center' => 'MF-CC-S']],
            ['331', 'credit', 5.00, ['dimensions' => [$secondType => $project]]],
        ]);

        $found = $this->journal->paginate($this->supplierId, ['dimension' => $filter], 50, 0);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $found['items']);
        sort($ids);
        $expected = [$byCenterText, $byProjectText];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertNotContains($centerOnly, $ids);
        self::assertNotContains($apart, $ids);

        $tb = array_column($this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, false, $filter)['rows'], null, 'account_code');
        self::assertEqualsWithDelta(30.00, $tb['521']['turnover_md'], 0.001);
        self::assertEqualsWithDelta(20.00, $tb['518']['turnover_md'], 0.001);
        self::assertArrayNotHasKey('331', $tb);
    }

    public function testFilterRejectsMoreDimensionTypesThanTheProductCanHold(): void
    {
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId);
        $ids = [];
        foreach ($types as $kind => $typeId) {
            $ids[] = $this->value($typeId, 'MF-MAX-' . $kind);
        }
        $extra = (int) $this->dimensions->createType($this->supplierId, ['code' => 'mf_extra', 'name' => 'Syntetický typ', 'kind' => 'custom'])['id'];
        $ids[] = $this->value($extra, 'MF-MAX-X');
        self::assertGreaterThan(DimensionFilter::MAX_TYPES, count($ids));
        try {
            $this->dimensions->filters($this->supplierId, $ids);
            self::fail('Víc typů, než unese součin dílů, se musí odmítnout.');
        } catch (DimensionException $e) {
            self::assertSame('dimension_value_invalid', $e->errorCode);
        }
        self::assertCount(DimensionFilter::MAX_TYPES, $this->dimensions->filters($this->supplierId, array_slice($ids, 0, DimensionFilter::MAX_TYPES))->criteria());
    }

    public function testDimensionCashFlowRejectsAListInsteadOfTakingTheFirstValue(): void
    {
        $action = $this->value($this->projectType, 'MF-CF-A');
        $grant = $this->value($this->centerType, 'MF-CF-D');
        $cashFlow = $this->container->get(DimensionCashFlowAction::class);
        $bad = $cashFlow($this->request(['from' => self::FROM, 'to' => self::TO, 'dimension_value_id' => "{$action},{$grant}"]), new Psr7Response());
        self::assertSame(422, $bad->getStatusCode());
        $ok = $cashFlow($this->request(['from' => self::FROM, 'to' => self::TO, 'dimension_value_id' => (string) $action]), new Psr7Response());
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/reports')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    /** @param list<array{0:string,1:string,2:float,3?:array<string,mixed>}> $lines */
    private function post(array $lines): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, array_map(
            static fn (array $l): array => ['account_code' => $l[0], 'side' => $l[1], 'amount' => $l[2]] + ($l[3] ?? []),
            $lines,
        ), ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
    }

    private function incomeProfit(DimensionFilter $filter): float
    {
        return (float) $this->statements->incomeStatement($this->supplierId, $this->periodId, self::TO, 'full', $filter)['checks']['profit_current'];
    }
}
