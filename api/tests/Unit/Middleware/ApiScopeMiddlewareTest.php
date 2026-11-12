<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiScopeMiddlewareTest extends TestCase
{
    public function testBearerCanReadWorkReportBillingTemplatesThroughPublicApi(): void
    {
        $scope = $this->middleware();
        $handler = new class($scope, $this->okHandler()) implements RequestHandlerInterface {
            public function __construct(
                private readonly ApiScopeMiddleware $scope,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertSame('/api/work-report-billing-templates', $request->getUri()->getPath());
                return $this->scope->process($request, $this->next);
            }
        };
        $app = \Slim\Factory\AppFactory::create();
        \MyInvoice\Routes::register($app);
        foreach (['GET', 'HEAD'] as $method) {
            self::assertSame(\Slim\Routing\RoutingResults::FOUND,
                $app->getRouteResolver()->computeRoutingResults('/api/work-report-billing-templates', $method)->getRouteStatus());
            foreach (['read', 'read_write'] as $tokenScope) {
                foreach ([false, true] as $clientSurface) {
                    $request = $this->bearer($method, '/api/v1/work-report-billing-templates', $tokenScope)
                        ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $tokenScope, 'mcp_client_surface' => $clientSurface]);
                    $response = (new ApiVersionRewriteMiddleware())->process($request, $handler);
                    self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
                    self::assertSame('1', $response->getHeaderLine('X-API-Version'));
                }
            }
        }
    }

    public function testBearerCannotManageWorkReportBillingTemplatesOrReachSiblingEndpoints(): void
    {
        foreach (['read', 'read_write'] as $scope) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $this->middleware()->process($this->bearer($method, '/api/work-report-billing-templates', $scope), $this->okHandler());
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('token_write_forbidden', $this->errorCode($response));
            }
            foreach (['/api/work-report-billing-templates/7', '/api/work-report-billing-templates-extra', '/api/work-report-billing-preview'] as $path) {
                foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                    $response = $this->middleware()->process($this->bearer($method, $path, $scope), $this->okHandler());
                    self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
                    self::assertSame('token_endpoint_forbidden', $this->errorCode($response));
                }
            }
        }
    }

    public function testDelegatedInvoiceDefaultsRemainSessionOnly(): void
    {
        foreach (['GET', 'PUT'] as $method) {
            $path = '/api/settings/client/invoice-defaults';
            foreach (['read', 'read_write'] as $scope) {
                $response = $this->middleware()->process($this->bearer($method, $path, $scope), $this->okHandler());
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('token_endpoint_forbidden', $this->errorCode($response));
            }
            $session = (new ServerRequestFactory())->createServerRequest($method, $path)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
            self::assertSame(204, $this->middleware()->process($session, $this->okHandler())->getStatusCode());
        }
    }

    public function testMembershipCatalogTokenReadsRegisteredAccountingRoutesOnly(): void
    {
        $app = \Slim\Factory\AppFactory::create();
        \MyInvoice\Routes::register($app);
        foreach (['roles', 'roles/7', 'receivables', 'receivables/7'] as $resource) {
            $path = '/api/accounting/catalog/nonprofit-membership/' . $resource;
            $routing = $app->getRouteResolver()->computeRoutingResults($path, 'GET');
            self::assertSame(\Slim\Routing\RoutingResults::FOUND, $routing->getRouteStatus());
            $permission = (new \MyInvoice\Security\RoutePermissionMap())->match('GET', $path);
            self::assertSame('accounting', $permission->key);
            self::assertSame(\MyInvoice\Security\AccessLevel::READ, $permission->minimum);
            foreach (['read', 'read_write'] as $scope) {
                self::assertSame(204, $this->middleware()->process($this->bearer('GET', $path, $scope), $this->okHandler())->getStatusCode());
                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    self::assertSame(403, $this->middleware()->process($this->bearer($method, $path, $scope), $this->okHandler())->getStatusCode());
                }
            }
        }
    }

    public function testPropertyTokenReadsOnlyCuratedCatalogAndNeverChangesRegistry(): void
    {
        foreach (['profile', 'summary', 'houses', 'units', 'services', 'unit-legal-roles', 'unit-legal-roles/7'] as $resource) {
            $path = '/api/property/catalog/' . $resource;
            self::assertSame(204, $this->middleware()->process($this->bearer('GET', $path, 'read'), $this->okHandler())->getStatusCode());
            self::assertSame(403, $this->middleware()->process($this->bearer('POST', $path, 'read_write'), $this->okHandler())->getStatusCode());
        }
        foreach (['profile', 'export', 'objects/7', 'import/preview', 'units', 'catalog/units/7'] as $resource) {
            self::assertSame(403, $this->middleware()->process($this->bearer('GET', '/api/property/' . $resource, 'read_write'), $this->okHandler())->getStatusCode());
        }
    }

    public function testPropertyChargeTokenReadsOnlyCuratedHistory(): void
    {
        foreach (['components', 'versions', 'versions/7'] as $resource) {
            $path = '/api/property/catalog/charges/' . $resource;
            self::assertSame(204, $this->middleware()->process($this->bearer('GET', $path, 'read'), $this->okHandler())->getStatusCode());
            self::assertSame(403, $this->middleware()->process($this->bearer('POST', $path, 'read_write'), $this->okHandler())->getStatusCode());
        }
        foreach (['components', 'context', 'versions', 'versions/7', 'preview'] as $resource) {
            self::assertSame(403, $this->middleware()->process($this->bearer('GET', '/api/property/charges/' . $resource, 'read_write'), $this->okHandler())->getStatusCode());
        }
    }

    public function testCatalogReadPostHasExactReadScopeException(): void
    {
        foreach ([
            '/api/catalog/products/batch',
            '/api/catalog/prices/batch',
            '/api/catalog/exports',
            '/api/stock/items/42/neighbors',
            '/api/stock/intrastat/preview',
            '/api/stock/intrastat/export',
        ] as $path) {
            self::assertSame(204, $this->middleware()->process($this->bearer('POST', $path, 'read'), $this->okHandler())->getStatusCode());
            self::assertSame(403, $this->middleware()->process($this->bearer('DELETE', $path, 'read'), $this->okHandler())->getStatusCode());
        }
        self::assertSame(403, $this->middleware()->process($this->bearer('POST', '/api/catalog/products/batch/apply', 'read'), $this->okHandler())->getStatusCode());
        self::assertSame(403, $this->middleware()->process($this->bearer('POST', '/api/stock/items/42/neighbors/apply', 'read'), $this->okHandler())->getStatusCode());
        self::assertSame(403, $this->middleware()->process($this->bearer('POST', '/api/stock/intrastat/export/other', 'read'), $this->okHandler())->getStatusCode());
    }

    /**
     * Import už vystaveného dokladu (issue #145) je zápis do veřejného API faktur:
     * `read_write` token projde, `read` dostane 403 a nedostane se ani k akci.
     */
    public function testInvoiceImportNeedsReadWriteScope(): void
    {
        $denied = $this->middleware()->process($this->bearer('POST', '/api/invoices/import', 'read'), $this->okHandler());
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('insufficient_scope', $this->errorCode($denied));

        self::assertSame(204, $this->middleware()->process(
            $this->bearer('POST', '/api/invoices/import', 'read_write'),
            $this->okHandler(),
        )->getStatusCode());
    }

    public function testBearerReadCanGetCatalogChangeFeed(): void
    {
        self::assertSame(204, $this->middleware()->process($this->bearer('GET', '/api/catalog/changes', 'read'), $this->okHandler())->getStatusCode());
        self::assertSame(403, $this->middleware()->process($this->bearer('POST', '/api/catalog/changes', 'read'), $this->okHandler())->getStatusCode());
        self::assertSame(403, $this->middleware()->process($this->bearer('GET', '/api/catalog/changes/1', 'read'), $this->okHandler())->getStatusCode());
    }

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

    public function testPurchaseInvoiceAiImportRequiresWriteScopeOnTokenRoute(): void
    {
        $path = '/api/purchase-invoices/import-ai';
        $read = $this->middleware()->process($this->bearer('POST', $path, 'read'), $this->okHandler());
        self::assertSame(403, $read->getStatusCode());
        self::assertSame('insufficient_scope', $this->errorCode($read));

        $write = $this->middleware()->process($this->bearer('POST', $path, 'read_write'), $this->okHandler());
        self::assertSame(204, $write->getStatusCode());
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
            // Zrušení párování zaúčtovaného pohybu stornuje zápis stejně jako unpost.
            ['POST', '/api/bank-transactions/34/unmatch'],
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

    /**
     * Jediná zápisová výjimka v účetní vrstvě: koncepty ostatních pohledávek
     * a závazků, jejich splátkový kalendář a opakování. Nic z toho nezapisuje
     * do deníku; automatické účtování hlídá samotná akce.
     */
    public function testBearerReadWriteCanWriteOtherItemDrafts(): void
    {
        foreach ([
            ['POST', '/api/accounting/other-items'],
            ['PUT', '/api/accounting/other-items/12'],
            ['DELETE', '/api/accounting/other-items/12'],
            ['PUT', '/api/accounting/other-items/12/installments'],
            ['POST', '/api/accounting/other-items/12/schedule'],
            ['PUT', '/api/accounting/other-items/schedules/3/status'],
            ['POST', '/api/accounting/other-items/12/allocations'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(204, $r->getStatusCode(), "bearer $method $path musí projít");

            $r = $this->middleware()->process($this->bearer($method, $path, 'read'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer read $method $path");
            self::assertSame('insufficient_scope', $this->errorCode($r), "bearer read $method $path");
        }
    }

    /**
     * Analytický účet osnovy (např. 378.800) potřebuje integrace dřív, než na něj
     * připraví koncept. Token ho smí založit a upravit; syntetiku hlídá akce.
     */
    public function testBearerReadWriteCanCreateAndUpdateAnalyticAccounts(): void
    {
        foreach ([
            ['POST', '/api/accounting/accounts'],
            ['PATCH', '/api/accounting/accounts/12'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(204, $r->getStatusCode(), "bearer $method $path musí projít");

            $r = $this->middleware()->process($this->bearer($method, $path, 'read'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer read $method $path");
            self::assertSame('insufficient_scope', $this->errorCode($r), "bearer read $method $path");
        }

        foreach ([
            ['DELETE', '/api/accounting/accounts/12'],
            ['PUT', '/api/accounting/accounts/12'],
            ['POST', '/api/accounting/accounts/12'],
            ['PATCH', '/api/accounting/accounts'],
            ['POST', '/api/accounting/accounts/import'],
            ['POST', '/api/accounting/accounts-extra'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer $method $path musí být odmítnuto");
            self::assertSame('token_write_forbidden', $this->errorCode($r), "bearer $method $path");
        }
    }

    /** Dimenze smí token zapsat jen ke konceptu ostatní položky; jiné doklady a náhled ne. */
    public function testBearerReadWriteCanTagOnlyOtherItemDimensions(): void
    {
        $ok = $this->middleware()->process(
            $this->bearer('PUT', '/api/accounting/dimensions/documents/other-items/12', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(204, $ok->getStatusCode());

        foreach ([
            ['PUT', '/api/accounting/dimensions/documents/purchase-invoices/12'],
            ['PUT', '/api/accounting/dimensions/documents/assets/12'],
            ['POST', '/api/accounting/dimensions/documents/other-items/12/preview'],
            ['PUT', '/api/accounting/dimensions/journal/12'],
            ['DELETE', '/api/accounting/dimensions/documents/other-items/12'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer $method $path");
            self::assertSame('token_write_forbidden', $this->errorCode($r), "bearer $method $path");
        }
    }

    /** Zaúčtování, storno, přeúčtování, úhrady a generování opakování zůstávají zavřené. */
    public function testBearerCannotPostOrSettleOtherItems(): void
    {
        foreach ([
            ['POST', '/api/accounting/other-items/12/post'],
            ['POST', '/api/accounting/other-items/12/reverse'],
            ['POST', '/api/accounting/other-items/12/repost'],
            ['DELETE', '/api/accounting/other-items/12/allocations/5'],
            ['POST', '/api/accounting/other-items/schedules/3/generate'],
            ['PUT', '/api/accounting/other-items'],
            ['DELETE', '/api/accounting/other-items'],
            ['POST', '/api/accounting/other-items/12'],
            ['PATCH', '/api/accounting/other-items/12'],
            ['DELETE', '/api/accounting/other-items/12/installments'],
            ['POST', '/api/accounting/other-items/12/installments'],
            ['PUT', '/api/accounting/other-items/12/schedule'],
            ['POST', '/api/accounting/other-items/schedules'],
            ['POST', '/api/accounting/other-items/schedules/3/status'],
            ['PUT', '/api/accounting/other-items/schedules/3'],
            ['DELETE', '/api/accounting/other-items/schedules/3'],
            ['POST', '/api/accounting/other-items-extra'],
            ['PUT', '/api/accounting/other-items/12/installments/extra'],
            ['POST', '/api/accounting/journal'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer $method $path musí být odmítnuto");
            self::assertSame('token_write_forbidden', $this->errorCode($r), "bearer $method $path");
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

    public function testBearerCanUseLimitedPayrollPersonnelAndInputSurface(): void
    {
        foreach ([
            ['GET', '/api/payroll/people', 'read'],
            ['GET', '/api/payroll/people/7', 'read'],
            // Onboarding z jiné agendy je záměrně dostupný; mazání osoby ne.
            ['POST', '/api/payroll/people', 'read_write'],
            ['GET', '/api/payroll/components', 'read'],
            ['GET', '/api/payroll/inputs?period=2026-08', 'read'],
            ['POST', '/api/payroll/inputs', 'read_write'],
            ['PUT', '/api/payroll/inputs/8', 'read_write'],
            ['PUT', '/api/payroll/employments/4/terms', 'read_write'],
            ['PATCH', '/api/payroll/employments/4/terms/current', 'read_write'],
            ['GET', '/api/payroll/runs', 'read'],
            ['GET', '/api/payroll/runs/9', 'read'],
            ['GET', '/api/payroll/revisions/12/net-results/7', 'read'],
            ['GET', '/api/payroll/time/month', 'read'],
            ['POST', '/api/payroll/time/entries', 'read_write'],
            ['GET', '/api/payroll/time/absences', 'read'],
            ['POST', '/api/payroll/time/absences', 'read_write'],
            ['GET', '/api/payroll/time/averages', 'read'],
        ] as [$method, $path, $scope]) {
            $response = $this->middleware()->process(
                $this->bearer($method, $path, $scope),
                $this->okHandler(),
            );
            self::assertSame(204, $response->getStatusCode(), "bearer {$method} {$path}");
        }
    }

    public function testBearerCannotControlPayrollRunsOrSensitivePayrollSurface(): void
    {
        foreach ([
            ['DELETE', '/api/payroll/people/7'],
            ['POST', '/api/payroll/components'],
            ['POST', '/api/payroll/time/averages'],
            ['POST', '/api/payroll/runs'],
            ['DELETE', '/api/payroll/runs/9'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->bearer($method, $path, 'read_write'),
                $this->okHandler(),
            );
            self::assertSame(403, $response->getStatusCode(), "bearer {$method} {$path}");
            self::assertSame('token_write_forbidden', $this->errorCode($response));
        }

        foreach ([
            ['POST', '/api/payroll/runs/9/commands/calculate'],
            ['POST', '/api/payroll/runs/9/commands/approve'],
            ['POST', '/api/payroll/runs/9/commands/post'],
            ['POST', '/api/payroll/runs/9/commands/prepare_payments'],
            ['POST', '/api/payroll/runs/9/commands/close'],
            ['POST', '/api/payroll/time/months/2026-08/approve'],
            ['POST', '/api/payroll/time/absences/3/decision'],
            ['POST', '/api/payroll/time/absences/3/cancel'],
            ['POST', '/api/payroll/submissions/sickness-cases'],
            ['GET', '/api/payroll/documents'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->bearer($method, $path, 'read_write'),
                $this->okHandler(),
            );
            self::assertSame(403, $response->getStatusCode(), "bearer {$method} {$path}");
            self::assertSame('token_endpoint_forbidden', $this->errorCode($response));
        }
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
            '/api/settings/client/signing/profiles',
            '/api/settings/client/signing/profiles/1/credentials/certificate',
            '/api/settings/client/pdf-signing',
            '/api/settings/client/email-profiles',
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
     * Bankovní spojení firmy (měnové účty) token nezmění ani se scope read_write:
     * přepsaný IBAN by se tiskl na každou další fakturu a do QR platby.
     */
    public function testBearerReadWriteCannotChangeCompanyBankAccounts(): void
    {
        foreach ([
            ['POST', '/api/settings/currencies'],
            ['PUT', '/api/settings/currencies/5'],
            ['DELETE', '/api/settings/currencies/5'],
        ] as [$method, $path]) {
            $r = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer $method $path");
            self::assertSame('token_write_forbidden', $this->errorCode($r));
        }
        self::assertSame(204, $this->middleware()->process(
            $this->bearer('GET', '/api/settings/currencies', 'read_write'),
            $this->okHandler(),
        )->getStatusCode());
        // Session (webové rozhraní) zůstává beze změny.
        self::assertSame(204, $this->middleware()->process(
            (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/currencies/5')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session'),
            $this->okHandler(),
        )->getStatusCode());
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
            ['GET', '/api/user/favorites'],
            ['DELETE', '/api/user/favorites/7'],
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

    public function testClosingDecisionWorkspacesRequireSessionBeforeHandler(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                return (new ResponseFactory())->createResponse(204);
            }
        };
        foreach (['asset-reserve', 'legal-provisions'] as $workspace) {
            $path = '/api/accounting/periods/7/closing/' . $workspace . '/workspace';
            foreach (['GET', 'HEAD'] as $method) {
                foreach (['read', 'read_write'] as $scope) {
                    $response = $this->middleware()->process($this->bearer($method, $path, $scope), $handler);
                    self::assertSame(403, $response->getStatusCode(), "$scope $method $path");
                    self::assertSame('token_endpoint_forbidden', $this->errorCode($response));
                    self::assertSame(0, $handler->calls);
                }
            }
            $session = (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
            self::assertSame(204, $this->middleware()->process($session, $this->okHandler())->getStatusCode());
        }
    }

    public function testClosingPublicPreviewsRemainAvailableToReadTokens(): void
    {
        foreach (['fx-preview', 'deferred-tax-preview', 'provisions-preview'] as $preview) {
            foreach (['read', 'read_write'] as $scope) {
                $response = $this->middleware()->process(
                    $this->bearer('GET', '/api/accounting/periods/7/closing/' . $preview, $scope),
                    $this->okHandler(),
                );
                self::assertSame(204, $response->getStatusCode(), "$scope $preview");
            }
        }
    }

    /**
     * Mobilní aplikace a integrace čtou evidenci tržeb tokenem (Domů, Tržby, detail),
     * zápisové EET a rychlou účtenku jen se scope `read_write`. Nastavení, jednotky,
     * zařízení, certifikáty, diagnostika a export zůstávají jen pro webovou relaci.
     */
    public function testEetAndMobileReceiptBearerBoundary(): void
    {
        $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
        foreach (['/api/eet/summary', '/api/eet/records', '/api/eet/records/7', '/api/eet/queue', '/api/eet/capabilities', '/api/eet/payments/' . $uuid] as $path) {
            self::assertSame(204, $this->middleware()->process($this->bearer('GET', $path, 'read'), $this->okHandler())->getStatusCode(), $path);
        }
        foreach ([
            '/api/eet/payments', '/api/eet/payments/preview', '/api/eet/payment-intents',
            '/api/eet/payment-intents/' . $uuid . '/confirm', '/api/eet/payment-intents/' . $uuid . '/cancel',
            '/api/eet/records/7/retry', '/api/eet/records/7/corrections', '/api/eet/records/7/corrections/preview',
            '/api/mobile/receipts',
        ] as $path) {
            $denied = $this->middleware()->process($this->bearer('POST', $path, 'read'), $this->okHandler());
            self::assertSame(403, $denied->getStatusCode(), $path);
            self::assertSame('insufficient_scope', $this->errorCode($denied), $path);
            self::assertSame(204, $this->middleware()->process($this->bearer('POST', $path, 'read_write'), $this->okHandler())->getStatusCode(), $path);
        }
        foreach ([
            ['GET', '/api/eet/certificates'], ['POST', '/api/eet/certificates'], ['POST', '/api/eet/certificates/3/deactivate'],
            ['GET', '/api/eet/settings'], ['PUT', '/api/eet/settings'], ['GET', '/api/eet/units'], ['POST', '/api/eet/devices'],
            ['POST', '/api/eet/diagnostics'], ['GET', '/api/eet/export'],
        ] as [$method, $path]) {
            $denied = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $denied->getStatusCode(), $method . ' ' . $path);
            self::assertSame('token_endpoint_forbidden', $this->errorCode($denied), $method . ' ' . $path);
        }
        // Veřejná účtenka zákazníka: odkaz spravuje token jako ostatní zápisy faktur.
        self::assertSame(204, $this->middleware()->process($this->bearer('POST', '/api/invoices/7/receipt-link', 'read_write'), $this->okHandler())->getStatusCode());
        self::assertSame(403, $this->middleware()->process($this->bearer('DELETE', '/api/invoices/7/receipt-link', 'read'), $this->okHandler())->getStatusCode());
    }
    /**
     * Zápis do read-only vrstvy mimo allowlist (SVJ) hlásí běžnému tokenu `token_write_forbidden`,
     * čtení mimo allowlist a session-only cesty dál `token_endpoint_forbidden` (#371).
     */
    public function testPlainTokenWriteOutsideAllowlistReportsWriteForbidden(): void
    {
        foreach ([
            ['POST', '/api/property/houses'],
            ['POST', '/api/property/units'],
            ['POST', '/api/property/objects/5/revisions'],
            ['POST', '/api/property/objects/5/revisions/7/confirm'],
            ['POST', '/api/property/charges/components'],
            ['POST', '/api/property/charges/versions'],
            ['POST', '/api/property/charges/issues/preview'],
            ['POST', '/api/property/charges/issues/batches/5/post'],
            ['POST', '/api/property/charges/issues/5/reverse'],
            ['POST', '/api/property/payments/confirm'],
            ['POST', '/api/property/payments/bank-proposals/5/plan'],
        ] as [$method, $path]) {
            $denied = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $denied->getStatusCode(), $path);
            self::assertSame('token_write_forbidden', $this->errorCode($denied), $path);
        }
        foreach ([
            ['GET', '/api/property/houses'],
            ['GET', '/api/property/charges/issues/context'],
            ['POST', '/api/accounting/payroll/post'],
            ['POST', '/api/purchase-invoices/renumber'],
        ] as [$method, $path]) {
            $denied = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $denied->getStatusCode(), $path);
            self::assertSame('token_endpoint_forbidden', $this->errorCode($denied), $method . ' ' . $path);
        }
    }

    /**
     * Token s plným zápisem (#371) projde read-only účetní vrstvou, session-only cestami
     * i SVJ zápisem; přihlášení, správu tokenů, licence, instance a přístupové údaje ne.
     * Bez příznaku (nebo s příznakem, který AuthMiddleware nepotvrdil) zůstává 403.
     */
    public function testSystemWriteTokenActsAsSessionExceptSecuritySurfaces(): void
    {
        $open = [
            ['POST', '/api/accounting/other-items/5/post'],
            ['POST', '/api/accounting/other-items/5/reverse'],
            ['POST', '/api/invoices/5/book'],
            ['POST', '/api/bank-transactions/5/post'],
            ['POST', '/api/property/charges/issues/batches/5/post'],
            ['POST', '/api/property/houses'],
            ['POST', '/api/payroll/runs/5/approve'],
            ['PUT', '/api/settings/client/invoice-defaults'],
            ['POST', '/api/purchase-invoices/renumber'],
            ['GET', '/api/auth/api-me'],
        ];
        foreach ($open as [$method, $path]) {
            $response = $this->middleware()->process($this->systemWrite($method, $path), $this->okHandler());
            self::assertSame(204, $response->getStatusCode(), $method . ' ' . $path);

            $plain = $this->middleware()->process($this->bearer($method, $path, 'read_write'), $this->okHandler());
            if ($path !== '/api/auth/api-me') {
                self::assertSame(403, $plain->getStatusCode(), 'bez příznaku ' . $method . ' ' . $path);
            }
            $unconfirmed = $this->bearer($method, $path, 'read_write')->withAttribute(
                AuthMiddleware::ATTR_API_TOKEN,
                ['scope' => 'read_write', 'allow_system_write' => true],
            );
            if ($path !== '/api/auth/api-me') {
                self::assertSame(403, $this->middleware()->process($unconfirmed, $this->okHandler())->getStatusCode(),
                    'nepotvrzený příznak ' . $method . ' ' . $path);
            }
        }

        $closed = [
            ['POST', '/api/auth/tokens'],
            ['DELETE', '/api/auth/tokens/5'],
            ['POST', '/api/auth/change-password'],
            ['POST', '/api/auth/totp/setup'],
            ['GET', '/api/auth/me'],
            ['POST', '/api/auth/webauthn/register/options'],
            ['GET', '/api/admin/users'],
            ['POST', '/api/admin/roles'],
            ['GET', '/api/instance-admin/status'],
            ['POST', '/api/license/activate'],
            ['GET', '/api/mcp/grants'],
            ['POST', '/api/settings/signing/profiles'],
            ['POST', '/api/settings/databox'],
            ['POST', '/api/settings/domains'],
            ['PUT', '/api/settings/ai-assist'],
        ];
        foreach ($closed as [$method, $path]) {
            $response = $this->middleware()->process($this->systemWrite($method, $path), $this->okHandler());
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('token_endpoint_forbidden', $this->errorCode($response));
        }
    }

    private function systemWrite(string $method, string $path): ServerRequestInterface
    {
        return $this->bearer($method, $path, 'read_write')->withAttribute(
            AuthMiddleware::ATTR_API_TOKEN,
            ['scope' => 'read_write', 'allow_system_write' => true, 'system_write' => true],
        );
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
