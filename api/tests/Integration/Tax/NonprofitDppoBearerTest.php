<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,ApiScopeMiddleware,ApiVersionRewriteMiddleware,PermissionMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
use PHPUnit\Framework\TestCase;

final class NonprofitDppoBearerTest extends TestCase
{
    private NonprofitDppoDataProviderTest $fixture;private Connection $db;private \DI\Container $container;private int $supplier;private int $period;
    protected function setUp():void
    {
        $this->fixture=new NonprofitDppoDataProviderTest('testCanonicalJ6AndNativeStatementsUseTrueFiscalInterval');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','container','supplier','period']as$key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        (new \ReflectionMethod($this->fixture,'profile'))->invoke($this->fixture);
    }
    protected function tearDown():void{(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    public function testActualBearerCannotReadPrivateLegalProfileOrSavingsOwnerGraph():void
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO roles(system_key,name,role_type,is_active)VALUES(NULL,'Synthetic DPPO token role','staff',1)")->execute();$role=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions(role_id,permission_key,access_level)VALUES(?,'accounting',1)")->execute([$role]);
        $pdo->prepare("INSERT INTO users(email,password_hash,name,role_id,locale,is_active)VALUES(?,?,'Synthetic DPPO reader',?,'cs',1)")->execute(['dppo-'.bin2hex(random_bytes(5)).'@example.test',password_hash('synthetic-unused-password',PASSWORD_DEFAULT),$role]);$user=(int)$pdo->lastInsertId();
        $this->container->get(UserSupplierRepository::class)->replaceForUser($user,[['supplier_id'=>$this->supplier,'role_id'=>null]]);$token=$this->container->get(ApiTokenService::class)->generateInTransaction($pdo,$user,$this->supplier,'Synthetic DPPO read token','read_write')['plaintext'];
        $app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$this->container);\MyInvoice\Routes::register($app);$app->add($this->container->get(PermissionMiddleware::class));$app->add($this->container->get(SupplierScopeMiddleware::class));$app->add($this->container->get(ApiScopeMiddleware::class));$app->add($this->container->get(AuthMiddleware::class));$app->add(new ApiVersionRewriteMiddleware());
        foreach(['/api/v1/accounting/periods/'.$this->period.'/nonprofit-legal-profile','/api/v1/accounting/nonprofit-tax-savings']as$path){$request=(new ServerRequestFactory())->createServerRequest('GET',$path,['REMOTE_ADDR'=>'127.0.0.1'])->withHeader('Authorization','Bearer '.$token)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new TenantDomainContext('canonical','example.test','https://example.test'));$response=$app->handle($request);$body=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);self::assertSame(403,$response->getStatusCode(),$path);self::assertSame('token_endpoint_forbidden',$body['error']['code']);self::assertArrayNotHasKey('latest',$body);self::assertArrayNotHasKey('sources',$body);}
    }
}
