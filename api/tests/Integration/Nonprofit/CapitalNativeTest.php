<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Capital\CapitalCapacity;
use MyInvoice\Service\Nonprofit\Capital\CapitalException;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
final class CapitalNativeTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private CapitalService $capital;
    private DepreciationPostingService $depreciation;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $asset;
    private int $provider;
    private int $kind;
    private ?Connection $cloneConnection=null;
    private ?PDO $cloneServer=null;
    private ?string $cloneSource=null;
    private ?string $cloneTarget=null;
    private ?\MyInvoice\Infrastructure\Config\Config $cloneConfig=null;
    private array $catalog;
    private array $dimensions;
    protected function setUp():void
    {
        $c=Bootstrap::buildContainer();$this->db=$c->get(Connection::class);
        if($this->name()==='testFullCloneKeepsCapitalSourceConditionsNativeUseAndExactReversal'){
            $cfg=$c->get(\MyInvoice\Infrastructure\Config\Config::class);
            $base=(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$base);
            $this->cloneSource='myucto_capital_src_'.bin2hex(random_bytes(5)).'_test';$this->cloneTarget='myucto_capital_dst_'.bin2hex(random_bytes(5)).'_test';
            $this->cloneServer=new PDO('mysql:host='.$cfg->get('db.host').';port='.$cfg->get('db.port',3306).';charset=utf8mb4',$cfg->get('db.user'),$cfg->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $cloner=new \MyInvoice\Service\Anonymization\DatabaseCloner($this->cloneServer);$plan=$cloner->plan($base);$cloner->copyTables($plan,$this->cloneSource,static function():void{});$cloner->finalize($plan,$this->cloneSource);
            $data=$cfg->all();$data['db']['name']=$this->cloneSource;$this->cloneConfig=new \MyInvoice\Infrastructure\Config\Config($data);
            $this->cloneConnection=Connection::withoutSharedTestConnection(fn()=>new Connection($this->cloneConfig));$this->db=$this->cloneConnection;$c->set(Connection::class,$this->db);
        }
        $this->pdo=$this->db->pdo();self::assertStringEndsWith('_test',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn());$this->pdo->beginTransaction();
        $seed=(int)$this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$seed);$this->actor=(int)$this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier=$this->createIsolatedSupplier($this->pdo,$seed);$this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $this->period=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2026,'2026-01-01','2026-12-31');$this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $this->catalog=$c->get(SystemDimensionCatalog::class)->ensureForFramework($this->supplier,'2026-01-01');$this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'SPORT','Synthetic sporting activity')")->execute([$this->catalog['activity_kind']['id'],$this->supplier]);$this->kind=(int)$this->pdo->lastInsertId();
        $this->dimensions=[$this->catalog['activity']['id']=>$this->catalog['activity']['values']['main'],$this->catalog['activity_kind']['id']=>$this->kind,$this->catalog['tax_regime']['id']=>$this->catalog['tax_regime']['values']['exempt']];
        foreach(['221'=>'asset','022'=>'asset','082'=>'asset','551'=>'expense','518'=>'expense','901'=>'equity','911'=>'equity','648'=>'revenue','649'=>'revenue','691'=>'revenue','682'=>'revenue']as$code=>$type)$this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type)VALUES(?,?,?,?)')->execute([$this->supplier,(string)$code,'Synthetic '.$code,$type]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)VALUES(?,'Synthetic capital donor','Synthetic street','Synthetic city','10000',203,'capital@example.test','cs',?,0,1)")->execute([$this->supplier,(int)$this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn()]);$this->provider=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO assets(supplier_id,inventory_number,name,input_price,acquisition_date,put_into_use_date,status,accumulated_account_code,tax_method,acc_method,acc_useful_life_months)VALUES(?,'CAPITAL-ASSET','Synthetic machine',500000,'2025-12-01','2025-12-01','in_use','082','none','straight_line',60)")->execute([$this->supplier]);$this->asset=(int)$this->pdo->lastInsertId();
        foreach($this->dimensions as$type=>$value)$this->pdo->prepare("INSERT INTO document_dimensions(supplier_id,doc_type,doc_id,item_no,dimension_type_id,dimension_value_id,assignment_origin)VALUES(?,'asset',?,0,?,?,'explicit')")->execute([$this->supplier,$this->asset,$type,$value]);
        $this->posting=$c->get(PostingService::class);$this->capital=new CapitalService($this->db,$this->posting);$this->depreciation=$c->get(DepreciationPostingService::class);
    }
    protected function tearDown():void
    {
        if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();
        $this->cloneConnection?->close();
        if($this->cloneServer!==null)foreach([$this->cloneTarget,$this->cloneSource]as$name){
            if($name===null||!preg_match('/^myucto_capital_(?:src|dst)_[a-f0-9]{10}_test$/D',$name))throw new \LogicException('Unexpected owned clone schema.');
            $this->cloneServer->exec('DROP DATABASE IF EXISTS '.\MyInvoice\Service\Anonymization\DatabaseCloner::quote($name));
        }
    }

    private function posted(string $account,string $amount,string $side='credit'):int
    {
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>$account,'side'=>$side,'amount'=>$amount,'dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])],['account_code'=>'221','side'=>$side==='credit'?'debit':'credit','amount'=>$amount,'dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])]],['entry_date'=>'2026-01-01','description'=>'Synthetic native capital proof','posted'=>true,'posted_by'=>$this->actor]);
        $q=$this->pdo->prepare('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code=?');$q->execute([$this->supplier,$entry,$account]);return (int)$q->fetchColumn();
    }
    private function policy(string $kind,int $line):array
    {
        return ['kind'=>$kind,'source_line_id'=>$line,'asset_id'=>$kind==='investment'?$this->asset:null,'provider_id'=>$this->provider,'revenue_tax_regime_id'=>$this->catalog['tax_regime']['values']['exempt'],'title'=>'Synthetic '.$kind,'purpose'=>'Sporting equipment and activity','evidence'=>'Synthetic governing decision and donor contract','decision_authority'=>'Synthetic authorized board','valid_from'=>'2026-01-01','valid_to'=>'2035-12-31','activity_kind_ids'=>[$this->kind],'account_prefixes'=>[$kind==='investment'?'551':'518']];
    }
    private function enroll(string $kind,int $line,string $token='capital-source'):array
    {
        $policy=$this->policy($kind,$line);$p=$this->capital->previewSource($this->supplier,$policy);return $this->capital->confirmSource($this->supplier,$policy,$p['input_hash'],'Synthetic explicit future release approval',$token,$this->actor);
    }
    public function testActualDepreciationAtomicallyReleasesSixtyPercentThrough901649():void
    {
        $this->enroll('investment',$this->posted('901','300000.00'));
        $result=$this->depreciation->bookYear($this->supplier,2026,['posted_by'=>$this->actor,'user_id'=>$this->actor]);self::assertSame([],$result['errors']);self::assertSame(99996.0,$result['total_accounting']);
        $rows=$this->capital->read($this->supplier)['sources'];self::assertCount(1,$rows);self::assertSame(5999760,$rows[0]['used_cents']);self::assertCount(1,$rows[0]['events']);
        self::assertSame(['901','649'],array_column($rows[0]['events'][0]['receipt']['lines'],'account_code'));self::assertSame(24000240,$rows[0]['available_cents']);
        $again=$this->depreciation->bookYear($this->supplier,2026,['posted_by'=>$this->actor,'user_id'=>$this->actor]);self::assertSame([],$again['errors']);self::assertSame($rows,$this->capital->read($this->supplier)['sources']);
    }
    public function testReversedEarlierInvestmentReleaseCannotBeMovedToANewerDepreciationCost():void
    {
        $this->enroll('investment',$this->posted('901','300000.00'));$first=$this->depreciation->bookYear($this->supplier,2026,['posted_by'=>$this->actor,'user_id'=>$this->actor]);self::assertSame([],$first['errors']);
        $event=$this->capital->read($this->supplier)['sources'][0]['events'][0];$preview=$this->capital->previewReverse($this->supplier,$event['id'],'2026-12-31');$this->capital->confirmReverse($this->supplier,$event['id'],'2026-12-31',$preview['input_hash'],'Synthetic controlled reversal of earlier investment use','reverse-investment',$this->actor);
        Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->create($this->supplier,2027,'2027-01-01','2027-12-31');
        $next=$this->depreciation->bookYear($this->supplier,2027,['posted_by'=>$this->actor,'user_id'=>$this->actor]);self::assertNotSame([],$next['errors']);self::assertSame(0,$next['booked']);
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id=? AND fiscal_year=2027");$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());self::assertSame(0,$this->capital->read($this->supplier)['sources'][0]['used_cents']);
    }
    public function testSourceRegistrationIsImmutableTenantBoundAndIdempotent():void
    {
        $line=$this->posted('901','300000.00');$policy=$this->policy('investment',$line);$p=$this->capital->previewSource($this->supplier,$policy);$source=$this->capital->confirmSource($this->supplier,$policy,$p['input_hash'],'Synthetic approved registration','registered',$this->actor);
        self::assertSame($source,$this->capital->confirmSource($this->supplier,$policy,$p['input_hash'],'Synthetic approved registration','registered',$this->actor));
        try{$this->capital->previewSource(1,$policy);self::fail('Foreign source cannot be registered');}catch(CapitalException $e){self::assertSame('nonprofit_capital_changed',$e->errorCode);}
        try{$this->pdo->prepare('UPDATE nonprofit_capital_sources SET amount_cents=1 WHERE supplier_id=? AND id=?')->execute([$this->supplier,$source['id']]);self::fail('Source must be append only');}catch(\PDOException $e){self::assertStringContainsString('nonprofit_capital_immutable',$e->getMessage());}
    }
    public function testFundDoesNotConvertUnknownExpenseClassificationIntoAuthoritativeModuleOrigin():void
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$cost=$this->posted('518','800.00','debit');$this->pdo->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin='unknown' WHERE supplier_id=? AND line_id=? AND dimension_type_id=?")->execute([$this->supplier,$cost,$this->catalog['activity_kind']['id']]);$this->expectException(CapitalException::class);$this->capital->previewFund($this->supplier,$source['id'],$cost,40000,'2026-12-31');
    }
    public function testSourcePreviewBindsDimensionProvenanceAsWellAsTheNumericValues():void
    {
        $line=$this->posted('911','1000.00');$policy=$this->policy('fund',$line);$preview=$this->capital->previewSource($this->supplier,$policy);
        $this->pdo->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin_ref='Synthetic changed provenance' WHERE supplier_id=? AND line_id=?")->execute([$this->supplier,$line]);
        $this->expectException(CapitalException::class);$this->capital->confirmSource($this->supplier,$policy,$preview['input_hash'],'Synthetic prior preview must not accept changed provenance','origin-cas',$this->actor);
    }
    private function fundUse(array $source,int $cost,int $amount,string $token='fund-use'):array
    {
        $preview=$this->capital->previewFund($this->supplier,$source['id'],$cost,$amount,'2026-12-31');
        return $this->capital->confirmFund($this->supplier,$source['id'],$cost,$amount,'2026-12-31',$preview['input_hash'],'Synthetic authorized actual fund use',$token,$this->actor);
    }
    public function testActualFundUses911648AndSharesNativeCostCapacityAndReversal():void
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$cost=$this->posted('518','800.00','debit');$event=$this->fundUse($source,$cost,60000);
        self::assertSame(['911','648'],array_column($event['receipt']['lines'],'account_code'));$claims=(new CapitalCapacity($this->db))->costClaims($this->supplier,$cost);self::assertCount(1,$claims);self::assertSame('60000',$claims[0]['amount_cents']);self::assertSame($event['id'],$claims[0]['event_id']);self::assertSame(64,strlen($claims[0]['request_hash']));self::assertSame('60000',(new CapitalCapacity($this->db))->coveredCost($this->supplier,$cost));
        try{$this->capital->previewFund($this->supplier,$source['id'],$cost,20001,'2026-12-31');self::fail('Another source cannot exceed this native cost');}catch(CapitalException $e){self::assertSame('nonprofit_capital_cost_overcovered',$e->errorCode);}
        $otherCost=$this->posted('518','800.00','debit');
        try{$this->capital->previewFund($this->supplier,$source['id'],$otherCost,40001,'2026-12-31');self::fail('Fund source cannot become debit');}catch(CapitalException $e){self::assertSame('nonprofit_capital_source_overdrawn',$e->errorCode);}
        $income=$event['receipt']['lines'][1]['id'];
        try{(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->income($this->supplier,$income);self::fail('Own648release must not become another funding source');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('accounting_cost_coverage_source_invalid',$e->errorCode);}
        $preview=$this->capital->previewReverse($this->supplier,$event['id'],'2026-12-31');$reverse=$this->capital->confirmReverse($this->supplier,$event['id'],'2026-12-31',$preview['input_hash'],'Synthetic exact reversal','reverse-fund',$this->actor);
        self::assertSame('0',(new CapitalCapacity($this->db))->coveredCost($this->supplier,$cost));self::assertSame($event['id'],$reverse['original_id']);
        $q=$this->pdo->prepare('SELECT reversed_by FROM journal_entries WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$event['entry_id']]);self::assertSame($reverse['entry_id'],(int)$q->fetchColumn());
        self::assertSame(100000,$this->capital->read($this->supplier)['sources'][0]['available_cents']);
    }
    public function testAtomicReleaseFailureRollsBackTheActualDepreciationAndPreservesCallerWork():void
    {
        $this->enroll('investment',$this->posted('901','300000.00'));
        $this->pdo->prepare("DELETE FROM chart_of_accounts WHERE supplier_id=? AND account_code='649'")->execute([$this->supplier]);
        $result=$this->depreciation->bookYear($this->supplier,2026,['posted_by'=>$this->actor,'user_id'=>$this->actor]);self::assertNotSame([],$result['errors']);self::assertSame(0,$result['booked']);self::assertTrue($this->pdo->inTransaction());
        foreach([['depreciation_entries','supplier_id'],['nonprofit_capital_events','supplier_id']]as[$table,$column]){$q=$this->pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());}
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='depreciation'");$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());
        self::assertCount(1,$this->capital->read($this->supplier)['sources']);
    }
    public function testNative648DirectCoverageCannotBeUsedAgainByFund():void
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'800.00','dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])],['account_code'=>'648','side'=>'credit','amount'=>'200.00','dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])],['account_code'=>'221','side'=>'credit','amount'=>'600.00','dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])]],['entry_date'=>'2026-01-01','description'=>'Synthetic directly matched fund coverage','posted'=>true,'posted_by'=>$this->actor]);
        $q=$this->pdo->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='518'");$q->execute([$this->supplier,$entry]);$cost=(int)$q->fetchColumn();
        self::assertSame(20000,(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->forCost($this->supplier,$cost)['covered_cents']);
        $this->expectException(CapitalException::class);$this->expectExceptionMessage('nekrytou');$this->capital->previewFund($this->supplier,$source['id'],$cost,60001,'2026-12-31');
    }
    public function testNativeDirectCoverageHonorsRedStornoAndRejectsAmbiguousMultipleCosts():void
    {
        $meta=['entry_date'=>'2026-01-01','description'=>'Synthetic coverage contra','posted'=>true,'posted_by'=>$this->actor];$dim=['dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])];
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'800.00']+$dim,['account_code'=>'649','side'=>'credit','amount'=>'200.00']+$dim,['account_code'=>'648','side'=>'credit','amount'=>'50.00','is_red_storno'=>true]+$dim,['account_code'=>'221','side'=>'credit','amount'=>'650.00']+$dim],$meta);
        $q=$this->pdo->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='518'");$q->execute([$this->supplier,$entry]);$cost=(int)$q->fetchColumn();$proof=new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db);self::assertSame(15000,$proof->forCost($this->supplier,$cost)['covered_cents']);
        $mixed=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'500.00']+$dim,['account_code'=>'551','side'=>'debit','amount'=>'300.00']+$dim,['account_code'=>'691','side'=>'credit','amount'=>'200.00']+$dim,['account_code'=>'221','side'=>'credit','amount'=>'600.00']+$dim],$meta);
        $q->execute([$this->supplier,$mixed]);$cost=(int)$q->fetchColumn();$this->expectException(\MyInvoice\Service\Accounting\PostingException::class);$this->expectExceptionMessage('více nákladových');$proof->forCost($this->supplier,$cost);
    }
    public function testMultipleInvestmentSourcesCannotRoundIntoMoreThanTheActualDepreciationCost():void
    {
        $this->pdo->prepare('UPDATE assets SET input_price=1000 WHERE supplier_id=? AND id=?')->execute([$this->supplier,$this->asset]);$this->enroll('investment',$this->posted('901','500.00'),'round-source-one');$this->enroll('investment',$this->posted('901','500.00'),'round-source-two');$asset=Bootstrap::buildContainer()->get(AssetRepository::class)->find($this->supplier,$this->asset);
        try{$this->depreciation->postAccountingEntry($this->supplier,$asset,['amount'=>0.01,'fiscal_year'=>2026,'residual_end'=>999.99,'months_count'=>1],'2026-12-31',['posted_by'=>$this->actor]);self::fail('Two rounded half-cent releases cannot finance a one-cent cost twice');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_capital_cost_overcovered',$e->errorCode);}
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_capital_events WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());$q=$this->pdo->prepare('SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());
    }
    public function testFundCannotBeReleasedBeforeItsRealSourceWasRecognized():void
    {
        $line=$this->posted('911','1000.00');$this->pdo->prepare('UPDATE journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id SET e.entry_date="2026-12-31" WHERE l.supplier_id=? AND l.id=?')->execute([$this->supplier,$line]);$source=$this->enroll('fund',$line);$cost=$this->posted('518','800.00','debit');
        $this->expectException(CapitalException::class);$this->capital->previewFund($this->supplier,$source['id'],$cost,40000,'2026-06-30');
    }
    public function testNativeGenericReversalCannotChangeOwnedSourceOrActiveCost():void
    {
        $line=$this->posted('911','1000.00');$source=$this->enroll('fund',$line);$cost=$this->posted('518','800.00','debit');$event=$this->fundUse($source,$cost,40000);$q=$this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id IN (?,?) ORDER BY id');$q->execute([$this->supplier,$line,$cost]);$entries=$q->fetchAll(PDO::FETCH_COLUMN);$entries[]=$event['entry_id'];
        foreach($entries as$entry){try{$this->posting->reverse($this->supplier,(int)$entry,['entry_date'=>'2026-12-31','posted_by'=>$this->actor]);self::fail('Generic native reversal cannot bypass the capital owner');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_capital_source_in_use',$e->errorCode);}}
        self::assertSame(40000,$this->capital->read($this->supplier)['sources'][0]['used_cents']);
    }
    public function testOwnedPostedEntryNoOpKeepsExactNativeIdentityAndMetadata():void
    {
        $line=$this->posted('911','1000.00');$this->enroll('fund',$line);
        $q=$this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$line]);$entry=(int)$q->fetchColumn();
        $repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);$before=$repo->findForUpdate($entry,$this->supplier);
        self::assertFalse($repo->markPosted($entry,$this->supplier,null));self::assertSame($before,$repo->findForUpdate($entry,$this->supplier));
    }
    public function testOwnedCapitalReferencesRejectPhysicalMutationBeforeAnySourceChange():void
    {
        $line=$this->posted('911','1000.00');$this->enroll('fund',$line);$q=$this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$line]);$entry=(int)$q->fetchColumn();
        $context=new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db);
        foreach([fn()=>$context->assertEntriesMutable($this->supplier,[$entry]),fn()=>$context->assertLinesMutable($this->supplier,[$line])]as$write){try{$write();self::fail('Owned capital source must reject physical mutation');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_capital_source_in_use',$e->errorCode);}}
        self::assertSame(100000,$this->capital->read($this->supplier)['sources'][0]['available_cents']);
    }
    public function testDescriptionEditCannotInvalidateTheFrozenCapitalSourceProof():void
    {
        $line=$this->posted('911','1000.00');$this->enroll('fund',$line);$q=$this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$line]);$entry=(int)$q->fetchColumn();
        try{Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class)->updateDescription($entry,$this->supplier,'Synthetic changed evidence',$this->actor);self::fail('Changed description must not invalidate a capital source');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_capital_source_in_use',$e->errorCode);}
    }
    public function testInvestmentSourceRejectsNativeTechnicalImprovementBeforeChangingItsValuation():void
    {
        $this->enroll('investment',$this->posted('901','300000.00'));
        $assets=Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Assets\AssetService::class);
        try{$assets->addImprovement($this->supplier,$this->asset,['completed_on'=>'2026-06-01','amount'=>'1000.00','description'=>'Synthetic technical improvement']);self::fail('Frozen investment valuation must reject technical improvement before persistence');}catch(\MyInvoice\Service\Accounting\Assets\AssetException $e){self::assertSame('nonprofit_capital_asset_basis_in_use',$e->errorCode);}
        self::assertSame(30000000,$this->capital->read($this->supplier)['sources'][0]['available_cents']);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM asset_improvements WHERE supplier_id=? AND asset_id=?');$q->execute([$this->supplier,$this->asset]);self::assertSame(0,(int)$q->fetchColumn());
    }
    public function testInvestmentSourceKeepsExistingNativeImprovementAndFrozenOpeningValuation():void
    {
        $assets=Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Assets\AssetService::class);
        $improvement=$assets->addImprovement($this->supplier,$this->asset,['completed_on'=>'2026-01-01','amount'=>'1000.00']);$this->enroll('investment',$this->posted('901','300000.00'));
        foreach([fn()=>$assets->deleteImprovement($this->supplier,$this->asset,$improvement['improvement']['id'])]as$write){try{$write();self::fail('Native mutation must retain the investment source valuation');}catch(\MyInvoice\Service\Accounting\Assets\AssetException $e){self::assertSame('nonprofit_capital_asset_basis_in_use',$e->errorCode);}}
        $this->pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type)VALUES(?,'042','Synthetic acquisition','asset')")->execute([$this->supplier]);
        $assets->update($this->supplier,$this->asset,['name'=>'Synthetic reviewed equipment name']);
        self::assertSame(50100000,$this->capital->read($this->supplier)['sources'][0]['source']['basis']['asset']['valuation_cents']);
    }
    public function testDirectNativeDimensionInsertCannotAddAssignmentsToAnOwnedCapitalSource():void
    {
        $line=$this->posted('911','1000.00');$this->enroll('fund',$line);$dimensions=new DimensionAssignmentRepository($this->db);
        $this->pdo->prepare("INSERT INTO dimension_types(supplier_id,code,name)VALUES(?,'CAPITAL-EXTRA','Synthetic extra classification')")->execute([$this->supplier]);$type=(int)$this->pdo->lastInsertId();$this->pdo->prepare("INSERT INTO dimension_values(supplier_id,type_id,code,name)VALUES(?,?,'EXTRA','Synthetic extra value')")->execute([$this->supplier,$type]);$value=(int)$this->pdo->lastInsertId();
        try{$dimensions->insertLineDimensions($this->supplier,$line,[$type=>$value],[$type=>['origin'=>'explicit','ref'=>null]]);self::fail('Direct native dimension insertion must retain capital source evidence');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_capital_source_in_use',$e->errorCode);}
        self::assertSame($this->dimensions,$dimensions->lineDimensions($this->supplier,[$line],true)[$line]);
    }
    public function testActualActionRequiresSessionAndValidatesEveryConfirmationField():void
    {
        $action=Bootstrap::buildContainer()->get(\MyInvoice\Action\Nonprofit\CapitalAction::class);$policy=$this->policy('fund',$this->posted('911','1000.00'));$valid=['operation'=>'register','policy'=>$policy,'input_hash'=>str_repeat('a',64),'reason'=>'Synthetic authorized review','idempotency_key'=>'capital-action'];$request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/accounting/nonprofit-capital/confirm')->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'admin']);
        foreach(['bearer',null]as$method)self::assertSame(403,$action->confirm($request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,$method)->withParsedBody($valid),new \Slim\Psr7\Response())->getStatusCode());$request=$request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session');
        foreach([['policy'=>null],['operation'=>'unknown'],['input_hash'=>null],['reason'=>[]],['extra'=>true]]as$invalid){$response=$action->confirm($request->withParsedBody(array_replace($valid,$invalid)),new \Slim\Psr7\Response());self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());}
        $response=$action->preview($request->withParsedBody(['operation'=>'register','policy'=>$policy]),new \Slim\Psr7\Response());self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());self::assertSame([],$this->capital->read($this->supplier)['sources']);
    }
    public function testHumanChoicesReadWithoutRowLocks():void
    {
        $this->posted('911','1000.00');
        $this->posted('518','800.00','debit');
        $lockedRows=function():int{
            // InnoDB obnovuje cache INNODB_TRX nejvýš po 100 ms.
            usleep(150000);
            $q=$this->pdo->query('SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=CONNECTION_ID()');
            return (int)$q->fetchColumn();
        };
        $before=$lockedRows();
        $choices=(new \MyInvoice\Service\Nonprofit\Capital\CapitalChoices($this->db))->read($this->supplier,$this->period);
        self::assertNotSame([],$choices['sources']);
        self::assertSame($before,$lockedRows(),'Čtení výběrů kapitálu nesmí zamykat řádky.');
    }
    public function testHumanChoicesAreTenantScopedSearchableAndRemoveAlreadyOwnedSources():void
    {
        $line=$this->posted('911','1000.00');$cost=$this->posted('518','800.00','debit');$choices=new \MyInvoice\Service\Nonprofit\Capital\CapitalChoices($this->db);$one=$choices->read($this->supplier,$this->period,['provider_search'=>'capital donor']);self::assertSame([$line],array_column($one['sources'],'id'));self::assertSame([$cost],array_column($one['costs'],'id'));self::assertSame([$this->provider],array_column($one['providers'],'id'));self::assertContains($this->asset,array_column($one['assets'],'id'));self::assertFalse($one['sources_more']);
        $this->enroll('fund',$line);self::assertSame([],$choices->read($this->supplier,$this->period)['sources']);self::assertSame([],$choices->read($this->supplier,$this->period,['provider_search'=>'%'])['providers']);
        $this->expectException(CapitalException::class);$choices->read(1,$this->period);
    }
    private function pendingFundProof():array
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$cost=$this->posted('518','800.00','debit');$preview=$this->capital->previewFund($this->supplier,$source['id'],$cost,40000,'2026-12-31');
        $request=['action'=>'release','source_id'=>$source['id'],'intent'=>$preview['intent'],'input_hash'=>$preview['input_hash'],'reason'=>'Synthetic pending native owner command','actor'=>$this->actor,'plan'=>$preview['plan']];
        $command=(new \MyInvoice\Service\Nonprofit\Capital\CapitalRepository($this->db))->appendCommand($this->supplier,'pending-proof',$request,$this->actor);
        return [$command,\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof::fromPending($this->db,$this->capital,$this->supplier,$command)];
    }
    public function testLiveProofBindsExactJournalAndRejectsSerializationAndAnotherConnection():void
    {
        [, $proof]=$this->pendingFundProof();$journal=$proof->journal($this->db);self::assertSame([],$proof->indices($this->db,$journal['header'],$journal['lines']));
        try{serialize($proof);self::fail('Live proof must not be serializable');}catch(\LogicException){self::assertTrue(true);}
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(Bootstrap::buildContainer()->get(\MyInvoice\Infrastructure\Config\Config::class)));
        try{$proof->journal($other);self::fail('Proof cannot cross PDO owners');}catch(\LogicException){self::assertTrue(true);}
        $lines=$journal['lines'];$lines[0]['amount']='400.01';
        $this->expectException(CapitalException::class);$proof->indices($this->db,$journal['header'],$lines);
    }
    public function testProofCannotSurviveRollbackOrBeConsumedTwice():void
    {
        [$command,$old]=$this->pendingFundProof();$this->pdo->exec('SAVEPOINT before_new_proof');$invalid=\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof::fromPending($this->db,$this->capital,$this->supplier,$command);$this->pdo->exec('ROLLBACK TO SAVEPOINT before_new_proof');
        try{$invalid->journal($this->db);self::fail('Rollback must invalidate proof epoch');}catch(\LogicException){self::assertTrue(true);}
        $proof=\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof::fromPending($this->db,$this->capital,$this->supplier,$command);$journal=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);$entry=$journal->insertNonprofitCapital($proof);self::assertGreaterThan(0,$entry);self::assertCount(1,$this->capital->canonicalEvents($this->supplier));
        $this->expectException(\LogicException::class);$journal->insertNonprofitCapital($proof);
    }
    public function testCompletionRejectsPersistedHeaderDifferentFromTheAuthorizedCommand():void
    {
        [, $proof]=$this->pendingFundProof();$journal=$proof->journal($this->db);$journal['header']['description']='Synthetic injected different authorization';$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);$entry=(new \ReflectionMethod($repo,'persistJournal'))->invoke($repo,$journal['header'],$journal['lines']);$this->expectException(CapitalException::class);$proof->complete($this->db,$entry);
    }
    public function testActualClientDeleteRetainsHistoricalCapitalProviderInClosedPeriod():void
    {
        $this->enroll('fund',$this->posted('911','1000.00'));$this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->period]);$request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE','/api/clients/'.$this->provider)->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'admin']);$response=Bootstrap::buildContainer()->get(\MyInvoice\Action\Client\DeleteClientAction::class)->__invoke($request,new \Slim\Psr7\Response(),['id'=>(string)$this->provider]);self::assertSame(409,$response->getStatusCode(),(string)$response->getBody());$q=$this->pdo->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$this->provider]);self::assertSame($this->provider,(int)$q->fetchColumn());
    }
    public function testGenericReplaceCannotConvertAnUnrelatedEntryIntoCapitalOwner():void
    {
        [, $proof]=$this->pendingFundProof();$line=$this->posted('518','400.00','debit');$q=$this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$line]);$entry=(int)$q->fetchColumn();$journal=$proof->journal($this->db);$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);try{$repo->replace($entry,$journal['header'],$journal['lines']);self::fail('Reserved owner source cannot be forged through generic replacement');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_capital_owner_required',$e->errorCode);}
    }
    public function testGenericInsertCannotForgeTheNativeCapitalOwner():void
    {
        [, $proof]=$this->pendingFundProof();$journal=$proof->journal($this->db);$this->expectException(\MyInvoice\Service\Accounting\PostingException::class);$this->expectExceptionMessage('živý účetní proof');Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class)->insert($journal['header'],$journal['lines']);
    }
    public function testFullCloneKeepsCapitalSourceConditionsNativeUseAndExactReversal():void
    {
        foreach(['accounting_securities_debt_lots','property_funding_operations']as$table)self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        $policy=$this->policy('fund',$this->posted('911','1000.00'));$policy['title']='Synthetic capital donor private source';$policy['evidence']='Synthetic capital donor capital-private@example.test purpose proof';$p=$this->capital->previewSource($this->supplier,$policy);$fund=$this->capital->confirmSource($this->supplier,$policy,$p['input_hash'],'Synthetic capital donor capital-private@example.test decision','clone-source',$this->actor);$cost=$this->posted('518','800.00','debit');$first=$this->fundUse($fund,$cost,20000,'clone-first-use');$reverse=$this->capital->previewReverse($this->supplier,$first['id'],'2026-12-31');$this->capital->confirmReverse($this->supplier,$first['id'],'2026-12-31',$reverse['input_hash'],'Synthetic capital donor private correction','clone-reverse',$this->actor);$this->fundUse($fund,$cost,60000,'clone-second-use');
        $this->enroll('investment',$this->posted('901','300000.00'),'clone-investment');$result=$this->depreciation->bookYear($this->supplier,2026,['posted_by'=>$this->actor]);self::assertSame([],$result['errors']);$before=$this->capital->read($this->supplier);$this->pdo->commit();
        (new \MyInvoice\Service\Anonymization\AnonymizationService($this->cloneConfig))->run(new \MyInvoice\Service\Anonymization\AnonymizationOptions(source:$this->cloneSource,target:$this->cloneTarget,seed:'synthetic-capital-clone'),static function():void{});
        $data=$this->cloneConfig->all();$data['db']['name']=$this->cloneTarget;$target=Connection::withoutSharedTestConnection(fn()=>new Connection(new \MyInvoice\Infrastructure\Config\Config($data)));
        try{$c=Bootstrap::buildContainer();$c->set(Connection::class,$target);$service=new CapitalService($target,$c->get(PostingService::class));$after=$service->read($this->supplier);self::assertSame(array_column($before['sources'],'used_cents'),array_column($after['sources'],'used_cents'));self::assertSame(array_column($before['sources'],'available_cents'),array_column($after['sources'],'available_cents'));self::assertSame('60000',(new CapitalCapacity($target))->coveredCost($this->supplier,$cost));
            $dump='';foreach(['nonprofit_capital_sources','nonprofit_capital_events','nonprofit_capital_commands']as$table)$dump.=json_encode($target->pdo()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);self::assertStringNotContainsString('capital-private@example.test',$dump);self::assertStringNotContainsString('Synthetic capital donor',$dump);
            try{$service->previewFund($this->supplier,$fund['id'],$cost,20001,'2026-12-31');self::fail('Clone retains actual cost capacity');}catch(CapitalException $e){self::assertSame('nonprofit_capital_cost_overcovered',$e->errorCode);}
        }finally{$target->close();}
    }
    private function taxAssessment(int $gift,int $cost,int $funded):array
    {
        $tax=Bootstrap::buildContainer()->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class);$snapshot=$tax->read($this->supplier,$this->period)['snapshot'];
        $assessment=['profile'=>['kind'=>'narrow_vpp','evidence'=>'Synthetic nonprofit statutes'],'activities'=>[$this->kind=>['evidence'=>'Synthetic documented activity']],'decisions'=>[],'funding'=>[['source_id'=>'line:'.$gift,'cost_id'=>'line:'.$cost,'amount_cents'=>$funded,'evidence'=>'Synthetic original donor funding allocation']]];
        foreach($snapshot['sources']as$row){$assessment['decisions'][$row['id']]=$row['kind']==='expense'?['title'=>'cost','deductible_cents'=>$row['amount_cents'],'legal_basis'=>'zdp_24_1','evidence'=>'Synthetic expense']
            :($row['line_id']===$gift?['title'=>'gift','facts'=>['claim_exemption'=>true,'qualifying_purpose'=>true,'election_evidence'=>'Synthetic binding original gift election'],'evidence'=>'Synthetic original gift']
                :['title'=>'other_taxable','evidence'=>'Synthetic separate actual fund release']);}
        return [$tax,$snapshot,$assessment];
    }
    public function testJ6Then911CannotOvercoverAndWholePeriodRefreshExcludesOnlyItsOldClaim():void
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$gift=$this->posted('682','1000.00');$cost=$this->posted('518','800.00','debit');
        [$tax,$snapshot,$assessment]=$this->taxAssessment($gift,$cost,40000);$receipt=$tax->confirm($this->supplier,$this->period,$assessment,$snapshot['input_hash'],'Synthetic first J6 use','j6-first',$this->actor);
        try{$this->capital->previewFund($this->supplier,$source['id'],$cost,40001,'2026-12-31');self::fail('911 must include earlier native J6 use');}catch(CapitalException $e){self::assertSame('nonprofit_capital_cost_overcovered',$e->errorCode);}
        $this->fundUse($source,$cost,40000);[$tax,$snapshot,$assessment]=$this->taxAssessment($gift,$cost,40000);
        $current=$tax->confirm($this->supplier,$this->period,$assessment,$snapshot['input_hash'],'Synthetic whole-period refresh after actual648','j6-refresh',$this->actor,$receipt['id']);self::assertGreaterThan($receipt['id'],$current['id']);
        self::assertSame('80000',(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->claimed($this->supplier,$cost));
    }
    public function testFundPreviewBindsExactOtherCapitalClaimsEvenWhenTheirAmountStaysTheSame():void
    {
        $one=$this->enroll('fund',$this->posted('911','1000.00'),'capital-source-one');$two=$this->enroll('fund',$this->posted('911','1000.00'),'capital-source-two');$cost=$this->posted('518','800.00','debit');$event=$this->fundUse($one,$cost,40000,'capital-first-claim');$preview=$this->capital->previewFund($this->supplier,$two['id'],$cost,20000,'2026-12-31');$reverse=$this->capital->previewReverse($this->supplier,$event['id'],'2026-12-31');$this->capital->confirmReverse($this->supplier,$event['id'],'2026-12-31',$reverse['input_hash'],'Synthetic reviewed replacement of original capital claim','replace-old-claim',$this->actor);$this->fundUse($one,$cost,40000,'capital-replacement-claim');self::assertSame('40000',(new CapitalCapacity($this->db))->coveredCost($this->supplier,$cost));
        $this->expectException(CapitalException::class);$this->capital->confirmFund($this->supplier,$two['id'],$cost,20000,'2026-12-31',$preview['input_hash'],'Synthetic stale source-two preview must be rejected','stale-capital-preview',$this->actor);
    }
    public function testFundConfirmationBindsTheExactSharedCostPoolEvidence():void
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$gift=$this->posted('682','1000.00');$cost=$this->posted('518','800.00','debit');$other=$this->posted('518','200.00','debit');[$tax,$snapshot,$assessment]=$this->taxAssessment($gift,$cost,40000);$assessment['funding_costs']=[['anchor'=>'line:'.$cost,'members'=>['line:'.$cost,'line:'.$other],'evidence'=>'Synthetic original combined eligible costpool']];$receipt=$tax->confirm($this->supplier,$this->period,$assessment,$snapshot['input_hash'],'Synthetic exact original J6 pool','original-pool',$this->actor);
        $preview=$this->capital->previewFund($this->supplier,$source['id'],$cost,20000,'2026-12-31');$assessment['funding_costs'][0]['evidence']='Synthetic newly reviewed legal pool evidence';$tax->confirm($this->supplier,$this->period,$assessment,$snapshot['input_hash'],'Synthetic refreshed legal pool','updated-pool',$this->actor,$receipt['id']);
        $this->expectException(CapitalException::class);$this->capital->confirmFund($this->supplier,$source['id'],$cost,20000,'2026-12-31',$preview['input_hash'],'Synthetic outdated earlier preview must be rejected','old-pool-preview',$this->actor);
    }
    public function test911ThenJ6CannotOvercoverTheSameActualCost():void
    {
        $source=$this->enroll('fund',$this->posted('911','1000.00'));$gift=$this->posted('682','1000.00');$cost=$this->posted('518','800.00','debit');$this->fundUse($source,$cost,40000);
        [$tax,$snapshot,$assessment]=$this->taxAssessment($gift,$cost,40001);
        try{$tax->preview($this->supplier,$this->period,$assessment);self::fail('J6 must include earlier actual911648 use');}catch(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_cost_overcovered',$e->errorCode);}
        $assessment['funding'][0]['amount_cents']=40000;$tax->confirm($this->supplier,$this->period,$assessment,$snapshot['input_hash'],'Synthetic compatible remaining gift use','j6-after-911',$this->actor);
        self::assertSame('80000',(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->claimed($this->supplier,$cost));
    }
}
