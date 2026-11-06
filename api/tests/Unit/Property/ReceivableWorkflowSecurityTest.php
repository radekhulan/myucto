<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Action\Property\Receivables\ReceivableWorkflowAction;
use MyInvoice\Middleware\{AuthMiddleware,ApiScopeMiddleware};
use MyInvoice\Security\{EffectiveRole,RoutePermissionMap,AccessLevel};
use MyInvoice\Routes;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
use Slim\Psr7\Response;
final class ReceivableWorkflowSecurityTest extends TestCase
{
    public function testRoutesPermissionsAndBearerDenial():void
    {
        $app=AppFactory::create();Routes::register($app);$map=new RoutePermissionMap();
        foreach(['transferPreview'=>['transfers/preview','manage'],'transferConfirm'=>['transfers/confirm','confirm'],'certificatePreview'=>['certificates/preview','certificates'],'certificateIssue'=>['certificates/issue','certificates']] as $method=>[$path,$permission]){
            $url='/api/property/receivables/'.$path;$result=$app->getRouteResolver()->computeRoutingResults($url,'POST');self::assertSame(1,$result->getRouteStatus());
            $route=$app->getRouteResolver()->resolveRoute((string)$result->getRouteIdentifier());self::assertSame([ReceivableWorkflowAction::class,$method],$route->getCallable());
            $policy=$map->match('POST',$url);self::assertSame('property.receivables.'.$permission,$policy->key);self::assertSame(AccessLevel::WRITE,$policy->minimum);
            $action=(new \ReflectionClass(ReceivableWorkflowAction::class))->newInstanceWithoutConstructor();
            $r=(new ServerRequestFactory())->createServerRequest('POST',$url)->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>1,'role'=>'admin']);
            self::assertSame(403,$action->$method($r,new Response())->getStatusCode());
            $role=new EffectiveRole(1,'Synthetic denied','staff',true,['property'=>1,'property.payments.manage'=>2,'property.registry.confirm'=>2]);
            self::assertSame(403,$action->$method($r->withAttribute(AuthMiddleware::ATTR_METHOD,'session')->withAttribute('auth.effective_role',$role),new Response())->getStatusCode());
            $handler=new class implements \Psr\Http\Server\RequestHandlerInterface {public function handle(\Psr\Http\Message\ServerRequestInterface $r):\Psr\Http\Message\ResponseInterface{throw new \LogicException('Bearer mutation reached handler');}};
            foreach(['read','read_write'] as $scope){$denied=(new ApiScopeMiddleware(new ResponseFactory()))->process($r->withAttribute(AuthMiddleware::ATTR_API_TOKEN,['scope'=>$scope]),$handler);self::assertSame(403,$denied->getStatusCode());}
        }
    }
}
