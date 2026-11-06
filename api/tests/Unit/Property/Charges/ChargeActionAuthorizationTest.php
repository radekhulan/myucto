<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Charges;
use MyInvoice\Action\Property\Charges\ChargeAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ChargeActionAuthorizationTest extends TestCase
{
    #[DataProvider('writers')]
    public function testPublicTokensNeverMutateEvenWithAdminRole(string $method): void
    {
        $action=(new \ReflectionClass(ChargeAction::class))->newInstanceWithoutConstructor();
        $request=(new ServerRequestFactory())->createServerRequest('POST','/api/property/charges')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>1,'role'=>'admin'])->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer');
        $response=$action->$method($request,new Response());
        self::assertSame(403,$response->getStatusCode());self::assertSame('session_required',json_decode((string)$response->getBody(),true)['error']['code']);
    }
    public static function writers(): iterable { foreach(['createComponent','preview','saveVersion'] as $method) yield [$method]; }
    public function testReadAndManageDoNotGrantConfirmation(): void
    {
        $action=(new \ReflectionClass(ChargeAction::class))->newInstanceWithoutConstructor();
        $request=(new ServerRequestFactory())->createServerRequest('POST','/api/property/charges')->withAttribute(AuthMiddleware::ATTR_METHOD,'session')
            ->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic','staff',true,['property'=>1,'property.charges.manage'=>2]));
        self::assertSame(403,$action->saveVersion($request,new Response())->getStatusCode());
        $reader=$request->withAttribute('auth.effective_role',new EffectiveRole(2,'Synthetic reader','staff',true,['property'=>1]));
        foreach(['createComponent','preview','saveVersion'] as $method) self::assertSame(403,$action->$method($reader,new Response())->getStatusCode());
        self::assertSame(422,$action->versions($reader->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer'),new Response())->getStatusCode());
    }
}
