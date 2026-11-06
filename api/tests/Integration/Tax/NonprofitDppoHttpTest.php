<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,CsrfMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
use PHPUnit\Framework\TestCase;

final class NonprofitDppoHttpTest extends TestCase
{
    private NonprofitDppoDataProviderTest $fixture;private Connection $db;private \DI\Container $container;private int $supplier;private int $period;private int $actor;private \Slim\App $app;
    protected function setUp():void
    {
        $this->fixture=new NonprofitDppoDataProviderTest('testCanonicalJ6AndNativeStatementsUseTrueFiscalInterval');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','container','supplier','period','actor']as$key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        $this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$this->container);\MyInvoice\Routes::register($this->app);$this->app->add($this->container->get(CsrfMiddleware::class));
    }
    protected function tearDown():void
    {(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function http(string $method,string $path,array $body=[],string $auth='session',?array $permissions=null,bool $csrf=true):array
    {
        $request=(new ServerRequestFactory())->createServerRequest($method,'/api/accounting'.$path)->withParsedBody($body)->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-dppo-csrf'])->withAttribute('auth.effective_role',new EffectiveRole(123,'Synthetic nonprofit tax','staff',true,$permissions??['accounting'=>1,'accounting.periods.close'=>2,'reports'=>2]))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');
        if($csrf)$request=$request->withHeader('X-CSRF-Token','synthetic-dppo-csrf');$response=$this->app->handle($request);return[$response->getStatusCode(),json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)];
    }
    public function testReadHasExplicitLegalChoicesWithoutCreatingProfileOrImplicitSvjIdentity():void
    {
        [$status,$body]=$this->http('GET','/periods/'.$this->period.'/nonprofit-legal-profile');self::assertSame(200,$status,json_encode($body));self::assertNull($body['latest']);self::assertContains('association',$body['entity_types']);self::assertContains('svj',$body['kinds']);self::assertFalse($body['capabilities']['filing_ready']);self::assertNull((new NonprofitLegalProfileService($this->db))->latest($this->supplier,$this->period));
        [$status]=$this->http('GET','/periods/'.$this->period.'/nonprofit-legal-profile',permissions:[]);self::assertSame(403,$status);
    }
    public function testRawLegalAndSavingsReadsRequireSessionWithoutScopeMiddleware():void
    {
        foreach(['bearer','']as$auth)foreach(['/periods/'.$this->period.'/nonprofit-legal-profile','/nonprofit-tax-savings']as$path){[$status,$body]=$this->http('GET',$path,auth:$auth);self::assertSame(403,$status);self::assertSame('token_endpoint_forbidden',$body['error']['code']);}
    }
    public function testEveryFinancialConfirmationRejectsBearerOrMissingSessionBeforeBodyEvaluation():void
    {
        foreach(['bearer','']as$auth)foreach(['/periods/'.$this->period.'/nonprofit-legal-profile/confirm','/nonprofit-tax-savings/sources/1/confirm','/nonprofit-tax-savings/sources/1/events/1/reverse-confirm']as$path){[$status]=$this->http('POST',$path,auth:$auth);self::assertSame(403,$status,$path);}
        [$status]=$this->http('POST','/periods/'.$this->period.'/nonprofit-legal-profile/confirm',csrf:false);self::assertSame(403,$status);
    }
    public function testConfirmedLegalProfileUsesSessionActorAndStrictBodyAndRevisionContracts():void
    {
        $body=['expected_revision'=>0,'idempotency_key'=>'http-legal-profile','reason'=>'Synthetic reviewed statutes','profile'=>['kind'=>'narrow_vpp','entity_type'=>'association','main_activity_nonbusiness'=>true,'evidence'=>'Synthetic association statutes','legal_basis'=>'zdp_17a']];$path='/periods/'.$this->period.'/nonprofit-legal-profile/confirm';
        [$status]=$this->http('POST',$path,$body+['actor'=>999999]);self::assertSame(422,$status);
        [$status,$record]=$this->http('POST',$path,$body);self::assertSame(200,$status,json_encode($record));self::assertSame($this->actor,$record['created_by']);
        [$status,$retry]=$this->http('POST',$path,$body);self::assertSame(200,$status);self::assertSame($record,$retry);
        $body['idempotency_key']='http-stale-legal-profile';[$status]=$this->http('POST',$path,$body);self::assertSame(409,$status);self::assertCount(1,(new NonprofitLegalProfileService($this->db))->history($this->supplier,$this->period));
    }
}
