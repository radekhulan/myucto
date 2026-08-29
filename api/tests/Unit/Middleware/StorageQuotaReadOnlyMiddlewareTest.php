<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use DateTimeImmutable;
use MyInvoice\Middleware\StorageQuotaReadOnlyMiddleware;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaState;
use MyInvoice\Service\System\StorageQuotaStatus;
use MyInvoice\Service\System\StorageUsageSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * H-10 — režim jen pro čtení při vyčerpané kvótě.
 *
 * Dvě věci, které tenhle test hlídá především:
 *  1. **Čtení se nikdy nezakazuje.** Zákazník musí dál vidět a vytisknout své
 *     doklady; jde jen o to, aby nepřibývala data.
 *  2. **Instalace se nesmí zamknout nadobro.** Přihlášení, mazání, objednávka
 *     většího prostoru, export a `/api/health` musí projít i v zamčeném stavu
 *     — bez nich by se zamčená instance neodemkla vlastními silami.
 */
final class StorageQuotaReadOnlyMiddlewareTest extends TestCase
{
    private const MB = 1024 * 1024;

    // ⚠️ Nesmí se jmenovat `status()`: `PHPUnit\Framework\TestCase::status()`
    // je final a překrytí shodí načtení CELÉ testové sady fatální chybou —
    // ne jenom tenhle soubor. Cílený běh přes --filter to nechytí, protože
    // se ostatní soubory nenačítají.
    private function quotaStatus(StorageQuotaState $state, ?float $percent, bool $enforceable = true): StorageQuotaStatus
    {
        return new StorageQuotaStatus(
            state:       $state,
            percent:     $percent,
            usageBytes:  $percent === null ? null : (int) round(10_000 * self::MB * $percent / 100),
            quotaBytes:  10_000 * self::MB,
            snapshot:    $percent === null
                ? StorageUsageSnapshot::unmeasured()
                : new StorageUsageSnapshot(
                    measuredAt: new DateTimeImmutable('2026-08-21 10:00:00'),
                    usageBytes: (int) round(10_000 * self::MB * $percent / 100),
                ),
            enforceable:     $enforceable,
            warnPercent:     90,
            readOnlyPercent: 100,
        );
    }

    private function middleware(
        StorageQuotaState $state,
        ?float $percent = null,
        bool $enforceable = true,
    ): StorageQuotaReadOnlyMiddleware {
        $policy = $this->createStub(StorageQuotaPolicy::class);
        $policy->method('isEnforceable')->willReturn($enforceable);
        $policy->method('evaluate')->willReturn($this->quotaStatus($state, $percent, $enforceable));
        $policy->method('readOnlyMessage')->willReturn('Vyčerpali jste přidělený prostor instalace.');

        return new StorageQuotaReadOnlyMiddleware($policy, new ResponseFactory());
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://instance.example.test' . $path);
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

    // ─────────────────────────────────────────────────────────────────────────
    //  Vyčerpaná kvóta
    // ─────────────────────────────────────────────────────────────────────────

    public function testWritesAreRejectedWhenQuotaIsExhausted(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            $response = $middleware->process($this->request($method, '/api/invoices'), $this->okHandler());
            $body = json_decode((string) $response->getBody(), true);

            self::assertSame(507, $response->getStatusCode(), $method);
            self::assertSame('storage_quota_exhausted', $body['error']['code'] ?? null, $method);
        }
    }

    /**
     * ⚠️ Čtení musí projít. Účetní systém, který zákazníkovi nedá vytisknout
     * jeho vlastní doklady, je horší než ten, kterému došlo místo.
     */
    public function testReadsKeepWorkingWhenQuotaIsExhausted(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $response = $middleware->process($this->request($method, '/api/invoices/17'), $this->okHandler());

            self::assertSame(204, $response->getStatusCode(), $method);
        }
    }

    /** Hosting musí podle health poznat, v jakém je instance stavu. */
    public function testHealthStaysReachable(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        foreach (['GET', 'POST'] as $method) {
            self::assertSame(
                204,
                $middleware->process($this->request($method, '/api/health'), $this->okHandler())->getStatusCode(),
                $method . ' /api/health',
            );
        }
    }

    /** Bez přihlášení by se do zamčené instalace nedostal ani admin. */
    public function testLoginAndLogoutStayPossible(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        $paths = [
            '/api/auth/login',
            '/api/auth/logout',
            '/api/auth/webauthn/login/options',
            '/api/auth/webauthn/login/verify',
            '/api/auth/mfa/step-up/totp',
            '/api/auth/mfa/step-up/recovery',
        ];

        foreach ($paths as $path) {
            self::assertSame(
                204,
                $middleware->process($this->request('POST', $path), $this->okHandler())->getStatusCode(),
                $path,
            );
        }
    }

    /** Mazání UVOLŇUJE místo — je to jediná cesta ven vlastními silami. */
    public function testDeletingDataIsAlwaysAllowed(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        foreach (['/api/invoices/17', '/api/documents/9', '/api/purchase-invoices/3'] as $path) {
            self::assertSame(
                204,
                $middleware->process($this->request('DELETE', $path), $this->okHandler())->getStatusCode(),
                $path,
            );
        }
    }

    /** Druhá cesta ven: objednat si větší prostor. */
    public function testOrderingMoreSpaceIsAllowed(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        // `quota*` jsou cesty pro dokup MÍSTA — tedy přesně ty, které musí projít,
        // když je plno. Jmenují se jinak než `upgrade*` kvůli hidden segmentu v IIS.
        $paths = [
            '/api/license/upgrade/quote',
            '/api/license/upgrade',
            '/api/license/quota/quote',
            '/api/license/quota',
            '/api/license/activate',
        ];
        foreach ($paths as $path) {
            self::assertSame(
                204,
                $middleware->process($this->request('POST', $path), $this->okHandler())->getStatusCode(),
                $path,
            );
        }
    }

    /** Třetí cesta ven: dostat data pryč. */
    public function testExportCanStillBeStarted(): void
    {
        $middleware = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0);

        foreach (
            [
                ['POST', '/api/admin/instance-export/start'],
                ['POST', '/api/admin/instance-export/12/cancel'],
            ] as [$method, $path]
        ) {
            self::assertSame(
                204,
                $middleware->process($this->request($method, $path), $this->okHandler())->getStatusCode(),
                $path,
            );
        }
    }

    /** Výjimky nesmí jít obejít percent-encodingem — normalizace je povinná. */
    public function testPercentEncodedPathCannotSneakPastTheGuard(): void
    {
        $response = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0)
            ->process($this->request('POST', '/api/inv%6Fices'), $this->okHandler());

        self::assertSame(507, $response->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  90 % a hlavičky
    // ─────────────────────────────────────────────────────────────────────────

    /** Na 90 % se varuje, ale zapisovat se nepřestává. */
    public function testWarningStateDoesNotBlockWrites(): void
    {
        $response = $this->middleware(StorageQuotaState::WARNING, 90.0)
            ->process($this->request('POST', '/api/invoices'), $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('warning', $response->getHeaderLine(StorageQuotaReadOnlyMiddleware::HEADER_STATE));
        self::assertSame('90.0', $response->getHeaderLine(StorageQuotaReadOnlyMiddleware::HEADER_PERCENT));
    }

    /** I odmítnutá odpověď nese stav — frontend z ní staví banner. */
    public function testRejectedWriteCarriesQuotaHeaders(): void
    {
        $response = $this->middleware(StorageQuotaState::EXHAUSTED, 100.0)
            ->process($this->request('POST', '/api/invoices'), $this->okHandler());

        self::assertSame('exhausted', $response->getHeaderLine(StorageQuotaReadOnlyMiddleware::HEADER_STATE));
        self::assertSame('100.0', $response->getHeaderLine(StorageQuotaReadOnlyMiddleware::HEADER_PERCENT));
    }

    /** Na zdravé instalaci se hlavičky neposílají — byl by to šum na každé odpovědi. */
    public function testHealthyInstallationSendsNoQuotaHeaders(): void
    {
        $response = $this->middleware(StorageQuotaState::OK, 12.0)
            ->process($this->request('POST', '/api/invoices'), $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader(StorageQuotaReadOnlyMiddleware::HEADER_STATE));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Kde se režim nesmí projevit vůbec
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ Nezměřená spotřeba nezamyká nic. `null` znamená „první měření ještě
     * neproběhlo", ne „nula bajtů" — a nezměřená instance se nesmí chovat ani
     * jako plná, ani jako prázdná.
     */
    public function testUnmeasuredUsageNeitherBlocksNorWarns(): void
    {
        $response = $this->middleware(StorageQuotaState::UNKNOWN, null)
            ->process($this->request('POST', '/api/invoices'), $this->okHandler());

        self::assertSame(204, $response->getStatusCode(), 'Nezměřeno se nesmí zamykat.');
        self::assertFalse(
            $response->hasHeader(StorageQuotaReadOnlyMiddleware::HEADER_STATE),
            'Nezměřená instance nemá co hlásit — ani jako varování, ani jako 0 %.',
        );
    }

    /**
     * Self-hosted instalace: middleware se nesmí ani zeptat na stav. Kvótu tam
     * nikdo nenastavil, takže by neměl co poměřovat.
     */
    public function testSelfHostedInstallationIsUntouched(): void
    {
        $policy = $this->createMock(StorageQuotaPolicy::class);
        $policy->method('isEnforceable')->willReturn(false);
        $policy->expects(self::never())->method('evaluate');

        $middleware = new StorageQuotaReadOnlyMiddleware($policy, new ResponseFactory());
        $response = $middleware->process($this->request('POST', '/api/invoices'), $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader(StorageQuotaReadOnlyMiddleware::HEADER_STATE));
    }

    /** Stav se předává dál v atributu requestu, ať ho Action nemusí počítat znovu. */
    public function testStatusIsExposedOnTheRequest(): void
    {
        $captured = null;
        $handler = new class ($captured) implements RequestHandlerInterface {
            public function __construct(private mixed &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = StorageQuotaReadOnlyMiddleware::status($request);

                return (new ResponseFactory())->createResponse(204);
            }
        };

        $this->middleware(StorageQuotaState::WARNING, 92.5)
            ->process($this->request('GET', '/api/invoices'), $handler);

        self::assertInstanceOf(StorageQuotaStatus::class, $captured);
        self::assertSame(StorageQuotaState::WARNING, $captured->state);
        self::assertSame(92.5, $captured->percent);
    }
}
