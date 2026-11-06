<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Receivables;
use MyInvoice\Action\Property\Receivables\PropertySaldoPublicAction;
use MyInvoice\Middleware\{ApiScopeMiddleware,AuthMiddleware,SupplierScopeMiddleware};
use MyInvoice\Security\{AccessLevel,EffectiveRole,RoutePermissionMap};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface,ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\{ResponseFactory,ServerRequestFactory};
use Slim\Psr7\Response;
final class PropertySaldoPublicContractTest extends TestCase
{
    public function testActualRouteAndBearerPolicyAllowOnlyPropertyRead():void
    {
        $path='/api/property/catalog/payments/saldo';$app=\Slim\Factory\AppFactory::create();\MyInvoice\Routes::register($app);
        $result=$app->getRouteResolver()->computeRoutingResults($path,'GET');self::assertSame(\Slim\Routing\RoutingResults::FOUND,$result->getRouteStatus());$route=$app->getRouteResolver()->resolveRoute((string)$result->getRouteIdentifier());self::assertSame(PropertySaldoPublicAction::class,$route->getCallable());
        $permission=(new RoutePermissionMap())->match('GET',$path);self::assertSame('property',$permission->key);self::assertSame(AccessLevel::READ,$permission->minimum);
        $policy=new ApiScopeMiddleware(new ResponseFactory());$handler=new class implements RequestHandlerInterface{public function handle(ServerRequestInterface $request):ResponseInterface{return new Response(204);}};
        foreach(['read','read_write'] as $scope){$request=(new ServerRequestFactory())->createServerRequest('GET',$path)->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')->withAttribute(AuthMiddleware::ATTR_API_TOKEN,['scope'=>$scope]);self::assertSame(204,$policy->process($request,$handler)->getStatusCode());foreach(['POST','PUT','PATCH','DELETE'] as $method)self::assertSame(403,$policy->process($request->withMethod($method),$handler)->getStatusCode());}
    }
    public function testPermissionAndMalformedPaginationFailBeforeRepositoryUse():void
    {
        $action=(new \ReflectionClass(PropertySaldoPublicAction::class))->newInstanceWithoutConstructor();$request=(new ServerRequestFactory())->createServerRequest('GET','/api/property/catalog/payments/saldo')->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic denied','staff',true,[]));
        self::assertSame(403,$action($request,new Response())->getStatusCode());
        $request=$request->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic read','staff',true,['property'=>1]))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,7);
        $time=['as_of'=>'2026-01-01T00:00:00Z','known_at'=>'2026-01-01T00:00:00Z'];
        foreach([[],['limit'=>0],['limit'=>101],['limit'=>null],['limit'=>[]],['cursor'=>null],['cursor'=>[]],['unit_id'=>1],['after_id'=>1]] as $query){if($query!==[])$query+=$time;self::assertSame(422,$action($request->withQueryParams($query),new Response())->getStatusCode());}
    }
}
