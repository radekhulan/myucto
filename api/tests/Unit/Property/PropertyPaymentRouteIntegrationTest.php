<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Action\Property\Payments\PaymentOperationsAction;
use MyInvoice\Action\Property\Payments\PaymentWorkflowAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Routes;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

final class PropertyPaymentRouteIntegrationTest extends TestCase
{
    public function testEveryPaymentRouteMatchesSlimHandlerAndPermission(): void
    {
        $app = AppFactory::create();
        Routes::register($app);
        $map = new RoutePermissionMap();

        foreach (self::routes() as [$method, $path, $handler, $permission, $level]) {
            $result = $app->getRouteResolver()->computeRoutingResults($path, $method);
            self::assertSame(RoutingResults::FOUND, $result->getRouteStatus(), "$method $path must match Slim");
            $route = $app->getRouteResolver()->resolveRoute((string) $result->getRouteIdentifier());
            self::assertSame($handler, self::handlerName($route->getCallable()), "$method $path handler");
            $policy = $map->match($method, $path);
            self::assertNotNull($policy, "$method $path policy");
            self::assertSame($permission, $policy->key, "$method $path permission");
            self::assertSame($level, $policy->minimum, "$method $path level");
        }
    }

    public static function routes(): iterable
    {
        $ops = PaymentOperationsAction::class;
        $flow = PaymentWorkflowAction::class;
        $bank = \MyInvoice\Action\Property\Payments\PaymentBankProposalAction::class;
        foreach ([
            ['GET', '/api/property/payments/debts', 'page', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/debts/7', 'detail', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/parties', 'parties', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/correction-options', 'correctionOptions', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/card', 'card', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/bank-accounts', 'bankAccounts', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/accessory-candidates', 'accessoryCandidates', 'property', AccessLevel::READ],
            ['POST', '/api/property/payments/debts/7/accessories', 'bindAccessory', 'property.payments.manage', AccessLevel::WRITE],
            ['GET', '/api/property/payments/reservations/7/abo-export', 'exportMetadata', 'property', AccessLevel::READ],
            ['POST', '/api/property/payments/reservations/abo/9/download', 'exportDownload', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/reservations/abo/preview', 'exportPreview', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/reservations/abo/confirm', 'exportConfirm', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/reservations/7/cancel-submission', 'cancelExport', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/debts/register', 'registerDebt', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/credits/7/reserve', 'reserve', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/reservations/7/cancel', 'cancelReservation', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/setoffs/preview', 'setoffPreview', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/setoffs/confirm', 'setoffConfirm', 'property.payments.post', AccessLevel::WRITE],
            ['POST', '/api/property/payments/corrections/preview', 'correctionPreview', 'property.payments.manage', AccessLevel::WRITE],
            ['POST', '/api/property/payments/corrections/confirm', 'correctionConfirm', 'property.payments.post', AccessLevel::WRITE],
            ['POST', '/api/property/payments/operations/7/reverse', 'reverse', 'property.payments.reverse', AccessLevel::WRITE],
            ['POST', '/api/property/payments/corrections/7/reverse', 'correctionReverse', 'property.payments.reverse', AccessLevel::WRITE],
            ['GET', '/api/property/payments/source', 'source', 'property', AccessLevel::READ, $flow],
            ['POST', '/api/property/payments/source-revisions/preview', 'revisionPreview', 'property.payments.manage', AccessLevel::WRITE, $flow],
            ['POST', '/api/property/payments/source-revisions/confirm', 'revisionConfirm', 'property.payments.post', AccessLevel::WRITE, $flow],
            ['GET', '/api/property/payments/context', 'context', 'property', AccessLevel::READ, $flow],
            ['GET', '/api/property/payments/candidates', 'candidates', 'property', AccessLevel::READ],
            ['GET', '/api/property/payments/history', 'history', 'property', AccessLevel::READ, $flow],
            ['POST', '/api/property/payments/preview', 'preview', 'property.payments.manage', AccessLevel::WRITE, $flow],
            ['POST', '/api/property/payments/confirm', 'confirm', 'property.payments.post', AccessLevel::WRITE, $flow],
            ['POST', '/api/property/payments/reservations/7/release-unexecuted', 'releaseUnexecuted', 'property.payments.manage', AccessLevel::WRITE],
            ['GET', '/api/property/payments/bank-proposals', 'queue', 'property', AccessLevel::READ, $bank],
            ['GET', '/api/property/payments/bank-proposals/7', 'detail', 'property', AccessLevel::READ, $bank],
            ['POST', '/api/property/payments/bank-proposals/7/plan', 'plan', 'property.payments.manage', AccessLevel::WRITE, $bank],
            ['GET', '/api/property/payments/bank-links', 'links', 'property', AccessLevel::READ, $bank],
         ] as $contract) {
            [$method, $path, $action, $permission, $level] = $contract;
            $class = $contract[5] ?? $ops;
            yield [$method, $path, $class . '::' . $action, $permission, $level];
        }
    }

    #[DataProvider('writers')]
    public function testEveryWriterRequiresSessionBeforeBodyOrDependencies(string $class, string $method, bool $hasArgs): void
    {
        $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/property/payments')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        $response = $hasArgs
            ? $action->$method($request, new Response(), ['id' => '7'])
            : $action->$method($request, new Response());
        self::assertSame(403, $response->getStatusCode(), $method);
        self::assertSame('session_required', json_decode((string) $response->getBody(), true)['error']['code'], $method);
    }

    #[DataProvider('writers')]
    public function testEveryWriterChecksItsOwnPermissionBeforeBodyOrDependencies(string $class, string $method, bool $hasArgs): void
    {
        $required = match ($method) {
            'reverse', 'correctionReverse' => 'property.payments.reverse',
            'revisionConfirm', 'confirm', 'setoffConfirm', 'correctionConfirm' => 'property.payments.post',
            default => 'property.payments.manage',
        };
        $permissions = ['property' => 1, 'property.payments.manage' => 2, 'property.payments.post' => 2, 'property.payments.reverse' => 2];
        unset($permissions[$required]);
        $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/property/payments')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic restricted accountant', 'staff', true, $permissions));
        $response = $hasArgs
            ? $action->$method($request, new Response(), ['id' => '7'])
            : $action->$method($request, new Response());
        self::assertSame(403, $response->getStatusCode(), $method);
        self::assertSame('forbidden', json_decode((string) $response->getBody(), true)['error']['code'], $method);
    }

    public function testExportMetadataRejectsMissingReadPermissionBeforeDependencies(): void
    {
        $action = (new \ReflectionClass(PaymentOperationsAction::class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/property/payments/reservations/7/abo-export')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic no property read', 'staff', true, ['property.payments.manage' => 2]));
        $response = $action->exportMetadata($request, new Response(), ['id' => '7']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public static function writers(): iterable
    {
        foreach ([
            [PaymentOperationsAction::class, 'bindAccessory', true],
            [PaymentOperationsAction::class, 'exportPreview', false],
            [PaymentOperationsAction::class, 'exportConfirm', false],
            [PaymentOperationsAction::class, 'exportDownload', true],
            [PaymentOperationsAction::class, 'cancelExport', true],
            [PaymentOperationsAction::class, 'registerDebt', false],
            [PaymentOperationsAction::class, 'reserve', true],
            [PaymentOperationsAction::class, 'cancelReservation', true],
            [PaymentOperationsAction::class, 'setoffPreview', false],
            [PaymentOperationsAction::class, 'setoffConfirm', false],
            [PaymentOperationsAction::class, 'correctionPreview', false],
            [PaymentOperationsAction::class, 'correctionConfirm', false],
            [PaymentOperationsAction::class, 'reverse', true],
            [PaymentOperationsAction::class, 'correctionReverse', true],
            [PaymentWorkflowAction::class, 'revisionPreview', false],
            [PaymentWorkflowAction::class, 'revisionConfirm', false],
            [PaymentWorkflowAction::class, 'preview', false],
            [PaymentWorkflowAction::class, 'confirm', false],
            [PaymentOperationsAction::class, 'releaseUnexecuted', true],
            [\MyInvoice\Action\Property\Payments\PaymentBankProposalAction::class, 'plan', true],
        ] as [$class, $method, $hasArgs]) {
            yield $class . '::' . $method => [$class, $method, $hasArgs];
        }
    }

    private static function handlerName(mixed $handler): string
    {
        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            return $handler[0] . '::' . $handler[1];
        }
        return is_string($handler) ? $handler . '::__invoke' : get_debug_type($handler);
    }
}
