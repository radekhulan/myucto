<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Action\Property\Receivables\ReceivableWorkflowAction;
use MyInvoice\Repository\Property\Receivables\{PartyEventRepository,DebtCertificateRepository,ReceivableWorkflowReadRepository};
use MyInvoice\Service\Property\Receivables\PropertyReceivableProjection;
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture};
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
final class ReceivableWorkflowActionTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture;
    private Connection $db;private array $f;private ReceivableWorkflowAction $action;private \PDO $server;private string $owned;
    protected function setUp():void{$container=Bootstrap::buildContainer();$config=$container->get(\MyInvoice\Infrastructure\Config\Config::class);$source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        $this->owned='myucto_s5_http_'.bin2hex(random_bytes(5)).'_test';$this->server=new \PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $cloner=new \MyInvoice\Service\Anonymization\DatabaseCloner($this->server);$plan=$cloner->plan($source);$cloner->copyTables($plan,$this->owned,static function():void{});$cloner->finalize($plan,$this->owned);
        $this->db=new Connection(new \MyInvoice\Infrastructure\Config\Config(['db'=>['host'=>$config->get('db.host'),'port'=>$config->get('db.port',3306),'name'=>$this->owned,'user'=>$config->get('db.user'),'pass'=>$config->get('db.pass','')]]));$this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);$this->action=new ReceivableWorkflowAction(new PartyEventRepository($this->db),new DebtCertificateRepository($this->db),new ReceivableWorkflowReadRepository($this->db));}
    protected function tearDown():void{if(isset($this->db)){if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();$this->db->close();}if(isset($this->owned)){self::assertMatchesRegularExpression('/^myucto_s5_http_[a-f0-9]{10}_test$/D',$this->owned);$this->server->exec('DROP DATABASE '.\MyInvoice\Service\Anonymization\DatabaseCloner::quote($this->owned));}}
    private function http(string $method,array $body,array $args=[]):array{$r=(new ServerRequestFactory())->createServerRequest('POST','/api/property/receivables')->withParsedBody($body)->withAttribute(AuthMiddleware::ATTR_METHOD,'session')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->f['supplier']);$response=$this->action->$method($r,new Response(),$args);return [$response->getStatusCode(),json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR)];}
    public function testTransferTemporalCutoffsSingleDebtGuaranteeStaleAndImmutableCertificate():void
    {
        $base=$this->settledPaymentFixture('1000','0');$supplier=$this->f['supplier'];$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$at='2026-02-02T10:00:00+01:00';
        $request=$this->request($base,$this->f['clients'][2],1,null,$at,[$old]);$before=PartyEventRepository::now();
        foreach([['debts'=>[false]],['debts'=>[['groups'=>[false]]]],['debts'=>[['groups'=>[['members'=>false]]]]]] as $bad)self::assertSame(422,$this->http('transferPreview',array_replace($request,$bad))[0]);
        [$status,$preview]=$this->http('transferPreview',$request);self::assertSame(200,$status);self::assertCount(1,$preview['snapshot']['debts']);
        $command=['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-http-transfer'];
        [$status,$event]=$this->http('transferConfirm',$command);self::assertSame(200,$status);self::assertSame([200,$event],$this->http('transferConfirm',$command));
        $command['idempotency_key']='synthetic-stale';self::assertSame(409,$this->http('transferConfirm',$command)[0]);
        $after=PartyEventRepository::now();$projection=new PropertyReceivableProjection($this->db);
        $prior=$projection->at($supplier,$at,$before,['unit_id'=>$request['unit_id']],true);$now=$projection->at($supplier,$at,$after,['unit_id'=>$request['unit_id']],true);
        self::assertSame($prior['totals']['gross_receivable_cents'],$now['totals']['gross_receivable_cents']);
        $claim=array_values(array_filter($now['claims'],fn($c)=>$c['debt_id']===$base['debt']['id']));self::assertCount(1,$claim);self::assertCount(2,$claim[0]['groups']);
        self::assertSame($event['id'],$claim[0]['liability']['event_id']);$priorClaim=array_values(array_filter($prior['claims'],fn($c)=>$c['debt_id']===$base['debt']['id']))[0];self::assertNull($priorClaim['liability']['event_id']);
        $cert=['unit_id'=>$request['unit_id'],'as_of'=>$at,'known_at'=>$after,'requester_id'=>$this->f['clients'][2],'authority_evidence'=>'Synthetic request','purpose'=>'Synthetic transfer','coverage_acknowledgement'=>'Recorded S3/S4 only'];
        [$status,$preview]=$this->http('certificatePreview',$cert);self::assertSame(200,$status);
        $command=['request'=>$cert,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-certificate'];[$status,$saved]=$this->http('certificateIssue',$command);self::assertSame(200,$status);self::assertFalse($saved['snapshot']['unconditional_no_debt']);self::assertSame([200,$saved],$this->http('certificateIssue',$command));
        $second=$this->request($base,$this->f['clients'][3],2,$event['id'],'2026-02-03T10:00:00+01:00',[$old,$this->f['clients'][2]]);[$status,$preview]=$this->http('transferPreview',$second);self::assertSame(200,$status);self::assertSame(200,$this->http('transferConfirm',['request'=>$second,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'second-transfer'])[0]);
        self::assertSame([200,$saved],$this->http('certificate',[],['id'=>$saved['id']]));
        $this->f['supplier']+=100000;self::assertSame(409,$this->http('certificate',[],['id'=>$saved['id']])[0]);
    }

    public function testActualHttpContextSnapshotCsrfAndSessionSecurity():void
    {
        $base=$this->settledPaymentFixture('1000','0');$unit=$base['charge']['unit_id'];$at='2026-02-02T10:00:00+01:00';$before=PartyEventRepository::now();$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$transfer=$this->request($base,$this->f['clients'][2],1,null,$at,[$old]);[$status,$preview]=$this->http('transferPreview',$transfer);self::assertSame(200,$status);[$status,$event]=$this->http('transferConfirm',['request'=>$transfer,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'http-context-transfer']);self::assertSame(200,$status);$this->db->pdo()->commit();$known=PartyEventRepository::now();
        $action=$this->action;$container=new class($action) implements \Psr\Container\ContainerInterface{public function __construct(private object $action){}public function has(string $id):bool{return $id===ReceivableWorkflowAction::class;}public function get(string $id):mixed{if(!$this->has($id))throw new \LogicException('Unexpected dependency');return $this->action;}};
        $app=\Slim\Factory\AppFactory::create(null,$container);\MyInvoice\Routes::register($app);
        $dependencies=$this->paymentFixtureContainer();$config=$dependencies->get(\MyInvoice\Infrastructure\Config\Config::class);$csrf=new \MyInvoice\Middleware\CsrfMiddleware($config,new \Slim\Psr7\Factory\ResponseFactory(),$dependencies->get(\MyInvoice\Middleware\FirstRunLockMiddleware::class));$app->add($csrf);$app->add(new \MyInvoice\Middleware\ApiScopeMiddleware(new \Slim\Psr7\Factory\ResponseFactory()));
        $request=(new ServerRequestFactory())->createServerRequest('GET','/api/property/receivables/context')->withQueryParams(['unit_id'=>$unit,'as_of'=>$at,'known_at'=>$known])->withAttribute(AuthMiddleware::ATTR_METHOD,'session')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->f['supplier']);
        $response=$app->handle($request);self::assertSame(200,$response->getStatusCode());self::assertFalse($this->db->pdo()->inTransaction());$context=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(\MyInvoice\Service\Property\PropertyInstant::normalize($at),$context['as_of']);self::assertSame(\MyInvoice\Service\Property\PropertyInstant::normalize($known),$context['known_at']);self::assertFalse($context['projection']['coverage']['complete']);self::assertCount(1,$context['events']);self::assertSame($event['id'],$context['events'][0]['id']);$reference=(new PropertyReceivableProjection($this->db))->at($this->f['supplier'],$at,$known,['unit_id'=>$unit]);self::assertSame($reference,$context['projection']);$past=$app->handle($request->withQueryParams(['unit_id'=>$unit,'as_of'=>$at,'known_at'=>$before]));self::assertSame(200,$past->getStatusCode());$past=json_decode((string)$past->getBody(),true,flags:JSON_THROW_ON_ERROR);self::assertSame([],$past['events']);self::assertCount(1,$past['unit']['history']);self::assertNotSame($context['unit']['revision']['id'],$past['unit']['revision']['id']);
        foreach(['READ COMMITTED','READ UNCOMMITTED','REPEATABLE READ'] as $level){$this->db->pdo()->exec('SET TRANSACTION ISOLATION LEVEL '.$level);$this->db->pdo()->exec('START TRANSACTION READ ONLY');$before=$this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Com_commit','Com_rollback')")->fetchAll(\PDO::FETCH_KEY_PAIR);$response=$app->handle($request);self::assertSame(409,$response->getStatusCode());self::assertTrue($this->db->pdo()->inTransaction());self::assertSame($before,$this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Com_commit','Com_rollback')")->fetchAll(\PDO::FETCH_KEY_PAIR));$this->db->pdo()->rollBack();}
        $cert=['unit_id'=>$unit,'as_of'=>$at,'known_at'=>$known,'requester_id'=>$this->f['clients'][1],'authority_evidence'=>'Synthetic authority','purpose'=>'Synthetic HTTP','coverage_acknowledgement'=>'Recorded S3/S4 only'];
        $uri=$request->getUri()->withPath('/api/property/receivables/certificates/preview');$write=$request->withMethod('POST')->withUri($uri)->withParsedBody($cert)->withHeader('Origin',(string)$config->get('app.url'))->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-csrf']);
        self::assertSame(403,$app->handle($write)->getStatusCode());self::assertSame(200,$app->handle($write->withHeader('X-CSRF-Token','synthetic-csrf'))->getStatusCode());
        self::assertSame(403,$app->handle($write->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')->withAttribute(AuthMiddleware::ATTR_API_TOKEN,['scope'=>'read_write']))->getStatusCode());
        self::assertSame(404,$app->handle($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->f['supplier']+100000))->getStatusCode());
        $r=$request->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(1,'Synthetic no read','staff',true,['property.receivables.confirm'=>2]));self::assertSame(403,$app->handle($r)->getStatusCode());
    }
}
