<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Licenční brána (E4). Ověřuje blokaci komerčních modulů v degradovaném /
 * prošlém-trial stavu, plný provoz MIT základu i licencovaného stavu a
 * aktivním stavu a chování denní obnovy tokenu (jen pro přihlášené, chyba obnovy
 * request neshodí).
 */
final class LicenseMiddlewareTest extends TestCase
{
    #[DataProvider('expiredStateProvider')]
    public function testExpiredStateBlocksCommercialFeatureForReadingAndWriting(
        string $state,
        string $method,
        string $path,
    ): void
    {
        $response = $this->middleware($this->stateReturning($state))
            ->process($this->request($method, $path), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('license_commercial_feature_unavailable', (string) $response->getBody());
    }

    public static function expiredStateProvider(): array
    {
        return [
            'degraded accounting GET'       => [LicenseState::DEGRADED, 'GET', '/api/accounting/journal'],
            'expired accounting POST'       => [LicenseState::TRIAL_EXPIRED, 'POST', '/api/accounting/journal'],
            'degraded stock GET'            => [LicenseState::DEGRADED, 'GET', '/api/stock/items'],
            'expired e-shop POST'           => [LicenseState::TRIAL_EXPIRED, 'POST', '/api/eshop/import'],
            'degraded invoice stock link'   => [LicenseState::DEGRADED, 'GET', '/api/invoices/12/stock-documents'],
            'expired invoice posting'       => [LicenseState::TRIAL_EXPIRED, 'POST', '/api/invoices/12/book'],
            'expired purchase stock link'   => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/purchase-invoices/8/stock-receipts'],
            'degraded purchase AI suggest'  => [LicenseState::DEGRADED, 'POST', '/api/purchase-invoices/8/ai-suggest'],
            'expired bank posting'          => [LicenseState::TRIAL_EXPIRED, 'POST', '/api/bank-transactions/21/post'],
            'degraded bank unposting'       => [LicenseState::DEGRADED, 'POST', '/api/bank-transactions/21/unpost'],
            'expired bank AI suggest'       => [LicenseState::TRIAL_EXPIRED, 'POST', '/api/bank-transactions/21/ai-suggest'],
            'degraded bank AI availability' => [LicenseState::DEGRADED, 'GET', '/api/bank-ai-suggestion-availability'],
            'degraded portfolio'            => [LicenseState::DEGRADED, 'GET', '/api/portfolio/overview'],
            'expired accounting automation' => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/automation/feed'],
            'degraded section 74b'          => [LicenseState::DEGRADED, 'GET', '/api/reports/s74b/preview'],
            'expired VAT section 43'        => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/reports/s43'],
            'degraded VAT section 46'       => [LicenseState::DEGRADED, 'GET', '/api/reports/s46/candidates'],
            'expired VAT section 79'        => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/reports/s79'],
            // Archiv podání sám zamčený NENÍ — bezplatná část zahrnuje DPH i KH
            // a zákazník se k jejich XML musí dostat. Zamčené je až PODÁNÍ do EPO,
            // což je služba, kterou provozujeme my. Rozhodnutí podle konkrétního
            // výkazu dělá TaxSubmissionAction, cesta o typu výkazu nic neví.
            'degraded EPO submit'           => [LicenseState::DEGRADED, 'POST', '/api/reports/submissions/12/epo-submit'],
            'degraded EPO credentials'      => [LicenseState::DEGRADED, 'GET', '/api/reports/submissions/epo-credentials'],
            'expired accounting activation' => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/settings/accounting-activation/status'],
            // Čtyři licencované moduly: účetnictví (obě jeho tváře), mzdy, sklad, OSS.
            'degraded tax evidence'         => [LicenseState::DEGRADED, 'POST', '/api/tax-evidence/cash-journal'],
            'expired OSS return'            => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/reports/oss/preview'],
            'degraded OSS bulk assign'      => [LicenseState::DEGRADED, 'POST', '/api/invoices/bulk-oss'],
            // Daň z příjmů: základ daně se počítá z výsledku hospodaření nebo
            // z peněžního deníku, a obojí je za licencí. Přiznání nad daty,
            // která nemá čím naplnit, by se jen tvářilo, že jde vystavit.
            'expired income tax return'     => [LicenseState::TRIAL_EXPIRED, 'GET', '/api/tax-return/dppo/preview'],
            'degraded income tax inputs'    => [LicenseState::DEGRADED, 'PUT', '/api/tax-return/dpfo/2026/inputs'],
            'degraded tax optimizer'        => [LicenseState::DEGRADED, 'GET', '/api/tax/analysis'],
        ];
    }

    #[DataProvider('freeCoreRequestProvider')]
    public function testFreeCoreRemainsFullyAvailableAfterExpiration(string $method, string $path): void
    {
        $response = $this->middleware($this->stateReturning(LicenseState::DEGRADED))
            ->process($this->request($method, $path), $this->handler());

        self::assertSame(204, $response->getStatusCode(), $method . ' ' . $path);
    }

    public static function freeCoreRequestProvider(): array
    {
        return [
            'invoice list'        => ['GET', '/api/invoices'],
            'invoice create'      => ['POST', '/api/invoices'],
            'purchase create'     => ['POST', '/api/purchase-invoices'],
            'client create'       => ['POST', '/api/clients'],
            'bank import'         => ['POST', '/api/bank-statements/upload'],
            'VAT report'          => ['GET', '/api/reports/dphdp3/preview'],
            // Pokladna a bankovní účty zůstávají v bezplatném základu i po vypršení:
            // jsou to evidence dokladů, ne účetní nadstavba (ta je o kus výš mezi
            // omezenými cestami včetně daňové evidence).
            'cash register'       => ['GET', '/api/accounting/cash-registers'],
            'cash document'       => ['POST', '/api/accounting/cash-documents'],
            'bank accounts'       => ['GET', '/api/accounting/bank-accounts'],
            'license activate'    => ['POST', '/api/license/activate'],
            'auth login'          => ['POST', '/api/auth/login'],
            'auth logout'         => ['POST', '/api/auth/logout'],
            'preflight'           => ['OPTIONS', '/api/accounting/journal'],
        ];
    }

    #[DataProvider('activeStateProvider')]
    public function testNonReadOnlyStatesAllowMutations(string $state): void
    {
        $response = $this->middleware($this->stateReturning($state))
            ->process($this->request('POST', '/api/invoices'), $this->handler());

        self::assertSame(204, $response->getStatusCode(), $state);
    }

    public static function activeStateProvider(): array
    {
        return [
            'active'  => [LicenseState::ACTIVE],
            'overage' => [LicenseState::OVERAGE],
            'trial'   => [LicenseState::TRIAL],
        ];
    }

    public function testRenewRunsForAuthenticatedUser(): void
    {
        $service = $this->createMock(LicenseService::class);
        $service->method('current')->willReturn($this->state(LicenseState::ACTIVE));
        $service->expects($this->once())->method('renewIfDue');

        $response = $this->middleware($service)
            ->process($this->request('POST', '/api/invoices'), $this->handler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testPayrollRequiresItsOwnEntitlementAfterTrial(): void
    {
        $withoutAddon = $this->middleware($this->stateReturning(LicenseState::ACTIVE))
            ->process($this->request('GET', '/api/payroll/runs'), $this->handler());
        self::assertSame(403, $withoutAddon->getStatusCode());
        self::assertStringContainsString('license_payroll_feature_unavailable', (string) $withoutAddon->getBody());

        $trial = $this->middleware($this->stateReturning(LicenseState::TRIAL))
            ->process($this->request('GET', '/api/payroll/runs'), $this->handler());
        self::assertSame(204, $trial->getStatusCode());

        $licensed = $this->createStub(LicenseService::class);
        $licensed->method('current')->willReturn($this->state(LicenseState::ACTIVE, payrollEnabled: true));
        $allowed = $this->middleware($licensed)
            ->process($this->request('GET', '/api/accounting/payroll/preview'), $this->handler());
        self::assertSame(204, $allowed->getStatusCode());
    }

    public function testRenewSkippedForAnonymousRequest(): void
    {
        $service = $this->createMock(LicenseService::class);
        $service->method('current')->willReturn($this->state(LicenseState::ACTIVE));
        $service->expects($this->never())->method('renewIfDue');

        $response = $this->middleware($service)
            ->process($this->request('GET', '/api/invoices', authenticated: false), $this->handler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testRenewFailureDoesNotBreakRequest(): void
    {
        $service = $this->createStub(LicenseService::class);
        $service->method('current')->willReturn($this->state(LicenseState::ACTIVE));
        $service->method('renewIfDue')->willThrowException(new \RuntimeException('licenční server nedostupný'));

        $response = $this->middleware($service)
            ->process($this->request('POST', '/api/invoices'), $this->handler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testStateFailureLetsRequestThrough(): void
    {
        // Když nejde spočítat stav (DB výpadek), request musí projít, ne spadnout.
        $service = $this->createStub(LicenseService::class);
        $service->method('current')->willThrowException(new \RuntimeException('DB down'));

        $response = $this->middleware($service)
            ->process($this->request('POST', '/api/invoices'), $this->handler());

        self::assertSame(204, $response->getStatusCode());
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function middleware(LicenseService $service): LicenseMiddleware
    {
        return new LicenseMiddleware(
            $service,
            new ResponseFactory(),
            new NullLogger(),
        );
    }

    private function stateReturning(string $state): LicenseService
    {
        $service = $this->createStub(LicenseService::class);
        $service->method('current')->willReturn($this->state($state));
        return $service;
    }

    private function state(string $state, bool $payrollEnabled = false): LicenseState
    {
        return new LicenseState(
            $state,
            'iid-1',
            'single',
            null,
            0,
            0,
            0,
            null,
            null,
            null,
            null,
            null,
            true,
            payrollEnabled: $payrollEnabled,
        );
    }

    private function request(string $method, string $path, bool $authenticated = true): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($authenticated) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        }
        return $request;
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
