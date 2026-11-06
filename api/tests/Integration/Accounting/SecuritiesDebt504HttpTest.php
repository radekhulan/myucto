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
final class SecuritiesDebt504HttpTest extends TestCase
{
    private SecuritiesDebt504WorkflowTest $fixture;
    private Connection $db;
    private \Slim\App $app;
    private int $supplier;
    private int $actor;
    protected function setUp(): void
    {
        $this->fixture=new SecuritiesDebt504WorkflowTest('testNativeCouponAcquisitionAccrualAcrossYearPartialReceiptsAndPrincipalAreNotIncome');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','supplier','actor'] as $key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        $c=Bootstrap::buildContainer();$this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$c);\MyInvoice\Routes::register($this->app);$this->app->add($c->get(CsrfMiddleware::class));
    }
    protected function tearDown(): void{(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function http(string $method,string $path,array $body=[],string $auth='session',bool $csrf=true,array $permissions=['accounting'=>1,'accounting.journal.post'=>2],?int $supplier=null,array $query=[]): array
    {
        $q=(new ServerRequestFactory())->createServerRequest($method,'/api/accounting/securities/debt'.$path)->withParsedBody($body)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-debt-csrf'])->withAttribute('auth.effective_role',new EffectiveRole(99,'Synthetic debt role','staff',true,$permissions))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier??$this->supplier)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');if($csrf)$q=$q->withHeader('X-CSRF-Token','synthetic-debt-csrf');$r=$this->app->handle($q);return[$r->getStatusCode(),json_decode((string)$r->getBody(),true,flags:JSON_THROW_ON_ERROR)];
    }
    public function testSourcePaginationReachesEveryNativeLineOnTheSelectedDate(): void
    {
        $posting=(new \ReflectionProperty($this->fixture,'posting'))->getValue($this->fixture);$dimensions=(new \ReflectionProperty($this->fixture,'dims'))->getValue($this->fixture);$lines=[];
        for($i=0;$i<101;$i++)$lines[]=['account_code'=>'253','side'=>'debit','amount'=>'1.00','dimensions'=>$dimensions];$lines[]=['account_code'=>'221','side'=>'credit','amount'=>'101.00','dimensions'=>$dimensions];
        $entry=$posting->postDocument($this->supplier,'manual',null,$lines,['entry_date'=>'2025-12-15','posted'=>true,'posted_by'=>$this->actor]);
        [$status,$first]=$this->http('GET','/sources/acquisitions',query:['date'=>'2025-12-15']);self::assertSame(200,$status);self::assertCount(100,$first['rows']);self::assertIsArray($first['next_cursor']);
        [$status,$second]=$this->http('GET','/sources/acquisitions',query:['date'=>'2025-12-15','after_entry_id'=>$first['next_cursor']['entry_id'],'after_line_id'=>$first['next_cursor']['line_id']]);self::assertSame(200,$status);self::assertCount(1,$second['rows']);self::assertNull($second['next_cursor']);
        $rows=[...$first['rows'],...$second['rows']];self::assertCount(101,array_unique(array_column($rows,'line_id')));self::assertSame([$entry],array_map('intval',array_values(array_unique(array_column($rows,'id')))));
    }

    public function testSessionPermissionCsrfAndFailClosedSharedMutationGateAreEnforcedWithoutDml(): void
    {
        $terms=(new \ReflectionMethod($this->fixture,'coupon'))->invoke($this->fixture);$source=(new \ReflectionMethod($this->fixture,'acquisition'))->invoke($this->fixture,$terms);$command=['operation'=>'register','terms'=>$terms]+$source;
        $counts=fn()=>(new \ReflectionMethod($this->fixture,'countRows'))->invoke($this->fixture);$before=$counts();
        foreach([['bearer',true,['accounting'=>1,'accounting.journal.post'=>2]],['session',false,['accounting'=>1,'accounting.journal.post'=>2]],['session',true,['accounting'=>1]]] as [$auth,$csrf,$permissions]){[$status]=$this->http('POST','/preview',['command'=>$command],$auth,$csrf,$permissions);self::assertSame(403,$status);self::assertSame($before,$counts());}
        [$status,$page]=$this->http('GET','');self::assertSame(200,$status);self::assertSame(SecuritiesDebt504UseGuard::integrated(),$page['financial_enabled']);self::assertIsBool($page['framework_supported']);
        [$status,$preview]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(SecuritiesDebt504UseGuard::integrated()?200:409,$status,json_encode($preview));if(!SecuritiesDebt504UseGuard::integrated())self::assertSame('debt_mutation_guard_unavailable',$preview['error']['code']);self::assertSame($before,$counts());
        [$status,$choices]=$this->http('GET','/profile-choices',query:['date'=>'2025-12-31']);self::assertSame(200,$status);self::assertCount(1,$choices['periods']);
        [$status,$sources]=$this->http('GET','/sources/acquisitions',query:['date'=>$terms['acquired_on']]);self::assertSame(200,$status);self::assertContains((string)$source['line_id'],array_map('strval',array_column($sources['rows'],'line_id')));
        [$status,$wrongDate]=$this->http('GET','/sources/acquisitions',query:['date'=>'2025-12-31']);self::assertSame(200,$status);self::assertSame([],$wrongDate['rows']);
        (new \ReflectionMethod($this->fixture,'applyCommand'))->invoke($this->fixture,$command);[$status,$claimed]=$this->http('GET','/sources/acquisitions',query:['date'=>$terms['acquired_on']]);self::assertSame(200,$status);self::assertSame([],$claimed['rows']);
        [$status,$other]=$this->http('GET','/sources/acquisitions',supplier:$this->supplier+999999,query:['date'=>'2025-12-31']);self::assertSame(200,$status);self::assertSame([],$other['rows']);
    }
}
