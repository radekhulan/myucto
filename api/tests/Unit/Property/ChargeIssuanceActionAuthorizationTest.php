<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Action\Property\Charges\Issuance\IssuanceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ChargeIssuanceActionAuthorizationTest extends TestCase
{
    #[DataProvider('writers')]
    public function testEveryWriterRejectsPublicAdminTokenBeforeReadingBody(string $method): void
    {
        $action=(new \ReflectionClass(IssuanceAction::class))->newInstanceWithoutConstructor();
        $request=(new ServerRequestFactory())->createServerRequest('POST','/api/property/charges/issues')->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER,['id'=>1,'role'=>'admin']);
        $response=$action->$method($request,new Response(),['id'=>'1']);
        self::assertSame(403,$response->getStatusCode());
        self::assertSame('session_required',json_decode((string)$response->getBody(),true)['error']['code']);
    }

    public static function writers(): iterable { foreach(['preview','issue','post','reverse','recordEvidence'] as $method) yield [$method]; }

    public function testIssuePermissionNeverGrantsPostOrReverse(): void
    {
        $action=(new \ReflectionClass(IssuanceAction::class))->newInstanceWithoutConstructor();
        $request=(new ServerRequestFactory())->createServerRequest('POST','/api/property/charges/issues')->withAttribute(AuthMiddleware::ATTR_METHOD,'session')
            ->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic issuer','staff',true,['property'=>1,'property.charges.issue'=>2]));
        foreach(['post','reverse'] as $method) self::assertSame(403,$action->$method($request,new Response(),['id'=>'1'])->getStatusCode());
    }
}
