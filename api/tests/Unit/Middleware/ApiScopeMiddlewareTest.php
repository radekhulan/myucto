<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiScopeMiddlewareTest extends TestCase
{
    public function testSessionRequestPassesThroughEvenOnAdminPath(): void
    {
        // Non-bearer (session) request — ApiScope ho neřeší vůbec.
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/users')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $r = $this->middleware()->process($req, $this->okHandler());
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerReadCanGetPublicResource(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('GET', '/api/clients', 'read'),
            $this->okHandler(),
        );
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerReadCanGetApiMe(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('GET', '/api/auth/api-me', 'read'),
            $this->okHandler(),
        );
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerBlockedFromAdminEndpoint(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('GET', '/api/admin/users', 'read'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('token_endpoint_forbidden', $this->errorCode($r));
    }

    public function testBearerReadWriteStillBlockedFromAdminEndpoint(): void
    {
        // Path allowlist se vyhodnocuje PŘED scope — i read_write token admina
        // se na /api/admin nedostane.
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/admin/users', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('token_endpoint_forbidden', $this->errorCode($r));
    }

    /**
     * Účetní a daňová vrstva je pro token jednosměrná. Bez tohohle testu by stačilo
     * přidat `/api/accounting` do allowlistu (kvůli čtení sestav) a token s právem
     * zápisu by tiše získal možnost zaúčtovat doklad nebo uzavřít období.
     */
    public function testBearerReadWriteCannotWriteToAccountingOrTaxLayer(): void
    {
        $writes = [
            ['POST', '/api/accounting/journal'],
            ['POST', '/api/accounting/journal/12/reverse'],
            ['POST', '/api/accounting/periods/3/status'],
            ['POST', '/api/invoices/12/book'],
            ['DELETE', '/api/invoices/12/book'],
            ['POST', '/api/invoices/12/rebuild-snapshots'],
            ['POST', '/api/bank-transactions/34/post'],
            ['POST', '/api/bank-transactions/34/unpost'],
            ['POST', '/api/reports/s74b/record'],
            ['POST', '/api/reports/s46/correction'],
            ['PUT',  '/api/tax/profile'],
            ['PUT',  '/api/tax-evidence/closing/2026'],
        ];

        foreach ($writes as [$method, $path]) {
            $r = $this->middleware()->process(
                $this->bearer($method, $path, 'read_write'),
                $this->okHandler(),
            );
            self::assertSame(403, $r->getStatusCode(), "$method $path musí být odmítnuto");
            self::assertSame('token_write_forbidden', $this->errorCode($r), "$method $path");
        }
    }

    public function testBearerCanReadAccountingAndTaxReports(): void
    {
        $reads = [
            '/api/accounting/periods',
            '/api/accounting/reports/trial-balance',
            '/api/accounting/reports/balance-sheet',
            '/api/accounting/journal',
            '/api/reports/dphdp3/preview',
            '/api/tax/analysis',
            '/api/tax-evidence/cash-journal',
        ];

        foreach ($reads as $path) {
            $r = $this->middleware()->process(
                $this->bearer('GET', $path, 'read'),
                $this->okHandler(),
            );
            self::assertSame(204, $r->getStatusCode(), "GET $path musí projít");
        }
    }

    public function testBearerCannotReachLegacyPayrollAccountingSurface(): void
    {
        foreach ([
            ['GET', '/api/accounting/payroll/employees'],
            ['POST', '/api/accounting/payroll/preview'],
            ['POST', '/api/accounting/payroll/post'],
            ['PUT', '/api/accounting/payroll/employees/7'],
            ['DELETE', '/api/accounting/payroll/employees/7'],
            ['GET', '/api/accounting/reports/payroll-sheet'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->bearer($method, $path, 'read_write'),
                $this->okHandler(),
            );

            self::assertSame(403, $response->getStatusCode(), "bearer {$method} {$path}");
            self::assertSame(
                'token_endpoint_forbidden',
                $this->errorCode($response),
                "bearer {$method} {$path}",
            );
        }

        $session = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/payroll/employees')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        self::assertSame(
            204,
            $this->middleware()->process($session, $this->okHandler())->getStatusCode(),
        );
    }

    public function testBearerCanReachStockAndEshopModules(): void
    {
        // MCP server nad nimi staví nástroje pro zboží a zásoby; případné vypnutí
        // modulu řeší až GuardsStockEnabled, ne scope middleware.
        foreach (['/api/stock/levels', '/api/eshop/products/1', '/api/price-list-items'] as $path) {
            $r = $this->middleware()->process(
                $this->bearer('GET', $path, 'read'),
                $this->okHandler(),
            );
            self::assertSame(204, $r->getStatusCode(), "GET $path musí projít");
        }
    }

    public function testBearerReadCannotWriteToEshop(): void
    {
        // E-shop zápis JE povolený, ale jen se scope read_write — kontrola,
        // že rozšíření allowlistu neobešlo běžné scope pravidlo.
        $r = $this->middleware()->process(
            $this->bearer('PUT', '/api/eshop/products/1/prices', 'read'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('insufficient_scope', $this->errorCode($r));

        $ok = $this->middleware()->process(
            $this->bearer('PUT', '/api/eshop/products/1/prices', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(204, $ok->getStatusCode());
    }

    public function testBearerBlockedFromTokenManagementAndSensitiveSettings(): void
    {
        foreach ([
            '/api/auth/tokens',
            '/api/auth/login',
            '/api/auth/webauthn/credentials',
            '/api/auth/webauthn/register/options',
            '/api/settings/signing',
            '/api/settings/signing/profiles/1/credentials/certificate',
            '/api/settings/bank-email-notices',
            '/api/settings/email-branding/preview',
        ] as $path) {
            $r = $this->middleware()->process(
                $this->bearer('GET', $path, 'read'),
                $this->okHandler(),
            );
            self::assertSame(403, $r->getStatusCode(), "bearer GET $path");
            self::assertSame('token_endpoint_forbidden', $this->errorCode($r), "bearer GET $path");
        }
    }

    public function testBearerReadWriteCanSetInvoiceCounter(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('PUT', '/api/settings/supplier/invoice-counter', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerReadCannotSetInvoiceCounter(): void
    {
        // Path je povolená, ale read scope nesmí PUT → insufficient_scope.
        $r = $this->middleware()->process(
            $this->bearer('PUT', '/api/settings/supplier/invoice-counter', 'read'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('insufficient_scope', $this->errorCode($r));
    }

    public function testBearerReadWriteCanUploadSupplierLogo(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/settings/supplier/logo', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerBlockedFromEmailBrandingLogoDespiteLogoAlias(): void
    {
        // Alias /api/settings/supplier/logo je povolený, ale původní interní
        // cesta email-branding zůstává pro tokeny zavřená (preview = čtení disku).
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/settings/email-branding/logo', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('token_endpoint_forbidden', $this->errorCode($r));
    }

    public function testBearerReadCanUseInvoiceExports(): void
    {
        // Hromadný export + per-faktura ISDOC jsou verejné GETy pod /api/invoices —
        // bearer token se scope `read` na ně dosáhne (na rozdíl od /api/admin/export).
        foreach ([
            '/api/invoices/export',
            '/api/invoices/42/isdoc',
        ] as $path) {
            $r = $this->middleware()->process(
                $this->bearer('GET', $path, 'read'),
                $this->okHandler(),
            );
            self::assertSame(204, $r->getStatusCode(), "bearer GET $path");
        }
    }

    public function testBearerReadCannotWriteAllowedResource(): void
    {
        // Path je povolená, ale read scope nesmí POST → insufficient_scope (NE path).
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/clients', 'read'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('insufficient_scope', $this->errorCode($r));
    }

    public function testBearerReadWriteCanWriteAllowedResource(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/clients', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerCanReadPublicSettingsSubset(): void
    {
        foreach ([
            '/api/settings/supplier',
            '/api/settings/currencies',
            '/api/settings/vat-rates',
            '/api/settings/units',
            '/api/settings/countries',
        ] as $path) {
            $r = $this->middleware()->process(
                $this->bearer('GET', $path, 'read'),
                $this->okHandler(),
            );
            self::assertSame(204, $r->getStatusCode(), "bearer GET $path");
        }
    }

    /**
     * Daňová přiznání byla přes token nedostupná omylem: `#^/api/tax(/|$)#`
     * vyžaduje po „tax" lomítko, takže `/api/tax-return/...` nikdy nechytil.
     * Čtení přiznání je přitom to, kvůli čemu se integrace staví.
     */
    public function testBearerCanReadTaxReturns(): void
    {
        foreach ([
            '/api/tax-return/advances/upcoming',
            '/api/tax-return/dppo/2026',
            '/api/tax-return/dppo/2026/xml',
            '/api/tax-return/dppo/2026/advances',
            '/api/tax-return/dpfo/2026/insurance',
        ] as $path) {
            $r = $this->middleware()->process(
                $this->bearer('GET', $path, 'read'),
                $this->okHandler(),
            );
            self::assertSame(204, $r->getStatusCode(), "bearer GET $path");
        }
    }

    /** Podat přiznání, znovuotevřít ho ani přepsat zálohy token nesmí — to dělá člověk. */
    public function testBearerCannotWriteTaxReturns(): void
    {
        foreach ([
            ['POST', '/api/tax-return/dppo/2026/finalize'],
            ['POST', '/api/tax-return/dppo/2026/reopen'],
            ['PUT', '/api/tax-return/dppo/2026/inputs'],
            ['POST', '/api/tax-return/dppo/2026/advances/generate'],
            ['DELETE', '/api/tax-return/dppo/2026/advances/overrides/7'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process(
                $this->bearer($method, $path, 'read_write'),
                $this->okHandler(),
            );
            self::assertSame(403, $r->getStatusCode(), "bearer $method $path");
            self::assertSame('token_write_forbidden', $this->errorCode($r), "bearer $method $path");
        }
    }

    /** Přehledy automatizace čtou tatáž data jako /api/accounting; pravidla mění člověk. */
    public function testBearerCanReadAutomationButNotApplyWizard(): void
    {
        foreach (['/api/automation/overview', '/api/automation/feed', '/api/automation/counts'] as $path) {
            $r = $this->middleware()->process($this->bearer('GET', $path, 'read'), $this->okHandler());
            self::assertSame(204, $r->getStatusCode(), "bearer GET $path");
        }
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/automation/wizard/apply', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('token_write_forbidden', $this->errorCode($r));
    }

    public function testBearerCanReachDocumentRequestsAndHelpers(): void
    {
        foreach ([
            ['GET', '/api/document-requests'],
            ['GET', '/api/slug'],
            ['GET', '/api/bank-ai-suggestion-availability'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read'), $this->okHandler());
            self::assertSame(204, $r->getStatusCode(), "bearer $method $path");
        }
    }

    /**
     * Hranice zůstává úzká. Klientský portál je pohled webového rozhraní, uživatelské
     * filtry a předvolby jsou stav UI, a přijetí AI návrhu je zaúčtování (permission
     * `accounting.journal.post`) — to token nesmí ani se `read_write`.
     */
    public function testBearerStaysBlockedFromSessionOnlySurface(): void
    {
        foreach ([
            ['GET', '/api/portal/summary'],
            ['GET', '/api/portal/document-requests'],
            ['POST', '/api/portal/document-requests/7/upload'],
            ['GET', '/api/user/filters'],
            ['GET', '/api/user/preferences'],
            ['DELETE', '/api/user/preferences/theme'],
            ['POST', '/api/ai/suggestions/7/accept'],
            ['POST', '/api/ai/suggestions/7/reject'],
            ['GET', '/api/settings/ai-assist'],
            ['GET', '/api/settings/accounting-activation/status'],
            ['GET', '/api/settings/mode-switch-preview'],
            ['GET', '/api/admin/bank-rule-templates'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer $method $path");
            self::assertSame('token_endpoint_forbidden', $this->errorCode($r), "bearer $method $path");
        }
    }

    private function middleware(): ApiScopeMiddleware
    {
        return new ApiScopeMiddleware(new ResponseFactory());
    }

    private function bearer(string $method, string $path, string $scope): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope]);
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
