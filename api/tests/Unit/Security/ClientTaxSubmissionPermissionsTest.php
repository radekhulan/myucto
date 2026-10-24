<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Middleware\{AuthMiddleware, PermissionMiddleware};
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{PermissionNotAllowedForRoleType, RoleRepository};
use MyInvoice\Security\{AccessLevel, ClientTaxSubmissionPolicy, EffectiveRole, PermissionCatalog, PermissionChecker, PermissionResolver, RequestAuthorization, RoutePermissionMap};
use MyInvoice\Service\Tenant\{ClientRoutePolicy, SupplierAccess, SupplierAccessResolver};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\{ResponseFactory, ServerRequestFactory};

final class ClientTaxSubmissionPermissionsTest extends TestCase
{
    public function testClientArchiveAndSubmissionAreOptInAndManagementRemainsStaffOnly(): void
    {
        $catalog = new PermissionCatalog();
        self::assertTrue($catalog->allowsRoleType('reports.submissions', 'client'));
        self::assertFalse($catalog->allowsRoleType('reports.submissions', 'staff'));
        self::assertTrue($catalog->allowsRoleType('reports.submit', 'client'));
        self::assertTrue($catalog->allowsRoleType('reports.submit', 'staff'));
        self::assertFalse($catalog->allowsRoleType('reports.submissions.manage', 'client'));
        self::assertTrue($catalog->allowsRoleType('reports.submissions.manage', 'staff'));
        foreach (['reports.submissions', 'reports.submit', 'reports.submissions.manage'] as $key) {
            self::assertArrayNotHasKey($key, $catalog->legacyPreset('client'));
        }
    }

    public function testClientSubmitRequiresArchiveReadAndCannotGrantManagement(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        foreach ([0, 1, 2] as $read) {
            $role = $this->role(['reports.submissions' => $read, 'reports.submit' => 2, 'reports.submissions.manage' => 2]);
            self::assertSame($read > 0, $checker->allows($role, 'reports.submit', AccessLevel::WRITE));
            self::assertSame($read > 0, RequestAuthorization::allows($this->request($role), 'reports.submit', AccessLevel::WRITE));
            self::assertFalse(RequestAuthorization::canManageTaxSubmissions($this->request($role)));
        }
        $inactive = new EffectiveRole(4, 'Neaktivní klient', 'client', false, ['reports.submissions' => 1, 'reports.submit' => 2]);
        self::assertFalse(RequestAuthorization::canReadTaxSubmissions($this->request($inactive)));
    }

    public function testSavingClientRoleNormalizesArchiveReadAndAddsSubmitDependency(): void
    {
        $repository = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $validate = new \ReflectionMethod($repository, 'validatePermissions');
        self::assertSame(['reports.submissions' => 1], $validate->invoke($repository, 'client', ['reports.submissions' => 2]));
        $normalized = $validate->invoke($repository, 'client', ['reports.submit' => 2]);
        self::assertSame(1, $normalized['reports.submissions']);
        self::assertSame(2, $normalized['reports.submit']);
        self::assertSame([], $validate->invoke($repository, 'client', ['reports.submissions' => 0, 'reports.submit' => 0]));
        $this->expectException(PermissionNotAllowedForRoleType::class);
        $validate->invoke($repository, 'client', ['reports.submissions.manage' => 2]);
    }

    public function testStaffReadExportAndLegacySubmitKeepTheirSeparateMeanings(): void
    {
        $reader = $this->request(new EffectiveRole(5, 'Čtenář', 'staff', true, ['reports' => 1]));
        self::assertTrue(RequestAuthorization::canReadTaxSubmissions($reader));
        self::assertFalse(RequestAuthorization::canExportTaxSubmission($reader));
        self::assertFalse(RequestAuthorization::canManageTaxSubmissions($reader));
        $sender = $this->request(new EffectiveRole(5, 'Podatel', 'staff', true, ['reports.submit' => 2]));
        self::assertTrue(RequestAuthorization::canManageTaxSubmissions($sender));
        $manager = $this->request(new EffectiveRole(5, 'Správce podání', 'staff', true, ['reports.submissions.manage' => 2]));
        self::assertTrue(RequestAuthorization::canManageTaxSubmissions($manager));
        $exporter = $this->request(new EffectiveRole(5, 'Export', 'staff', true, ['reports.export' => 1]));
        self::assertTrue(RequestAuthorization::canExportTaxSubmission($exporter));
    }

    public function testClientCanReadArchivedXmlWithoutGeneralReportExport(): void
    {
        $request = $this->request($this->role(['reports.submissions' => 1]));
        self::assertTrue(RequestAuthorization::canReadTaxSubmissions($request));
        self::assertTrue(RequestAuthorization::canExportTaxSubmission($request));
        self::assertFalse(RequestAuthorization::allows($request, 'reports.export'));
        self::assertFalse(RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE));
    }

    public function testFormWhitelistRejectsIncomePayrollOssAndUnknownCodes(): void
    {
        $request = $this->request($this->role(['reports.submissions' => 1]));
        foreach (['dphdp3', 'dphkh1', 'dphshv'] as $form) {
            self::assertTrue(ClientTaxSubmissionPolicy::allowsForm($form));
            self::assertTrue(RequestAuthorization::canAccessTaxSubmission($request, ['form_code' => $form]));
        }
        foreach (['dpzvd6', 'dpsvd2', 'dppdp9', 'dpfdp7', 'dpfdp5', 'ossei1', 'jmhz', '', 'dphdp3/../dpzvd6'] as $form) {
            self::assertFalse(ClientTaxSubmissionPolicy::allowsForm($form), $form);
            self::assertFalse(RequestAuthorization::canAccessTaxSubmission($request, ['form_code' => $form]), $form);
        }
        self::assertFalse(RequestAuthorization::canAccessTaxSubmission($request, []));
    }

    public function testClientCapabilitiesRequireAuthenticatedBrowserSession(): void
    {
        $role = $this->role(['reports.submissions' => 1, 'reports.submit' => 2]);
        foreach (['bearer', null, ''] as $method) {
            $request = $this->request($role)->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
            self::assertFalse(RequestAuthorization::canReadTaxSubmissions($request));
            self::assertFalse(RequestAuthorization::canExportTaxSubmission($request));
            self::assertFalse(RequestAuthorization::canAccessTaxSubmission($request, ['form_code' => 'dphdp3']));
            self::assertFalse(RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE));
            self::assertSame(403, $this->middleware($role)->process($request, $this->handler())->getStatusCode());
        }
    }

    public function testNormalizedPathsCannotBypassClientBoundary(): void
    {
        $role = $this->role(['reports.submissions' => 1, 'reports.submit' => 2, 'reports.submissions.manage' => 2]);
        foreach ([
            ['HEAD', '/api/reports/submissions/17/xml', 204],
            ['GET', '/api/reports/%73ubmissions/17/xml', 204],
            ['PUT', '/api/reports/submissions/%73ettings', 403],
            ['POST', '/api/reports/submissions/17/epo-attempts/23/%72esolve-not-submitted', 403],
        ] as [$method, $path, $status]) {
            self::assertSame($status, $this->middleware($role)->process($this->request($role, $method, $path), $this->handler())->getStatusCode(), "$method $path");
        }
    }

    public static function routes(): iterable
    {
        foreach ([
            ['GET', '', true], ['GET', '/17', true], ['GET', '/17/xml', true], ['GET', '/17/payment', true],
            ['GET', '/17/artifacts/23/download', true], ['GET', '/settings', true], ['GET', '/isds-routes', true],
            ['GET', '/epo-credentials', true], ['POST', '/epo-credentials', true],
            ['PUT', '/epo-credentials/23/supplier', true], ['DELETE', '/epo-credentials/23', false],
            ['POST', '/17/epo-handoff', true], ['POST', '/17/epo-test', true], ['POST', '/17/epo-submit', true],
            ['POST', '/17/epo-status', true], ['POST', '/17/epo-attempts/23/confirmation', true],
            ['POST', '/17/epo-attempts/23/state-password', true],
            ['POST', '/17/artifacts', true], ['POST', '/17/submit', true], ['POST', '/17/isds', true],
            ['POST', '/17/isds-cancel', true], ['POST', '/17/isds-receipt', true],
            ['POST', '/isds-gateway/outbox/23', true], ['POST', '/isds-gateway/callback', true],
            ['PUT', '/settings', false], ['PUT', '/isds-routes', false], ['DELETE', '/17', false],
            ['POST', '/17/epo-attempts/23/resolve-not-submitted', false], ['GET', '/17/isds-inbox', false],
            ['POST', '/17/isds-inbox/23', false], ['GET', '/17/kh-evidence', false], ['GET', '/17/kh-evidence/export', false],
            ['POST', '/17/unknown-action', false], ['GET', '/17/xml/extra', false], ['POST', '', false],
        ] as [$method, $suffix, $allowed]) {
            yield "$method $suffix" => [$method, '/api/reports/submissions' . $suffix, $allowed];
        }
    }

    #[DataProvider('routes')]
    public function testExactClientRoutesOnCanonicalAndClientHosts(string $method, string $path, bool $allowed): void
    {
        self::assertSame($allowed, ClientTaxSubmissionPolicy::allowsRoute($method, $path));
        self::assertSame($allowed, (new ClientRoutePolicy())->allowsApiRequest($method, $path));
        $role = $this->role(['reports' => 2, 'reports.export' => 2, 'reports.submissions' => 1, 'reports.submit' => 2, 'reports.submissions.manage' => 2]);
        self::assertSame($allowed ? 204 : 403, $this->middleware($role)->process($this->request($role, $method, $path), $this->handler())->getStatusCode());
    }

    public function testReadOnlyClientCannotSendAndRevokedClientCannotRead(): void
    {
        foreach ([['reports.submissions' => 1], ['reports.submit' => 2], []] as $permissions) {
            $role = $this->role($permissions);
            $middleware = $this->middleware($role);
            self::assertSame(isset($permissions['reports.submissions']) ? 204 : 403, $middleware->process($this->request($role), $this->handler())->getStatusCode());
            self::assertSame(403, $middleware->process($this->request($role, 'POST', '/api/reports/submissions/17/epo-submit'), $this->handler())->getStatusCode());
        }
    }

    private function role(array $permissions): EffectiveRole
    {
        return new EffectiveRole(4, 'Delegovaný klient', 'client', true, $permissions);
    }

    private function request(EffectiveRole $role, string $method = 'GET', string $path = '/api/reports/submissions'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 4])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role);
    }

    private function middleware(EffectiveRole $role): PermissionMiddleware
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new SupplierAccess(7, false, null));
        return new PermissionMiddleware(new ResponseFactory(), new RoutePermissionMap(), $roles, new PermissionChecker(new PermissionCatalog()), $suppliers);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
