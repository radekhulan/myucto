<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,CsrfMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
final class FrameworkConversionHttpTest extends TestCase
{
    private FrameworkConversionWorkflowTest $fixture;
    private Connection $db;
    private \Slim\App $app;
    private int $supplier;
    private int $actor;
    protected function setUp(): void
    {
        $this->fixture=new FrameworkConversionWorkflowTest('testNativeCloseProducesExplicitBalancedCellsWithoutDml');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','supplier','actor'] as $key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        $c=Bootstrap::buildContainer();$this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$c);\MyInvoice\Routes::register($this->app);$this->app->add($c->get(CsrfMiddleware::class));
    }
    protected function tearDown(): void{(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function http(string $method,string $path,array $body=[],string $auth='session',bool $csrf=true,array $permissions=['accounting'=>1,'accounting.journal.post'=>2],?int $supplier=null,array $query=[]): array
    {
        $q=(new ServerRequestFactory())->createServerRequest($method,'/api/accounting/framework-conversions'.$path)->withParsedBody($body)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-debt-csrf'])->withAttribute('auth.effective_role',new EffectiveRole(99,'Synthetic debt role','staff',true,$permissions))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier??$this->supplier)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');if($csrf)$q=$q->withHeader('X-CSRF-Token','synthetic-debt-csrf');$r=$this->app->handle($q);return[$r->getStatusCode(),json_decode((string)$r->getBody(),true,flags:JSON_THROW_ON_ERROR)];
    }
    public function testActualRootRoutesEnforceSessionWriteCsrfTenantAndBoundPreview():void
    {
        $command=(new \ReflectionMethod($this->fixture,'command'))->invoke($this->fixture);$dump=fn()=>(new \ReflectionMethod($this->fixture,'dump'))->invoke($this->fixture);$before=$dump();
        foreach([['bearer',true,['accounting'=>1,'accounting.journal.post'=>2]],['session',false,['accounting'=>1,'accounting.journal.post'=>2]],['session',true,['accounting'=>1]]] as [$auth,$csrf,$permissions]){[$status]=$this->http('POST','/preview',['command'=>$command],$auth,$csrf,$permissions);self::assertSame(403,$status);self::assertSame($before,$dump());}
        [$status,$page]=$this->http('GET','');self::assertSame(200,$status);self::assertTrue($page['financial_enabled']);self::assertCount(2,$page['periods']);
        [$status,$choices]=$this->http('GET','/choices',query:['source_period_id'=>$command['source_period_id'],'target_period_id'=>$command['target_period_id']]);self::assertSame(200,$status);self::assertCount(5,$choices['cells']);
        [$status,$other]=$this->http('GET','/choices',supplier:$this->supplier+999999,query:['source_period_id'=>$command['source_period_id'],'target_period_id'=>$command['target_period_id']]);self::assertSame(404,$status);self::assertSame('conversion_period_missing',$other['error']['code']);self::assertSame($before,$dump());
        [$status,$review]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(200,$status,json_encode($review));self::assertSame($before,$dump());
        $body=['command'=>$command,'state_hash'=>$review['state_hash'],'preview_hash'=>$review['preview_hash'],'command_token'=>'synthetic-http-confirm'];[$status,$result]=$this->http('POST','/commands',$body);self::assertSame(200,$status,json_encode($result));self::assertGreaterThan(0,$result['opening_entry_id']);[$status,$retry]=$this->http('POST','/commands',$body);self::assertSame(200,$status);self::assertSame($result,$retry);
        $body['command']['evidence']='Synthetic changed request';[$status,$conflict]=$this->http('POST','/commands',$body);self::assertSame(409,$status);self::assertSame('conversion_idempotency_conflict',$conflict['error']['code']);
    }
}
