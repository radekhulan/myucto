<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,FrameworkConversionRepository,JournalEntryRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService};
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
#[Group('integration')]
final class FrameworkConversionWorkflowTest extends TestCase
{
    use \MyInvoice\Tests\Support\SharedClassFixture;
    private $container;private Connection $db;private int $supplier;private int $source;private int $target;private int $actor;private ?array $converted=null;
    /** Testy, které začínají hotovou konverzí: dostanou ji ze sdílené fixture, konverzi samotnou ověřují ostatní testy. */
    private const CONVERTED=['testNext504ForeignNativeCaseUsesProvedConversionOpening','testRehashedForeignOriginTamperCannotChangeNativeOpeningLineage','testGenericNativeOpeningInsertCannotDuplicateOwnedConversion','testSourceCloseRevertIsBlockedAfterConversion','testNativeOpeningMutationIsBlocked','testLateDecisionPersistMutationRollsBackEveryDecisionWrite','testCancelledConversionReleasesLiveSourceCloseAndPreservesHistoricalIdentity'];
    protected function setUp():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);$p=$this->db->pdo();
        if(str_contains($this->name(),'LatePersist')||str_contains($this->name(),'LateDecisionPersist')){
            $p->exec("SET @conversion_test_mutant=0");
            $p->exec("CREATE OR REPLACE TRIGGER conversion_test_line_mutant BEFORE INSERT ON journal_entry_lines FOR EACH ROW BEGIN IF COALESCE(@conversion_test_mutant,0)=1 AND (EXISTS(SELECT 1 FROM accounting_framework_conversions c WHERE c.supplier_id=NEW.supplier_id AND c.status='pending') OR EXISTS(SELECT 1 FROM journal_entries e WHERE e.id=NEW.entry_id AND e.supplier_id=NEW.supplier_id AND e.source_type='profit_distribution')) THEN SET NEW.amount=NEW.amount+0.01; END IF; END");
            $p->exec("CREATE OR REPLACE TRIGGER conversion_test_header_mutant BEFORE INSERT ON journal_entries FOR EACH ROW BEGIN IF COALESCE(@conversion_test_mutant,0)=2 AND (EXISTS(SELECT 1 FROM accounting_framework_conversions c WHERE c.supplier_id=NEW.supplier_id AND c.status='pending') OR NEW.source_type='profit_distribution') THEN SET NEW.entry_date=DATE_ADD(NEW.entry_date,INTERVAL 1 DAY); END IF; END");
        }
        $this->actor=(int)$p->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$this->actor);
        $variant='base';foreach(['ProfitTuple','CustomAnalytic','Offbalance','PendingRelease','Foreign'] as $name)if(str_contains($this->name(),$name))$variant=$name;
        // FrameworkConversionCloneTest a HttpTest si třídu berou přes reflexi jako fixturu.
        if(!$this->preparedByPhpunit()){$p->beginTransaction();$this->closedSource($variant);if($variant==='Foreign')$this->foreignRates();return;}
        $converted=in_array($this->name(),self::CONVERTED,true);
        $data=$this->sharedFixture($p,$variant.($converted?'+converted':''),function()use($variant,$converted):array{
            $this->closedSource($variant);$data=['supplier'=>$this->supplier,'source'=>$this->source,'target'=>$this->target];
            if($converted)$data['converted']=$this->convert();
            return [$this->supplier,$data];
        });
        $this->supplier=$data['supplier'];$this->source=$data['source'];$this->target=$data['target'];$this->converted=$data['converted']??null;
        if($variant==='Foreign')$this->foreignRates();
    }
    /** Kurzy jsou globální číselník: do sdílené fixture se necommitují, test si je vloží ve své transakci. */
    private function foreignRates():void
    {$this->db->pdo()->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES('2025-12-31','EUR',25),('2026-12-31','EUR',26) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");}
    private function closedSource(string $variant):void
    {
        $p=$this->db->pdo();
        $p->exec("INSERT INTO supplier(company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id) SELECT 'Synthetic conversion','Test 1','Praha','11000',country_id,'conversion@example.com',default_currency_id,default_vat_rate_id FROM supplier ORDER BY id LIMIT 1");$this->supplier=(int)$p->lastInsertId();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $periods=$this->container->get(AccountingPeriodRepository::class);$this->source=$periods->create($this->supplier,2025,'2025-01-01','2025-12-31');$this->target=$periods->create($this->supplier,2026,'2026-01-01','2026-12-31');
        $this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>1000],['account_code'=>'311','side'=>'debit','amount'=>200],['account_code'=>'321','side'=>'credit','amount'=>100],['account_code'=>'428','side'=>'credit','amount'=>700],['account_code'=>'602','side'=>'credit','amount'=>400]],['entry_date'=>'2025-06-01','posted'=>true,'posted_by'=>$this->actor,'user_id'=>$this->actor]);
        if($variant==='ProfitTuple'){
            $posting=$this->container->get(PostingService::class);foreach([['221','debit','602','credit','A'],['518','debit','221','credit','B']] as [$a,$side,$b,$other,$center])$posting->postDocument($this->supplier,'manual',null,[['account_code'=>$a,'side'=>$side,'amount'=>'1000.00','cost_center'=>$center],['account_code'=>$b,'side'=>$other,'amount'=>'1000.00','cost_center'=>$center]],['entry_date'=>'2025-06-01','posted'=>true,'user_id'=>$this->actor,'posted_by'=>$this->actor]);
            $posting->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'400.00'],['account_code'=>'221','side'=>'credit','amount'=>'400.00']],['entry_date'=>'2025-06-01','posted'=>true,'user_id'=>$this->actor,'posted_by'=>$this->actor]);
        }
        if($variant==='CustomAnalytic'){
            $chart=$this->container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);$parent=$chart->findByCode($this->supplier,'336','2025-01-01');$chart->insert($this->supplier,['account_code'=>'336.007','name'=>'Synthetic approved custom analytic','account_type'=>'liability','normal_side'=>'debit','is_synthetic'=>false,'parent_id'=>$parent['id']]);
            $this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'336.007','side'=>'debit','amount'=>'7.00'],['account_code'=>'574','side'=>'debit','amount'=>'10.00'],['account_code'=>'221','side'=>'credit','amount'=>'17.00']],['entry_date'=>'2025-06-01','posted'=>true,'user_id'=>$this->actor,'posted_by'=>$this->actor]);
        }
        if($variant==='Offbalance'){
            foreach(['998','999'] as $code)$this->container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class)->insert($this->supplier,['account_code'=>$code,'name'=>'Synthetic offbalance '.$code,'account_type'=>'offbalance','normal_side'=>null,'is_synthetic'=>true]);
            $this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'998','side'=>'debit','amount'=>'10.00'],['account_code'=>'999','side'=>'credit','amount'=>'10.00']],['entry_date'=>'2025-06-01','posted'=>true,'user_id'=>$this->actor,'posted_by'=>$this->actor]);
        }
        if($variant==='PendingRelease')$this->container->get(PostingService::class)->postDocument($this->supplier,'small_asset_accrual',\MyInvoice\Service\Accounting\Closing\ClosingSourceId::smallAssetAccrual($this->source),[['account_code'=>'381','side'=>'debit','amount'=>'20.00'],['account_code'=>'501','side'=>'credit','amount'=>'20.00']],['entry_date'=>'2025-12-31','posted'=>true,'user_id'=>$this->actor,'posted_by'=>$this->actor]);
        $rates=null;
        if($variant==='Foreign'){
            $p->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source) VALUES(?,'Synthetic conversion EUR bank',?,'1000000005','0100','EUR','2025-06-01','gpc')")->execute([$this->supplier,hash('sha256','synthetic-conversion-'.$this->supplier)]);$statement=(int)$p->lastInsertId();$p->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_name) VALUES(?,'2025-06-01','4.00','EUR','Synthetic contribution')")->execute([$statement]);$bank=(int)$p->lastInsertId();
            $this->container->get(PostingService::class)->postDocument($this->supplier,'bank',$bank,[['account_code'=>'221','side'=>'debit','amount'=>'100.00','currency_code'=>'EUR','amount_foreign'=>'4.00','fx_rate'=>'25.000000'],['account_code'=>'428','side'=>'credit','amount'=>'100.00']],['entry_date'=>'2025-06-01','posted'=>true,'user_id'=>$this->actor,'posted_by'=>$this->actor]);
            $rates=$p->query("SELECT rate_date,currency_code,rate FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN ('2025-12-31','2026-12-31')")->fetchAll(\PDO::FETCH_ASSOC);$this->foreignRates();
        }
        $c=$this->container->get(ClosingService::class);$s=$this->supplier;$id=$this->source;$meta=['user_id'=>$this->actor,'posted_by'=>$this->actor];
        $c->start($s,$id,$this->rv(),$meta);$items=[];foreach($c->inventoryPreview($s,$id)['rows'] as $row)$items[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];
        $c->saveInventory($s,$id,$this->rv(),['complete'=>true],$items,$meta);$c->runPrecheck($s,$id,$this->rv(),$meta);$c->confirmStep($s,$id,'depreciation','skipped',null,$this->rv(),$meta);
        $fx=$c->fxPreview($s,$id);$c->runFxRevaluation($s,$id,[],$this->rv(),$meta,isset($fx['snapshot_hash'])?['snapshot_hash'=>$fx['snapshot_hash']]:[]);
        foreach(['estimates','deferrals','provisions','income_tax'] as $step)$c->confirmStep($s,$id,$step,'skipped',null,$this->rv(),$meta);
        self::assertSame('closed',$c->closeBooks($s,$id,$this->rv(),$meta)['status']);
        if($rates!==null){$p->exec("DELETE FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN ('2025-12-31','2026-12-31')");$ins=$p->prepare('INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES(?,?,?)');foreach($rates as $r)$ins->execute([$r['rate_date'],$r['currency_code'],$r['rate']]);}
    }
    protected function tearDown():void{if(isset($this->db)){$p=$this->db->pdo();if($p->inTransaction())$p->rollBack();if(str_contains($this->name(),'LatePersist')||str_contains($this->name(),'LateDecisionPersist')){$p->exec('SET @conversion_test_mutant=0');$p->exec('DROP TRIGGER IF EXISTS conversion_test_line_mutant');$p->exec('DROP TRIGGER IF EXISTS conversion_test_header_mutant');}}}
    private function rv():int{return(int)$this->container->get(AccountingPeriodRepository::class)->findById($this->supplier,$this->source)['row_version'];}
    public function testNativeCloseProducesExplicitBalancedCellsWithoutDml():void
    {
        $before=$this->dump();$r=new FrameworkConversionRepository($this->db);$snapshot=$r->source($this->supplier,$this->source,$this->target);$cells=$r->cells($snapshot);
        self::assertSame($before,$this->dump());self::assertCount(5,$cells);self::assertSame(0,array_sum(array_column($cells,'czk_cents')));
        self::assertSame(-40000,array_values(array_filter($cells,fn($c)=>$c['role']==='pending_approval'))[0]['czk_cents']);
        self::assertSame(-70000,array_values(array_filter($cells,fn($c)=>$c['role']==='retained'))[0]['czk_cents']);
    }
    public function testNative500CloseConvertsToActual504OpeningWithSeparate931And932():void
    {
        $w=$this->container->get(FrameworkConversionWorkflow::class);$choices=$w->choices($this->supplier,$this->source,$this->target);
        $command=['source_period_id'=>$this->source,'target_period_id'=>$this->target,'evidence'=>'Synthetic approved conversion record','definitions'=>$choices['definitions'],'mapping'=>[]];
        foreach($choices['cells'] as $cell)$command['mapping'][$cell['cell_key']]=['account_code'=>match($cell['role']){'pending_approval'=>'931','retained'=>'932',default=>$cell['account_code']},'activity'=>'main','activity_kind'=>['code'=>'SYNTHETIC_CONVERSION','name'=>'Synthetic activity'],'tax_regime'=>'out_of_scope'];
        $before=$this->dump();$preview=$w->preview($this->supplier,$command,$this->actor);self::assertSame($before,$this->dump());
        $result=$w->confirm($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'conversion-synthetic-1');
        self::assertGreaterThan(0,$result['opening_entry_id']);self::assertSame($result,$w->confirm($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'conversion-synthetic-1'));
        $a=$this->db->pdo()->prepare("SELECT action,user_id FROM activity_log WHERE supplier_id=? AND entity_type='journal_entry' AND entity_id=?");$a->execute([$this->supplier,$result['opening_entry_id']]);
        self::assertSame([['action'=>'accounting.posted','user_id'=>$this->actor]],array_map(fn($r)=>['action'=>$r['action'],'user_id'=>(int)$r['user_id']],$a->fetchAll(\PDO::FETCH_ASSOC)));
        $q=$this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no');$q->execute([$this->supplier,$result['opening_entry_id']]);$lines=$q->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame('400.00',array_values(array_filter($lines,fn($l)=>$l['account_code']==='931'))[0]['amount']);self::assertSame('700.00',array_values(array_filter($lines,fn($l)=>$l['account_code']==='932'))[0]['amount']);
        self::assertContains('961',array_column($lines,'account_code'));self::assertNotContains('701',array_column($lines,'account_code'));
        self::assertSame('cz_500',$this->container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)->forDate($this->supplier,'2025-12-31'));
        self::assertSame('cz_504',$this->container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)->forDate($this->supplier,'2026-01-01'));
    }
    public function testNext504ForeignNativeCaseUsesProvedConversionOpening():void
    {
        $result=$this->converted;$periods=$this->container->get(AccountingPeriodRepository::class);self::assertTrue($periods->setStatusCas($this->source,$this->supplier,'approved',$this->rv(),$this->actor,['body'=>'Synthetic competent assembly','decision_ref'=>'Synthetic approved2025 statements','document_hash'=>hash('sha256','synthetic approved statements')]));
        $decisions=$this->container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionResultDecision::class);$choices=$decisions->choices($this->supplier,$result['conversion_id'],'2026-03-01');$body=['decision_date'=>'2026-03-01','decision_authority'=>'Synthetic competent assembly','decision_reference'=>'Synthetic approved profit appropriation','allocations'=>array_map(fn($cell)=>['cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>\MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($cell['available_cents']))],$choices['cells'])];$before=$this->dump();$review=$decisions->preview($this->supplier,$result['conversion_id'],$body,$this->actor);self::assertSame($before,$this->dump());$decision=$decisions->confirm($this->supplier,$result['conversion_id'],$body,$this->actor,$review['state_hash'],$review['preview_hash'],'synthetic-approve500-result');self::assertGreaterThan(0,$decision['journal_entry_id']);
        $c=$this->container->get(ClosingService::class);$s=$this->supplier;$id=$this->target;$periods=$this->container->get(AccountingPeriodRepository::class);$rv=fn()=>(int)$periods->findById($s,$id)['row_version'];$meta=['user_id'=>$this->actor,'posted_by'=>$this->actor];
        $c->start($s,$id,$rv(),$meta);$items=[];foreach($c->inventoryPreview($s,$id)['rows'] as $row)$items[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];$c->saveInventory($s,$id,$rv(),['complete'=>true],$items,$meta);$c->runPrecheck($s,$id,$rv(),$meta);$c->confirmStep($s,$id,'depreciation','skipped',null,$rv(),$meta);
        $preview=$c->fxPreview($s,$id);self::assertTrue($preview['complete']);self::assertSame([],$preview['case_findings']);self::assertSame([],$preview['case_unresolved']);self::assertSame('cash_account',$preview['positions'][0]['case_origin']['kind']);self::assertSame('4.00',$preview['positions'][0]['foreign_balance']);self::assertSame('104.00',$preview['positions'][0]['new_czk']);self::assertSame('4.00',$preview['positions'][0]['diff']);self::assertSame('4.00',$preview['totals']['gain']);
        $result=$c->runFxRevaluation($s,$id,[],$rv(),$meta,['snapshot_hash'=>$preview['snapshot_hash']]);self::assertNotEmpty($result);$entry=$this->container->get(JournalEntryRepository::class)->findBySource($s,'fx_revaluation',\MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxBank($id));self::assertNotNull($entry);$q=$this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY a.account_code,l.side');$q->execute([$s,$entry['id']]);self::assertSame([['account_code'=>'221','side'=>'debit','amount'=>'4.00'],['account_code'=>'645','side'=>'credit','amount'=>'4.00']],$q->fetchAll(\PDO::FETCH_ASSOC));
    }
    public function testRehashedForeignOriginTamperCannotChangeNativeOpeningLineage():void
    {
        $result=$this->converted;$r=new FrameworkConversionRepository($this->db);$row=$r->receipt($this->supplier,$result['conversion_id'],true);$receipt=$row['receipt'];$tampered=null;
        foreach($receipt['plan']['mapped_cells'] as &$mapped)if($mapped['source']['currency_code']==='EUR'){$mapped['source']['origin']['account_id']=999999;$tampered=$mapped['source'];}unset($mapped);self::assertNotNull($tampered);
        $this->db->pdo()->prepare('UPDATE accounting_framework_conversions SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([FrameworkConversionRepository::json($receipt),FrameworkConversionRepository::hash($receipt),$this->supplier,$result['conversion_id']]);$this->db->pdo()->prepare('UPDATE accounting_framework_conversion_cells SET source_json=? WHERE supplier_id=? AND conversion_id=? AND cell_key=?')->execute([FrameworkConversionRepository::json($tampered),$this->supplier,$result['conversion_id'],$tampered['cell_key']]);
        try{(new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier,$this->target,true);self::fail('Rehashed origin must be proved against native500 sources');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_cells_changed',$e->errorCode);}
    }
    public function testKnownLegalSvjCannotConvertFromGlWithoutTypedS6Adoption():void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET legal_form='145' WHERE id=?")->execute([$this->supplier]);$w=$this->container->get(FrameworkConversionWorkflow::class);$command=$this->command();$before=$this->dump();try{$w->preview($this->supplier,$command,$this->actor);self::fail('GL-only SVJ conversion requires typed S6 adoption');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_svj_adoption_required',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testOffbalancePositionsCannotDisappearBehindNewOpeningAnchor():void
    {
        $w=$this->container->get(FrameworkConversionWorkflow::class);try{$w->choices($this->supplier,$this->source,$this->target);self::fail('Nonzero offbalance positions require explicit owner adoption');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_offbalance_adoption_required',$e->errorCode);}
    }
    public function testSmallAssetPendingReleaseCannotBeSilentlyDropped():void
    {
        $w=$this->container->get(FrameworkConversionWorkflow::class);try{$w->choices($this->supplier,$this->source,$this->target);self::fail('Actual small asset defer ledger needs a proved next-year release');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_native_followup_required',$e->errorCode);}
    }
    public function testGenericNativeOpeningInsertCannotDuplicateOwnedConversion():void
    {
        $result=$this->converted;$receipt=(new FrameworkConversionRepository($this->db))->receipt($this->supplier,$result['conversion_id'],true)['receipt'];$before=$this->dump();$header=array_replace($receipt['header'],['source_id'=>$this->target+999999]);
        try{$this->container->get(JournalEntryRepository::class)->insert($header,$receipt['lines']);self::fail('A generic different source ID must not insert a second owned opening');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('framework_conversion_source_immutable',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testCustomAnalytic336And574UseExplicitDatedDefinitionsWithHistoricalReportsPreserved():void
    {
        $chart=$this->container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);$periods=$this->container->get(AccountingPeriodRepository::class);self::assertTrue($periods->setStatusCas($this->source,$this->supplier,'approved',$this->rv(),$this->actor,['body'=>'Synthetic competent assembly','decision_ref'=>'Synthetic approved statements','document_hash'=>hash('sha256','synthetic approved custom statements')]));$before=$this->reports();$old=$chart->findByCode($this->supplier,'336.007','2025-12-31');$result=$this->convert();self::assertSame($before,$this->reports());$new=$chart->findByCode($this->supplier,'336.007','2026-01-01');self::assertSame($old['id'],$new['id']);self::assertSame($old['account_type'],$new['account_type']);self::assertSame($old['parent_id'],$new['parent_id']);self::assertSame('debit',$chart->findByCode($this->supplier,'336.007','2025-12-31')['normal_side']);self::assertNull($new['normal_side']);self::assertSame('credit',$chart->findByCode($this->supplier,'574','2026-01-01')['normal_side']);self::assertSame($before,$this->reports());
        $decisions=$this->container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionResultDecision::class);$choices=$decisions->choices($this->supplier,$result['conversion_id'],'2026-03-01');$body=['decision_date'=>'2026-03-01','decision_authority'=>'Synthetic competent assembly','decision_reference'=>'Synthetic approved appropriation','allocations'=>array_map(fn($cell)=>['cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>\MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($cell['available_cents']))],$choices['cells'])];$preview=$decisions->preview($this->supplier,$result['conversion_id'],$body,$this->actor);$decisions->confirm($this->supplier,$result['conversion_id'],$body,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-custom-result');self::assertSame($before,$this->reports());
        self::assertSame('cz_504',$this->container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class)->balanceSheet($this->supplier,$this->target,'2026-12-31','full')['accounting_framework']);
    }
    public function testCustomAccountTypeCannotBeSilentlyOverwritten():void
    {
        $w=$this->container->get(FrameworkConversionWorkflow::class);$command=$this->command();foreach($command['definitions'] as &$definition)if($definition['account_code']==='221')$definition['account_type']='liability';unset($definition);$before=$this->dump();try{$w->preview($this->supplier,$command,$this->actor);self::fail('Existing account identity/type must remain immutable');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_account_identity_immutable',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testMixedSourceFrameworkHistoryCannotBeApprovedAsNative500Conversion():void
    {
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504'),(?,'2025-07-01','cz_500')")->execute([$this->supplier,$this->supplier]);try{$this->container->get(FrameworkConversionWorkflow::class)->choices($this->supplier,$this->source,$this->target);self::fail('Mixed source history must not be reclassified by its end date');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_source_500_required',$e->errorCode);}
    }
    public function testProfitTupleGrossCellsMustNotNetAcrossSourceCostCenters():void
    {
        $w=$this->container->get(FrameworkConversionWorkflow::class);$choices=$w->choices($this->supplier,$this->source,$this->target);$profit=array_values(array_filter($choices['cells'],fn($cell)=>$cell['role']==='pending_approval'));self::assertCount(2,$profit);self::assertSame(0,array_sum(array_column($profit,'czk_cents')));$centers=array_column($profit,'cost_center');sort($centers);self::assertSame(['A','B'],$centers);foreach($profit as $cell)self::assertNotEmpty($cell['source_line_ids']);
        $result=$this->convert();$periods=$this->container->get(AccountingPeriodRepository::class);$rv=(int)$periods->findById($this->supplier,$this->target)['row_version'];try{$this->container->get(ClosingService::class)->start($this->supplier,$this->target,$rv,['user_id'=>$this->actor]);self::fail('Gross zero-net931 positions require explicit result decision');}catch(\MyInvoice\Service\Accounting\Closing\ClosingException $e){self::assertSame('profit_not_distributed',$e->errorCode);}
        self::assertTrue($periods->setStatusCas($this->source,$this->supplier,'approved',$this->rv(),$this->actor,['body'=>'Synthetic competent assembly','decision_ref'=>'Synthetic approved gross result','document_hash'=>hash('sha256','synthetic gross approval')]));$decision=$this->container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionResultDecision::class);$cells=$decision->choices($this->supplier,$result['conversion_id'],'2026-03-01')['cells'];self::assertCount(2,$cells);$body=['decision_date'=>'2026-03-01','decision_authority'=>'Synthetic competent assembly','decision_reference'=>'Synthetic explicit per-cell result decision','allocations'=>array_map(fn($cell)=>['cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>\MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($cell['available_cents']))],$cells)];$preview=$decision->preview($this->supplier,$result['conversion_id'],$body,$this->actor);$decision->confirm($this->supplier,$result['conversion_id'],$body,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-gross-result');self::assertSame('closing',$this->container->get(ClosingService::class)->start($this->supplier,$this->target,$rv,['user_id'=>$this->actor])['status']);
    }
    private function command():array
    {
        $choices=$this->container->get(FrameworkConversionWorkflow::class)->choices($this->supplier,$this->source,$this->target);$command=['source_period_id'=>$this->source,'target_period_id'=>$this->target,'evidence'=>'Synthetic approved conversion record','definitions'=>$choices['definitions'],'mapping'=>[]];
        foreach($choices['cells'] as $cell)$command['mapping'][$cell['cell_key']]=['account_code'=>match($cell['role']){'pending_approval'=>'931','retained'=>'932',default=>$cell['account_code']},'activity'=>'main','activity_kind'=>['code'=>'SYNTHETIC_CONVERSION','name'=>'Synthetic activity'],'tax_regime'=>'out_of_scope'];return $command;
    }
    private function convert():array
    {$w=$this->container->get(FrameworkConversionWorkflow::class);$command=$this->command();$preview=$w->preview($this->supplier,$command,$this->actor);return $w->confirm($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-convert');}
    private function reports():string
    {
        $f=$this->container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class);$reports=['bs'=>$f->balanceSheet($this->supplier,$this->source,'2025-12-31','full'),'pl'=>$f->incomeStatement($this->supplier,$this->source,'2025-12-31','full'),'trial'=>$this->container->get(\MyInvoice\Repository\LedgerReportRepository::class)->trialBalanceRows($this->supplier,'2025-01-01','2025-12-31','2025-01-01',true,[],true)];foreach($reports as &$report)unset($report['entity']['prepared_at']);unset($report);return FrameworkConversionRepository::json($reports);
    }
    public function testHistorical500StatementsRemainByteIdentical():void
    {$before=$this->reports();$this->convert();self::assertSame($before,$this->reports());}
    public function testFailedCatalogCreationRollsBackDefinitionsFrameworkAndAllJournalWrites():void
    {
        $this->db->pdo()->prepare("INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES(?,'activity','Synthetic conflicting user type','custom')")->execute([$this->supplier]);$command=$this->command();$w=$this->container->get(FrameworkConversionWorkflow::class);$preview=$w->preview($this->supplier,$command,$this->actor);$before=$this->dump();
        try{$w->confirm($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-catalog-conflict');self::fail('Expected catalog owner conflict');}catch(\MyInvoice\Service\Accounting\Dimension\DimensionException $e){self::assertSame('system_dimension_code_conflict',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testPreviewIsBoundToActorAndCurrentState():void
    {
        $command=$this->command();$w=$this->container->get(FrameworkConversionWorkflow::class);$preview=$w->preview($this->supplier,$command,$this->actor);$before=$this->dump();
        try{$w->confirm($this->supplier,$command,$this->actor+1,$preview['state_hash'],$preview['preview_hash'],'synthetic-wrong-actor');self::fail('Expected actor mismatch');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_preview_stale',$e->errorCode);}self::assertSame($before,$this->dump());
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET name='Synthetic changed source account' WHERE supplier_id=? AND account_code='221'")->execute([$this->supplier]);$before=$this->dump();
        try{$w->confirm($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-stale');self::fail('Expected current state mismatch');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_preview_stale',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testSourceCloseRevertIsBlockedAfterConversion():void
    {
        self::assertNotNull($this->converted);$before=$this->dump();try{$this->container->get(ClosingService::class)->revertStep($this->supplier,$this->source,'close_books',$this->rv(),['user_id'=>$this->actor]);self::fail('Converted native close must remain owned');}catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Closing\ClosingException $e){self::assertSame('framework_conversion_source_immutable',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testOwnedCancelRestoresPriorConfigAndKeepsNative500Close():void
    {
        $before=$this->dump();$reports=$this->reports();$result=$this->convert();$w=$this->container->get(FrameworkConversionWorkflow::class);$preview=$w->cancellationPreview($this->supplier,$result['conversion_id'],$this->actor);$cancel=$w->cancel($this->supplier,$result['conversion_id'],$this->actor,$preview['receipt_hash'],$preview['preview_hash'],'synthetic-cancel');self::assertSame('cancelled',$cancel['status']);self::assertSame($cancel,$w->cancel($this->supplier,$result['conversion_id'],$this->actor,$preview['receipt_hash'],$preview['preview_hash'],'synthetic-cancel'));self::assertSame($reports,$this->reports());
        $old=json_decode($before,true,512,JSON_THROW_ON_ERROR);$after=json_decode($this->dump(),true,512,JSON_THROW_ON_ERROR);foreach(['chart_of_accounts','accounting_account_definition_history','supplier_accounting_frameworks','journal_entries','journal_entry_lines','dimension_types','dimension_values'] as $table)self::assertSame($old[$table],$after[$table],$table);self::assertSame('cancelled',$after['accounting_framework_conversions'][0]['status']);
    }
    public function testNativeOpeningMutationIsBlocked():void
    {
        $result=$this->converted;$before=$this->dump();try{$this->container->get(PostingService::class)->reverse($this->supplier,$result['opening_entry_id'],['entry_date'=>'2026-02-01','user_id'=>$this->actor,'posted_by'=>$this->actor]);self::fail('Expected protected native opening');}catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Closing\ClosingException $e){self::assertSame('framework_conversion_source_immutable',$e->errorCode);}self::assertSame($before,$this->dump());
    }
    public static function latePersistMutants():array{return ['amount'=>[1,'conversion_written_lines_changed'],'entry_date'=>[2,'conversion_written_header_changed']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('latePersistMutants')]
    public function testLatePersistMutationRollsBackEveryConversionWrite(int $mutant,string $code):void
    {
        $w=$this->container->get(FrameworkConversionWorkflow::class);$command=$this->command();$preview=$w->preview($this->supplier,$command,$this->actor);$before=$this->dump();$this->db->pdo()->exec('SET @conversion_test_mutant='.$mutant);
        try{$w->confirm($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-late-persist');self::fail('Persisted header and every financial line must match the approved native plan');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $error){self::assertSame($code,$error->errorCode);}self::assertSame($before,$this->dump());
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('latePersistMutants')]
    public function testLateDecisionPersistMutationRollsBackEveryDecisionWrite(int $mutant,string $code):void
    {
        $result=$this->converted;$periods=$this->container->get(AccountingPeriodRepository::class);self::assertTrue($periods->setStatusCas($this->source,$this->supplier,'approved',$this->rv(),$this->actor,['body'=>'Synthetic competent assembly','decision_ref'=>'Synthetic result approval','document_hash'=>hash('sha256','synthetic late decision')]));
        $decision=$this->container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionResultDecision::class);$cells=$decision->choices($this->supplier,$result['conversion_id'],'2026-03-01')['cells'];$body=['decision_date'=>'2026-03-01','decision_authority'=>'Synthetic assembly','decision_reference'=>'Synthetic native result decision','allocations'=>array_map(fn($cell)=>['cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>\MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($cell['available_cents']))],$cells)];$preview=$decision->preview($this->supplier,$result['conversion_id'],$body,$this->actor);$before=$this->dump();$this->db->pdo()->exec('SET @conversion_test_mutant='.$mutant);
        try{$decision->confirm($this->supplier,$result['conversion_id'],$body,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-late-decision');self::fail('Result decision must prove its actual persisted header and native lines');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $error){self::assertSame($code,$error->errorCode);}self::assertSame($before,$this->dump());
    }
    public function testCancelledConversionReleasesLiveSourceCloseAndPreservesHistoricalIdentity():void
    {
        $result=$this->converted;$r=new FrameworkConversionRepository($this->db);$original=$r->receipt($this->supplier,$result['conversion_id'],true);$sourceEntry=(int)$original['source_entry_id'];$w=$this->container->get(FrameworkConversionWorkflow::class);$preview=$w->cancellationPreview($this->supplier,$result['conversion_id'],$this->actor);$w->cancel($this->supplier,$result['conversion_id'],$this->actor,$preview['receipt_hash'],$preview['preview_hash'],'synthetic-cancel-release');
        $this->container->get(ClosingService::class)->revertStep($this->supplier,$this->source,'close_books',$this->rv(),['user_id'=>$this->actor]);$cancelled=$r->receipt($this->supplier,$result['conversion_id'],true);self::assertNull($cancelled['source_entry_id']);self::assertSame($sourceEntry,$cancelled['receipt']['plan']['source_entry_id']);$q=$this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$sourceEntry]);self::assertFalse($q->fetchColumn());
    }
    private function dump():string
    {$out=[];foreach(['chart_of_accounts','accounting_account_definition_history','supplier_accounting_frameworks','journal_entries','journal_entry_lines','journal_entry_line_dimensions','journal_entry_line_dimension_splits','accounting_framework_conversions','accounting_framework_conversion_cells','accounting_framework_conversion_commands','accounting_framework_conversion_decisions','dimension_types','dimension_values'] as $table){$q=$this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->supplier]);$out[$table]=$q->fetchAll(\PDO::FETCH_ASSOC);}return FrameworkConversionRepository::json($out);}
}
