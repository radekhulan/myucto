<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Anonymization\Fx504CloneScrubber;
use PHPUnit\Framework\TestCase;

final class Fx504BoundaryTest extends TestCase
{
    public function testPreviewReadPermissionDoesNotAuthorizePosting(): void
    {
        $map = new RoutePermissionMap();
        $preview = $map->match('POST', '/api/accounting/periods/17/closing/fx-preview');
        self::assertSame('accounting', $preview->key);
        self::assertSame(AccessLevel::READ, $preview->minimum);
        foreach (['run', 'revert'] as $operation) {
            $write = $map->match('POST', '/api/accounting/periods/17/closing/steps/fx_revaluation/' . $operation);
            self::assertSame('accounting.periods.close', $write->key);
            self::assertSame(AccessLevel::WRITE, $write->minimum);
        }
    }

    public function testReadonlySessionNeverReachesFxMutationHandler(): void
    {
        $role = new \MyInvoice\Security\EffectiveRole(8, 'Synthetic reader', 'staff', true, ['accounting' => 1, 'accounting.periods.close' => 1]);
        $roles = $this->createStub(\MyInvoice\Security\PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $roles->method('resolveDefault')->willReturn($role);
        $suppliers = $this->createStub(\MyInvoice\Service\Tenant\SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new \MyInvoice\Service\Tenant\SupplierAccess(1, false, null));
        $middleware = new \MyInvoice\Middleware\PermissionMiddleware(new \Slim\Psr7\Factory\ResponseFactory(), new RoutePermissionMap(), $roles,
            new \MyInvoice\Security\PermissionChecker(new \MyInvoice\Security\PermissionCatalog()), $suppliers);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public int $calls = 0;
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $this->calls++;
                return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(204);
            }
        };
        foreach (['run', 'revert'] as $operation) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/periods/17/closing/steps/fx_revaluation/' . $operation)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 8]);
            self::assertSame(403, $middleware->process($request, $handler)->getStatusCode());
        }
        self::assertSame(0, $handler->calls);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/periods/17/closing/fx-preview')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 8]);
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testAnonymizationRemovesFxEvidenceAndAuthorityPreservingOtherOwners(): void
    {
        $other = ['legal_pending' => ['status' => 'written'], 'asset_source_revisions' => [['id' => 7]], 'carry' => ['source_hash' => 'preserved'], 'assessment' => ['confirmed' => true]];
        $payload = $other + ['fx504' => ['snapshot_hash' => 'old-authority', 'case_decisions' => [['reason' => 'Synthetic private reason', 'origin' => ['reference' => 'Synthetic private deed']]]],
            'preview' => ['case_source_cells' => [['reference' => 'Synthetic private deed']]]];
        $scrubbed = json_decode(Fx504CloneScrubber::payload(json_encode($payload, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($other + ['fx504_anonymized' => true], $scrubbed);
        self::assertStringNotContainsString('Synthetic private', json_encode($scrubbed, JSON_THROW_ON_ERROR));
        $ordinary = json_encode($other + ['preview' => ['source' => 'asset']], JSON_THROW_ON_ERROR);
        self::assertSame($ordinary, Fx504CloneScrubber::payload($ordinary));
    }
}
