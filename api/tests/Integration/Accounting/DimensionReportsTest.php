<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\DimensionCashFlowAction;
use MyInvoice\Action\Accounting\Reports\DimensionProfitAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\CashFlowStatementService;
use MyInvoice\Service\Accounting\Reports\DimensionCashFlowService;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Výkazy po dimenzi: výkazy filtrované na hodnotu promítají rozpad řádku poměrem
 * (stejné haléře jako výsledovka po dimenzi), součet po hodnotách + „bez hodnoty"
 * = výsledek firmy, větev a odpovědná osoba, rozpad po účtech, peněžní tok nepřímou
 * metodou a součet globální hodnoty za skupinu firem. Vše v jedné transakci,
 * tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionReportsTest extends TestCase
{
    private const YEAR = 2094;
    private const DATE = self::YEAR . '-05-10';
    private const FROM = self::YEAR . '-01-01';
    private const TO = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private DimensionService $dimensions;
    private DimensionProfitService $profit;
    private DimensionCashFlowService $cashFlow;
    private CashFlowStatementService $directCashFlow;
    private FinancialStatementService $statements;
    private TrialBalanceService $trialBalance;
    private GeneralLedgerService $generalLedger;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private ContainerInterface $container;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $centerType = 0;
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
            $this->profit = $container->get(DimensionProfitService::class);
            $this->cashFlow = $container->get(DimensionCashFlowService::class);
            $this->directCashFlow = $container->get(CashFlowStatementService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->generalLedger = $container->get(GeneralLedgerService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
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

        $this->periodId = $this->prepareCompany($this->supplierId);
        $pdo->prepare('UPDATE supplier SET supplier_group_id = NULL WHERE id = ?')->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->centerType = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
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

    public function testFilteredStatementsSplitLineProportionallyAndSumToCompanyResult(): void
    {
        $a = $this->value($this->centerType, 'R-A');
        $b = $this->value($this->centerType, 'R-B');
        $split = [$this->centerType => [$a => 1 / 3, $b => 2 / 3]];
        $this->post([
            ['518', 'debit', 100.01, ['dimension_splits' => $split]],
            ['321', 'credit', 100.01, ['dimension_splits' => $split]],
        ]);
        $this->post([
            ['311', 'debit', 500.00, ['dimensions' => [$this->centerType => $a]]],
            ['602', 'credit', 500.00, ['dimensions' => [$this->centerType => $a]]],
        ]);
        $this->post([['518', 'debit', 200.00], ['321', 'credit', 200.00]]);

        $filterA = $this->dimensions->filter($this->supplierId, $a);
        $filterB = $this->dimensions->filter($this->supplierId, $b);

        $profitA = $this->incomeProfit($filterA);
        $profitB = $this->incomeProfit($filterB);
        self::assertEqualsWithDelta(466.66, $profitA, 0.001, 'A = výnos 500 − třetina nákladu 33,34.');
        self::assertEqualsWithDelta(-66.67, $profitB, 0.001, 'B = dvě třetiny nákladu.');

        $report = $this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId]);
        $company = $this->incomeProfit(null);
        self::assertEqualsWithDelta(199.99, $company, 0.001);
        self::assertSame(
            (int) round($company * 100),
            (int) round(($profitA + $profitB + $report['unassigned']['result']) * 100),
            'Výkazy po hodnotách + bez hodnoty = výsledek firmy na haléř.',
        );
        self::assertSame((int) round($company * 100), (int) round($report['totals']['result'] * 100));
        $rows = array_column($report['rows'], null, 'value_id');
        self::assertSame((int) round($profitA * 100), (int) round($rows[$a]['total']['result'] * 100), 'Výsledovka po dimenzi = výkaz filtrovaný na hodnotu.');

        $tb = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, false, $filterA);
        $tbRows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(33.34, $tbRows['518']['turnover_md'], 0.001, 'Předvaha bere z rozpadu jen díl hodnoty.');
        self::assertEqualsWithDelta(33.34, $tbRows['321']['turnover_d'], 0.001);
        self::assertEqualsWithDelta(533.34, $tb['checks']['journal_turnover_md'], 0.001, 'Kontrolní obrat deníku počítá stejné díly.');

        $gl = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['dimension' => $filterB]);
        $glRows = array_column($gl['accounts'], null, 'account_code');
        self::assertEqualsWithDelta(66.67, $glRows['518']['turnover_md'], 0.001, 'Hlavní kniha bere z rozpadu jen díl hodnoty.');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::TO, 'full', $filterA);
        self::assertEqualsWithDelta(500.00, $bs['checks']['assets_net'], 0.001);
        self::assertTrue($bs['checks']['balanced'], 'Hodnota nesená oběma stranami zápisů → rozvaha hodnoty je vyrovnaná.');
        self::assertSame($a, $bs['dimension']['value_id']);
    }

    public function testFilterLineSourceReadsOnlyEntriesInsideTheRequestedDates(): void
    {
        $a = $this->value($this->centerType, 'R-D');
        $b = $this->value($this->centerType, 'R-E');
        $this->post([
            ['518', 'debit', 40.00, ['dimension_splits' => [$this->centerType => [$a => 0.5, $b => 0.5]]]],
            ['321', 'credit', 40.00, ['dimensions' => [$this->centerType => $a]]],
        ]);
        $filter = $this->dimensions->filter($this->supplierId, $a);
        $lines = function (?string $from, string $to) use ($filter): array {
            [$sql, $params] = $filter->lineSource($this->supplierId, 'l', $from, $to);
            $stmt = $this->db->pdo()->prepare("SELECT l.amount FROM {$sql} ORDER BY l.amount");
            $stmt->execute($params);
            return array_map('floatval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        };
        self::assertSame([20.0, 40.0], $lines(self::FROM, self::TO), 'Jediná hodnota v plné výši, rozpad dílem.');
        self::assertSame([20.0, 40.0], $lines(null, self::DATE), 'Bez spodní meze od počátku historie, horní mez včetně.');
        self::assertSame([], $lines(self::FROM, self::YEAR . '-05-09'), 'Zápis po horní mezi do zdroje nevstoupí.');
        self::assertSame([], $lines(self::YEAR . '-05-11', self::TO), 'Zápis před spodní mezí do zdroje nevstoupí.');
    }

    public function testSplitRemainderGoesToLargestShareIdenticallyEverywhere(): void
    {
        $a = $this->value($this->centerType, 'R-X');
        $b = $this->value($this->centerType, 'R-Y');
        $c = $this->value($this->centerType, 'R-Z');
        $third = [$this->centerType => [$a => 1 / 3, $b => 1 / 3, $c => 1 / 3]];
        $this->post([['518', 'debit', 0.05, ['dimension_splits' => $third]], ['321', 'credit', 0.05]]);

        $rows = array_column($this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId])['rows'], null, 'value_id');
        $cents = [];
        foreach ([$a, $b, $c] as $v) {
            $cents[$v] = (int) round($rows[$v]['own']['cost'] * 100);
            $filtered = (int) round(-$this->incomeProfit($this->dimensions->filter($this->supplierId, $v)) * 100);
            self::assertSame($cents[$v], $filtered, 'Výsledovka po dimenzi a filtr výkazu dělí haléře stejně.');
        }
        self::assertSame([$a => 1, $b => 2, $c => 2], $cents, 'Zbytek po zaokrouhlení nese hodnota s nižším id při shodě podílů.');
    }

    public function testTinySplitSpreadsNegativeRemainderWithoutNegativeMarginals(): void
    {
        $values = [];
        foreach (range(1, 5) as $i) {
            $values[] = $this->value($this->centerType, 'R-T' . $i);
        }
        $shares = array_fill_keys($values, 0.2);
        $split = ['dimension_splits' => [$this->centerType => $shares]];
        $this->post([['518', 'debit', 0.03, $split], ['321', 'credit', 0.03]]);

        $report = $this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId]);
        $rows = array_column($report['rows'], null, 'value_id');
        $cents = [];
        foreach ($values as $valueId) {
            $cents[$valueId] = (int) round($rows[$valueId]['own']['cost'] * 100);
            $filtered = (int) round(-$this->incomeProfit($this->dimensions->filter($this->supplierId, $valueId)) * 100);
            self::assertSame($cents[$valueId], $filtered, 'Výsledovka po dimenzi a její filtr musí mít stejný marginální díl.');
            self::assertGreaterThanOrEqual(0, $cents[$valueId], 'Kladný náklad nesmí vytvořit záporný díl.');
        }
        self::assertSame([0, 0, 1, 1, 1], array_values($cents));
        self::assertSame(3, array_sum($cents), 'Součet pěti dílů musí zůstat přesně tři haléře.');
        self::assertSame(-3, (int) round($report['totals']['result'] * 100));
    }

    public function testTinyRedStornoMirrorsPositiveSplit(): void
    {
        $values = [];
        foreach (range(1, 5) as $i) {
            $values[] = $this->value($this->centerType, 'R-TS' . $i);
        }
        $split = ['dimension_splits' => [$this->centerType => array_fill_keys($values, 0.2)]];
        $this->post([['518', 'debit', 0.06, $split], ['321', 'credit', 0.06]]);
        $this->post([
            ['518', 'debit', 0.03, $split + ['is_red_storno' => true]],
            ['321', 'credit', 0.03, ['is_red_storno' => true]],
        ]);

        $rows = array_column($this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId])['rows'], null, 'value_id');
        $cents = [];
        foreach ($values as $valueId) {
            $part = (int) round($rows[$valueId]['own']['cost'] * 100);
            $cents[] = $part;
            self::assertSame(
                $part,
                (int) round(-$this->incomeProfit($this->dimensions->filter($this->supplierId, $valueId)) * 100),
                'Červené storno musí používat stejné díly i ve filtrovaném výkazu.',
            );
        }
        self::assertSame([2, 1, 0, 0, 0], $cents);
        self::assertSame(3, array_sum($cents));
    }

    public function testRedStornoSplitReducesEachDimensionCost(): void
    {
        $a = $this->value($this->centerType, 'R-S1');
        $b = $this->value($this->centerType, 'R-S2');
        $split = ['dimension_splits' => [$this->centerType => [$a => 0.5, $b => 0.5]]];
        $this->post([['518', 'debit', 100.00, $split], ['321', 'credit', 100.00]]);
        $this->post([
            ['518', 'debit', 20.00, $split + ['is_red_storno' => true]],
            ['321', 'credit', 20.00, ['is_red_storno' => true]],
        ]);

        $rows = array_column($this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId])['rows'], null, 'value_id');
        foreach ([$a, $b] as $valueId) {
            self::assertEqualsWithDelta(40.0, $rows[$valueId]['own']['cost'], 0.001);
            self::assertEqualsWithDelta(-40.0, $this->incomeProfit($this->dimensions->filter($this->supplierId, $valueId)), 0.001);
        }
    }

    public function testJournalFilterFindsEntryWithSplitLine(): void
    {
        $a = $this->value($this->centerType, 'R-J1');
        $b = $this->value($this->centerType, 'R-J2');
        $entry = $this->post([
            ['518', 'debit', 10.00, ['dimension_splits' => [$this->centerType => [$a => 0.5, $b => 0.5]]]],
            ['321', 'credit', 10.00],
        ]);
        $found = $this->journal->paginate($this->supplierId, ['dimension' => $this->dimensions->filter($this->supplierId, $b)], 50, 0);
        self::assertContains($entry, array_map(static fn (array $r): int => (int) $r['id'], $found['items']));
    }

    public function testBranchResponsibleAndAccountMatrix(): void
    {
        $parent = $this->value($this->centerType, 'R-P');
        $child1 = $this->value($this->centerType, 'R-P1', $parent);
        $child2 = $this->value($this->centerType, 'R-P2', $parent);
        $other = $this->value($this->centerType, 'R-O');
        $this->db->pdo()->prepare('UPDATE dimension_values SET responsible_user_id = ? WHERE id IN (?, ?)')
            ->execute([$this->userId, $child2, $other]);

        $this->post([['518', 'debit', 100.00, ['dimensions' => [$this->centerType => $child1]]], ['321', 'credit', 100.00]]);
        $this->post([['311', 'debit', 300.00], ['602', 'credit', 300.00, ['dimensions' => [$this->centerType => $child2]]]]);
        $this->post([['501', 'debit', 40.00, ['dimensions' => [$this->centerType => $other]]], ['321', 'credit', 40.00]]);
        $this->post([['518', 'debit', 7.00], ['321', 'credit', 7.00]]);

        $branch = $this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId], ['value_id' => $parent]);
        self::assertTrue($branch['restricted']);
        self::assertSame([$parent, $child1, $child2], array_column($branch['rows'], 'value_id'));
        self::assertEqualsWithDelta(200.00, $branch['totals']['result'], 0.001, 'Větev = výnos etapy 2 − náklad etapy 1.');

        $mine = $this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId], ['responsible_user_id' => $this->userId]);
        self::assertSame([$other, $child2], array_column(array_filter($mine['rows'], static fn (array $r): bool => $r['depth'] === 0), 'value_id'));
        self::assertEqualsWithDelta(260.00, $mine['totals']['result'], 0.001);

        $full = $this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId], ['accounts' => true]);
        $matrix = $full['matrix'];
        self::assertSame([$other, $parent, null], array_column($matrix['columns'], 'value_id'), 'Sloupce = hodnoty nejvyšší úrovně a bez hodnoty.');
        $byCode = array_column($matrix['rows'], null, 'code');
        self::assertSame('602', $matrix['rows'][0]['code'], 'Výnosy nahoře.');
        self::assertSame([0.0, 100.0, 7.0], $byCode['518']['cells']);
        self::assertEqualsWithDelta(107.00, $byCode['518']['total'], 0.001);
        self::assertEqualsWithDelta($full['totals']['result'], array_sum($matrix['results']), 0.001, 'Součet sloupců = výsledek sestavy.');
        self::assertEqualsWithDelta($this->incomeProfit(null), $matrix['total_result'], 0.001, 'Rozpad po účtech sedí na výsledek firmy.');
    }

    public function testIndirectCashFlowReconcilesToCashAndSplitsByDimension(): void
    {
        $a = $this->value($this->centerType, 'R-CF');
        $onA = ['dimensions' => [$this->centerType => $a]];
        $this->post([['311', 'debit', 1_210.00, $onA], ['602', 'credit', 1_000.00, $onA], ['343', 'credit', 210.00, $onA]]);
        $this->post([['221', 'debit', 1_210.00], ['311', 'credit', 1_210.00]]);
        $this->post([['518', 'debit', 400.00, $onA], ['321', 'credit', 400.00, $onA]]);
        $this->post([['321', 'debit', 150.00, $onA], ['221', 'credit', 150.00, $onA]]);
        $this->post([['551', 'debit', 90.00, $onA], ['082', 'credit', 90.00, $onA]]);
        $this->post([['221', 'debit', 5_000.00], ['461', 'credit', 5_000.00]]);
        $this->post([['022', 'debit', 2_000.00, $onA], ['221', 'credit', 2_000.00, $onA]]);

        $company = $this->cashFlow->build(self::FROM, self::TO, [$this->supplierId => null]);
        self::assertTrue($company['reconciles'], 'Bez filtru se nepřímá metoda rovná pohybu peněz.');
        $direct = $this->directCashFlow->build($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta($direct['net_change'], $company['net_cash_flow'], 0.001, 'Shoda s přímou metodou výkazu.');
        self::assertEqualsWithDelta(510.00, $company['profit'], 0.001);
        self::assertEqualsWithDelta(90.00, $company['non_cash']['total'], 0.001, 'Odpisy se přičtou zpět.');
        self::assertEqualsWithDelta(5_000.00, $company['financing']['total'], 0.001);
        self::assertEqualsWithDelta(-2_000.00, $company['investing']['total'], 0.001);

        $project = $this->cashFlow->build(self::FROM, self::TO, [$this->supplierId => $this->dimensions->filter($this->supplierId, $a)]);
        self::assertEqualsWithDelta(510.00, $project['profit'], 0.001);
        // Projekt: 311 +1210 (neuhrazeno v jeho řádcích), 343 −210, 321 +250 → provozní 510 + 90 − 1210 + 210 + 250 = −150.
        self::assertEqualsWithDelta(-150.00, $project['operating'], 0.001);
        self::assertEqualsWithDelta(-2_150.00, $project['net_cash_flow'], 0.001);
        self::assertEqualsWithDelta(-2_150.00, $project['cash_movement'], 0.001, 'Platby nesoucí projekt.');
        self::assertTrue($project['reconciles']);
    }

    public function testGroupSumsGlobalProjectAcrossCompanies(): void
    {
        $pdo = $this->db->pdo();
        $groupId = $this->dimensions->createGroup($this->supplierId, 'Skupina testovací');
        $second = $this->newSupplier($this->supplierId, 'Dceřiná SPV test');
        $this->prepareCompany($second);
        $this->dimensions->joinGroup($second, $groupId, [$this->supplierId, $second], true);
        $this->dimensions->setEnabled($second, true);
        $type = $this->dimensions->createType($this->supplierId, ['code' => 'gproj', 'name' => 'Projekt skupiny', 'kind' => 'project', 'level' => 'global']);
        $project = $this->value((int) $type['id'], 'G-1');

        $dims = ['dimensions' => [(int) $type['id'] => $project]];
        $this->post([['311', 'debit', 800.00], ['602', 'credit', 800.00, $dims]]);
        $this->post([['518', 'debit', 300.00, $dims], ['321', 'credit', 300.00]], $second);
        $this->post([['518', 'debit', 50.00], ['321', 'credit', 50.00]]);

        $alone = $this->profit->build($this->supplierId, (int) $type['id'], self::FROM, self::TO, [$this->supplierId]);
        $group = $this->profit->build($this->supplierId, (int) $type['id'], self::FROM, self::TO, [$this->supplierId, $second]);
        self::assertEqualsWithDelta(800.00, array_column($alone['rows'], null, 'value_id')[$project]['total']['result'], 0.001);
        self::assertEqualsWithDelta(500.00, array_column($group['rows'], null, 'value_id')[$project]['total']['result'], 0.001, 'Skupinový projekt sečte obě firmy.');

        $companies = $this->profit->build($this->supplierId, (int) $type['id'], self::FROM, self::TO,
            [$this->supplierId, $second], ['companies' => true]);
        $byCompany = array_column($companies['companies'], null, 'id');
        self::assertEqualsWithDelta(800.00, $byCompany[$this->supplierId]['revenue'], 0.001);
        self::assertEqualsWithDelta(50.00, $byCompany[$this->supplierId]['cost'], 0.001);
        self::assertEqualsWithDelta(300.00, $byCompany[$second]['cost'], 0.001);
        self::assertEqualsWithDelta($companies['totals']['result'], array_sum(array_column($companies['companies'], 'result')), 0.001);

        $branch = $this->profit->build($this->supplierId, (int) $type['id'], self::FROM, self::TO,
            [$this->supplierId, $second], ['companies' => true, 'value_id' => $project]);
        $branchCompanies = array_column($branch['companies'], null, 'id');
        self::assertEqualsWithDelta(0.00, $branchCompanies[$this->supplierId]['cost'], 0.001);
        self::assertEqualsWithDelta(500.00, array_sum(array_column($branch['companies'], 'result')), 0.001);

        $cf = $this->cashFlow->build(self::FROM, self::TO, [
            $this->supplierId => $this->dimensions->filter($this->supplierId, $project),
            $second => $this->dimensions->filter($second, $project),
        ]);
        self::assertEqualsWithDelta(500.00, $cf['profit'], 0.001);
        self::assertSame([$this->supplierId, $second], $cf['supplier_ids']);
        unset($pdo);
    }

    public function testAnalyticsMonthlySplitsAndCompanyTotalsMatchProfitReport(): void
    {
        $groupId = $this->dimensions->createGroup($this->supplierId, 'Skupina analytiky');
        $second = $this->newSupplier($this->supplierId, 'Dceřiná firma analytiky');
        $this->prepareCompany($second);
        $this->dimensions->joinGroup($second, $groupId, [$this->supplierId, $second], true);
        $type = $this->dimensions->createType($this->supplierId, ['code' => 'analytics', 'name' => 'Analytika', 'kind' => 'project', 'level' => 'global']);
        $typeId = (int) $type['id'];
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET tax_deductibility = 'non_deductible' WHERE supplier_id = ? AND account_code = '518'")
            ->execute([$this->supplierId]);
        $parent = $this->value($typeId, 'A');
        $child = $this->value($typeId, 'A-1', $parent);
        $other = $this->value($typeId, 'B');
        $split = ['dimension_splits' => [$typeId => [$child => 0.5, $other => 0.5]]];
        $this->post([['311', 'debit', 101.01], ['602', 'credit', 101.01, $split]]);
        $this->post([['518', 'debit', 30.00, ['dimensions' => [$typeId => $child]]], ['321', 'credit', 30.00]], $second);
        $this->post([['518', 'debit', 10.00], ['321', 'credit', 10.00]]);

        $companies = [
            ['id' => $this->supplierId, 'company_name' => 'Mateřská firma'],
            ['id' => $second, 'company_name' => 'Dceřiná firma'],
        ];
        $analytics = $this->profit->analytics($this->supplierId, $typeId, self::YEAR, $companies);
        $report = $this->profit->build($this->supplierId, $typeId, self::FROM, self::TO, [$this->supplierId, $second]);
        self::assertSame($report['totals'], array_intersect_key($analytics['totals'], $report['totals']));
        self::assertSame($report['unassigned'], array_intersect_key($analytics['unassigned'], $report['unassigned']));
        self::assertCount(12, $analytics['monthly']);
        self::assertEqualsWithDelta(101.01, $analytics['monthly'][4]['revenue'], 0.001);
        self::assertEqualsWithDelta(40.00, $analytics['monthly'][4]['cost'], 0.001);
        self::assertEqualsWithDelta(0.00, $analytics['previous_monthly'][4]['result'], 0.001);
        $series = (array) $analytics['value_monthly'];
        $reportRows = array_column($report['rows'], null, 'value_id');
        self::assertEqualsWithDelta($reportRows[$parent]['total']['revenue'], $series[(string) $parent][4]['revenue'], 0.001);
        self::assertEqualsWithDelta($reportRows[$other]['total']['revenue'], $series[(string) $other][4]['revenue'], 0.001);
        self::assertEqualsWithDelta(101.01, $series[(string) $parent][4]['revenue'] + $series[(string) $other][4]['revenue'], 0.001);
        self::assertEqualsWithDelta(30.00, $series[(string) $parent][4]['cost'], 0.001);
        self::assertEqualsWithDelta(10.00, $series[''][4]['cost'], 0.001);
        self::assertEqualsWithDelta(30.00, $analytics['totals']['tax_deductible_cost'], 0.001);
        self::assertEqualsWithDelta(10.00, $analytics['totals']['non_deductible_cost'], 0.001);
        self::assertEqualsWithDelta(10.00, (new NonDeductibleCostsService($this->db))->sum($this->supplierId, self::FROM, self::TO), 0.001);
        self::assertEqualsWithDelta(61.01, array_sum(array_column($analytics['companies'], 'result')), 0.001);
        $companyValues = (array) $analytics['company_value_totals'];
        $secondValues = (array) $companyValues[$second];
        self::assertEqualsWithDelta(-30.00, $secondValues[(string) $parent]['result'], 0.001);

        $action = $this->container->get(DimensionProfitAction::class);
        $invalid = $action->analytics($this->request(['type_id' => (string) $typeId, 'year' => (string) self::YEAR, 'supplier_id' => '999999999']), new Psr7Response());
        self::assertSame(403, $invalid->getStatusCode());
        $foreign = $action->analytics($this->request(['type_id' => (string) $typeId, 'year' => (string) self::YEAR, 'supplier_id' => (string) $second]), new Psr7Response());
        self::assertSame(403, $foreign->getStatusCode());
        $visible = $this->json($action->analytics($this->request(['type_id' => (string) $typeId, 'year' => (string) self::YEAR, 'supplier_id' => 'all']), new Psr7Response()));
        self::assertSame([$this->supplierId], ($visible['data'] ?? $visible)['supplier_ids']);
    }

    public function testSupplierBoundTokenCannotReadOtherGroupCompanies(): void
    {
        $groupId = $this->dimensions->createGroup($this->supplierId, 'Skupina tokenu');
        $second = $this->newSupplier($this->supplierId, 'Druhá firma tokenu');
        $this->prepareCompany($second);
        $this->dimensions->joinGroup($second, $groupId, [$this->supplierId, $second], true);
        $type = $this->dimensions->createType($this->supplierId, ['code' => 'token-project', 'name' => 'Projekt tokenu', 'kind' => 'project', 'level' => 'global']);
        $typeId = (int) $type['id'];
        $value = $this->value($typeId, 'T-1');
        $this->post([['311', 'debit', 100.00], ['602', 'credit', 100.00, ['dimensions' => [$typeId => $value]]]], $second);

        $request = $this->request(['type_id' => (string) $typeId, 'year' => (string) self::YEAR, 'supplier_id' => 'all'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $this->supplierId]);
        $action = $this->container->get(DimensionProfitAction::class);
        $analytics = $this->json($action->analytics($request, new Psr7Response()));
        self::assertSame([$this->supplierId], ($analytics['data'] ?? $analytics)['supplier_ids']);
        self::assertEqualsWithDelta(0.0, ($analytics['data'] ?? $analytics)['totals']['revenue'], 0.001);
        self::assertSame(403, $action->analytics($request->withQueryParams([
            'type_id' => (string) $typeId, 'year' => (string) self::YEAR, 'supplier_id' => (string) $second,
        ]), new Psr7Response())->getStatusCode());

        $report = $this->json($action($request->withQueryParams([
            'type_id' => (string) $typeId, 'from' => self::FROM, 'to' => self::TO, 'scope' => 'group', 'companies' => '1',
        ]), new Psr7Response()));
        self::assertSame([$this->supplierId], ($report['data'] ?? $report)['supplier_ids']);
        self::assertSame([$this->supplierId], array_column(($report['data'] ?? $report)['companies'], 'id'));

        $domainRequest = $request
            ->withoutAttribute(AuthMiddleware::ATTR_API_TOKEN)
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext(
                TenantDomainContext::CUSTOM, 'company.example', 'https://company.example', supplierId: $this->supplierId,
            ));
        $domainReport = $this->json($action->analytics($domainRequest, new Psr7Response()));
        self::assertSame([$this->supplierId], ($domainReport['data'] ?? $domainReport)['supplier_ids']);
    }

    public function testAnalyticsClassifiesNondeductiblePurchaseAndIncomeTaxSeparately(): void
    {
        $value = $this->value($this->centerType, 'TAX');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            SELECT ?, 'Syntetický dodavatel', 'Testovací 1', 'Praha', '10000', country_id, 'tax-test@example.invalid', default_currency_id FROM supplier WHERE id = ?")
            ->execute([$this->supplierId, $this->supplierId]);
        $vendorId = (int) $pdo->lastInsertId();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot, status, tax_deductible, created_by)
            VALUES (?, ?, 'TEST-DIM-TAX', ?, ?, ?, ?, ?, '{}', 'received', 0, ?)")
            ->execute([$this->supplierId, $vendorId, self::DATE, self::DATE, self::DATE, self::DATE, $currencyId, $this->userId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 25.00, 'dimensions' => [$this->centerType => $value]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 25.00],
        ], ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $this->post([['591', 'debit', 5.00, ['dimensions' => [$this->centerType => $value]]], ['341', 'credit', 5.00]]);

        $analytics = $this->profit->analytics($this->supplierId, $this->centerType, self::YEAR, [
            ['id' => $this->supplierId, 'company_name' => 'Testovací firma'],
        ]);
        self::assertEqualsWithDelta(30.00, $analytics['totals']['cost'], 0.001);
        self::assertEqualsWithDelta(0.00, $analytics['totals']['tax_deductible_cost'], 0.001);
        self::assertEqualsWithDelta(25.00, $analytics['totals']['non_deductible_cost'], 0.001);
        self::assertEqualsWithDelta(5.00, $analytics['totals']['income_tax_cost'], 0.001);
        self::assertEqualsWithDelta(25.00, (new NonDeductibleCostsService($this->db))->sum($this->supplierId, self::FROM, self::TO), 0.001);
        $values = (array) $analytics['value_totals'];
        self::assertEqualsWithDelta(25.00, $values[(string) $value]['non_deductible_cost'], 0.001);
    }

    public function testEndpointsReturnReportAndXlsx(): void
    {
        $a = $this->value($this->centerType, 'R-API');
        $this->post([['518', 'debit', 120.00, ['dimensions' => [$this->centerType => $a]]], ['321', 'credit', 120.00]]);

        $profit = $this->container->get(DimensionProfitAction::class);
        $body = $this->json($profit(
            $this->request(['type_id' => (string) $this->centerType, 'from' => self::FROM, 'to' => self::TO, 'accounts' => '1']),
            new Psr7Response(),
        ));
        self::assertEqualsWithDelta(-120.00, array_column($body['data']['rows'] ?? $body['rows'], null, 'value_id')[$a]['total']['result'], 0.001);
        self::assertArrayHasKey('matrix', $body['data'] ?? $body);

        $bad = $profit($this->request(['type_id' => (string) $this->centerType, 'from' => self::TO, 'to' => self::FROM]), new Psr7Response());
        self::assertSame(422, $bad->getStatusCode());

        $cashFlow = $this->container->get(DimensionCashFlowAction::class);
        $cf = $this->json($cashFlow(
            $this->request(['from' => self::FROM, 'to' => self::TO, 'dimension_value_id' => (string) $a]),
            new Psr7Response(),
        ));
        $cf = $cf['data'] ?? $cf;
        self::assertEqualsWithDelta(-120.00, $cf['profit'], 0.001);
        self::assertSame($a, $cf['dimension']['value_id']);
        self::assertStringContainsString('R-API', (string) $cf['dimension']['label']);

        $xlsx = $cashFlow->export($this->request(['from' => self::FROM, 'to' => self::TO]), new Psr7Response());
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
        $xlsx = $profit->export($this->request(['type_id' => (string) $this->centerType, 'from' => self::FROM, 'to' => self::TO]), new Psr7Response());
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
        $pdf = $profit->export($this->request(['type_id' => (string) $this->centerType, 'from' => self::FROM, 'to' => self::TO, 'format' => 'pdf']), new Psr7Response());
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
        $analytics = $profit->exportAnalytics($this->request([
            'type_id' => (string) $this->centerType, 'year' => (string) self::YEAR,
            'table' => 'monthly', 'value_id' => (string) $a, 'format' => 'xlsx',
        ]), new Psr7Response());
        self::assertStringStartsWith('PK', (string) $analytics->getBody());
        $analyticsPdf = $profit->exportAnalytics($this->request([
            'type_id' => (string) $this->centerType, 'year' => (string) self::YEAR,
            'table' => 'comparison', 'format' => 'pdf',
        ]), new Psr7Response());
        self::assertStringStartsWith('%PDF', (string) $analyticsPdf->getBody());
        $invalidValue = $profit->exportAnalytics($this->request([
            'type_id' => (string) $this->centerType, 'year' => (string) self::YEAR,
            'table' => 'monthly', 'value_id' => '999999999', 'format' => 'pdf',
        ]), new Psr7Response());
        self::assertSame(404, $invalidValue->getStatusCode());
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

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function prepareCompany(int $supplierId): int
    {
        $this->seeder->seedForSupplier($supplierId);
        $periodId = $this->periods->create($supplierId, self::YEAR, self::FROM, self::TO);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$supplierId]);
        return (int) $periodId;
    }

    private function newSupplier(int $baseSupplier, string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, accounting_enabled)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id, "double_entry", 1
               FROM supplier WHERE id = ?'
        )->execute([$name, 'spv-test@example.invalid', $baseSupplier]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function value(int $typeId, string $code, ?int $parentId = null): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code]
            + ($parentId !== null ? ['parent_id' => $parentId] : []))['id'];
    }

    /** @param list<array{0:string,1:string,2:float,3?:array<string,mixed>}> $lines */
    private function post(array $lines, ?int $supplierId = null): int
    {
        return $this->posting->postDocument($supplierId ?? $this->supplierId, 'manual', null, array_map(
            static fn (array $l): array => ['account_code' => $l[0], 'side' => $l[1], 'amount' => $l[2]] + ($l[3] ?? []),
            $lines,
        ), ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
    }

    private function incomeProfit(?\MyInvoice\Service\Accounting\Dimension\DimensionFilter $filter): float
    {
        return (float) $this->statements->incomeStatement($this->supplierId, $this->periodId, self::TO, 'full', $filter)['checks']['profit_current'];
    }
}
