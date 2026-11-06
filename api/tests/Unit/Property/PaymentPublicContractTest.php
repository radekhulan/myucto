<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Action\Property\Payments\PaymentPublicAction;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Routes;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Property\Payments\PaymentPublicProjection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

final class PaymentPublicContractTest extends TestCase
{
    public function testActualRoutesAndTokenPermissionsExposeOnlyReads(): void
    {
        $app = AppFactory::create(); Routes::register($app);
        $middleware = new ApiScopeMiddleware(new \Slim\Psr7\Factory\ResponseFactory()); $map = new RoutePermissionMap();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return new Response(204); }
        };
        foreach (['card' => 'card', 'operations' => 'operations', 'history' => 'history', 'reconciliation' => 'reconciliation',
            'reconciliation/parties' => 'partyReconciliation'] as $suffix => $method) {
            $path = '/api/property/catalog/payments/' . $suffix;
            $result = $app->getRouteResolver()->computeRoutingResults($path, 'GET');
            self::assertSame(RoutingResults::FOUND, $result->getRouteStatus(), $path);
            $route = $app->getRouteResolver()->resolveRoute((string) $result->getRouteIdentifier());
            self::assertSame([PaymentPublicAction::class, $method], $route->getCallable());
            $policy = $map->match('GET', $path);
            self::assertSame('property', $policy->key); self::assertSame(AccessLevel::READ, $policy->minimum);
            self::assertSame(204, $middleware->process($this->request('GET', $path), $handler)->getStatusCode());
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $write) self::assertSame(403, $middleware->process($this->request($write, $path, 'read_write'), $handler)->getStatusCode());
        }
        foreach (['operations/7', 'reservations/abo/7/download', 'source', 'preview', 'confirm', 'context'] as $suffix) {
            foreach (['/api/property/payments/', '/api/property/catalog/payments/'] as $prefix) {
                foreach (['GET', 'POST'] as $method) self::assertSame(403, $middleware->process($this->request($method, $prefix . $suffix, 'read_write'), $handler)->getStatusCode());
            }
        }
    }

    public function testEachActionDeniesMissingPropertyPermissionBeforeReadingDependencies(): void
    {
        $action = (new \ReflectionClass(PaymentPublicAction::class))->newInstanceWithoutConstructor();
        $request = $this->request('GET', '/api/property/catalog/payments/card')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic denied', 'staff', true, []));
        foreach (['card', 'operations', 'history', 'reconciliation', 'partyReconciliation'] as $method) {
            self::assertSame(403, $action->$method($request, new Response())->getStatusCode());
        }
    }

    public function testInvalidScopeAndPagingNeverFallbackToWholeCompanyOrClamp(): void
    {
        $action = (new \ReflectionClass(PaymentPublicAction::class))->newInstanceWithoutConstructor();
        $request = $this->request('GET', '/api/property/catalog/payments/card')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic reader', 'staff', true, ['property' => 1]))
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 1);
        foreach ([['card', []], ['card', ['unit_id' => 0]], ['card', ['unit_id' => []]],
            ['operations', ['limit' => 101]], ['operations', ['after_id' => -1]], ['operations', ['unit_id' => 'bad']],
            ['operations', ['before_id' => 42]], ['card', ['client_id' => 1]],
            ['history', ['after_id' => 42]], ['history', ['limit' => 0]], ['history', ['before_id' => -1]], ['history', ['limit' => null]],
            ['partyReconciliation', ['date' => '2027-01-01', 'limit' => 101]], ['partyReconciliation', ['date' => '2027-01-01', 'limit' => 0]],
            ['partyReconciliation', ['date' => '2027-01-01', 'unit_id' => 1]], ['partyReconciliation', []]] as [$method, $query]) {
            $response = $action->$method($request->withQueryParams($query), new Response());
            self::assertSame(422, $response->getStatusCode(), $method . json_encode($query));
        }
    }

    public function testCuratedProjectionRetainsUncertaintyAndLegalRolesWithoutPrivateEvidence(): void
    {
        $private = 'Synthetic private legal evidence';
        $group = ['group_key' => 'shared', 'role' => 'guarantor', 'mode' => 'solidary', 'basis' => 'documented_title',
            'members' => [['id' => 3, 'label' => 'Synthetic A'], ['id' => 4, 'label' => 'Synthetic B']],
            'extent' => ['num' => '1', 'den' => '1'], 'valid_from' => '2026-01-01T00:00:00Z', 'valid_to' => null,
            'remaining_cap_cents' => null, 'evidence_ref' => $private, 'legal_reason' => $private];
        $claim = ['claim_key' => 'debt:7', 'amount_cents' => null, 'person_liability_cents' => null, 'complete' => false,
            'groups' => [$group], 'events' => [['private' => $private]], 'source_hash' => str_repeat('a', 64),
            'liability' => ['event_id' => 2, 'event_hash' => str_repeat('b', 64), 'head_event_id' => 3,
                'complete' => false, 'review_required' => true, 'precision' => 'day', 'remaining_cents' => null, 'groups' => [$group]]];
        $card = ['claims' => [$claim], 'credits' => [['gross_credit_cents' => null, 'events' => [$private], 'uncertain_event_ids' => ['claim:7']]],
            'totals' => ['gross_receivable_cents' => null, 'gross_credit_cents' => null], 'precision' => 'day',
            'coverage' => ['financial_complete' => false, 'complete' => false, 'unresolved_negative_charge_line_ids' => [7]],
            'snapshot_hash' => str_repeat('c', 64), 'current_party_heads' => [$private]];
        $result = PaymentPublicProjection::card($card);
        self::assertSame($card['totals'], $result['totals']); self::assertSame($card['coverage'], $result['coverage']);
        self::assertSame($card['snapshot_hash'], $result['source_snapshot_hash']);
        self::assertSame('guarantor', $result['claims'][0]['groups'][0]['role']);
        self::assertNull($result['claims'][0]['groups'][0]['remaining_cap_cents']);
        self::assertTrue($result['claims'][0]['liability']['review_required']);
        self::assertSame(['claim:7'], $result['credits'][0]['uncertain_event_ids']);
        self::assertStringNotContainsString($private, json_encode($result));
        $operation = PaymentPublicProjection::operation(['id' => 5, 'amount_cents' => '9007199254740993', 'reason' => 'Synthetic reason',
            'request' => [$private], 'allocations' => [['id' => 9, 'debt_id' => 7, 'amount_cents' => '9007199254740993', 'label' => $private]]]);
        self::assertSame('9007199254740993', $operation['allocations'][0]['amount_cents']);
        self::assertSame('Synthetic reason', $operation['reason']);
        self::assertStringNotContainsString($private, json_encode($operation));
    }

    private function request(string $method, string $path, string $scope = 'read'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope]);
    }
}
