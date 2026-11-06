<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
final class FundingNativeTest extends TestCase
{
    use IsolatedSupplierTrait;
    private PDO $pdo;
    private FundingService $funding;
    private NonprofitTaxAssessmentService $tax;
    private FundingRepository $repository;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $kind;
    private int $provider;
    private array $catalog;
    private array $accounts=[];
    private array $policy;
    private array $assessment;
    private array $receipt;
    private ?Connection $cloneConnection=null;
    private ?PDO $cloneServer=null;
    private ?string $cloneSource=null;
    private ?string $cloneTarget=null;
    private ?\MyInvoice\Infrastructure\Config\Config $cloneConfig=null;
    private int $income;
    private int $expense;
    protected function setUp():void
    {
        $c=Bootstrap::buildContainer();$db=$c->get(Connection::class);
        if($this->name()==='testFullClonePreservesPurposeUseAndTaxElectionWithoutOriginalPrivateEvidence'){
            $cfg=$c->get(\MyInvoice\Infrastructure\Config\Config::class);
            $base=(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$base);
            $this->cloneSource='myucto_funding_src_'.bin2hex(random_bytes(5)).'_test';$this->cloneTarget='myucto_funding_dst_'.bin2hex(random_bytes(5)).'_test';
            $this->cloneServer=new PDO('mysql:host='.$cfg->get('db.host').';port='.$cfg->get('db.port',3306).';charset=utf8mb4',$cfg->get('db.user'),$cfg->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $cloner=new \MyInvoice\Service\Anonymization\DatabaseCloner($this->cloneServer);$plan=$cloner->plan($base);$cloner->copyTables($plan,$this->cloneSource,static function():void{});$cloner->finalize($plan,$this->cloneSource);
            $data=$cfg->all();$data['db']['name']=$this->cloneSource;$this->cloneConfig=new \MyInvoice\Infrastructure\Config\Config($data);
            $this->cloneConnection=Connection::withoutSharedTestConnection(fn()=>new Connection($this->cloneConfig));$db=$this->cloneConnection;$c->set(Connection::class,$db);
        }
        $this->pdo=$db->pdo();
        self::assertStringEndsWith('_test',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed=(int)$this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0,$seed);
        $this->actor=(int)$this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier=$this->createIsolatedSupplier($this->pdo,$seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $this->period=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2026,'2026-01-01','2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $this->catalog=$c->get(SystemDimensionCatalog::class)->ensureForFramework($this->supplier,'2026-01-01');
        $this->pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$this->catalog['activity_kind']['id'],$this->supplier,'SPORT','Synthetic sport']);$this->kind=(int)$this->pdo->lastInsertId();
        foreach(['221'=>'asset','501'=>'expense','518'=>'expense','682'=>'revenue','691'=>'revenue']as$code=>$type){
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')->execute([$this->supplier,(string)$code,'Synthetic '.$code,$type]);$this->accounts[$code]=(int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES (?,'Synthetic donor','Test','Test','10000',203,'donor@example.test','cs',?,0,1)")->execute([$this->supplier,(int)$this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn()]);$this->provider=(int)$this->pdo->lastInsertId();
        $this->income=$this->post('682','1000.00');$this->expense=$this->post('518','800.00');
        $this->tax=$c->get(NonprofitTaxAssessmentService::class);$this->funding=$c->get(FundingService::class);$this->repository=$c->get(FundingRepository::class);
        $snapshot=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->assessment=['profile'=>['kind'=>'narrow_vpp','evidence'=>'Synthetic statutes'],'activities'=>[$this->kind=>['evidence'=>'Synthetic sports activity']],
            'decisions'=>['line:'.$this->income=>['title'=>'gift','evidence'=>'Synthetic gift agreement','facts'=>['claim_exemption'=>true,'qualifying_purpose'=>true,'election_evidence'=>'Synthetic irrevocable gift election']],
                'line:'.$this->expense=>['title'=>'cost','evidence'=>'Synthetic expense','legal_basis'=>'zdp_24_1','deductible_cents'=>80000]],'funding'=>[]];
        $this->receipt=$this->tax->confirm($this->supplier,$this->period,$this->assessment,$snapshot['input_hash'],'Synthetic review','initial-tax',$this->actor);
        $this->policy=['kind'=>'gift','title'=>'Synthetic sporting gift','purpose'=>'Sports for children','evidence'=>'Synthetic agreement and budget','provider_id'=>$this->provider,'budget_cents'=>70000,
            'valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','activity_kind_ids'=>[$this->kind],'account_prefixes'=>['518'],'source_anchor'=>'line:'.$this->income,'source_members'=>['line:'.$this->income]];
    }
    protected function tearDown():void
    {
        if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();
        $this->cloneConnection?->close();
        if($this->cloneServer!==null)foreach([$this->cloneTarget,$this->cloneSource]as$name){
            if($name===null||!preg_match('/^myucto_funding_(?:src|dst)_[a-f0-9]{10}_test$/D',$name))throw new \LogicException('Unexpected owned clone schema.');
            $this->cloneServer->exec('DROP DATABASE IF EXISTS '.\MyInvoice\Service\Anonymization\DatabaseCloner::quote($name));
        }
    }
    private function post(string $code,string $amount,string $date='2026-06-01',bool $red=false):int
    {
        $expense=str_starts_with($code,'5');
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,'manual')")->execute([$this->supplier,$this->period,$date]);$entry=(int)$this->pdo->lastInsertId();$first=0;
        foreach([[$code,$expense?'debit':'credit'],['221',$expense?'credit':'debit']]as$no=>[$account,$side]){
            $center=$this->name()==='testFullClonePreservesPurposeUseAndTaxElectionWithoutOriginalPrivateEvidence'?'synthetic.center@example.invalid':null;
            $this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount,is_red_storno,cost_center) VALUES (?,?,?,?,?,?,?,?)')->execute([$this->supplier,$entry,$no,$this->accounts[$account],$side,$amount,(int)$red,$center]);
            if($no===0){$first=(int)$this->pdo->lastInsertId();foreach(['activity'=>$this->catalog['activity']['values']['main'],'activity_kind'=>$this->kind,'tax_regime'=>$this->catalog['tax_regime']['values']['exempt']]as$axis=>$value)$this->pdo->prepare("INSERT INTO journal_entry_line_dimensions(supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin) VALUES (?,?,?,?,'explicit')")->execute([$this->supplier,$first,$this->catalog[$axis]['id'],$value]);}
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE id=?')->execute([$entry]);return $first;
    }
    private function enroll(?array $policy=null,string $token='source'):array
    {
        $policy??=$this->policy;$p=$this->funding->preview($this->supplier,$this->period,'gift-sport',$policy);
        return $this->funding->confirm($this->supplier,$this->period,'gift-sport',$policy,$p['input_hash'],$p['expected_revision'],'Synthetic authorized purpose',$token,$this->actor);
    }
    private function linked(int $amount=60000):array
    {
        $a=$this->assessment;$a['funding']=[['source_id'=>'line:'.$this->income,'cost_id'=>'line:'.$this->expense,'amount_cents'=>$amount,'evidence'=>'Synthetic actual eligible use']];return $a;
    }
    public function testActualTaxConfirmationRejectsGrantBudgetOveruse():void
    {
        $this->enroll();$snapshot=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->expectException(NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('schválený rozpočet');
        $this->tax->confirm($this->supplier,$this->period,$this->linked(80000),$snapshot['input_hash'],'Synthetic excessive use','over-budget',$this->actor,$this->receipt['id']);
    }
    public function testActualTaxPreviewRejectsIneligibleCostDate():void
    {
        $policy=$this->policy;$policy['valid_to']='2026-05-31';$this->enroll($policy);
        $this->expectException(NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('podmínky zdroje');
        $this->tax->preview($this->supplier,$this->period,$this->linked());
    }
    public function testCurrentReportUsesOneConfirmedTaxLedgerAndPreservesGiftElection():void
    {
        $this->enroll();$before=(int)$this->pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        $s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $r=$this->tax->confirm($this->supplier,$this->period,$this->linked(),$s['input_hash'],'Synthetic actual use','actual-use',$this->actor,$this->receipt['id']);
        $report=$this->funding->read($this->supplier);
        self::assertTrue($report['complete']);self::assertFalse($report['financial_authorization']);
        self::assertSame(60000,$report['sources'][0]['drawdown_cents']);self::assertSame(10000,$report['sources'][0]['budget_remaining_cents']);
        self::assertCount(1,$report['sources'][0]['uses']);self::assertSame($r['id'],$report['sources'][0]['uses'][0]['assessment_id']);
        self::assertTrue($report['sources'][0]['revision']['basis']['source_rows']['line:'.$this->income]['decision']['facts']['claim_exemption']);
        self::assertSame($before,(int)$this->pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
    }
    public function testIdempotenceCasAndRetainedProviderAreEnforcedByActualStorage():void
    {
        $p=$this->funding->preview($this->supplier,$this->period,'gift-sport',$this->policy);
        $row=$this->funding->confirm($this->supplier,$this->period,'gift-sport',$this->policy,$p['input_hash'],0,'Synthetic review','repeat',$this->actor);
        self::assertSame($row,$this->funding->confirm($this->supplier,$this->period,'gift-sport',$this->policy,$p['input_hash'],0,'Synthetic review','repeat',$this->actor));
        self::assertCount(1,$this->repository->all($this->supplier));
        try{$this->funding->confirm($this->supplier,$this->period,'gift-sport',$this->policy,$p['input_hash'],0,'Changed review','repeat',$this->actor);self::fail('Conflicting request must fail');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_idempotency_conflict',$e->errorCode);}
        try{$this->funding->confirm($this->supplier,$this->period,'gift-sport',$this->policy,$p['input_hash'],0,'Synthetic review','cas',$this->actor);self::fail('Stale revision must fail');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_sources_changed',$e->errorCode);}
        try{$this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->provider]);self::fail('Historical provider must remain');}
        catch(\PDOException $e){self::assertSame(1451,(int)$e->errorInfo[1]);}
    }
    public function testConfirmedUsePreventsRetroactiveEligibilityAndBudgetReduction():void
    {
        $this->enroll();$s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->tax->confirm($this->supplier,$this->period,$this->linked(),$s['input_hash'],'Synthetic use','use',$this->actor,$this->receipt['id']);
        $changed=$this->policy;$changed['budget_cents']=50000;
        try{$this->funding->preview($this->supplier,$this->period,'gift-sport',$changed);self::fail('Budget below use must fail');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_budget_exceeded',$e->errorCode);}
        $changed=$this->policy;$changed['account_prefixes']=['501'];
        try{$this->funding->preview($this->supplier,$this->period,'gift-sport',$changed);self::fail('Past cost must remain eligible');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_cost_ineligible',$e->errorCode);}
    }
    public function testSourceCannotBeReusedAndTenantCannotSelectAnotherProvider():void
    {
        $this->enroll();
        try{$this->funding->preview($this->supplier,$this->period,'other-gift',$this->policy);self::fail('One source cannot have two owners');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_source_already_used',$e->errorCode);}
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) SELECT 1,'Foreign synthetic donor',street,city,zip,country_id,main_email,language,currency_default_id,0,1 FROM clients WHERE id=?")->execute([$this->provider]);
        $changed=$this->policy;$changed['provider_id']=(int)$this->pdo->lastInsertId();
        try{$this->funding->preview($this->supplier,$this->period,'gift-sport',$changed);self::fail('Cross-tenant donor must fail');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_provider_not_found',$e->errorCode);}
        self::assertSame([], $this->funding->read(1)['sources']);
    }
    public function testSourceRevisionIsAppendOnlyAndCannotForgetItsOrigin():void
    {
        $one=$this->enroll();$changed=$this->policy;$changed['purpose']='Sports equipment for children';$two=$this->enroll($changed,'revision-two');
        self::assertSame(2,$two['revision_no']);self::assertSame($one['record_hash'],$two['parent_hash']);self::assertCount(2,$this->repository->all($this->supplier));
        foreach(['UPDATE nonprofit_funding_revisions SET reason=reason WHERE id=?','DELETE FROM nonprofit_funding_revisions WHERE id=?']as$sql){
            try{$this->pdo->prepare($sql)->execute([$one['id']]);self::fail('Immutable funding evidence must remain');}
            catch(\PDOException $e){self::assertStringContainsString('nonprofit_funding_immutable',$e->getMessage());}
        }
    }
    public function testNewLedgerRowMakesReportIncompleteAndCannotAuthorizeNewSource():void
    {
        $this->enroll();$this->post('518','1.00');
        $report=$this->funding->read($this->supplier);self::assertFalse($report['complete']);self::assertFalse($report['financial_authorization']);
        self::assertSame('funding_source_not_current',$report['findings'][0]['code']);
        $this->expectException(NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('aktuální potvrzené');
        $this->funding->preview($this->supplier,$this->period,'gift-sport',$this->policy);
    }

    public function testFullClonePreservesPurposeUseAndTaxElectionWithoutOriginalPrivateEvidence():void
    {
        $cohort=$this->assessment;$cohort['activities'][$this->kind]['cohorts']=['donor@example.test'=>'Synthetic documented donor cohort'];
        foreach($cohort['decisions']as&$decision)$decision['cohort']='donor@example.test';unset($decision);
        $cohort['decisions']['line:'.$this->income]['facts']['donor@example.test']='Synthetic private metadata';
        $s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->receipt=$this->tax->confirm($this->supplier,$this->period,$cohort,$s['input_hash'],'Synthetic cohort review','cohort-review',$this->actor,$this->receipt['id']);$this->assessment=$cohort;

        $this->policy['evidence']='Synthetic donor donor@example.test agreement';$this->policy['purpose']='Synthetic donor donor@example.test sports';
        $this->enroll();$policy=$this->policy;$policy['title']='Synthetic donor donor@example.test revised title';$this->enroll($policy,'second-source-revision');
        $s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $used=$this->tax->confirm($this->supplier,$this->period,$this->linked(),$s['input_hash'],'Synthetic donor donor@example.test actual use','actual-use',$this->actor,$this->receipt['id']);
        $extra=$this->post('518','1.00','2026-07-01');$secondIncome=$this->post('682','100.00','2026-07-02');$updated=$this->linked();
        $updated['decisions']['line:'.$secondIncome]=$updated['decisions']['line:'.$this->income];
        $extraDecision=['title'=>'cost','evidence'=>'Synthetic donor donor@example.test late expense','legal_basis'=>'zdp_24_1','deductible_cents'=>100,'cohort'=>'donor@example.test'];
        $updated['decisions']['line:'.$extra]=$extraDecision;$s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $final=$this->tax->confirm($this->supplier,$this->period,$updated,$s['input_hash'],'Synthetic new source review','new-source-review',$this->actor,$used['id']);
        $second=$this->policy;$second['source_anchor']='line:'.$secondIncome;$second['source_members']=['line:'.$secondIncome];$second['budget_cents']=10000;
        $secondPreview=$this->funding->preview($this->supplier,$this->period,'aaa-second-gift',$second);
        $this->funding->confirm($this->supplier,$this->period,'aaa-second-gift',$second,$secondPreview['input_hash'],0,'Synthetic distinct gift','second-gift',$this->actor);
        $this->pdo->commit();
        (new \MyInvoice\Service\Anonymization\AnonymizationService($this->cloneConfig))->run(new \MyInvoice\Service\Anonymization\AnonymizationOptions(source:$this->cloneSource,target:$this->cloneTarget,seed:'synthetic-funding-clone'),static function():void{});
        $data=$this->cloneConfig->all();$data['db']['name']=$this->cloneTarget;
        $target=Connection::withoutSharedTestConnection(fn()=>new Connection(new \MyInvoice\Infrastructure\Config\Config($data)));
        try{
            $q=$target->pdo()->prepare('SELECT COUNT(*) FROM nonprofit_tax_assessments WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(4,(int)$q->fetchColumn());
            $c=Bootstrap::buildContainer();$c->set(Connection::class,$target);$report=$c->get(FundingService::class)->read($this->supplier);
            self::assertTrue($report['complete']);$original=array_values(array_filter($report['sources'],fn($r)=>$r['revision']['policy']['source_anchor']==='line:'.$this->income))[0];self::assertSame(60000,$original['drawdown_cents']);self::assertSame(10000,$original['budget_remaining_cents']);
            $all=$c->get(FundingRepository::class)->all($this->supplier);self::assertCount(3,$all);$originalRows=array_values(array_filter($all,fn($r)=>$r['policy']['source_anchor']==='line:'.$this->income));self::assertSame($originalRows[0]['record_hash'],$originalRows[1]['parent_hash']);
            $dump=json_encode($target->pdo()->query('SELECT policy_json,basis_json,request_json,reason FROM nonprofit_funding_revisions')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
            $dump.=json_encode($target->pdo()->query('SELECT snapshot_json,assessment_json,result_json,reason FROM nonprofit_tax_assessments')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('donor@example.test',$dump);self::assertStringNotContainsString('Synthetic donor',$dump);
            self::assertStringNotContainsString('synthetic.center@example.invalid',$dump);$centers=$target->pdo()->query('SELECT DISTINCT cost_center FROM journal_entry_lines WHERE cost_center IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);self::assertNotEmpty($centers);foreach($centers as$center)self::assertMatchesRegularExpression('/^CC[0-9A-F]{48}$/D',$center);
            self::assertTrue($originalRows[1]['basis']['source_rows']['line:'.$this->income]['decision']['facts']['claim_exemption']);
            $tax=$c->get(NonprofitTaxAssessmentService::class);
            $snap=$tax->read($this->supplier,$this->period)['snapshot'];
            $q=$target->pdo()->prepare('SELECT input_hash FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$used['id']]);self::assertNotSame($snap['input_hash'],$q->fetchColumn());
            $q->execute([$this->supplier,$final['id']]);self::assertSame($snap['input_hash'],$q->fetchColumn());
            $excess=$this->linked(80000);$excess['decisions']['line:'.$extra]=$extraDecision;$excess['decisions']['line:'.$secondIncome]=$updated['decisions']['line:'.$secondIncome];
            try{$tax->preview($this->supplier,$this->period,$excess);self::fail('Clone must retain the actual budget gate');}
            catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_budget_exceeded',$e->errorCode);}
        }finally{$target->close();}
    }

    public function testLaterFiscalPeriodCannotExceedOriginalApprovedBudget():void
    {
        $policy=$this->policy;$policy['valid_to']='2027-12-31';$this->enroll($policy);
        $s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->tax->confirm($this->supplier,$this->period,$this->linked(),$s['input_hash'],'Synthetic first year use','first-year',$this->actor,$this->receipt['id']);
        $c=Bootstrap::buildContainer();$later=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2027,'2027-01-01','2027-12-31');
        $this->period=$later;$cost=$this->post('518','200.00','2027-06-01');$s=$this->tax->read($this->supplier,$later)['snapshot'];
        $a=['profile'=>['kind'=>'documented','evidence'=>'Synthetic verified taxpayer profile'],
            'method'=>['mode'=>'documented_manual','valid_from'=>'2027-01-01','valid_to'=>'2027-12-31','evidence'=>'Synthetic reviewed applicable law','legal_basis'=>'Synthetic verified law for fiscal period'],
            'activities'=>[$this->kind=>['evidence'=>'Synthetic sport']],
            'decisions'=>['line:'.$cost=>['title'=>'cost','evidence'=>'Synthetic later expense','legal_basis'=>'zdp_24_1','deductible_cents'=>20000,'final_regime'=>'subject']],
            'funding_sources'=>[['anchor'=>'line:'.$this->income,'members'=>['line:'.$this->income],'evidence'=>'Synthetic continuing source']],
            'funding'=>[['source_id'=>'line:'.$this->income,'cost_id'=>'line:'.$cost,'amount_cents'=>20000,'evidence'=>'Synthetic later use']]];
        try{$this->tax->confirm($this->supplier,$later,$a,$s['input_hash'],'Synthetic excessive later use','later-excess',$this->actor);self::fail('Budget is shared across fiscal periods');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_funding_budget_exceeded',$e->errorCode);}
        $a['funding'][0]['amount_cents']=10000;
        $this->tax->confirm($this->supplier,$later,$a,$s['input_hash'],'Synthetic remaining use','later-valid',$this->actor);
        $report=$this->funding->read($this->supplier);self::assertTrue($report['complete']);self::assertSame(70000,$report['sources'][0]['drawdown_cents']);self::assertSame(0,$report['sources'][0]['budget_remaining_cents']);self::assertCount(2,$report['sources'][0]['uses']);
    }
    public function testActionRejectsNonSessionAndMalformedRequiredFieldsWithoutWriting():void
    {
        $action=Bootstrap::buildContainer()->get(\MyInvoice\Action\Nonprofit\FundingAction::class);
        $valid=['source_key'=>'gift-sport','policy'=>$this->policy,'input_hash'=>str_repeat('a',64),'expected_revision'=>0,'reason'=>'Synthetic review','idempotency_key'=>'native-action'];
        $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/accounting/periods/'.$this->period.'/nonprofit-funding/confirm')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'admin']);
        foreach(['bearer',null]as$method){
            $r=$action->confirm($request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,$method)->withParsedBody($valid),new \Slim\Psr7\Response(),['id'=>(string)$this->period]);self::assertSame(403,$r->getStatusCode());
        }
        foreach([['policy'=>null],['source_key'=>null],['input_hash'=>null],['expected_revision'=>null],['reason'=>[]],['unexpected'=>true]]as$invalid){
            $r=$action->confirm($request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session')->withParsedBody(array_replace($valid,$invalid)),new \Slim\Psr7\Response(),['id'=>(string)$this->period]);self::assertSame(422,$r->getStatusCode(),(string)$r->getBody());
        }
        self::assertSame([],$this->repository->all($this->supplier));
    }

    public function testHumanChoicesRequireCurrentConfirmedSourceAndNeverExposeForeignProvider():void
    {
        $choices=$this->funding->choices($this->supplier,$this->period,'donor');
        self::assertTrue($choices['assessment_current']);self::assertCount(1,$choices['sources']);self::assertSame('gift',$choices['sources'][0]['kind']);
        self::assertSame('line:'.$this->income,$choices['sources'][0]['anchor']);self::assertSame($this->provider,$choices['providers'][0]['id']);self::assertSame(100000,$choices['sources'][0]['amount_cents']);
        self::assertSame('Synthetic donor',$choices['providers'][0]['label']);self::assertFalse($choices['providers_more']);
        $this->post('518','1.00');$choices=$this->funding->choices($this->supplier,$this->period,'donor');
        self::assertFalse($choices['assessment_current']);self::assertSame([],$choices['sources']);
    }

    public function testConfirmedNegativeCorrectionCanRefreshTheSourceWithoutLosingPurposeCaps():void
    {
        $this->enroll();$s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $used=$this->tax->confirm($this->supplier,$this->period,$this->linked(),$s['input_hash'],'Synthetic first use','before-correction',$this->actor,$this->receipt['id']);
        $refund=$this->post('682','200.00','2026-07-01',true);$a=$this->linked();$a['decisions']['line:'.$refund]=$a['decisions']['line:'.$this->income];
        $members=['line:'.$this->income,'line:'.$refund];$a['funding_sources']=[['anchor'=>'line:'.$this->income,'members'=>$members,'evidence'=>'Synthetic documented partial gift return']];
        $s=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->tax->confirm($this->supplier,$this->period,$a,$s['input_hash'],'Synthetic reviewed gift return','tax-correction',$this->actor,$used['id']);
        $pending=$this->funding->read($this->supplier);self::assertFalse($pending['complete']);self::assertNull($pending['sources'][0]['available_cents']);
        $policy=$this->policy;$policy['source_members']=$members;$this->enroll($policy,'source-correction');
        $report=$this->funding->read($this->supplier);self::assertTrue($report['complete']);self::assertSame(80000,$report['sources'][0]['recognized_cents']);self::assertSame(60000,$report['sources'][0]['drawdown_cents']);self::assertSame(10000,$report['sources'][0]['available_cents']);
    }

    public function testAnotherPositiveGiftCannotBypassTheRegisteredSourceOwner():void
    {
        $this->enroll();$other=$this->post('682','100.00');$a=$this->linked();$a['decisions']['line:'.$other]=$a['decisions']['line:'.$this->income];
        $a['funding_sources']=[['anchor'=>'line:'.$this->income,'members'=>['line:'.$this->income,'line:'.$other],'evidence'=>'Synthetic unsupported extra positive member']];
        $this->expectException(NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('Nové kladné zdroje');
        $this->tax->preview($this->supplier,$this->period,$a);
    }
    public function testActualClientDeleteKeepsTheHistoricalDonor():void
    {
        $this->enroll();$c=Bootstrap::buildContainer();
        self::assertTrue($c->get(\MyInvoice\Service\Nonprofit\Funding\FundingPartyUseGuard::class)->inUse($this->supplier,$this->provider));
        self::assertFalse($c->get(\MyInvoice\Service\Nonprofit\Funding\FundingPartyUseGuard::class)->inUse(1,$this->provider));
        $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE','/api/clients/'.$this->provider)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'admin']);
        $response=$c->get(\MyInvoice\Action\Client\DeleteClientAction::class)($request,new \Slim\Psr7\Response(),['id'=>(string)$this->provider]);
        self::assertSame(409,$response->getStatusCode(),(string)$response->getBody());
        $q=$this->pdo->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$this->provider]);self::assertSame($this->provider,(int)$q->fetchColumn());
    }

    public function testSharedCurrentReceiptProofRejectsChangedResultDespiteMatchingSourceHash():void
    {
        $capacity=Bootstrap::buildContainer()->get(\MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity::class);$capacity->assertCurrentReceipt($this->supplier,$this->receipt);$receipt=$this->receipt;$receipt['result']['accounting_result_cents']++;
        $this->expectException(NonprofitTaxAssessmentException::class);$capacity->assertCurrentReceipt($this->supplier,$receipt);
    }

    public function testActualRoutesEnforceGrantMetadataSessionPermissionAndCsrf():void
    {
        $c=Bootstrap::buildContainer();$app=\Slim\Factory\AppFactory::create(new \Slim\Psr7\Factory\ResponseFactory(),$c);\MyInvoice\Routes::register($app);$app->add($c->get(\MyInvoice\Middleware\CsrfMiddleware::class));
        $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/accounting/periods/'.$this->period.'/nonprofit-funding/preview')->withParsedBody(['source_key'=>'gift-sport','policy'=>$this->policy])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session')->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'accountant'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-grant-csrf'])->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(111,'Synthetic grant role','staff',true,['accounting'=>1,'accounting.periods.close'=>2]))
            ->withAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');
        self::assertSame(403,$app->handle($request)->getStatusCode());$valid=$request->withHeader('X-CSRF-Token','synthetic-grant-csrf');
        self::assertSame(403,$app->handle($valid->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'bearer'))->getStatusCode());
        self::assertSame(403,$app->handle($valid->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(112,'Synthetic read role','staff',true,['accounting'=>1])))->getStatusCode());
        $response=$app->handle($valid);self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());$body=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);self::assertFalse($body['financial_authorization']);self::assertSame([],$this->repository->all($this->supplier));
    }

    public function testPublicJ6CapacityUsesOnlyCurrentLatestConfirmedTenantClaimsAndAllowsPeriodReplacement():void
    {
        $this->enroll();$a=$this->linked();$snapshot=$this->tax->read($this->supplier,$this->period)['snapshot'];
        $this->tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic use','capacity-use',$this->actor,$this->receipt['id']);
        $capacity=Bootstrap::buildContainer()->get(\MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity::class);
        self::assertSame('60000',$capacity->claimedCost($this->supplier,$this->expense));
        self::assertSame('0',$capacity->claimedCost($this->supplier,$this->expense,$this->period));
        self::assertSame('0',$capacity->claimedCost(1,$this->expense));
        self::assertSame('0',$capacity->claimedCost($this->supplier,$this->income));
        $this->post('518','1.00');
        self::assertSame('0',$capacity->claimedCost($this->supplier,$this->expense,$this->period));
        try{$capacity->claimedCost($this->supplier,$this->expense);self::fail('Stale native J6 use must block capacity, never disappear');}
        catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_tax_funding_changed',$e->errorCode);}
    }
}
