<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,ApiScopeMiddleware,ApiVersionRewriteMiddleware,PermissionMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};

trait CuratedReadBearerFixture
{
    use IsolatedSupplierTrait;
    private Connection $db;private \DI\Container $container;private int $supplier;private int $otherSupplier;private int $actor;private \Slim\App $app;
    protected function setUp():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);$pdo=$this->db->pdo();self::assertStringEndsWith('_test',(string)$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$pdo->beginTransaction();
        $seed=(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$seed);$this->supplier=$this->createIsolatedSupplier($pdo,$seed);$this->otherSupplier=$this->createIsolatedSupplier($pdo,$seed);$this->actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$this->container);\MyInvoice\Routes::register($this->app);
        $this->app->add($this->container->get(PermissionMiddleware::class));$this->app->add($this->container->get(SupplierScopeMiddleware::class));$this->app->add($this->container->get(ApiScopeMiddleware::class));$this->app->add($this->container->get(AuthMiddleware::class));$this->app->add(new ApiVersionRewriteMiddleware());
    }
    protected function tearDown():void
    {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function token(array $permissions=['property'=>1,'accounting'=>1]):string
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO roles(system_key,name,role_type,is_active)VALUES(NULL,?,'staff',1)")->execute(['Synthetic curated '.bin2hex(random_bytes(5))]);$role=(int)$pdo->lastInsertId();
        foreach($permissions as$key=>$level)$pdo->prepare('INSERT INTO role_permissions(role_id,permission_key,access_level)VALUES(?,?,?)')->execute([$role,$key,$level]);
        $pdo->prepare("INSERT INTO users(email,password_hash,name,role_id,locale,is_active)VALUES(?,?,'Synthetic curated reader',?,'cs',1)")->execute(['curated-'.bin2hex(random_bytes(5)).'@example.test',password_hash('synthetic-unused-password',PASSWORD_DEFAULT),$role]);$user=(int)$pdo->lastInsertId();
        $this->container->get(UserSupplierRepository::class)->replaceForUser($user,[['supplier_id'=>$this->supplier,'role_id'=>null]]);
        return$this->container->get(ApiTokenService::class)->generateInTransaction($pdo,$user,$this->supplier,'Synthetic curated token','read_write')['plaintext'];
    }
    private function http(string $method,string $path,string $token,array $query=[],array $headers=[]):array
    {
        $request=(new ServerRequestFactory())->createServerRequest($method,$path,['REMOTE_ADDR'=>'127.0.0.1'])->withQueryParams($query)->withHeader('Authorization','Bearer '.$token)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new TenantDomainContext('canonical','example.test','https://example.test'));
        foreach($headers as$name=>$value)$request=$request->withHeader($name,$value);$response=$this->app->handle($request);$body=(string)$response->getBody();return[$response->getStatusCode(),$body===''?null:json_decode($body,true,512,JSON_THROW_ON_ERROR)];
    }
}
