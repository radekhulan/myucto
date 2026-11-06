<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Action\Property\PropertyAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PropertyActionAuthorizationTest extends TestCase
{
    #[DataProvider('mutations')]
    public function testEveryMutationAndExportRequiresSessionBeforeDependencies(string $method, ?string $channel): void
    {
        $action = (new \ReflectionClass(PropertyAction::class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/property')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        if ($channel !== null) $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, $channel);
        $response = $action->$method($request, new Response(), ['kind' => 'units', 'id' => 1, 'revisionId' => 1]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public static function mutations(): iterable
    {
        foreach (['saveProfile', 'create', 'revise', 'confirm', 'importPreview', 'importApply', 'export'] as $method) {
            yield $method . ' bearer' => [$method, 'bearer'];
            yield $method . ' anonymous' => [$method, null];
        }
    }

    public function testManageDoesNotGrantConfirmationOrExportAndReadDoesNotGrantWrites(): void
    {
        $action = (new \ReflectionClass(PropertyAction::class))->newInstanceWithoutConstructor();
        $base = (new ServerRequestFactory())->createServerRequest('POST', '/api/property')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic manager', 'staff', true, ['property' => 1, 'property.registry.manage' => 2]));
        foreach (['confirm', 'saveProfile', 'export'] as $method) self::assertSame(403, $action->$method($base, new Response(), ['id' => 1, 'revisionId' => 1])->getStatusCode());
        foreach (['create', 'importApply'] as $method) {
            $body = $method === 'create' ? ['status' => 'confirmed'] : ['plan' => ['status' => 'confirmed']];
            self::assertSame(403, $action->$method($base->withParsedBody($body), new Response(), ['kind' => 'units'])->getStatusCode());
        }
        $readonly = $base->withAttribute('auth.effective_role', new EffectiveRole(2, 'Synthetic reader', 'staff', true, ['property' => 1]));
        self::assertSame(403, $action->create($readonly, new Response(), ['kind' => 'units'])->getStatusCode());
        $allowedRead = $action->summary($readonly->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response());
        self::assertSame(422, $allowedRead->getStatusCode(), 'Authorized read reaches explicit as_of validation.');
        self::assertSame('property_invalid_instant', json_decode((string) $allowedRead->getBody(), true)['error']['code']);
        self::assertSame(403, $action->summary($readonly->withAttribute('auth.effective_role', EffectiveRole::denied()), new Response())->getStatusCode());
    }
}
