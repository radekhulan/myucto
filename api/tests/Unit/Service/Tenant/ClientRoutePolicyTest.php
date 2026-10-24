<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientRoutePolicyTest extends TestCase
{
    private ClientRoutePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ClientRoutePolicy();
    }

    public function testManifestCoversAuditedCoreAndLegacyAliasMatrixWithoutDuplicates(): void
    {
        $routes = $this->policy->routes();
        $names = array_column($routes, 'name');
        $patterns = array_column($routes, 'path_pattern');

        self::assertCount(59, $routes);
        self::assertCount(59, array_unique($names));
        self::assertCount(59, array_unique($patterns));
        self::assertContains('data-exchange', $names);
        self::assertContains('admin-export', $names);
        self::assertContains('admin-import', $names);
    }

    public function testEveryManifestPermissionIsLegitimateForClientRoleType(): void
    {
        $catalog = new PermissionCatalog();
        foreach ($this->policy->routes() as $route) {
            foreach ($route['permission_any'] ?? (isset($route['permission']) ? [$route['permission']] : []) as $permission) {
                self::assertTrue(
                    $catalog->allowsRoleType((string) $permission, 'client'),
                    sprintf('%s používá permission mimo client role type.', $route['name'] ?? '?'),
                );
            }
        }
    }

    #[DataProvider('allowedWebPaths')]
    public function testAllowsEveryClientRouteFamilyAndRealRedirectAliases(string $path): void
    {
        self::assertTrue($this->policy->allowsAuthenticatedPath($path), $path);
    }

    /** @return iterable<string,array{string}> */
    public static function allowedWebPaths(): iterable
    {
        foreach ([
            '/',
            '/portal',
            '/projects', '/projects/new', '/projects/42', '/projects/42/edit', '/logbook',
            '/stock/items', '/stock/items/new', '/stock/items/42', '/stock/items/42/edit', '/stock/fulfillment',
            '/bank',
            '/bank/42',
            '/reports/submissions',
            '/portal/document-requests',
            '/portal/purchase-invoice-submissions',
            '/portal/settings',
            '/clients',
            '/clients/new',
            '/clients/42',
            '/clients/42/edit',
            '/invoices',
            '/invoices/ai-import',
            '/invoices/new',
            '/invoices/42',
            '/invoices/42/edit',
            '/invoices/export',
            '/invoices/import',
            '/purchase-invoices',
            '/purchase-invoices/export',
            '/purchase-invoices/import',
            '/purchase-invoices/ai-import',
            '/purchase-invoices/new',
            '/purchase-invoices/42',
            '/purchase-invoices/42/edit',
            '/recurring',
            '/recurring/new',
            '/recurring/42',
            '/recurring/42/edit',
            '/profile/totp',
            '/profile/password',
            '/profile/shortcuts',
            '/profile/passkeys',
            '/profile/session-lock',
            '/setup-mfa',
            '/setup-totp',
            '/exchange',
            '/admin/export',
            '/admin/import',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('deniedWebPaths')]
    public function testRejectsRoutesOutsideClientRoleSurface(string $path): void
    {
        self::assertFalse($this->policy->allowsAuthenticatedPath($path), $path);
    }

    /** @return iterable<string,array{string}> */
    public static function deniedWebPaths(): iterable
    {
        foreach ([
            '/projects/private',
            '/purchase-invoices/incoming',
            '/purchase-invoices/payment-orders',
            '/documents',
            '/bank/42/edit',
            '/accounting/journal',
            '/admin/settings',
            '/profile/api-tokens',
            '/profile/mcp-server',
            '/profile/signing-profiles',
            '/login',
            '/forgot',
            '/reset',
            '/invoice/public-token',
            '/x%2f..%2fportal',
            '/manual',
        ] as $path) {
            yield $path => [$path];
        }
    }

    public function testReturnPathValidationUsesSameAuthenticatedSurface(): void
    {
        foreach ([
            '/clients/42?role=customer#history',
            '/invoices/new?type=proforma',
            '/purchase-invoices/42/edit',
            '/recurring/42',
            '/profile/password?tab=passkeys',
            '/exchange?tab=export-issued',
            '/admin/export',
            '/admin/import?tab=purchase',
        ] as $path) {
            self::assertTrue($this->policy->allowsReturnPath($path), $path);
        }

        foreach ([
            '//attacker.example',
            '/\\attacker.example',
            '/clients/../admin/settings',
            '/projects/private',
            '/login',
            '/invoice/public-token',
            '/x%252f..%252fportal',
        ] as $path) {
            self::assertFalse($this->policy->allowsReturnPath($path), $path);
        }
    }

    public function testCanonicalHandoffIsLimitedToManifestedWebAuthnDestinations(): void
    {
        self::assertSame(
            '/profile/password?tab=passkeys',
            $this->policy->canonicalHandoffPath('/profile/passkeys'),
        );
        self::assertSame(
            '/profile/password?tab=passkeys',
            $this->policy->canonicalHandoffPath('/profile/password?tab=passkeys'),
        );
        self::assertSame(
            '/profile/password?tab=totp',
            $this->policy->canonicalHandoffPath('/profile/password?tab=totp'),
        );
        self::assertSame('/setup-mfa', $this->policy->canonicalHandoffPath('/setup-mfa'));
        self::assertSame(
            '/setup-mfa?method=totp',
            $this->policy->canonicalHandoffPath('/setup-mfa?method=totp'),
        );
        self::assertSame('/setup-mfa?method=totp', $this->policy->canonicalHandoffPath('/setup-totp'));

        foreach ([
            '/profile/password',
            '/profile/password?tab=passkeys&tab=totp',
            '/profile/password?tab=totp&tab=totp',
            '/profile/password?tab[]=totp',
            '/portal',
            '/admin/settings',
            '//attacker.example/profile/passkeys',
        ] as $path) {
            self::assertNull($this->policy->canonicalHandoffPath($path), $path);
        }
    }

    public function testApiPolicyIsDerivedFromClientRoleTypeAndKeepsPublicRoutesSeparate(): void
    {
        foreach ([
            ['GET', '/api/projects'],
            ['POST', '/api/settings/email-profiles'],
            ['GET', '/api/settings/email-templates'],
            ['POST', '/api/settings/email-branding/logo'],
            ['GET', '/api/auth/setup-status'],
            ['GET', '/api/auth/domain-context'],
            ['POST', '/api/auth/domain-login/start'],
            ['POST', '/api/auth/domain-login/exchange'],
            ['GET', '/api/version'],
            ['GET', '/api/auth/me'],
            ['POST', '/api/auth/change-password'],
            ['GET', '/api/portal/summary'],
            ['POST', '/api/portal/document-requests/7/upload'],
            ['GET', '/api/clients/7'],
            ['POST', '/api/clients'],
            ['POST', '/api/invoices/7/issue'],
            ['POST', '/api/purchase-invoices/7/transition'],
            ['POST', '/api/recurring/7/run'],
            ['GET', '/api/bank-statements'],
            ['POST', '/api/bank-statements/upload'],
            ['POST', '/api/bank-transactions/7/unignore'],
            ['GET', '/api/settings'],
            ['GET', '/api/settings/client/signing/profiles'],
            ['POST', '/api/settings/client/signing/profiles/7/credentials/certificate'],
            ['PUT', '/api/settings/client/pdf-signing/output-settings'],
            ['GET', '/api/settings/client/email-profiles'],
            ['POST', '/api/settings/client/email-profiles'],
            ['GET', '/api/settings/client/email-templates'],
            ['GET', '/api/settings/client/email-templates/invoice_send/cs'],
            ['PUT', '/api/settings/client/email-templates/invoice_send/en'],
            ['DELETE', '/api/settings/client/email-templates/invoice_send/cs'],
            ['POST', '/api/settings/client/email-templates/invoice_send/cs/preview'],
            ['PUT', '/api/settings/client/branding'],
            ['POST', '/api/settings/client/branding/profiles/7/logo'],
            ['GET', '/api/settings/client/payment-qr'],
            ['PUT', '/api/settings/client/payment-qr'],
            ['PUT', '/api/user/preferences/navigation'],
        ] as [$method, $path]) {
            self::assertTrue($this->policy->allowsApiRequest($method, $path), "$method $path");
        }

        foreach ([
            ['POST', '/api/auth/login'],
            ['POST', '/api/auth/forgot'],
            ['POST', '/api/auth/domain-login/authorize'],
            ['POST', '/api/auth/webauthn/register/options'],
            ['POST', '/api/auth/webauthn/step-up/options'],
            ['DELETE', '/api/auth/webauthn/credentials/7'],
            ['GET', '/api/health'],
            ['GET', '/api/public/invoice/token'],
            ['GET', '/api/purchase-invoices/payment-orders'],
            ['POST', '/api/purchase-invoices/scan-inbox'],
            ['POST', '/api/bank-statements/scan'],
            ['GET', '/api/admin/users'],
            ['GET', '/api/admin/export'],
            ['POST', '/api/admin/import'],
            ['GET', '/api/auth/tokens'],
            ['POST', '/api/settings'],
            ['PUT', '/api/settings/supplier'],
            ['PUT', '/api/admin/email-templates/invoice_send/cs'],
        ] as [$method, $path]) {
            self::assertFalse($this->policy->allowsApiRequest($method, $path), "$method $path");
        }
    }
}
