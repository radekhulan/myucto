<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,CsrfMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
final class CapitalHttpTest extends TestCase
{
    private CapitalNativeTest $fixture;
    private Connection $db;
    private \Slim\App $app;
    private int $supplier;
    private int $actor;
    private int $period;
    protected function setUp():void
    {
        $this->fixture=new CapitalNativeTest('testSourceRegistrationIsImmutableTenantBoundAndIdempotent');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','supplier','actor','period']as$key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        $c=Bootstrap::buildContainer();$this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$c);\MyInvoice\Routes::register($this->app);$this->app->add($c->get(CsrfMiddleware::class));
    }
    protected function tearDown():void{(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function http(string $method,string $path,array $body=[],string $auth='session',bool $csrf=true,array $permissions=['accounting'=>1,'accounting.periods.close'=>2]):array
    {
        $q=(new ServerRequestFactory())->createServerRequest($method,'/api/accounting'.$path)->withParsedBody($body)->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-capital-csrf'])->withAttribute('auth.effective_role',new EffectiveRole(99,'Synthetic capital role','staff',true,$permissions))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');if($csrf)$q=$q->withHeader('X-CSRF-Token','synthetic-capital-csrf');$r=$this->app->handle($q);return[$r->getStatusCode(),json_decode((string)$r->getBody(),true,flags:JSON_THROW_ON_ERROR)];
    }
    public function testActualRegisteredRoutesEnforceSessionWriteAndCsrfThenKeepIdempotentSource():void
    {
        $line=(new \ReflectionMethod($this->fixture,'posted'))->invoke($this->fixture,'911','1000.00');$policy=(new \ReflectionMethod($this->fixture,'policy'))->invoke($this->fixture,'fund',$line);$body=['operation'=>'register','policy'=>$policy];
        $counts=function():array{$out=[];foreach(\MyInvoice\Service\Nonprofit\Capital\CapitalRepository::TABLES as$table){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->supplier]);$out[$table]=(int)$q->fetchColumn();}return$out;};$before=$counts();
        foreach([['bearer',true,['accounting'=>1,'accounting.periods.close'=>2]],['session',false,['accounting'=>1,'accounting.periods.close'=>2]],['session',true,['accounting'=>1]]]as[$auth,$csrf,$permissions]){[$status]=$this->http('POST','/nonprofit-capital/preview',$body,$auth,$csrf,$permissions);self::assertSame(403,$status);self::assertSame($before,$counts());}
        [$status,$preview]=$this->http('POST','/nonprofit-capital/preview',$body);self::assertSame(200,$status,json_encode($preview));self::assertSame($before,$counts());
        $command=$body+['input_hash'=>$preview['input_hash'],'reason'=>'Synthetic reviewed capital source','idempotency_key'=>'capital-http-source'];[$status,$receipt]=$this->http('POST','/nonprofit-capital/confirm',$command);self::assertSame(200,$status,json_encode($receipt));$after=$counts();[$status,$retry]=$this->http('POST','/nonprofit-capital/confirm',$command);self::assertSame(200,$status);self::assertSame($receipt,$retry);self::assertSame($after,$counts());
        [$status,$read]=$this->http('GET','/nonprofit-capital');self::assertSame(200,$status);self::assertCount(1,$read['sources']);[$status]=$this->http('GET','/periods/'.$this->period.'/nonprofit-capital/choices');self::assertSame(200,$status);
    }
}
