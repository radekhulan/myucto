<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,AccountingPeriodRepository,ChartOfAccountsRepository,ClosingRepository,LedgerReportRepository,SaldoRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService,AccountDefinitionException};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Reports\{FinancialStatementService,AccountDetailService,AccountStatementService,BalanceInventoryService,SaldoService};
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\TestCase;
final class AccountDefinitionHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;
    private \DI\Container $container;
    private Connection $db;
    private int $supplier;
    private int $actor;
    private array $periods=[];
    private array $ids=[];
    private AccountDefinitionRepository $definitions;
    private bool $committed=false;
    private ?int $originalIsolation=null;
    protected function setUp(): void
    {
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);$p=$this->db->pdo();self::assertSame(getenv('MYINVOICE_DB_NAME'),$p->query('SELECT DATABASE()')->fetchColumn());$p->beginTransaction();
        $seed=(int)$p->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();$this->supplier=$this->createIsolatedSupplier($p,$seed);$p->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);$this->actor=(int)$p->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier,'2025-01-01');$chart=$this->container->get(ChartOfAccountsRepository::class);$parent=$chart->findByCode($this->supplier,'336');$chart->insert($this->supplier,['account_code'=>'336.007','name'=>'Synthetic custom debit account','account_type'=>'liability','normal_side'=>'debit','is_synthetic'=>false,'parent_id'=>$parent['id']]);foreach($chart->listForTenant($this->supplier,true) as $a)$this->ids[$a['account_code']]=$a['id'];
        foreach([2025,2026] as $year)$this->periods[$year]=$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,$year,"$year-01-01","$year-12-31");$this->definitions=new AccountDefinitionRepository($this->db);
        foreach([['336','25.00'],['574','10.00'],['336.007','7.00']] as [$code,$amount])$this->post('2025-07-01',$code,'221',$amount);
    }
    protected function tearDown(): void
    {
        if(!isset($this->db))return;$p=$this->db->pdo();if($p->inTransaction())$p->rollBack();if($this->committed){foreach(['document_dimensions','other_items','accounting_account_definition_history','activity_log','journal_entries'] as $table)$p->prepare('DELETE FROM '.$table.' WHERE supplier_id=?')->execute([$this->supplier]);$p->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);}if($this->originalIsolation!==null)$p->exec('SET SESSION innodb_snapshot_isolation='.$this->originalIsolation);
    }
    private function post(string $date,string $debit,string $credit,string $amount,array $dimensions=[]): void
    {
        $this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>$debit,'side'=>'debit','amount'=>$amount,'dimensions'=>$dimensions],['account_code'=>$credit,'side'=>'credit','amount'=>$amount,'dimensions'=>$dimensions]],['entry_date'=>$date,'posted'=>true,'posted_by'=>$this->actor]);
    }
    private function targetDefinitions(): void
    {
        foreach(['336'=>null,'574'=>'credit'] as $code=>$side){$id=$this->ids[$code];$this->definitions->change($this->supplier,$id,'2026-01-01',['normal_side'=>$side,'name'=>'Synthetic approved 504 '.$code],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic explicit target definition');}
    }
    private function reports2025(): array
    {
        $s=$this->supplier;$p=$this->periods[2025];$c=$this->container;$report=$c->get(FinancialStatementService::class);
        $data=['bs'=>$report->balanceSheet($s,$p,'2025-12-31','full'),'pl'=>$report->incomeStatement($s,$p,'2025-12-31','full'),'trial'=>$c->get(LedgerReportRepository::class)->trialBalanceRows($s,'2025-01-01','2025-12-31','2025-01-01',true,[],true),'unusual'=>$c->get(ClosingRepository::class)->accountsOnUnusualSide($s,$p,'2025-12-31'),'saldo'=>$c->get(SaldoService::class)->build($s,$p,'2025-12-31','336'),'saldo574'=>$c->get(SaldoService::class)->build($s,$p,'2025-12-31','574'),'open_items'=>$c->get(\MyInvoice\Service\Accounting\Reports\OpenItemsService::class)->build($s,$this->ids['336'],'2025-12-31',false,1,50),'detail'=>$c->get(AccountDetailService::class)->build($s,$this->ids['336'],'2025-01-01','2025-12-31'),'statement'=>$c->get(AccountStatementService::class)->build($s,$this->ids['336'],'2025-01-01','2025-12-31',1,50),'inventory'=>$c->get(BalanceInventoryService::class)->build($s,$p)];
        foreach($data as &$report)unset($report['entity']['prepared_at']);unset($report);return $data;
    }
    public function testNative500HistoryAnd504ReportsUseExplicitDatedSidesWithoutRewritingCustomAnalytics(): void
    {
        $before=$this->reports2025();$base=$this->db->pdo()->query('SELECT * FROM chart_of_accounts WHERE supplier_id='.$this->supplier.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);$this->targetDefinitions();
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);$catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-01-01');$this->db->pdo()->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'synthetic','Synthetic confirmed activity kind')")->execute([$catalog['activity_kind']['id'],$this->supplier]);$dimensions=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>(int)$this->db->pdo()->lastInsertId(),$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];$this->post('2026-02-01','336','221','5.00',$dimensions);$this->post('2026-02-01','221','574','2.00',$dimensions);$this->post('2026-02-01','336.007','221','1.00',$dimensions);
        self::assertSame($before,$this->reports2025());self::assertSame($base,$this->db->pdo()->query('SELECT * FROM chart_of_accounts WHERE supplier_id='.$this->supplier.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $trial=$this->container->get(LedgerReportRepository::class)->trialBalanceRows($this->supplier,'2026-01-01','2026-12-31','2026-01-01',true,[],true);$byCode=array_column($trial,null,'account_code');self::assertNull($byCode['336']['normal_side']);self::assertSame('credit',$byCode['574']['normal_side']);self::assertSame('debit',$byCode['336.007']['normal_side']);$saldo=$this->container->get(SaldoService::class)->build($this->supplier,$this->periods[2026],'2026-12-31','574');self::assertSame('credit',$saldo['accounts'][0]['account']['normal_side']);self::assertSame(2.0,$saldo['accounts'][0]['gl_balance']);
        $unusual=$this->container->get(ClosingRepository::class)->accountsOnUnusualSide($this->supplier,$this->periods[2026],'2026-12-31');self::assertNotContains('336',array_column($unusual,'account_code'));self::assertSame('cz_504',$this->container->get(FinancialStatementService::class)->balanceSheet($this->supplier,$this->periods[2026],'2026-12-31','full')['accounting_framework']);
    }
    public function testSqlAndPhpReadersPreserveNullNewestBoundaryAndTenantIsolation(): void
    {
        $this->targetDefinitions();$this->definitions->change($this->supplier,$this->ids['336'],'2027-01-01',['normal_side'=>'debit'],$this->definitions->stateHash($this->supplier,$this->ids['336']),$this->actor,'Synthetic next approved definition');
        foreach(['2025-12-31'=>'credit','2026-01-01'=>null,'2027-01-01'=>'debit'] as $date=>$side){$php=[];foreach($this->definitions->listForDate($this->supplier,$date) as $row)$php[$row['account_code']]=$row['normal_side'];$sql=$this->db->pdo()->query('SELECT account_code,normal_side FROM '.AccountDefinitionRepository::relation($date,$this->supplier).' a')->fetchAll(\PDO::FETCH_KEY_PAIR);ksort($php);ksort($sql);self::assertSame($php,$sql);self::assertSame($side,$php['336']);self::assertSame('debit',$php['336.007']);}
        $other=$this->createIsolatedSupplier($this->db->pdo(),$this->supplier);$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$chart->insert($other,['account_code'=>'336','name'=>'Synthetic other tenant','account_type'=>'liability','normal_side'=>'credit','is_synthetic'=>true]);self::assertSame('credit',$chart->findById($other,$id,'2026-12-31')['normal_side']);self::assertNull($this->definitions->findForDate($other,$this->ids['336'],'2026-12-31'));
    }
    /**
     * Relace definic čte z historie jen sloupce, které vnější dotaz potřebuje. Celý řádek
     * nese `definition_json` (LONGTEXT) a s ním by se mezivýsledek window funkce nevešel
     * do paměťové tabulky: každé čtení osnovy (předvaha, účtování, výkazy) by zakládalo
     * dočasnou tabulku na disku.
     */
    public function testDefinitionRelationsDoNotMaterializeOnDisk(): void
    {
        $this->targetDefinitions();
        $p=$this->db->pdo();
        $disk=static fn():int=>(int)$p->query("SHOW SESSION STATUS LIKE 'Created_tmp_disk_tables'")->fetch(\PDO::FETCH_NUM)[1];
        foreach(['relation'=>'SELECT COUNT(*),MIN(name),MAX(normal_side) FROM '.AccountDefinitionRepository::relation('2026-06-30',$this->supplier).' a',
            'current relation'=>'SELECT COUNT(*),MIN(name),MAX(normal_side) FROM '.AccountDefinitionRepository::relation('2026-06-30',$this->supplier,true).' a',
            'journal join'=>'SELECT COUNT(*),MIN(a.name),MAX(a.normal_side) FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id '.AccountDefinitionRepository::journalJoin($this->supplier).' WHERE l.supplier_id='.$this->supplier] as $label=>$sql){
            $before=$disk();$rows=$p->query($sql)->fetch(\PDO::FETCH_NUM);
            self::assertGreaterThan(0,(int)$rows[0],$label);
            self::assertSame(0,$disk()-$before,$label.': definice účtů se nesmí materializovat do dočasné tabulky na disku.');
        }
    }
    public function testCreatedHistoricalAccountDoesNotExistBeforeItsExplicitBirthDate(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$chart->insert($this->supplier,['account_code'=>'336.008','name'=>'Synthetic new dated account','account_type'=>'liability','normal_side'=>'credit','parent_id'=>$this->ids['336']],'2027-03-01',$this->actor);
        self::assertNull($chart->findById($this->supplier,$id,'2027-02-28'));self::assertSame('credit',$chart->findById($this->supplier,$id,'2027-03-01')['normal_side']);$q=$this->db->pdo()->query('SELECT account_code FROM '.AccountDefinitionRepository::relation('2027-02-28',$this->supplier).' a');self::assertNotContains('336.008',$q->fetchAll(\PDO::FETCH_COLUMN));
        try{$this->definitions->change($this->supplier,$id,'2026-01-01',['name'=>'Synthetic forbidden invented past'],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic past boundary');self::fail('A new account was given invented previous existence.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_before_creation',$e->errorCode);}
    }
    public function testMetadataCasRejectsStaleApprovalAndAccountIdentityChanges(): void
    {
        $this->targetDefinitions();$id=$this->ids['336'];$before=$this->definitions->stateHash($this->supplier,$id);$after=$this->definitions->change($this->supplier,$id,'2027-01-01',['name'=>'Synthetic first approval'],$before,$this->actor,'Synthetic first decision');self::assertNotSame($before,$after);
        try{$this->definitions->change($this->supplier,$id,'2028-01-01',['normal_side'=>'debit'],$before,$this->actor,'Synthetic stale decision');self::fail('Stale metadata was accepted.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_stale',$e->errorCode);}
        foreach(['account_type'=>'asset','parent_id'=>$this->ids['574'],'account_code'=>'999'] as $field=>$value){try{$this->definitions->change($this->supplier,$id,'2028-01-01',[$field=>$value],$after,$this->actor,'Synthetic identity decision');self::fail('Account identity was changed.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_identity_immutable',$e->errorCode);}}
    }
    public function testHistoricalEditAndNativeCsvImportAreDatedWhileLegacyUpdateKeepsItsBehavior(): void
    {
        $chart=$this->container->get(ChartOfAccountsRepository::class);$chart->update($this->supplier,$this->ids['336.007'],['name'=>'Synthetic legacy update'],'2027-01-01');self::assertSame('Synthetic legacy update',$chart->findById($this->supplier,$this->ids['336.007'],'2025-12-31')['name']);self::assertFalse($this->definitions->hasHistory($this->supplier));
        $this->targetDefinitions();$before=$chart->findById($this->supplier,$this->ids['336'],'2025-12-31');$base=$this->db->pdo()->query('SELECT name FROM chart_of_accounts WHERE id='.$this->ids['336'])->fetchColumn();$chart->update($this->supplier,$this->ids['336'],['name'=>'Synthetic dated edit'],'2027-01-01');self::assertSame($before,$chart->findById($this->supplier,$this->ids['336'],'2025-12-31'));self::assertSame('Synthetic dated edit',$chart->findById($this->supplier,$this->ids['336'],'2027-01-01')['name']);
        $import=$this->container->get(\MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService::class);$csv="ucet;nazev\n336;Synthetic dated import\n";$state=$this->definitions->stateHash($this->supplier,$this->ids['336']);$preview=$import->import($this->supplier,$this->actor,$csv,'synthetic.csv',true,'2028-01-01');self::assertTrue($preview['ok']);self::assertSame($state,$this->definitions->stateHash($this->supplier,$this->ids['336']));$done=$import->import($this->supplier,$this->actor,$csv,'synthetic.csv',false,'2028-01-01');self::assertTrue($done['ok']);self::assertSame('Synthetic dated import',$chart->findById($this->supplier,$this->ids['336'],'2028-01-01')['name']);self::assertSame('Synthetic dated edit',$chart->findById($this->supplier,$this->ids['336'],'2027-12-31')['name']);self::assertSame($base,$this->db->pdo()->query('SELECT name FROM chart_of_accounts WHERE id='.$this->ids['336'])->fetchColumn());
    }
    public function testTenantOptInDatesAnExistingAccountWithoutItsOwnPreviousHistory(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$this->ids['518'];$before=$chart->findById($this->supplier,$id,'2025-12-31');self::assertFalse($this->definitions->hasHistory($this->supplier,$id));
        self::assertTrue($chart->update($this->supplier,$id,['name'=>'Synthetic newly versioned existing account','is_active'=>false],'2027-01-01'));
        self::assertSame($before,$chart->findById($this->supplier,$id,'2025-12-31'));self::assertFalse((bool)$chart->findById($this->supplier,$id,'2027-01-01')['is_active']);self::assertTrue($this->definitions->hasHistory($this->supplier,$id));
        self::assertSame($before['name'],$this->db->pdo()->query('SELECT name FROM chart_of_accounts WHERE id='.$id)->fetchColumn());
    }
    public function testTenantOptInDatesTaxMetadataOnAnExistingUnversionedAccount(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$this->ids['518'];self::assertFalse($this->definitions->hasHistory($this->supplier,$id));
        $chart->setTaxDeductibility($this->supplier,$id,'non_deductible','2027-01-01');self::assertSame('deductible',$chart->findById($this->supplier,$id,'2025-12-31')['tax_deductibility']);self::assertSame('non_deductible',$chart->findById($this->supplier,$id,'2027-01-01')['tax_deductibility']);self::assertTrue($this->definitions->hasHistory($this->supplier,$id));
        self::assertSame('deductible',$this->db->pdo()->query('SELECT tax_deductibility FROM chart_of_accounts WHERE id='.$id)->fetchColumn());
    }
    public function testTenantOptInPreventsUnversionedAccountUpdatesFromBypassingPostedAndClosedGuards(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$this->ids['221'];self::assertFalse($this->definitions->hasHistory($this->supplier,$id));
        try{$chart->update($this->supplier,$id,['name'=>'Synthetic forbidden historical rename'],'2025-01-01');self::fail('The first dated update bypassed an existing posted interval.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_in_use',$e->errorCode);}
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->periods[2026]]);
        try{$chart->setTaxDeductibility($this->supplier,$this->ids['518'],'non_deductible','2026-01-01');self::fail('The first tax definition bypassed a closed period.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_period_locked',$e->errorCode);}
    }

    public function testPostedIntervalAndClosedPeriodCannotBeReinterpreted(): void
    {
        $this->targetDefinitions();$id=$this->ids['336'];$hash=$this->definitions->stateHash($this->supplier,$id);
        try{$this->definitions->change($this->supplier,$id,'2025-01-01',['normal_side'=>'debit'],$hash,$this->actor,'Synthetic posted history rewrite');self::fail('Posted history metadata was rewritten.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_in_use',$e->errorCode);}
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->periods[2026]]);try{$this->definitions->change($this->supplier,$id,'2026-01-01',['normal_side'=>'debit'],$hash,$this->actor,'Synthetic closed period rewrite');self::fail('Closed period metadata was rewritten.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_period_locked',$e->errorCode);}
    }

    public function testCurrentLockedReaderAndCasSeeDefinitionCommittedAfterCallerSnapshot(): void
    {
        $this->targetDefinitions();$p=$this->db->pdo();$this->originalIsolation=(int)$p->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();$p->exec('SET SESSION innodb_snapshot_isolation=OFF');$p->commit();$this->committed=true;$p->beginTransaction();$old=$this->definitions->stateHash($this->supplier,$this->ids['336']);self::assertNull($this->definitions->findForDate($this->supplier,$this->ids['336'],'2027-01-01')['normal_side']);
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(\MyInvoice\Infrastructure\Config\Config::load(Bootstrap::rootDir())));try{self::assertSame(getenv('MYINVOICE_DB_NAME'),$other->pdo()->query('SELECT DATABASE()')->fetchColumn());$writer=new AccountDefinitionRepository($other);$writer->change($this->supplier,$this->ids['336'],'2027-01-01',['normal_side'=>'debit'],$writer->stateHash($this->supplier,$this->ids['336']),$this->actor,'Synthetic concurrent approved definition');}finally{$other->close();}
        self::assertSame('debit',$this->definitions->findForDate($this->supplier,$this->ids['336'],'2027-01-01',true)['normal_side']);try{$this->definitions->change($this->supplier,$this->ids['336'],'2028-01-01',['name'=>'Synthetic stale snapshot overwrite'],$old,$this->actor,'Synthetic stale RR decision');self::fail('An RR snapshot overwrote a newer committed definition.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_stale',$e->errorCode);}
    }
    public function testAnExistingPostedAccountCannotBeInitializedAsANewAccount(): void
    {
        try{$this->definitions->initializeCreated($this->supplier,$this->ids['336'],'2027-01-01',$this->actor);self::fail('A posted historical account was given a new birth date.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_existing_account',$e->errorCode);}
    }

    public function testFutureAccountBirthReservesPhysicalIdentityAndCannotBeRecreatedByEarlierImport(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$data=['account_code'=>'336.009','name'=>'Synthetic future physical identity','account_type'=>'liability','normal_side'=>'credit','parent_id'=>$this->ids['336']];$id=$chart->insert($this->supplier,$data,'2029-01-01',$this->actor);self::assertNull($chart->findById($this->supplier,$id,'2028-01-01'));
        try{$chart->insert($this->supplier,$data,'2028-01-01',$this->actor);self::fail('A future account physical code was reused.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_code_exists',$e->errorCode);}
        $import=$this->container->get(\MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService::class);$before=$this->definitions->stateHash($this->supplier,$id);$preview=$import->import($this->supplier,$this->actor,"ucet;nazev;nadrizeny_ucet\n336.009;Synthetic wrong earlier identity;336\n",'synthetic.csv',true,'2028-01-01');self::assertFalse($preview['ok']);self::assertSame(1,$preview['failed']);self::assertSame($before,$this->definitions->stateHash($this->supplier,$id));
    }

    public function testNativePostingCannotUseAccountDeactivatedAfterCallerSnapshot(): void
    {
        $this->targetDefinitions();$this->periods[2027]=$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,2027,'2027-01-01','2027-12-31');$p=$this->db->pdo();$this->originalIsolation=(int)$p->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();$p->exec('SET SESSION innodb_snapshot_isolation=OFF');$p->commit();$this->committed=true;$p->beginTransaction();self::assertTrue((bool)$this->definitions->findForDate($this->supplier,$this->ids['336'],'2027-01-01')['is_active']);
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(\MyInvoice\Infrastructure\Config\Config::load(Bootstrap::rootDir())));try{$writer=new AccountDefinitionRepository($other);$writer->change($this->supplier,$this->ids['336'],'2027-01-01',['is_active'=>false],$writer->stateHash($this->supplier,$this->ids['336']),$this->actor,'Synthetic concurrent deactivation');}finally{$other->close();}
        try{$this->post('2027-02-01','336','221','1.00');self::fail('Native posting used stale active account metadata.');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('account_inactive',$e->errorCode);}
    }

    public function testNativeDimensionRestampUsesAccountDefinitionCommittedAfterCallerSnapshot(): void
    {
        $p=$this->db->pdo();$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,2027,'2027-01-01','2027-12-31');
        $p->prepare('DELETE FROM chart_of_accounts WHERE supplier_id=? AND parent_id=?')->execute([$this->supplier,$this->ids['511']]);
        $chart=$this->container->get(ChartOfAccountsRepository::class);$target=0;
        foreach(['511.007','511.008','511.009'] as $code){$id=$chart->insert($this->supplier,['account_code'=>$code,'name'=>'Synthetic restamp analytic','account_type'=>'expense','normal_side'=>'debit','tax_deductibility'=>'deductible','parent_id'=>$this->ids['511'],'is_synthetic'=>false]);if($code==='511.008')$target=$id;}
        $dimensions=$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);$dimensions->setEnabled($this->supplier,true);$type=$dimensions->ensureDefaultTypes($this->supplier,['stredisko'])['cost_center'];$value=(int)$dimensions->createValue($this->supplier,$type,['code'=>'CURRENT-RESTAMP','name'=>'Synthetic current restamp'])['id'];$dimensions->updateType($this->supplier,$type,['drives_accounts'=>true]);
        $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService::class)->saveForValue($this->supplier,$value,[['synthetic_code'=>'511','analytic_code'=>'511.008']],$this->actor);
        $this->definitions->change($this->supplier,$target,'2027-01-01',['is_active'=>false],$this->definitions->stateHash($this->supplier,$target),$this->actor,'Synthetic unused inactive restamp target');
        $p->prepare("INSERT INTO other_items(supplier_id,side,title,issued_on,due_on,amount)VALUES(?,'payable','Synthetic dated restamp','2027-06-01','2027-06-01',100)")->execute([$this->supplier]);$source=(int)$p->lastInsertId();
        $fresh=Bootstrap::buildContainer();$fresh->set(Connection::class,$this->db);$posting=$fresh->get(PostingService::class);$entry=$posting->postDocument($this->supplier,'other_item',$source,[['account_code'=>'511','side'=>'debit','amount'=>'100.00'],['account_code'=>'221','side'=>'credit','amount'=>'100.00']],['entry_date'=>'2027-06-01','posted'=>true,'posted_by'=>$this->actor]);
        $this->originalIsolation=(int)$p->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();$p->exec('SET SESSION innodb_snapshot_isolation=OFF');$p->commit();$this->committed=true;$p->beginTransaction();self::assertFalse((bool)$this->definitions->findForDate($this->supplier,$target,'2027-06-01')['is_active']);
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(\MyInvoice\Infrastructure\Config\Config::load(Bootstrap::rootDir())));try{$writer=new AccountDefinitionRepository($other);$writer->change($this->supplier,$target,'2027-01-01',['is_active'=>true],$writer->stateHash($this->supplier,$target),$this->actor,'Synthetic concurrent target activation');}finally{$other->close();}
        $p->prepare("INSERT INTO document_dimensions(supplier_id,doc_type,doc_id,item_no,dimension_type_id,dimension_value_id)VALUES(?,'other_item',?,0,?,?)")->execute([$this->supplier,$source,$type,$value]);
        $before=(new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplier,$entry,true);$result=$posting->restampDimensions($this->supplier,'other_item',$source);
        self::assertTrue($result['needs_repost']);self::assertTrue($result['account_change']);$line=(int)$p->query("SELECT id FROM journal_entry_lines WHERE entry_id=".$entry." AND side='debit'")->fetchColumn();$after=(new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplier,$entry,true);self::assertSame($before[$line]??[],$after[$line]??[]);
        $q=$p->prepare('SELECT a.account_code,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.entry_id=? AND l.side=\'debit\'');$q->execute([$entry]);self::assertSame([['account_code'=>'511','amount'=>'100.00']],$q->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testNativeSingleAnalyticRoutingUsesEachPostingDateAndDoesNotReuseAnotherDate():void
    {
        $this->db->pdo()->prepare('DELETE FROM chart_of_accounts WHERE supplier_id=? AND parent_id=?')->execute([$this->supplier,$this->ids['518']]);
        $chart=$this->container->get(ChartOfAccountsRepository::class);$child=$chart->insert($this->supplier,['account_code'=>'518.007','name'=>'Synthetic dated expense analytic','account_type'=>'expense','normal_side'=>'debit','tax_deductibility'=>'deductible','is_synthetic'=>false,'parent_id'=>$this->ids['518']]);
        $this->definitions->change($this->supplier,$child,'2026-07-01',['tax_deductibility'=>'non_deductible'],$this->definitions->stateHash($this->supplier,$child),$this->actor,'Synthetic prospective analytic restriction');
        $this->post('2026-02-01','518','221','10.00');$this->post('2026-08-01','518','221','20.00');
        $q=$this->db->pdo()->prepare("SELECT e.entry_date,a.account_code FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE e.supplier_id=? AND e.entry_date>='2026-01-01' AND l.side='debit' ORDER BY e.entry_date");$q->execute([$this->supplier]);self::assertSame([['entry_date'=>'2026-02-01','account_code'=>'518.007'],['entry_date'=>'2026-08-01','account_code'=>'518']],$q->fetchAll(\PDO::FETCH_ASSOC));
    }
    public function testTaxReadersUseTheDefinitionOnEachActualExpenseDate():void
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-01-01');$p->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'TAX-KIND','Synthetic dated tax activity')")->execute([$catalog['activity_kind']['id'],$this->supplier]);$dimensions=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>(int)$p->lastInsertId(),$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];
        $this->post('2026-02-01','518','221','100.00',$dimensions);$id=$this->ids['518'];$this->definitions->change($this->supplier,$id,'2026-07-01',['tax_deductibility'=>'non_deductible'],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic prospective expense tax policy');$this->post('2026-08-01','518','221','200.00',$dimensions);
        self::assertSame(200.0,$this->container->get(\MyInvoice\Service\Tax\Return\NonDeductibleCostsService::class)->sum($this->supplier,'2026-01-01','2026-12-31'));
        $snapshot=$this->container->get(\MyInvoice\Repository\NonprofitTaxSourceRepository::class)->snapshot($this->supplier,$this->periods[2026],true);$rows=array_column($snapshot['sources'],null,'date');self::assertFalse($rows['2026-02-01']['mandatory_non_deductible']);self::assertTrue($rows['2026-08-01']['mandatory_non_deductible']);
        self::assertSame('deductible',$p->query('SELECT tax_deductibility FROM chart_of_accounts WHERE id='.$id)->fetchColumn());
        $this->post('2026-02-01','543','221','100.00',$dimensions);$reviewAccount=$this->ids['543'];$this->definitions->change($this->supplier,$reviewAccount,'2026-07-01',['tax_deductibility'=>'deductible'],$this->definitions->stateHash($this->supplier,$reviewAccount),$this->actor,'Synthetic prospective reviewable expense policy');$this->post('2026-08-01','543','221','200.00',$dimensions);
        $provider=$this->container->get(\MyInvoice\Service\Tax\Return\DppoReturnDataProvider::class);$suggestions=(new \ReflectionMethod($provider,'addbackSuggestions'))->invoke($provider,$this->supplier,'2026-01-01','2026-12-31');$selected=array_values(array_filter($suggestions,static fn(array $r):bool=>$r['account_code']==='543'));self::assertCount(1,$selected);self::assertSame(200.0,$selected[0]['amount']);self::assertFalse($selected[0]['already_non_deductible']);

    }
    public function testCurrentTaxJournalReadsDefinitionsAndEntriesCommittedAfterCallerSnapshot():void
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);$catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-01-01');$p->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'CURRENT-TAX','Synthetic current tax activity')")->execute([$catalog['activity_kind']['id'],$this->supplier]);$dimensions=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>(int)$p->lastInsertId(),$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];
        $this->originalIsolation=(int)$p->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();$p->exec('SET SESSION innodb_snapshot_isolation=OFF');$p->commit();$this->committed=true;$p->beginTransaction();self::assertSame('deductible',$this->definitions->findForDate($this->supplier,$this->ids['518'],'2026-12-31')['tax_deductibility']);
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(\MyInvoice\Infrastructure\Config\Config::load(Bootstrap::rootDir())));try{
            $writer=new AccountDefinitionRepository($other);$writer->change($this->supplier,$this->ids['518'],'2026-01-01',['tax_deductibility'=>'non_deductible'],$writer->stateHash($this->supplier,$this->ids['518']),$this->actor,'Synthetic concurrent tax definition');$c=Bootstrap::buildContainer();$c->set(Connection::class,$other);$c->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'125.00','dimensions'=>$dimensions],['account_code'=>'221','side'=>'credit','amount'=>'125.00','dimensions'=>$dimensions]],['entry_date'=>'2026-08-01','posted'=>true,'posted_by'=>$this->actor]);
        }finally{$other->close();}
        $snapshot=$this->container->get(\MyInvoice\Repository\NonprofitTaxSourceRepository::class)->snapshot($this->supplier,$this->periods[2026],true);self::assertCount(1,$snapshot['sources']);self::assertSame(12500,$snapshot['sources'][0]['amount_cents']);self::assertTrue($snapshot['sources'][0]['mandatory_non_deductible']);
    }
    public function testDefinitionIntervalCannotCrossAnEmptyClosedPeriod():void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->periods[2026]]);$id=$this->ids['518'];
        try{$this->definitions->change($this->supplier,$id,'2024-01-01',['name'=>'Synthetic forbidden interval'],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic definition preceding a closed period');self::fail('An unbounded definition must not change metadata in a closed period');}catch(AccountDefinitionException $e){self::assertSame('account_definition_period_locked',$e->errorCode);}
    }
    public function testDefinitionEndingExactlyBeforeAClosedPeriodRemainsEditable():void
    {
        $id=$this->ids['518'];$this->definitions->change($this->supplier,$id,'2026-01-01',['name'=>'Synthetic locked interval'],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic approved future boundary');$this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->periods[2026]]);
        $this->definitions->change($this->supplier,$id,'2024-01-01',['name'=>'Synthetic earlier interval'],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic earlier interval ends at closed-period boundary');self::assertSame('Synthetic earlier interval',$this->definitions->findForDate($this->supplier,$id,'2025-12-31')['name']);self::assertSame('Synthetic locked interval',$this->definitions->findForDate($this->supplier,$id,'2026-01-01')['name']);
    }
    public function testDatabaseRejectsMissingCanonicalMetadataFieldsInsteadOfSqlNullCheckPass(): void
    {
        $date='2029-01-01';$json='{}';$hash=hash('sha256',$this->supplier.':'.$this->ids['336'].':'.$date.':'.$json);$q=$this->db->pdo()->prepare("INSERT INTO accounting_account_definition_history(supplier_id,account_id,effective_from,name,normal_side,tax_deductibility,is_active,definition_json,definition_hash,evidence,recorded_at) VALUES(?,?,?,'Synthetic missing JSON',NULL,'deductible',1,?,?,'Synthetic malformed payload',UTC_TIMESTAMP(6))");
        try{$q->execute([$this->supplier,$this->ids['336'],$date,$json,$hash]);self::fail('SQL NULL checks accepted an incomplete canonical metadata payload.');}catch(\PDOException $e){self::assertSame(4025,(int)($e->errorInfo[1]??0));}
    }

}
