<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\AccountStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\AccountStatementScope;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Integrační test opisu účtu (Epic F2, T6): běžící zůstatek řádek po řádku,
 * opening + Σ pohybů == closing, stránkování nemění zůstatky a položky nesou
 * source_type/source_id (drill-down na prvotní doklad).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AccountStatementReportTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private AccountStatementService $statement;
    private AccountingPeriodRepository $periods;
    private ContainerInterface $container;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->container = $container;
            $this->db        = $container->get(Connection::class);
            $this->posting   = $container->get(PostingService::class);
            $this->statement = $container->get(AccountStatementService::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier (kopie FK hodnot z prvního): kumulativní PS rozvahových
        // účtů (R6) jde přes celou historii deníku, sdílený dev supplier s reálnými
        // zápisy by rozbil bilanční asserty a previousPeriod.
        $this->supplierId = $this->newSupplier('Izolovaný test s.r.o.');

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── T6 ────────────────────────────────────────────────────────────────

    public function testRunningBalanceAndTotals(): void
    {
        $accountId = $this->accountId('211');
        $this->manual([
            self::l('211', 'debit', 100.00),
            self::l('602', 'credit', 100.00),
        ], self::YEAR . '-01-10');
        $this->manual([
            self::l('518', 'debit', 30.00),
            self::l('211', 'credit', 30.00),
        ], self::YEAR . '-02-05');
        $this->manual([
            self::l('211', 'debit', 50.00),
            self::l('602', 'credit', 50.00),
        ], self::YEAR . '-03-03');

        $data = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);

        self::assertSame('211', $data['account']['code']);
        self::assertSame(0, self::cents($data['opening_balance']));
        self::assertSame(3, $data['total']);
        self::assertCount(3, $data['items']);

        // běžící zůstatek řádek po řádku
        self::assertSame(self::cents(100.00), self::cents($data['items'][0]['balance']));
        self::assertSame(self::cents(70.00), self::cents($data['items'][1]['balance']));
        self::assertSame(self::cents(120.00), self::cents($data['items'][2]['balance']));

        // opening + Σ pohybů == closing
        $sum = self::cents($data['opening_balance']);
        foreach ($data['items'] as $item) {
            $sum += $item['side'] === 'debit' ? self::cents($item['amount']) : -self::cents($item['amount']);
        }
        self::assertSame($sum, self::cents($data['closing_balance']), 'opening + Σ pohybů == closing (haléře).');
        self::assertSame(self::cents(150.00), self::cents($data['turnover_md']));
        self::assertSame(self::cents(30.00), self::cents($data['turnover_d']));

        // source_type v položkách (manual zápis nemá source_id)
        foreach ($data['items'] as $item) {
            self::assertSame('manual', $item['source_type']);
            self::assertNull($item['source_id']);
        }
    }

    public function testPaginationDoesNotChangeBalances(): void
    {
        $accountId = $this->accountId('211');
        $this->manual([self::l('211', 'debit', 100.00), self::l('602', 'credit', 100.00)], self::YEAR . '-01-10');
        $this->manual([self::l('518', 'debit', 30.00), self::l('211', 'credit', 30.00)], self::YEAR . '-02-05');
        $this->manual([self::l('211', 'debit', 50.00), self::l('602', 'credit', 50.00)], self::YEAR . '-03-03');

        $all = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);

        foreach ([1 => 100.00, 2 => 70.00, 3 => 120.00] as $page => $expected) {
            $paged = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', $page, 1);
            self::assertCount(1, $paged['items'], "Stránka {$page} má 1 položku.");
            self::assertSame(3, $paged['total']);
            self::assertSame(self::cents($expected), self::cents($paged['items'][0]['balance']), "Zůstatek na stránce {$page} sedí s celkovým výpisem.");
            self::assertSame($all['items'][$page - 1]['entry_id'], $paged['items'][0]['entry_id']);
            self::assertSame(self::cents($all['closing_balance']), self::cents($paged['closing_balance']), 'closing_balance nezávisí na stránce.');
        }
    }

    public function testOpeningWindowAndInvoiceSourceDrilldown(): void
    {
        // PS: pohyb v lednu, opis od února → opening = leden
        $accountId311 = $this->accountId('311');
        $clientId  = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-OPIS-1', $clientId, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => self::YEAR . '-01-15', 'document_no' => 'FV-2099-OPIS-1', 'posted_by' => $this->userId,
        ]);

        $full = $this->statement->build($this->supplierId, $accountId311, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);
        self::assertCount(1, $full['items']);
        self::assertSame('invoice', $full['items'][0]['source_type'], 'Položka nese source_type prvotního dokladu.');
        self::assertSame($invoiceId, (int) $full['items'][0]['source_id'], 'Položka nese source_id prvotního dokladu.');
        self::assertSame(self::cents(1210.00), self::cents($full['items'][0]['balance']));

        $fromFeb = $this->statement->build($this->supplierId, $accountId311, self::YEAR . '-02-01', self::YEAR . '-12-31', 1, 50);
        self::assertSame(self::cents(1210.00), self::cents($fromFeb['opening_balance']), 'PS opisu od února = lednový pohyb.');
        self::assertCount(0, $fromFeb['items']);
        self::assertSame(self::cents(1210.00), self::cents($fromFeb['closing_balance']));
    }

    /**
     * Regrese: otevírací zápis se počítal DVAKRÁT — jednou v počátečním stavu
     * (kam patří) a podruhé mezi pohyby. Pokladní kniha za 2026 pak tvrdila
     * konečný zůstatek 28 464 Kč místo 14 232, přesný dvojnásobek; opis účtu
     * 221 se mýlil o 4,1 mil. Předvaha tuhle opravu měla, do opisu se nikdy
     * neprotáhla.
     */
    public function testOpeningEntryCountsOnlyIntoOpeningBalance(): void
    {
        $accountId = $this->accountId('211');
        $this->technical(
            [self::l('211', 'debit', 14232.00), self::l('701', 'credit', 14232.00)],
            self::YEAR . '-01-01',
            'opening',
            null,
            'OT-' . self::YEAR . '-0001',
        );
        $this->manual([self::l('518', 'debit', 1000.00), self::l('211', 'credit', 1000.00)], self::YEAR . '-03-03');

        $data = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);

        self::assertSame(self::cents(14232.00), self::cents($data['opening_balance']), 'Otevírací zápis tvoří počáteční stav.');
        self::assertSame(1, $data['total'], 'Mezi pohyby patří jen reálný výdej.');
        self::assertCount(1, $data['items']);
        self::assertNotSame('opening', $data['items'][0]['source_type']);
        self::assertSame(self::cents(0.00), self::cents($data['turnover_md']), 'Otevírací zápis se nesmí objevit v obratech MD.');
        self::assertSame(self::cents(1000.00), self::cents($data['turnover_d']));
        self::assertSame(self::cents(13232.00), self::cents($data['closing_balance']));
        self::assertSame(self::cents(13232.00), self::cents($data['items'][0]['balance']), 'Běžící zůstatek startuje od PS, ne od nuly.');
    }

    /**
     * Okno přes přelom roku ukazovalo uzávěrkový i otevírací zápis jako běžné
     * pohyby hotovosti — v knize se tak zůstatek propadl na nulu a zase vyskočil.
     */
    public function testClosingAndOpeningEntriesStayOutOfMovements(): void
    {
        $accountId = $this->accountId('211');
        $nextYear = self::YEAR + 1;
        $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');

        $this->manual([self::l('211', 'debit', 14232.00), self::l('602', 'credit', 14232.00)], self::YEAR . '-06-15');
        $this->technical(
            [self::l('702', 'debit', 14232.00), self::l('211', 'credit', 14232.00)],
            self::YEAR . '-12-31',
            'closing',
            $this->periodId,
            'UZ-' . self::YEAR . '-0002',
        );
        $this->technical(
            [self::l('211', 'debit', 14232.00), self::l('701', 'credit', 14232.00)],
            $nextYear . '-01-01',
            'opening',
            null,
            'OT-' . $nextYear . '-0001',
        );

        $data = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', $nextYear . '-12-31', 1, 50);

        $sourceTypes = array_column($data['items'], 'source_type');
        self::assertNotContains('closing', $sourceTypes, 'Uzavření knih není pohyb hotovosti.');
        self::assertNotContains('opening', $sourceTypes, 'Otevření knih není pohyb hotovosti.');
        self::assertSame(1, $data['total'], 'Zůstává jediný reálný příjem.');
        self::assertSame(self::cents(14232.00), self::cents($data['closing_balance']), 'V pokladně je to, co v ní reálně je.');
    }

    public function testDimensionBreakdownReconcilesOpeningAnalyticsSplitsAndRedStorno(): void
    {
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $type = $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $parent = (int) $dimensions->createValue($this->supplierId, $type, ['code' => 'S1', 'name' => 'Testovací oddělení'])['id'];
        $child = (int) $dimensions->createValue($this->supplierId, $type, ['code' => 'S2', 'name' => 'Testovací tým', 'parent_id' => $parent])['id'];
        $tag = ['dimensions' => [$type => $parent]];
        $split = ['dimension_splits' => [$type => [$parent => 0.5, $child => 0.5]]];
        $account = $this->accountId('211');
        $this->periods->create($this->supplierId, self::YEAR - 1, '2098-01-01', '2098-12-31');
        $this->manual([self::l('211', 'debit', 999.00) + $tag, self::l('602', 'credit', 999.00) + $tag], '2098-12-01');
        $this->technical([self::l('211', 'debit', 120.00) + $tag, self::l('701', 'credit', 120.00)], '2099-01-01', 'opening', null, 'OT-DIM-1');
        $this->db->pdo()->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
            SELECT supplier_id, '211.123', 'Testovací analytika', account_type, normal_side, 0, id FROM chart_of_accounts WHERE id = ?")
            ->execute([$account]);
        $this->manual([self::l('211.123', 'debit', 20.00) + ['cost_center' => 'S2'], self::l('602', 'credit', 20.00) + $tag], '2099-01-10');
        $this->manual([self::l('211.123', 'debit', 100.01) + $split, self::l('602', 'credit', 100.01) + $tag], '2099-02-10');
        $red = ['is_red_storno' => true];
        $this->manual([self::l('211.123', 'debit', 20.00) + $split + $red, self::l('602', 'credit', 20.00) + $tag + $red], '2099-02-11');
        $this->manual([self::l('518', 'debit', 7.25), self::l('211.123', 'credit', 7.25)], '2099-02-12');

        $build = fn (int $id, string $from = '2099-02-01', ?AccountStatementScope $scope = null, int $page = 1): array => $this->statement->build(
            $this->supplierId, $id, $from, '2099-02-28', $page, 1, scope: $scope, groupByDimensionType: $type,
        );
        $data = $build($account);
        $breakdown = $data['dimension_breakdown'];
        self::assertTrue($breakdown['matches']);
        self::assertSame($type, $breakdown['type_id']);
        self::assertSame($breakdown, $build($account, page: 2)['dimension_breakdown']);
        self::assertSame([120.00, 20.00, 0.00], array_column($breakdown['rows'], 'opening_balance_signed'));
        self::assertSame([$parent, $child, null], array_column($breakdown['rows'], 'value_id'));
        self::assertSame([40.00, 40.01, 0.00], array_column($breakdown['rows'], 'turnover_md'));
        self::assertSame([0.00, 0.00, 7.25], array_column($breakdown['rows'], 'turnover_d'));
        self::assertSame([
            'opening_balance_signed' => 140.00, 'turnover_md' => 80.01, 'turnover_d' => 7.25, 'closing_balance_signed' => 212.76,
        ], $breakdown['totals']);

        $january = $build($account, '2099-01-01')['dimension_breakdown'];
        self::assertTrue($january['matches']);
        self::assertSame(120.00, $january['totals']['opening_balance_signed']);
        self::assertSame(100.01, $january['totals']['turnover_md']);

        $branch = $build($account, scope: new AccountStatementScope(['dimension' => $dimensions->filter($this->supplierId, $parent)]))['dimension_breakdown'];
        self::assertTrue($branch['matches']);
        self::assertSame([$parent, $child, null], array_column($branch['rows'], 'value_id'));
        self::assertSame(220.01, $branch['totals']['closing_balance_signed']);

        $exact = $build($account, scope: new AccountStatementScope(['dimension' => $dimensions->filter($this->supplierId, $parent, false)]))['dimension_breakdown'];
        self::assertTrue($exact['matches']);
        self::assertSame([$parent, null], array_column($exact['rows'], 'value_id'));
        self::assertSame(160.00, $exact['totals']['closing_balance_signed']);

        $own = $build($account, scope: new AccountStatementScope(withAnalytics: false))['dimension_breakdown'];
        self::assertTrue($own['matches']);
        self::assertSame(120.00, $own['totals']['closing_balance_signed']);

        $revenue = $build($this->accountId('602'))['dimension_breakdown'];
        self::assertTrue($revenue['matches']);
        self::assertSame(20.00, $revenue['totals']['opening_balance_signed']);
        self::assertSame(100.01, $revenue['totals']['closing_balance_signed']);
    }

    public function testDimensionBreakdownAllocatesTheRoundedAmountOfCombinedFilters(): void
    {
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko', 'projekt']);
        $center = $types['cost_center'];
        $project = $types['project'];
        $value = fn (int $type, string $code): int => (int) $dimensions->createValue($this->supplierId, $type, ['code' => $code, 'name' => $code])['id'];
        $first = $value($center, 'ST-A');
        $second = $value($center, 'ST-B');
        $selected = $value($project, 'PR-A');
        $other = $value($project, 'PR-B');
        $this->manual([
            self::l('518', 'debit', 0.02) + ['dimension_splits' => [$center => [$first => 0.5, $second => 0.5], $project => [$selected => 0.5, $other => 0.5]]],
            self::l('321', 'credit', 0.02),
        ], '2099-03-01');

        $scope = new AccountStatementScope(['dimension' => $dimensions->filters($this->supplierId, [$first, $second, $selected])]);
        $data = $this->statement->build(
            $this->supplierId, $this->accountId('518'), '2099-01-01', '2099-12-31', 1, 50,
            scope: $scope, groupByDimensionType: $center,
        );
        $breakdown = $data['dimension_breakdown'];
        self::assertTrue($breakdown['matches']);
        self::assertSame(0.01, $data['turnover_md']);
        self::assertSame($data['turnover_md'], $breakdown['totals']['turnover_md']);
        self::assertSame([0.00, 0.01, 0.00], array_column($breakdown['rows'], 'turnover_md'));
    }

    public function testDimensionBreakdownRejectsForeignTypesAndKeepsSharedValuesTenantScoped(): void
    {
        $foreign = $this->newSupplier('Jiná syntetická firma');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($foreign);
        $this->periods->create($foreign, self::YEAR, '2099-01-01', '2099-12-31');
        $dimensions = $this->container->get(DimensionService::class);
        $repo = $this->container->get(DimensionRepository::class);
        $group = $repo->createGroup('Syntetická skupina pro opis');
        foreach ([$this->supplierId, $foreign] as $supplierId) {
            $repo->setSupplierGroup($supplierId, $group);
            $dimensions->setEnabled($supplierId, true);
        }
        $shared = (int) $dimensions->createType($foreign, ['code' => 'shared', 'name' => 'Společný projekt', 'level' => 'global'])['id'];
        $private = (int) $dimensions->createType($foreign, ['code' => 'private', 'name' => 'Cizí projekt'])['id'];
        $value = (int) $dimensions->createValue($foreign, $shared, ['code' => 'SYN', 'name' => 'Testovací projekt'])['id'];
        $this->posting->postDocument($foreign, 'manual', null, [
            self::l('211', 'debit', 999.00) + ['dimensions' => [$shared => $value]],
            self::l('602', 'credit', 999.00),
        ], ['entry_date' => '2099-03-01', 'posted_by' => $this->userId]);

        $this->manual([self::l('211', 'debit', 12.00) + ['dimensions' => [$shared => $value]], self::l('602', 'credit', 12.00)], '2099-03-01');
        $action = $this->container->get(AccountStatementAction::class);
        $args = ['accountId' => (string) $this->accountId('211')];
        $query = ['from' => '2099-01-01', 'to' => '2099-12-31'];
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/accounting/reports/account-statement/' . $args['accountId'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        $bad = $action->get($request->withQueryParams($query + ['group_by_dimension_type' => ['1']]), new Response(), $args);
        self::assertSame(422, $bad->getStatusCode());
        $hidden = $action->get($request->withQueryParams($query + ['group_by_dimension_type' => (string) $private]), new Response(), $args);
        self::assertSame(404, $hidden->getStatusCode());
        $grouped = $query + ['group_by_dimension_type' => (string) $shared];
        $ok = $action->get($request->withQueryParams($grouped), new Response(), $args);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $data = json_decode((string) $ok->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $breakdown = ($data['data'] ?? $data)['dimension_breakdown'];
        self::assertTrue($breakdown['matches']);
        self::assertSame([$value, null], array_column($breakdown['rows'], 'value_id'));
        self::assertSame(12, $breakdown['totals']['turnover_md']);

        $export = $action->export($request->withQueryParams($grouped + ['format' => 'xlsx', 'dimension_value_id' => (string) $value]), new Response(), $args);
        self::assertSame(200, $export->getStatusCode(), $export->getStatusCode() === 200 ? '' : (string) $export->getBody());
        self::assertStringStartsWith('PK', (string) $export->getBody());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function newSupplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id, "double_entry"
               FROM supplier WHERE id = ?'
        )->execute([$name, 'izolace@example.com', $this->supplierId]);
        return (int) $pdo->lastInsertId();
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Technický zápis (uzavření / otevření knih). Nejde poslat přes `manual()` —
     * `source_type` je parametr postDocument(), ne meta, a účty 701/702 smí
     * PostingService zaúčtovat jen v uzávěrkovém zápisu.
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function technical(array $lines, string $date, string $sourceType, ?int $sourceId, string $documentNo): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            $sourceType,
            $sourceId,
            $lines,
            [
                'entry_date' => $date,
                'document_no' => $documentNo,
                'posted_by' => $this->userId,
                'user_id' => $this->userId,
            ],
        );
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @param array<string,mixed> $meta
     */
    private function manual(array $lines, string $date, array $meta = []): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            array_merge(['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId], $meta),
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $code, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-01-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
