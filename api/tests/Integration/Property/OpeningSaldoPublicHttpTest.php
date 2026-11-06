<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,ChartOfAccountsRepository};
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Opening\OpeningException;
use MyInvoice\Service\Property\Receivables\PropertyReceivableProjection;
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware,ApiScopeMiddleware,ApiVersionRewriteMiddleware};
use MyInvoice\Tests\Support\{PropertyChargeFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};

final class OpeningSaldoPublicHttpTest extends TestCase
{
    use PropertyChargeFixture,PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    private \PDO $server;
    private string $owned;
    private string|false $priorDatabase;
    private \DI\Container $container;
    private \Slim\App $app;
    protected function setUp():void
    {
        $this->priorDatabase=getenv('MYINVOICE_DB_NAME');
        $config=Config::load(Bootstrap::rootDir());self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        $source=(string)$config->get('db.name');if(!str_ends_with($source,'_test'))$source.='_test';self::assertMatchesRegularExpression('/^[a-zA-Z0-9_]+_test$/D',$source);
        $this->owned='myucto_s6_saldo_http_'.bin2hex(random_bytes(10)).'_test';self::assertNotSame($source,$this->owned);
        $this->server=new class('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]) extends \PDO {
            public ?string $created=null;
            public function exec(string $statement):int|false{$result=parent::exec($statement);if(preg_match('/^CREATE DATABASE `([a-z0-9_]+)` /D',$statement,$m))$this->created=$m[1];return $result;}
        };
        try{
            $q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$this->owned]);self::assertSame(0,(int)$q->fetchColumn());
            $cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($source);$cloner->copyTables($plan,$this->owned,static function():void{});self::assertSame($this->owned,$this->server->created);$cloner->finalize($plan,$this->owned);
            putenv('MYINVOICE_DB_NAME='.$this->owned);[$this->container,$this->db]=Connection::withoutSharedTestConnection(static function():array{$container=Bootstrap::buildContainer();return [$container,$container->get(Connection::class)];});
            self::assertSame($this->owned,$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
            $this->db->pdo()->beginTransaction();$this->f=$this->chargeFixture($this->db);$s=$this->f['supplier'];
            (new AccountingPeriodRepository($this->db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($this->db))->seedForSupplier($s,'2026-01-01');
            $accounts=new ChartOfAccountsRepository($this->db);foreach(['315.100','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
            $this->db->pdo()->commit();
            $this->app=\Slim\Factory\AppFactory::create(null,$this->container);\MyInvoice\Routes::register($this->app);$this->app->add(new ApiScopeMiddleware(new ResponseFactory()));$this->app->add(new ApiVersionRewriteMiddleware());
        }catch(\Throwable $e){$this->cleanup();throw $e;}
    }
    protected function tearDown():void{$this->cleanup();}
    private function cleanup():void
    {
        try{
            if(isset($this->db)){if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();$this->db->close();}
            if(isset($this->server)&&$this->server->created!==null){self::assertMatchesRegularExpression('/^myucto_s6_saldo_http_[a-f0-9]{20}_test$/D',$this->owned);self::assertSame($this->owned,$this->server->created);\MyInvoice\Tests\Support\OwnedTestDatabaseCleanup::drop($this->server,$this->owned,[$this->server->created]);$this->server->created=null;$q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$this->owned]);self::assertSame(0,(int)$q->fetchColumn());}
        }finally{
            if(isset($this->priorDatabase))putenv($this->priorDatabase===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$this->priorDatabase);
        }
    }
    private function get(string $path,array $query,array $attributes=[]):array
    {
        $request=(new ServerRequestFactory())->createServerRequest('GET',$path)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')->withAttribute(AuthMiddleware::ATTR_API_TOKEN,['scope'=>'read'])->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->f['supplier']);
        foreach($attributes as $key=>$value)$request=$request->withAttribute($key,$value);
        $response=$this->app->handle($request);self::assertFalse($this->db->pdo()->inTransaction());return [$response->getStatusCode(),json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR),$response->getHeaderLine('X-API-Version')];
    }
    private function unitPage(string $asOf,string $knownAt):array
    {
        $cursor=null;$found=null;$seen=[];$last=0;
        do{
            $q=['as_of'=>$asOf,'known_at'=>$knownAt,'limit'=>1];if($cursor!==null)$q['cursor']=$cursor;
            [$status,$page,$version]=$this->get('/api/v1/property/catalog/payments/saldo',$q);self::assertSame(200,$status,json_encode($page));self::assertSame('1',$version);self::assertLessThanOrEqual(1,count($page['items']));
            self::assertSame('selected_unit_financial_closure',$page['proof_scope']['kind']);self::assertFalse($page['coverage']['complete']);self::assertSame('recorded_s3_s4_s6',$page['coverage']['scope']);self::assertSame('verified_recorded_batches',$page['coverage']['opening_import']);self::assertArrayNotHasKey('source_snapshot_hash',$page);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$page['read_scope_digest']);
            foreach($page['items'] as $item){self::assertGreaterThan($last,$item['unit_id']);$last=$item['unit_id'];$seen[]=$last;if($last===$this->f['units']['U02'])$found=['item'=>$item,'page'=>$page];}
            $cursor=$page['next_cursor'];self::assertLessThanOrEqual(count($this->f['units']),count($seen));
        }while($cursor!==null);
        self::assertCount(count($this->f['units']),$seen);self::assertNotNull($found);return $found;
    }
    public function testOpeningFlowsThroughCuratedKeysetPageWithTemporalPersonalAndUncertainCoverage():void
    {
        $s=$this->f['supplier'];$unit=$this->f['units']['U02'];$before=PartyEventRepository::now();$repo=$this->container->get(OpeningRepository::class);
        $common=['unit_id'=>$unit,'service_id'=>$this->f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic HTTP title','evidence_ref'=>'Synthetic reconciliation'];
        $debt=$common+['case_ref'=>'debt-http','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'1000000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$this->f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
        $credit=$common+['case_ref'=>'credit-http','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$this->f['clients'][18]]];
        $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening HTTP','items'=>[$debt,$credit]];
        $posted=$repo->apply($s,$request,$repo->preview($s,$request)['preview_hash'],'opening-saldo-http',$this->f['actor']);self::assertSame('posted',$posted['status']);$firstKnown=PartyEventRepository::now();
        $initial=$this->unitPage('2026-12-31T23:59:59+01:00',$firstKnown);self::assertSame('1000000',$initial['item']['totals']['gross_receivable_cents']);self::assertSame('1000000',$initial['item']['totals']['gross_credit_cents']);self::assertTrue($initial['item']['coverage']['financial_complete']);
        $revision=$request;$revision['mode']='revision';$revision['expected_revision']=1;$revision['accounting_date']='2026-01-02';$revision['effective_at']='2026-01-02T00:00:00+01:00';foreach($revision['items'] as &$item)$item['components'][0]['amount_cents']='900000';unset($item);
        try{$repo->apply($s,$revision,$initial['page']['read_scope_digest'],'invalid-page-proof',$this->f['actor']);self::fail('Read page digest accepted as writer preview.');}catch(OpeningException $e){self::assertSame('property_opening_preview_stale',$e->errorCode);}
        $repo->apply($s,$revision,$repo->preview($s,$revision)['preview_hash'],'opening-saldo-revision',$this->f['actor']);$known=PartyEventRepository::now();
        $current=$this->unitPage('2026-12-31T23:59:59+01:00',$known);self::assertSame('900000',$current['item']['totals']['gross_receivable_cents']);self::assertSame('900000',$current['item']['totals']['gross_credit_cents']);self::assertFalse($current['item']['coverage']['complete']);
        $past=$this->unitPage('2026-01-01T12:00:00+01:00',$known);self::assertSame('1000000',$past['item']['totals']['gross_receivable_cents']);self::assertSame('1000000',$past['item']['totals']['gross_credit_cents']);
        $oldKnowledge=$this->unitPage('2026-12-31T23:59:59+01:00',$firstKnown);self::assertSame('1000000',$oldKnowledge['item']['totals']['gross_receivable_cents']);self::assertSame('1000000',$oldKnowledge['item']['totals']['gross_credit_cents']);
        $unknown=$this->unitPage('2026-12-31T23:59:59+01:00',$before);self::assertSame('0',$unknown['item']['totals']['gross_receivable_cents']);self::assertSame('0',$unknown['item']['totals']['gross_credit_cents']);
        $projection=new PropertyReceivableProjection($this->db);$debtor=$projection->at($s,'2026-12-31T23:59:59+01:00',$known,['unit_id'=>$unit,'client_id'=>$this->f['clients'][1]]);$creditor=$projection->at($s,'2026-12-31T23:59:59+01:00',$known,['unit_id'=>$unit,'client_id'=>$this->f['clients'][18]]);self::assertSame('900000',$debtor['totals']['gross_receivable_cents']);self::assertSame('0',$debtor['totals']['gross_credit_cents']);self::assertSame('0',$creditor['totals']['gross_receivable_cents']);self::assertSame('900000',$creditor['totals']['gross_credit_cents']);
        [$status,$card]=$this->get('/api/v1/property/catalog/payments/card',['unit_id'=>$unit,'as_of'=>'2026-12-31T23:59:59+01:00','known_at'=>$known]);self::assertSame(200,$status,json_encode($card));self::assertSame($current['item']['totals']['gross_receivable_cents'],$card['totals']['gross_receivable_cents']);foreach($card['claims'] as $claim)self::assertNull($claim['person_liability_cents']);
        $creditId=(int)$this->db->pdo()->query('SELECT MAX(id) FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();$payments=$this->container->get(PaymentRepository::class);\MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$s,$this->f['clients'][18]);$reservation=$payments->reserve($s,$creditId,1,['amount_cents'=>'10000','party_ids'=>[$this->f['clients'][18]],'disposition'=>'Synthetic HTTP refund','recipient_account'=>'1000000005/0100'],'saldo-http-reserve',$this->f['actor']);
        $bank=$this->correctionBank('-100.00');$refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-02-01','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic HTTP refund'];$payments->apply($s,$refund,$payments->preview($s,$refund)['preview_hash'],'saldo-http-refund',$this->f['actor']);$known=PartyEventRepository::now();
        $uncertain=$this->unitPage('2026-02-01T12:00:00+01:00',$known)['item'];self::assertFalse($uncertain['coverage']['financial_complete']);self::assertSame('day',$uncertain['precision']);self::assertGreaterThan(0,$uncertain['uncertain_event_count']);foreach($uncertain['totals'] as $amount)self::assertNull($amount);foreach($uncertain['aging'] as $amount)self::assertNull($amount);
        $settled=$this->unitPage('2026-12-31T23:59:59+01:00',$known)['item'];self::assertSame('900000',$settled['totals']['gross_receivable_cents']);self::assertSame('890000',$settled['totals']['gross_credit_cents']);self::assertSame('0',$settled['totals']['reserved_credit_cents']);self::assertTrue($settled['coverage']['financial_complete']);self::assertFalse($settled['coverage']['complete']);
        [$status]=$this->get('/api/v1/property/catalog/payments/saldo',['as_of'=>'2026-12-31T23:59:59+01:00','known_at'=>$known],['auth.effective_role'=>new \MyInvoice\Security\EffectiveRole(1,'Synthetic no read','staff',true,[])]);self::assertSame(403,$status);
    }
}
