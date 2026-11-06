<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Catalog;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware,ApiScopeMiddleware,ApiVersionRewriteMiddleware,TenantDomainMiddleware};
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Property\Catalog\PropertyAccountingCatalog;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PropertyAccountingCatalogHttpTest extends TestCase
{
    use FundingFixture {setUp as private fundingSetUp;tearDown as private fundingTearDown;}
    private const PRIVATE='catalog-private-person@example.invalid';
    private string $token;
    private \Slim\App $app;
    protected function setUp():void
    {
        $this->fundingSetUp();$this->token=$this->tokenFor($this->f['supplier'],1);$c=$this->container;$c->set(Connection::class,$this->db);$this->app=\Slim\Factory\AppFactory::create(null,$c);\MyInvoice\Routes::register($this->app);
        foreach([ApiScopeMiddleware::class,\MyInvoice\Middleware\PermissionMiddleware::class,SupplierScopeMiddleware::class,AuthMiddleware::class] as $middleware)$this->app->add($c->get($middleware));$this->app->add(new ApiVersionRewriteMiddleware());
    }
    protected function tearDown():void{$this->fundingTearDown();}
    private function tokenFor(int $supplier,int $read):string
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO roles(name,role_type,is_active)VALUES(?,'staff',1)")->execute(['Synthetic catalog role']);$role=(int)$p->lastInsertId();$p->prepare("INSERT INTO role_permissions(role_id,permission_key,access_level)VALUES(?,'property',?)")->execute([$role,$read]);
        $p->prepare("INSERT INTO users(email,password_hash,name,role,role_id,locale,is_active)VALUES(?,?,'Synthetic catalog user','readonly',?,'cs',1)")->execute(['catalog-'.bin2hex(random_bytes(6)).'@example.test',password_hash('Synthetic catalog password',PASSWORD_BCRYPT,['cost'=>4]),$role]);$user=(int)$p->lastInsertId();$p->prepare('INSERT INTO user_suppliers(user_id,supplier_id)VALUES(?,?)')->execute([$user,$supplier]);
        return $this->container->get(ApiTokenService::class)->generateInTransaction($p,$user,$supplier,'Synthetic catalog read','read')['plaintext'];
    }
    private function get(string $path,array $query=[],?string $token=null):array
    {
        $q=(new ServerRequestFactory())->createServerRequest('GET','/api/v1/property/catalog/'.$path,['REMOTE_ADDR'=>'127.0.0.1'])->withQueryParams($query)->withHeader('Authorization','Bearer '.($token??$this->token))->withHeader('X-Supplier-Id',(string)$this->f['supplier'])->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'));
        $r=$this->app->handle($q);$body=json_decode((string)$r->getBody(),true,flags:JSON_THROW_ON_ERROR);$directory=getenv('MYINVOICE_PROPERTY_CATALOG_CONTRACT_DIR');if($r->getStatusCode()===200&&is_string($directory)&&is_dir($directory))file_put_contents($directory.'/'.hash('sha256',$path.json_encode($query)).'.json',json_encode(['path'=>'/api/v1/property/catalog/'.$path,'body'=>json_decode((string)$r->getBody(),false,flags:JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR));return[$r->getStatusCode(),$body];
    }
    private function case(string $from='2026-01-01',string $to='2026-06-30'):int
    {
        $s=$this->f['supplier'];$chart=new ChartOfAccountsRepository($this->db);if(!$chart->findByCode($s,'315.210')){$parent=$chart->findByCode($s,'315');$chart->insert($s,['account_code'=>'315.210','name'=>self::PRIVATE,'account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);}
        return(new ServiceLifecycleRepository($this->db))->openCase($s,$this->f['units']['U02'],$this->f['service']['id'],$from,$to,'service_balance_sheet',self::PRIVATE,$this->f['actor'],'315.210');
    }
    private function assertPrivateAbsent(array $read):void
    {
        $json=json_encode($read,JSON_THROW_ON_ERROR);self::assertStringNotContainsString(self::PRIVATE,$json);
        $check=static function(array $value)use(&$check):void{foreach($value as $key=>$item){self::assertNotContains((string)$key,['actor','created_by','name','contact','email','request','snapshot','receipt','proofs','evidence','evidence_ref','facts_json','payload','dossier','purpose_ref','source_snapshot','request_hash']);if(is_array($item))$check($item);}};$check($read);
    }
    public function testActualReadTokenSeesPendingCaseWithUnknownAmountsAndNoPrivateGraph():void
    {
        $id=$this->case();[$status,$page]=$this->get('service-lifecycles');self::assertSame(200,$status,json_encode($page));self::assertSame([$id],array_column($page['items'],'id'));self::assertNull($page['items'][0]['estimate_cents']);self::assertNull($page['items'][0]['final_cents']);self::assertTrue($page['incomplete']);self::assertSame('current_ledger',$page['knowledge_basis']);$this->assertPrivateAbsent($page);
        [$status,$detail]=$this->get('service-lifecycles/'.$id);self::assertSame(200,$status);self::assertSame([],$detail['events']);self::assertSame('pending',$detail['phase']);$this->assertPrivateAbsent($detail);
    }
    public function testRealTokenReadGrantAndTenantPinningProtectAllFamilies():void
    {
        $id=$this->case();$denied=$this->tokenFor($this->f['supplier'],0);
        foreach(['service-lifecycles'=>[],'service-lifecycles/'.$id=>[],'service-lifecycles/reconciliation'=>['date'=>'2026-12-31'],'funds/operations'=>[],'funds/balances'=>['date'=>'2026-12-31']] as $path=>$query){[$status]=$this->get($path,$query,$denied);self::assertSame(403,$status,$path);}
        $other=$this->createIsolatedSupplier($this->db->pdo(),$this->f['supplier']);$this->db->pdo()->prepare("UPDATE supplier SET legal_form='145' WHERE id=?")->execute([$other]);$foreign=$this->tokenFor($other,1);[$status]=$this->get('service-lifecycles/'.$id,[],$foreign);self::assertSame(409,$status);[$status]=$this->get('service-lifecycles',['unit_id'=>$this->f['units']['U02']],$foreign);self::assertSame(404,$status);[$status]=$this->get('funds/balances',['date'=>'2026-12-31','house_id'=>$this->f['house']['id']],$foreign);self::assertSame(404,$status);
    }
    public function testClosedCursorFiltersLimitsAndUnsupportedKnowledgeAreExplicit():void
    {
        $a=$this->case();$b=$this->case('2026-07-01','2026-12-31');[$status,$first]=$this->get('service-lifecycles',['limit'=>1]);self::assertSame(200,$status);self::assertSame($a,$first['next_after_id']);[$status,$second]=$this->get('service-lifecycles',['after_id'=>$a,'limit'=>1]);self::assertSame(200,$status);self::assertSame([$b],array_column($second['items'],'id'));self::assertNull($second['next_after_id']);
        [$status]=$this->get('service-lifecycles',['after_id'=>PHP_INT_MAX]);self::assertSame(409,$status);[$status]=$this->get('service-lifecycles',['limit'=>101]);self::assertSame(422,$status);
        foreach(['service-lifecycles'=>[],'service-lifecycles/'.$a=>[],'service-lifecycles/reconciliation'=>['date'=>'2026-12-31']] as $path=>$query){[$status,$body]=$this->get($path,$query+['known_at'=>'2026-12-31T12:00:00+01:00']);self::assertSame(409,$status,json_encode($body));}
    }
    public function testServiceReconciliationReturnsNativeIncompleteWithoutPrivateEvidence():void
    {
        $this->case();[$status,$read]=$this->get('service-lifecycles/reconciliation',['date'=>'2026-12-31','unit_id'=>$this->f['units']['U02'],'service_id'=>$this->f['service']['id']]);self::assertSame(200,$status,json_encode($read));self::assertFalse($read['matches']);self::assertSame('incomplete',$read['coverage']);self::assertContains('lifecycle_estimate_missing',array_column($read['findings'],'code'));self::assertSame('CZK',$read['currency']);$this->assertPrivateAbsent($read);
    }
    private function income(string $date,string $key):array
    {
        $request=$this->fundingRequest('income',$date,'6000000')+['bank_id'=>$this->fundingBank($date,'60000.00'),'key'=>$this->fundingKey($date)];$request['evidence_ref']=self::PRIVATE;$request['reason']=self::PRIVATE;return $this->fundingApply($request,$key);
    }
    public function testNativeFundOperationsAndBalancesExposeOnlyTypedAmountsAndTechnicalIds():void
    {
        $income=$this->income('2026-06-01','catalog-income');[$status,$page]=$this->get('funds/operations',['house_id'=>$this->f['house']['id']]);self::assertSame(200,$status,json_encode($page));self::assertSame([$income['id']],array_column($page['items'],'id'));self::assertSame('6000000',$page['items'][0]['amount_cents']);$this->assertPrivateAbsent($page);
        [$status,$read]=$this->get('funds/balances',['date'=>'2026-12-31']);self::assertSame(200,$status,json_encode($read));self::assertSame('6000000',$read['totals']['379.920']);self::assertTrue($read['matches']);$this->assertPrivateAbsent($read);
        [$status,$past]=$this->get('funds/balances',['date'=>'2026-12-31','known_at'=>'2025-12-31T12:00:00+01:00']);self::assertSame(200,$status,json_encode($past));self::assertSame('posted_and_recorded_at_cutoff',$past['knowledge_basis']);self::assertSame([],$past['totals']);
    }
    public function testNativeFundingReversalKeepsTheOriginalSignedAmountAndClosedCursor():void
    {
        $income=$this->income('2026-06-01','catalog-income');$reverse=$this->fundingRequest('reverse','2026-07-01')+['operation_id'=>$income['id']];unset($reverse['amount_cents']);$this->fundingApply($reverse,'catalog-income-reverse');
        [$status,$first]=$this->get('funds/operations',['limit'=>1]);self::assertSame(200,$status,json_encode($first));self::assertSame('reversed',$first['items'][0]['status']);self::assertSame($income['id'],$first['next_after_id']);
        [$status,$second]=$this->get('funds/operations',['limit'=>1,'after_id'=>$first['next_after_id']]);self::assertSame(200,$status,json_encode($second));self::assertSame('-6000000',$second['items'][0]['amount_cents']);self::assertSame($income['id'],$second['items'][0]['reversal_of']);self::assertNull($second['next_after_id']);$this->assertPrivateAbsent($second);
    }
    public function testVerifiedEstimateAndReconciliationRejectMissingClaimsInsteadOfSkippingTheCase():void
    {
        $id=$this->case();$s=$this->f['supplier'];$chart=new ChartOfAccountsRepository($this->db);
        foreach(['388.210'=>'388','389.110'=>'389'] as $code=>$parent)if(!$chart->findByCode($s,$code)){$a=$chart->findByCode($s,$parent);$chart->insert($s,['account_code'=>$code,'name'=>self::PRIVATE,'account_type'=>$a['account_type'],'normal_side'=>$a['normal_side'],'parent_id'=>$a['id']]);}
        $q=$this->db->pdo()->prepare('SELECT v.type_id,v.id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id AND v.supplier_id=o.supplier_id WHERE o.supplier_id=? AND o.id IN (?,?)');$q->execute([$s,$this->f['units']['U02'],$this->f['service']['id']]);$dims=$q->fetchAll(\PDO::FETCH_KEY_PAIR);
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($s,'manual',null,[['account_code'=>'388.210','side'=>'debit','amount'=>'11000.00','dimensions'=>$dims],['account_code'=>'389.110','side'=>'credit','amount'=>'11000.00','dimensions'=>$dims]],['entry_date'=>'2026-06-01','posted_by'=>$this->f['actor'],'description'=>self::PRIVATE]);
        (new ServiceLifecycleRepository($this->db))->registerEstimate($s,$id,[$entry],self::PRIVATE);
        [$status,$detail]=$this->get('service-lifecycles/'.$id);self::assertSame(200,$status,json_encode($detail));self::assertSame('1100000',$detail['estimate_cents']);self::assertSame('estimated',$detail['phase']);self::assertSame('estimate',$detail['events'][0]['kind']);$this->assertPrivateAbsent($detail);
        [$status,$recon]=$this->get('service-lifecycles/reconciliation',['date'=>'2026-06-30','unit_id'=>$this->f['units']['U02'],'service_id'=>$this->f['service']['id']]);self::assertSame(200,$status,json_encode($recon));self::assertTrue($recon['matches']);self::assertSame('1100000',$recon['totals']['388.210']);$this->assertPrivateAbsent($recon);
        $this->db->pdo()->prepare("DELETE FROM property_service_lifecycle_claims WHERE supplier_id=? AND claim_role='estimate'")->execute([$s]);
        foreach(['service-lifecycles'=>[],'service-lifecycles/'.$id=>[],'service-lifecycles/reconciliation'=>['date'=>'2026-06-30']] as $path=>$query){[$status,$body]=$this->get($path,$query);self::assertSame(409,$status,json_encode($body));$this->assertPrivateAbsent($body);}
    }
    public function testFundingOperationAndBalancesRejectMissingCanonicalClaims():void
    {
        $income=$this->income('2026-06-01','catalog-corrupt-income');[$status]=$this->get('funds/operations');self::assertSame(200,$status);
        $this->db->pdo()->prepare('DELETE FROM property_funding_claims WHERE supplier_id=? AND operation_id=?')->execute([$this->f['supplier'],$income['id']]);
        foreach(['funds/operations'=>[],'funds/balances'=>['date'=>'2026-12-31']] as $path=>$query){[$status,$body]=$this->get($path,$query);self::assertSame(409,$status,json_encode($body));$this->assertPrivateAbsent($body);}
        [$status]=$this->get('funds/operations',['after_id'=>$income['id']]);self::assertSame(409,$status,'A corrupt cursor anchor must not skip native proof.');
    }
    public function testSupplierWideBalancesPreserveUnregisteredLedgerCoverageFailure():void
    {
        $p=$this->db->pdo();$s=$this->f['supplier'];$period=(int)$p->query('SELECT id FROM accounting_periods WHERE supplier_id='.$s.' AND fiscal_year=2026')->fetchColumn();
        $p->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type,description,posted_at)VALUES(?,?,'2026-06-01','manual',?,UTC_TIMESTAMP(6))")->execute([$s,$period,self::PRIVATE]);$entry=(int)$p->lastInsertId();
        $q=$p->prepare("INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount)VALUES(?,?,?,?,?,10.00)");$chart=new ChartOfAccountsRepository($this->db);foreach([['955.100','credit'],['221','debit']] as $i=>[$code,$side])$q->execute([$s,$entry,$i+1,$chart->findByCode($s,$code)['id'],$side]);
        [$status,$read]=$this->get('funds/balances',['date'=>'2026-12-31']);self::assertSame(200,$status,json_encode($read));self::assertFalse($read['matches']);self::assertNotEmpty($read['unregistered_line_ids']);$this->assertPrivateAbsent($read);
    }
    public function testBareDomainReadHasNoDmlAndCorruptCanonicalCaseFailsClosed():void
    {
        $id=$this->case();$pdo=$this->db->pdo();$stats=static function()use($pdo):array{return $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_write','Handler_update','Handler_delete')")->fetchAll(\PDO::FETCH_KEY_PAIR);};$before=$stats();$read=new PropertyAccountingCatalog($this->db);$read->lifecycles($this->f['supplier']);$read->reconciliation($this->f['supplier'],'2026-12-31');$read->balances($this->f['supplier'],'2026-12-31');self::assertSame($before,$stats());
        $pdo->prepare('UPDATE property_service_lifecycle_cases SET facts_hash=? WHERE supplier_id=? AND id=?')->execute([str_repeat('0',64),$this->f['supplier'],$id]);[$status,$body]=$this->get('service-lifecycles/'.$id);self::assertSame(409,$status);self::assertSame('settlement_lifecycle_corrupt',$body['error']['code']??$body['code']??null);$this->assertPrivateAbsent($body);[$status]=$this->get('service-lifecycles',['after_id'=>$id]);self::assertSame(409,$status,'A corrupt cursor anchor must not skip native proof.');
    }
}
