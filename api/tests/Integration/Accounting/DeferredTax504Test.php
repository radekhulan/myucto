<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\{ClosingException, DeferredTax504Service};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PHPUnit\Framework\TestCase;

final class DeferredTax504Test extends TestCase
{
    private Connection $db;
    private int $supplier;
    private int $period;
    private array $dimensions;
    private ?string $failureTrigger = null;
    private bool $committed = false;
    private ?\Psr\Container\ContainerInterface $other = null;
    private int $originalIsolation;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->originalIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        if ($this->name() === 'testFirstAdoptionLateFailureRollsBackBothBatchesAndPendingWithinCallerTransaction') {
            $this->failureTrigger = 'deferred_step_' . bin2hex(random_bytes(6));
            $pdo->exec('CREATE TRIGGER ' . $this->failureTrigger . " BEFORE UPDATE ON accounting_closing_steps FOR EACH ROW
                BEGIN IF @myucto_fail_deferred_step=1 AND NEW.step_key='deferred_tax' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.payload,'$.deferred504_pending.status'))='written'
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic deferred CAS failure'; END IF; END");
        }
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic deferred tax','Test 1','Test','10000','deferred-tax@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2096, '2096-07-01', '2097-06-30');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-07-01','cz_504')")->execute([$this->supplier]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier, '2097-06-30');
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2097-06-30');
        $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$catalog['activity_kind']['id'], $this->supplier, 'synthetic_deferred', 'Synthetic deferred activity']);
        $this->dimensions = [$catalog['activity']['id'] => $catalog['activity']['values']['economic'], $catalog['activity_kind']['id'] => (int) $pdo->lastInsertId(), $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->other !== null) $this->other->get(Connection::class)->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries'] as $table) $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
        $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->originalIsolation);
        $this->db->pdo()->exec('SET @myucto_fail_deferred_step=NULL');
        if ($this->failureTrigger !== null) $this->db->pdo()->exec('DROP TRIGGER ' . $this->failureTrigger);
    }

    public function testDefaultIsOffAndExplicitMethodPreparesAccountsAndPostsGrossAmounts(): void
    {
        $service = Bootstrap::buildContainer()->get(DeferredTax504Service::class);
        self::assertFalse($service->preview($this->supplier, $this->period)['enabled']);
        self::assertSame(0, $this->countAccounts());
        $result = $service->run($this->supplier, $this->period, $this->body(), $this->version());
        self::assertSame(3, $this->countAccounts());
        self::assertCount(1, $result['entry_ids']);
        $stmt = $this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.id');
        $stmt->execute([$result['entry_ids'][0]]);
        self::assertSame([['account_code' => '592', 'side' => 'debit', 'amount' => '210.00'], ['account_code' => '959.100', 'side' => 'credit', 'amount' => '210.00'], ['account_code' => '349', 'side' => 'debit', 'amount' => '105.00'], ['account_code' => '592', 'side' => 'credit', 'amount' => '105.00']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $preview = $service->preview($this->supplier, $this->period);
        self::assertSame(6300, $preview['assessment']['closing']['unrecognized_asset_cents']);
        self::assertSame('504-deferred-own-v1', $preview['method']['version']);
    }

    private function enableDatedAccountDefinitions(): void
    {
        $p=$this->db->pdo();$p->prepare("UPDATE accounting_periods SET status='open' WHERE id=?")->execute([$this->period]);
        $q=$p->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='518'");$q->execute([$this->supplier]);$id=(int)$q->fetchColumn();$definitions=new \MyInvoice\Repository\AccountDefinitionRepository($this->db);
        $definitions->change($this->supplier,$id,'2096-07-01',['name'=>'Synthetic dated deferred account context'],$definitions->stateHash($this->supplier,$id),null,'Synthetic explicit account history');
        $p->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
    }

    public function testDatedFirstAdoptionAccountsExistAtTheActualOpeningDate(): void
    {
        $this->enableDatedAccountDefinitions();$body=$this->body();$body['method']['first_adoption']=true;$body['method']['first_adoption_reason']='Synthetic initial temporary differences';$body['opening_titles']=$body['titles'];
        $result=Bootstrap::buildContainer()->get(DeferredTax504Service::class)->run($this->supplier,$this->period,$body,$this->version());self::assertCount(1,$result['entry_ids']);
        $q=$this->db->pdo()->prepare('SELECT entry_date FROM journal_entries WHERE id=? AND supplier_id=?');$q->execute([$result['entry_ids'][0],$this->supplier]);self::assertSame('2096-07-01',$q->fetchColumn());
        $definitions=new \MyInvoice\Repository\AccountDefinitionRepository($this->db);$rows=array_column($definitions->listForDate($this->supplier,'2096-07-01'),null,'account_code');
        foreach(['349','592','959.100']as$code)self::assertArrayHasKey($code,$rows,'An opening account is missing at its actual posting date.');
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_lines l LEFT JOIN '.\MyInvoice\Repository\AccountDefinitionRepository::relation('2096-07-01',$this->supplier).' a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.entry_id=? AND a.id IS NULL');$q->execute([$result['entry_ids'][0]]);self::assertSame(0,(int)$q->fetchColumn());
    }

    public function testFirstAdoptionCannotUseAccountsBornOnlyAtPeriodEnd(): void
    {
        $this->enableDatedAccountDefinitions();$chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($this->supplier,'959','2097-06-30');
        foreach([['349','asset','debit',null,'deductible'],['592','expense','debit',null,'non_deductible'],['959.100','liability','credit',(int)$parent['id'],'deductible']]as[$code,$type,$side,$parentId,$tax])$chart->insert($this->supplier,['account_code'=>$code,'name'=>'Synthetic future deferred account','account_type'=>$type,'normal_side'=>$side,'parent_id'=>$parentId,'tax_deductibility'=>$tax,'is_synthetic'=>$parentId===null],'2097-06-30');
        $body=$this->body();$body['prepare_accounts']=false;$body['method']['first_adoption']=true;$body['method']['first_adoption_reason']='Synthetic initial temporary differences';$body['opening_titles']=$body['titles'];
        try{Bootstrap::buildContainer()->get(DeferredTax504Service::class)->run($this->supplier,$this->period,$body,$this->version());self::fail('The opening used accounts before their effective birth.');}catch(ClosingException $e){self::assertSame('deferred_tax_accounts_required',$e->errorCode);}self::assertSame(0,$this->journalCount());
    }

    public function testMissingActivityRollsBackAccountPreparationAndPendingDecision(): void
    {
        $body = $this->body();
        foreach ($body['titles'] as &$title) $title['dimensions'] = [array_keys($this->dimensions)[1] => array_values($this->dimensions)[1]];
        unset($title);
        $version = $this->version();
        try { Bootstrap::buildContainer()->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $body, $version); self::fail('Activity is mandatory.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('dimension_required', $e->errorCode); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($version, $this->version());
        self::assertSame(0, $this->countAccounts());
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id=? AND step_key='deferred_tax'");
        $stmt->execute([$this->supplier]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testRetiredHistoricalCellCanBeReducedButNotIncreased(): void
    {
        $service = Bootstrap::buildContainer()->get(DeferredTax504Service::class);
        $service->run($this->supplier, $this->period, $this->body(), $this->version());
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=?')->execute([array_values($this->dimensions)[1]]);
        $body = $this->body();
        $body['titles'][0]['difference'] = '500.00';
        $body['titles'][1]['recognized_difference'] = '200.00';
        $result = $service->run($this->supplier, $this->period, $body, $this->version());
        self::assertCount(1, $result['entry_ids']);
        self::assertSame(10500, $service->preview($this->supplier, $this->period)['assessment']['closing']['liability_cents']);
        $body['titles'][0]['difference'] = '600.00';
        try { $service->run($this->supplier, $this->period, $body, $this->version()); self::fail('New retired assignment is not a historical reduction.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('dimension_closed', $e->errorCode); }
    }

    public function testNewAmountsMustSatisfyCustomMandatoryDimensionRules(): void
    {
        $container = Bootstrap::buildContainer();
        $dimensions = $container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $dimensions->setEnabled($this->supplier, true);
        $types = $dimensions->ensureDefaultTypes($this->supplier, ['stredisko']);
        $container->get(\MyInvoice\Service\Accounting\Dimension\DimensionRuleService::class)->create($this->supplier,
            ['dimension_type_id' => $types['cost_center'], 'account_mask' => '592', 'enforcement' => 'error']);
        $version = $this->version();
        try { $container->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $this->body(), $version); self::fail('Custom mandatory rule was bypassed.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('dimension_required', $e->errorCode); }
        self::assertSame(0, $this->countAccounts());
        self::assertSame($version, $this->version());
    }

    public function testDateLockRejectsFirstAdoptionBatchAtomically(): void
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings(supplier_id,locked_until) VALUES (?,?) ON DUPLICATE KEY UPDATE locked_until=VALUES(locked_until)')
            ->execute([$this->supplier, '2096-07-01']);
        $body = $this->body();
        $body['method']['first_adoption'] = true;
        $body['method']['first_adoption_reason'] = 'Synthetic prior differences, separate decision';
        $body['opening_titles'] = $body['titles'];
        $version = $this->version();
        try { Bootstrap::buildContainer()->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $body, $version); self::fail('Opening date lock was bypassed.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('date_locked', $e->errorCode); }
        self::assertSame(0, $this->countAccounts());
        self::assertSame($version, $this->version());
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testMixedHistoricalPeriodCannotUseOnlyFinalFramework(): void
    {
        $body = $this->body();
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-10-01','cz_500'),(?,'2097-01-01','cz_504')")
            ->execute([$this->supplier, $this->supplier]);
        try { Bootstrap::buildContainer()->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $body, $this->version()); self::fail('Mixed period was accepted.'); }
        catch (ClosingException $e) { self::assertSame('accounting_framework_operation_not_supported', $e->errorCode); }
        self::assertSame(0, $this->countAccounts());
    }

    public function testStatementsIncludeGrossBalancesAndSeparateCurrentAndDeferredAmounts(): void
    {
        $container = Bootstrap::buildContainer();
        $container->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $this->body(), $this->version());
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null,
            [['account_code' => '591', 'side' => 'debit', 'amount' => 50, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
             ['account_code' => '341', 'side' => 'credit', 'amount' => 50, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)]],
            ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $reports = $container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class);
        foreach (['full', 'small'] as $scope) {
            $pl = $reports->incomeStatement($this->supplier, $this->period, '2097-06-30', $scope);
            self::assertSame(-155.0, $pl['checks']['profit_current']);
            self::assertTrue($pl['checks']['profit_matches']);
            $rows = array_column($pl['rows'], null, 'row_code');
            $row = $rows[$scope === 'full' ? 'UT.38' : 'UT.37'];
            self::assertSame(155.0, $row['total']);
            self::assertSame([50.0, 105.0], array_column($row['components'], 'total'));
            $bs = $reports->balanceSheet($this->supplier, $this->period, '2097-06-30', $scope);
            self::assertTrue($bs['checks']['balanced']);
            self::assertSame(105.0, $bs['totals']['assets_end']);
            self::assertSame(105.0, $bs['totals']['liabilities_end']);
            $assets = array_column($bs['assets'], null, 'row_code');
            self::assertSame([0.0, 105.0], array_column($assets[$scope === 'full' ? 'UR.60' : 'UR.52']['components'], 'end'));
            $liabilities = array_column($bs['liabilities'], null, 'row_code');
            self::assertSame([0.0, 210.0], array_column($liabilities[$scope === 'full' ? 'US.20' : 'US.13']['components'], 'end'));
            self::assertSame('504-deferred-own-v1', $bs['deferred_tax_method']['version']);
        }
    }

    public function testNotesAlwaysDiscloseConfirmedMethodAndRecoverableVersusUnrecognizedAsset(): void
    {
        $container = Bootstrap::buildContainer();
        $notes = $container->get(\MyInvoice\Service\Accounting\Reports\StatementNotesService::class);
        $before = $notes->build($this->supplier, $this->period);
        self::assertNotContains('n504_deferred_tax_method', array_column($before['sections'], 'key'));
        $container->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $this->body(), $this->version());
        $after = $notes->build($this->supplier, $this->period);
        $sections = array_column($after['sections'], null, 'key');
        self::assertArrayHasKey('n504_deferred_tax_method', $sections);
        $section = $sections['n504_deferred_tax_method'];
        self::assertTrue($section['readonly']);
        self::assertTrue($section['filled']);
        foreach (['SYN-METHOD', '210,00', '105,00', '63,00', 'SYN-DTA', 'Synthetic probable future taxable base'] as $text) self::assertStringContainsString($text, $section['content']);
    }

    public function testWithoutOptInOrdinary959AnalyticKeepsOriginalStatementShape(): void
    {
        $container = Bootstrap::buildContainer();
        $stmt = $this->db->pdo()->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type,normal_side,parent_id) SELECT supplier_id,'959.100','Synthetic ordinary liability','liability','credit',id FROM chart_of_accounts WHERE supplier_id=? AND account_code='959'");
        $stmt->execute([$this->supplier]);
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null,
            [['account_code' => '221', 'side' => 'debit', 'amount' => 10], ['account_code' => '959.100', 'side' => 'credit', 'amount' => 10]],
            ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $data = $container->get(\MyInvoice\Repository\Statement504DataRepository::class);
        $balances = $data->balances($this->supplier, '2096-07-01', '2097-06-30');
        $expected = (new \MyInvoice\Service\Accounting\Reports\Statement504Builder())->balanceSheet($balances, [], 'full');
        $actual = $container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class)->balanceSheet($this->supplier, $this->period, '2097-06-30', 'full');
        self::assertArrayNotHasKey('deferred_tax_method', $actual);
        foreach ($expected as $key => $value) self::assertSame(serialize($value), serialize($actual[$key]));
        self::assertFalse(Bootstrap::buildContainer()->get(DeferredTax504Service::class)->preview($this->supplier, $this->period)['enabled']);
    }

    public function testFirstAdoptionSeparates932And592AndTypedRetryDoesNotDuplicate(): void
    {
        $container = Bootstrap::buildContainer();
        $body = $this->firstAdoption();
        $version = $this->version();
        $result = $container->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $body, $version);
        self::assertCount(2, $result['entry_ids']);
        $stmt = $this->db->pdo()->prepare('SELECT e.entry_date,a.account_code,l.side,l.amount FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.id=? ORDER BY l.id');
        $stmt->execute([$result['entry_ids'][0]]);
        $opening = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['932', '959.100'], array_column($opening, 'account_code'));
        self::assertSame(['2096-07-01', '2096-07-01'], array_column($opening, 'entry_date'));
        self::assertSame(['105.00', '105.00'], array_column($opening, 'amount'));
        self::assertSame($result, $container->get(\MyInvoice\Repository\JournalEntryRepository::class)->insertCurrentDeferredTax504($this->supplier, $this->period, $version));
        self::assertSame(2, $this->journalCount());
        self::assertSame(21000, $container->get(DeferredTax504Service::class)->preview($this->supplier, $this->period)['assessment']['closing']['liability_cents']);
    }

    public function testFirstAdoptionLateFailureRollsBackBothBatchesAndPendingWithinCallerTransaction(): void
    {
        $this->db->pdo()->exec('SET @myucto_fail_deferred_step=1');
        $version = $this->version();
        try { Bootstrap::buildContainer()->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $this->firstAdoption(), $version); self::fail('Late failure must abort the complete decision.'); }
        catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($version, $this->version());
        self::assertSame(0, $this->journalCount());
        self::assertSame(0, $this->countAccounts());
        self::assertFalse(Bootstrap::buildContainer()->get(DeferredTax504Service::class)->preview($this->supplier, $this->period)['enabled']);
    }

    public function testActualCloseAndOpeningPreserveRetiredGrossCellsForLaterReduction(): void
    {
        $container = Bootstrap::buildContainer();
        $deferred = $container->get(DeferredTax504Service::class);
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null,
            [['account_code' => '221', 'side' => 'debit', 'amount' => 105, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
             ['account_code' => '602', 'side' => 'credit', 'amount' => 105, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)]], ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $closing = $container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        $closing->saveInventory($this->supplier, $this->period, $this->version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $this->version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $this->version());
        $fx = $closing->fxPreview($this->supplier, $this->period);
        $closing->runFxRevaluation($this->supplier, $this->period, [], $this->version(), [], ['snapshot_hash' => $fx['snapshot_hash']]);
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=?')->execute([array_values($this->dimensions)[1]]);
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        self::assertSame(0.0, $closing->closeBooks($this->supplier, $this->period, $this->version())['profit']);
        $closing->openNext($this->supplier, $this->period, $this->version());
        $this->period = (int) $container->get(AccountingPeriodRepository::class)->findForDate($this->supplier, '2097-07-01')['id'];
        $closing->start($this->supplier, $this->period, $this->version());
        $body = $this->body();
        $body['titles'][0]['difference'] = '500.00';
        $body['titles'][1]['recognized_difference'] = '200.00';
        $result = $deferred->run($this->supplier, $this->period, $body, $this->version());
        self::assertCount(1, $result['entry_ids']);
        $preview = $deferred->preview($this->supplier, $this->period);
        self::assertSame(['asset' => 4200, 'liability' => 10500], array_column($preview['balances'], 'balance_cents', 'kind'));
    }

    public function testStructuredSessionPreviewDoesNotWriteAndRunUsesTheSameDecision(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\ClosingAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])->withParsedBody($this->body());
        foreach ([null, 'bearer'] as $method) {
            $response = $action->deferredTax504Preview($request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $method), new \Slim\Psr7\Response(), ['id' => $this->period]);
            self::assertSame(403, $response->getStatusCode());
        }
        $request = $request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session');
        $response = $action->deferredTax504Preview($request, new \Slim\Psr7\Response(), ['id' => $this->period]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('21000', (string) $response->getBody());
        self::assertSame(0, $this->countAccounts());
        self::assertSame(0, $this->journalCount());
        $response = $action->deferredTax504Preview($request->withParsedBody(['titles' => []]), new \Slim\Psr7\Response(), ['id' => $this->period]);
        self::assertSame(422, $response->getStatusCode());
        $response = $action->runStep($request->withParsedBody(['row_version' => $this->version(), 'decision_504' => $this->body()]), new \Slim\Psr7\Response(), ['id' => $this->period, 'step' => 'deferred_tax']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->journalCount());
        self::assertTrue(Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)->deferredTaxPreview($this->supplier, $this->period)['enabled']);
    }

    public function testCurrentSourceAfterRepeatableReadSnapshotChangesActualMovement(): void
    {
        $this->concurrentDecision(false);
    }

    public function testCurrentSourceUnderSnapshotIsolationAbortsWholeCallerTransaction(): void
    {
        $this->concurrentDecision(true);
    }

    public function testActualReversalRemovesRecognizedAssessmentAndAllowsExplicitReapplication(): void
    {
        $container = Bootstrap::buildContainer();
        $service = $container->get(DeferredTax504Service::class);
        $result = $service->run($this->supplier, $this->period, $this->body(), $this->version());
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $result['entry_ids'][0], ['allow_closing_period' => true]);
        $preview = $service->preview($this->supplier, $this->period);
        self::assertTrue($preview['enabled']);
        self::assertSame([], $preview['balances']);
        self::assertSame(0, $preview['assessment']['closing']['asset_cents']);
        self::assertSame(0, $preview['assessment']['closing']['unrecognized_asset_cents']);
        self::assertSame([], $preview['decision']['titles']);
        $again = $service->run($this->supplier, $this->period, $this->body(), $this->version());
        self::assertCount(1, $again['entry_ids']);
        self::assertSame(10500, $service->preview($this->supplier, $this->period)['assessment']['closing']['asset_cents']);
    }

    public function testReversedFirstAdoptionClosingBatchLeavesOnlyEvidencedOpeningAssessment(): void
    {
        $container = Bootstrap::buildContainer();
        $service = $container->get(DeferredTax504Service::class);
        $result = $service->run($this->supplier, $this->period, $this->firstAdoption(), $this->version());
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $result['entry_ids'][1], ['allow_closing_period' => true]);
        $preview = $service->preview($this->supplier, $this->period);
        self::assertSame(10500, $preview['assessment']['closing']['liability_cents']);
        self::assertSame(0, $preview['assessment']['closing']['asset_cents']);
        self::assertSame(0, $preview['assessment']['closing']['unrecognized_asset_cents']);
        self::assertFalse($preview['decision']['method']['first_adoption']);
        $again = $service->run($this->supplier, $this->period, $this->body(), $this->version());
        self::assertCount(1, $again['entry_ids']);
        self::assertSame(21000, $service->preview($this->supplier, $this->period)['assessment']['closing']['liability_cents']);
    }

    public function testPriorYearMethodCannotCloseNextBooksWithoutCurrentAssessment(): void
    {
        $container = Bootstrap::buildContainer();
        $deferred = $container->get(DeferredTax504Service::class);
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null,
            [['account_code' => '221', 'side' => 'debit', 'amount' => 105, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
             ['account_code' => '602', 'side' => 'credit', 'amount' => 105, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)]], ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $closing = $container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        $closing->saveInventory($this->supplier, $this->period, $this->version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $this->version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $this->version());
        $fx = $closing->fxPreview($this->supplier, $this->period);
        $closing->runFxRevaluation($this->supplier, $this->period, [], $this->version(), [], ['snapshot_hash' => $fx['snapshot_hash']]);
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closing->closeBooks($this->supplier, $this->period, $this->version());
        $closing->openNext($this->supplier, $this->period, $this->version());
        $this->period = (int) $container->get(AccountingPeriodRepository::class)->findForDate($this->supplier, '2097-07-01')['id'];
        $closing->start($this->supplier, $this->period, $this->version());
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        $closing->saveInventory($this->supplier, $this->period, $this->version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $this->version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $this->version());
        $fx = $closing->fxPreview($this->supplier, $this->period);
        $closing->runFxRevaluation($this->supplier, $this->period, [], $this->version(), [], ['snapshot_hash' => $fx['snapshot_hash']]);
        $count = $this->journalCount();
        try { $closing->closeBooks($this->supplier, $this->period, $this->version()); self::fail('Prior-year assessment allowed closing next books.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_assessment_required', $e->errorCode); }
        self::assertSame($count, $this->journalCount());
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        self::assertSame(0.0, $closing->closeBooks($this->supplier, $this->period, $this->version())['profit']);
    }

    public function testUnboundAnalyticCannotBePresentedAsCurrentTaxUnderTheConfirmedMethod(): void
    {
        $container = Bootstrap::buildContainer();
        $service = $container->get(DeferredTax504Service::class);
        $service->run($this->supplier, $this->period, $this->body(), $this->version());
        $stmt = $this->db->pdo()->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type,normal_side,parent_id) SELECT supplier_id,'349.100','Synthetic unbound asset','asset','debit',id FROM chart_of_accounts WHERE supplier_id=? AND account_code='349'");
        $stmt->execute([$this->supplier]);
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null,
            [['account_code' => '349.100', 'side' => 'debit', 'amount' => 1], ['account_code' => '221', 'side' => 'credit', 'amount' => 1]],
            ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        try {
            $container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class)->balanceSheet($this->supplier, $this->period, '2097-06-30', 'full');
            self::fail('An unbound analytic was silently presented as ordinary income-tax receivable.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
    }

    private function concurrentDecision(bool $isolation): void
    {
        $service = Bootstrap::buildContainer()->get(DeferredTax504Service::class);
        $service->run($this->supplier, $this->period, $this->body(), $this->version());
        $body = $this->body();
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($isolation ? 'ON' : 'OFF'));
        $pdo->beginTransaction();
        $pdo->query('SELECT payload FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchAll();
        $this->other = Connection::withoutSharedTestConnection(static function (): \Psr\Container\ContainerInterface {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->other->get(Connection::class)->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $otherBody = $body;
        $otherBody['titles'][0]['difference'] = '500.00';
        $this->other->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $otherBody, $this->version());
        $version = (int) $this->other->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        try {
            $result = $service->run($this->supplier, $this->period, $body, $version);
            self::assertFalse($isolation);
            self::assertCount(1, $result['entry_ids']);
            $stmt = $pdo->prepare("SELECT l.side,l.signed_amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND a.account_code='959.100' ORDER BY l.id FOR UPDATE");
            $stmt->execute([$this->supplier]);
            self::assertSame(21000, array_sum(array_map(static fn (array $line): int => (int) round((float) $line['signed_amount'] * 100) * ($line['side'] === 'credit' ? 1 : -1), $stmt->fetchAll(\PDO::FETCH_ASSOC))));
            self::assertTrue($pdo->inTransaction());
        } catch (\PDOException $e) {
            self::assertTrue($isolation);
            self::assertSame(1020, (int) $e->errorInfo[1]);
            self::assertFalse($pdo->inTransaction());
        }
    }


    public function testSourceAssessmentUsesJ6AndInvalidatesAfterProfitAndLossChanges(): void
    {
        $container = Bootstrap::buildContainer();
        $service = $container->get(DeferredTax504Service::class);
        $this->postIncome('100.00');
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $body = $this->body();
        $service->run($this->supplier, $this->period, $body, $this->version());
        self::assertTrue($service->closingCheck($this->supplier, $this->period)['complete']);
        self::assertTrue($container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class)->closingCheck($this->supplier, $this->period)['complete']);
        $this->postIncome('10.00');
        self::assertFalse($service->closingCheck($this->supplier, $this->period)['complete']);
        self::assertFalse($container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class)->closingCheck($this->supplier, $this->period)['complete']);
        $count = $this->journalCount();
        try { $service->run($this->supplier, $this->period, $body, $this->version()); self::fail('Stale source input must be rejected.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
        self::assertSame($count, $this->journalCount());
        $result = $service->run($this->supplier, $this->period, $this->body(), $this->version());
        self::assertSame([], $result['entry_ids']);
        self::assertTrue($service->closingCheck($this->supplier, $this->period)['complete']);
        self::assertFalse($container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class)->closingCheck($this->supplier, $this->period)['complete']);
    }

    public function testProofRejectsChangedPendingDecisionInSameTransaction(): void
    {
        $proof = $this->pendingProof();
        $batch = $proof->batch(0);
        $policy = new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db);
        $policy->assertDeferredTax504($batch);
        $this->db->pdo()->prepare("UPDATE accounting_closing_steps SET payload=JSON_SET(payload,'$.deferred504_pending.body.titles[0].difference','1100.00') WHERE supplier_id=? AND period_id=? AND step_key='deferred_tax'")->execute([$this->supplier, $this->period]);
        try { $policy->assertDeferredTax504($batch); self::fail('Changed pending evidence must invalidate the old proof.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
    }

    public function testProofRejectsDifferentTransactionEpoch(): void
    {
        $batch = $this->pendingProof()->batch(0);
        $this->db->pdo()->commit();
        $this->committed = true;
        $this->db->pdo()->beginTransaction();
        try { (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->assertDeferredTax504($batch); self::fail('Proof from committed transaction must not authorize new writes.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
    }

    private function pendingProof(): \MyInvoice\Service\Accounting\Closing\DeferredTax504Proof
    {
        $container = Bootstrap::buildContainer();
        $result = $container->get(DeferredTax504Service::class)->run($this->supplier, $this->period, $this->body(), $this->version());
        $stmt = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='deferred_tax'");
        $stmt->execute([$this->supplier, $this->period]);
        $payload = json_decode($stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        $payload['deferred504_pending']['status'] = 'pending';
        $payload['deferred504_pending']['expected_version'] = $this->version();
        unset($payload['deferred504_decisions']);
        foreach ($result['entry_ids'] as $id) $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE supplier_id=? AND id=?')->execute([$this->supplier, $id]);
        $this->db->pdo()->prepare("UPDATE accounting_closing_steps SET payload=? WHERE supplier_id=? AND period_id=? AND step_key='deferred_tax'")->execute([json_encode($payload, JSON_THROW_ON_ERROR), $this->supplier, $this->period]);
        return \MyInvoice\Service\Accounting\Closing\DeferredTax504Proof::forPending($this->db, $container->get(\MyInvoice\Repository\JournalEntryRepository::class), $this->supplier, $this->period, $this->version());
    }

    private function postIncome(string $amount): void
    {
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null,
            [['account_code' => '221', 'side' => 'debit', 'amount' => $amount, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
             ['account_code' => '602', 'side' => 'credit', 'amount' => $amount, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)]],
            ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
    }

    public function testHistoricalReductionCarriesRetiredPropertyTupleButIncreaseIsRejected(): void
    {
        $fixture = $this->propertyFixture();
        $container = Bootstrap::buildContainer();
        $service = $container->get(DeferredTax504Service::class);
        $service->run($this->supplier, $this->period, $this->body(), $this->version());
        foreach ($fixture['property'] as $row) $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $row['dimension_value_id']]);
        $body = $this->body();
        $body['titles'][0]['difference'] = '500.00';
        $body['titles'][1]['recognized_difference'] = '200.00';
        $result = $service->run($this->supplier, $this->period, $body, $this->version());
        self::assertCount(1, $result['entry_ids']);
        $actual = (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplier, $result['entry_ids'][0], true);
        self::assertCount(4, $actual);
        foreach ($actual as $tuple) { ksort($tuple); self::assertSame($this->dimensions, $tuple); }
        $body['titles'][0]['difference'] = '600.00';
        try { $service->run($this->supplier, $this->period, $body, $this->version()); self::fail('A new increase cannot reuse the retired tuple.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('property_dimension_unavailable', $e->errorCode); }
    }

    public function testProofPropertyConsumerRejectsDifferentHeaderAndAmounts(): void
    {
        $proof = $this->pendingProof()->batch(0);
        $policy = new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db);
        $policy->assertDeferredTax504Journal($proof->header, $proof->lines, $proof);
        $lines = $proof->lines;
        $lines[0]['amount'] = '210.01';
        try { $policy->assertDeferredTax504Journal($proof->header, $lines, $proof); self::fail('Amount must match current proof.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
        $header = $proof->header;
        ++$header['supplier_id'];
        try { $policy->assertDeferredTax504Journal($header, $proof->lines, $proof); self::fail('Tenant must match current proof.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
    }

    public function testProofDoesNotSurviveRollbackBeforeItsCreation(): void
    {
        $this->db->pdo()->exec('SAVEPOINT before_deferred_proof');
        $proof = $this->pendingProof()->batch(0);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_deferred_proof');
        try { $proof->assertConnection($this->db); self::fail('Rolled back proof must not be reusable.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_source_changed', $e->errorCode); }
    }

    private function propertyFixture(): array
    {
        $pdo = $this->db->pdo();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $profile = new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db);
        $profile->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2096-07-01T00:00:00+02:00',
            'enabled' => true, 'reason' => 'Synthetic property activity', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'asset-property-active'], $actor);
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT ?,'Synthetic owner','Synthetic 1','Synthetic','10000',country_id,'asset-owner@example.test',default_currency_id FROM supplier WHERE id=?")
            ->execute([$this->supplier, $this->supplier]);
        $client = (int) $pdo->lastInsertId();
        $registry = new \MyInvoice\Repository\Property\PropertyRegistryRepository($this->db);
        $request = static fn (string $code, array $state): array => ['code' => $code, 'expected_revision' => 0,
            'effective_from' => '2096-07-01T00:00:00+02:00', 'status' => 'confirmed', 'state' => $state,
            'reason' => 'Synthetic registry', 'title_ref' => 'Synthetic deed', 'idempotency_key' => $code];
        $house = $registry->create($this->supplier, 'house', $request('ASSET-HOUSE', ['name' => 'Synthetic house', 'active' => true,
            'address' => ['street' => 'Synthetic 1', 'city' => 'Synthetic', 'postal_code' => '10000', 'country' => 'CZ'],
            'declaration_ref' => 'Synthetic deed', 'share_scope' => 'ASSET-HOUSE', 'registry_complete' => true]), $actor);
        $service = $registry->create($this->supplier, 'service', $request('ASSET-SERVICE', ['name' => 'Synthetic water', 'active' => true, 'measure' => 'm3', 'measure_label' => '']), $actor);
        $unit = $registry->create($this->supplier, 'unit', $request('ASSET-UNIT', ['unit_no' => 'ASSET-UNIT', 'kind' => 'apartment', 'active' => true,
            'attributes' => ['share' => ['num' => '1', 'den' => '1']],
            'ownerships' => [['group_key' => 'OWNER', 'kind' => 'single', 'share' => ['num' => '1', 'den' => '1'], 'members' => [$client], 'title_ref' => 'Synthetic deed']],
            'roles' => [], 'identifiers' => [], 'services' => [$service['id']]]) + ['parent_id' => $house['id']], $actor);
        $statement = $pdo->prepare('SELECT o.dimension_value_id,v.type_id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id IN (?,?)');
        $statement->execute([$this->supplier, $unit['id'], $service['id']]);
        $property = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $property);
        foreach ($property as $row) $this->dimensions[(int) $row['type_id']] = (int) $row['dimension_value_id'];
        ksort($this->dimensions);
        return ['property' => $property, 'actor' => $actor];
    }

    public function testStructuredPreviewHasReadPermissionWithoutCloseAuthority(): void
    {
        $route = (new \MyInvoice\Security\RoutePermissionMap())->match('POST', '/api/accounting/periods/123/closing/deferred-tax-504-preview');
        self::assertSame('accounting', $route->key);
        self::assertSame(\MyInvoice\Security\AccessLevel::READ, $route->minimum);
        $write = (new \MyInvoice\Security\RoutePermissionMap())->match('POST', '/api/accounting/periods/123/closing/steps/deferred_tax/run');
        self::assertSame('accounting.periods.close', $write->key);
        self::assertSame(\MyInvoice\Security\AccessLevel::WRITE, $write->minimum);
    }

    public function testLegalReserveChangesInvalidateDeferredDecisionAndShareJ6Sources(): void
    {
        $container = Bootstrap::buildContainer();
        $deferred = $container->get(DeferredTax504Service::class);
        $deferred->run($this->supplier, $this->period, $this->body(), $this->version());
        $before = $deferred->preview($this->supplier, $this->period)['source_hash'];
        $body = ['row_version' => $this->version(), 'rule_key' => 'reserve.repairs.create', 'amount' => '100.01', 'description' => 'Synthetic legal reserve',
            'legal_decision' => ['reference' => 'SYN-RESERVE-C', 'legal_basis' => '593/1992 §7', 'taxable_activity' => true,
                'conditions_confirmed' => true, 'evidence' => 'Synthetic repair plan and statutory deposit assessment',
                'allocations' => [['amount' => '100.01', 'dimensions' => $this->dimensions]]]];
        $container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
        self::assertFalse($deferred->closingCheck($this->supplier, $this->period)['complete']);
        $after = $deferred->preview($this->supplier, $this->period)['source_hash'];
        self::assertNotSame($before, $after);
        $j6 = $container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class)->read($this->supplier, $this->period)['snapshot'];
        self::assertSame($j6['input_hash'], $after);
        self::assertSame(-10001, $j6['accounting_result_cents']);
        self::assertSame([], $j6['findings']);
        self::assertSame([], $deferred->run($this->supplier, $this->period, $this->body(), $this->version())['entry_ids']);
        self::assertTrue($deferred->closingCheck($this->supplier, $this->period)['complete']);
    }

    private function firstAdoption(): array
    {
        $body = $this->body();
        $body['method']['first_adoption'] = true;
        $body['method']['first_adoption_reason'] = 'Synthetic prior differences separately approved';
        $body['opening_titles'] = [$body['titles'][0]];
        $body['opening_titles'][0]['difference'] = '500.00';
        return $body;
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testStepRevertCannotErasePostedOrZeroMethodDecision(bool $zero): void
    {
        $container = Bootstrap::buildContainer();
        $service = $container->get(DeferredTax504Service::class);
        $body = $this->body();
        if ($zero) $body['titles'] = [];
        $result = $service->run($this->supplier, $this->period, $body, $this->version());
        self::assertCount($zero ? 0 : 1, $result['entry_ids']);
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare("SELECT status,payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='deferred_tax'");
        $statement->execute([$this->supplier, $this->period]);
        $before = $statement->fetch(\PDO::FETCH_ASSOC);
        $version = $this->version();
        $count = $this->journalCount();
        $preview = $service->preview($this->supplier, $this->period);
        try {
            $container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)->revertStep($this->supplier, $this->period, 'deferred_tax', $version);
            self::fail('Reverting the step must not erase recorded method decisions or leave unrecognized journals.');
        } catch (ClosingException $failure) {
            self::assertSame('deferred_tax_revert_required', $failure->errorCode);
            self::assertSame(409, $failure->httpStatus);
        }
        self::assertTrue($pdo->inTransaction());
        self::assertSame($version, $this->version());
        self::assertSame($count, $this->journalCount());
        $statement->execute([$this->supplier, $this->period]);
        self::assertSame($before, $statement->fetch(\PDO::FETCH_ASSOC));
        self::assertSame($preview, $service->preview($this->supplier, $this->period));
        self::assertTrue($service->closingCheck($this->supplier, $this->period)['complete']);
    }

    private function journalCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');
        $stmt->execute([$this->supplier]);
        return (int) $stmt->fetchColumn();
    }

    private function body(): array
    {
        $title = ['reference' => 'SYN-DTL', 'evidence' => 'Synthetic taxable temporary difference', 'temporary_confirmed' => true, 'kind' => 'taxable',
            'difference' => '1000.00', 'rate_percent' => '21.00', 'rate_basis' => 'Synthetic future enacted rate', 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)];
        $asset = array_replace($title, ['reference' => 'SYN-DTA', 'kind' => 'deductible', 'difference' => '800.00', 'recognized_difference' => '500.00', 'recoverability_evidence' => 'Synthetic probable future taxable base']);
        return ['source_hash' => \MyInvoice\Service\Accounting\Closing\DeferredTax504Repository::sourceHash($this->db, $this->supplier, $this->period), 'prepare_accounts' => true, 'method' => ['enabled' => true, 'version' => '504-deferred-own-v1', 'reference' => 'SYN-METHOD', 'faithful_image_reason' => 'Synthetic future tax effect',
            'standard_departure_reason' => 'Synthetic recoverable asset recognition', 'presentation_reason' => 'Synthetic separate current and deferred disclosure', 'first_adoption' => false], 'titles' => [$title, $asset]];
    }

    private function version(): int
    {
        return (int) Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
    }

    private function countAccounts(): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id=? AND account_code IN ('349','592','959.100')");
        $stmt->execute([$this->supplier]);
        return (int) $stmt->fetchColumn();
    }
}
