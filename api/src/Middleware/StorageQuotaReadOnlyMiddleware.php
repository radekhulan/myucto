<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Režim jen pro čtení při vyčerpané diskové kvótě (H-10).
 *
 * Účetní systém, kterému dojde disk v půlce ukládání dokladu, je horší než
 * účetní systém, který včas řekne „nezapisuju". Tahle vrstva to řekne: při
 * vyčerpané kvótě odmítne zápisové metody (POST/PUT/PATCH) s vysvětlením,
 * a ČTENÍ nechá projít. Zákazník musí dál vidět a vytisknout svoje doklady —
 * jde jen o to, aby nepřibývala data.
 *
 * ── ⚠️ Pět výjimek, bez kterých by se instalace zamkla nadobro ────────────
 *
 *  1. **`/api/health`** — hosting podle něj pozná stav instance. Bez toho
 *     nerozezná „došlo místo" od „instance je mrtvá".
 *  2. **Přihlášení a odhlášení** — jinak se do zamčené instalace nedostane
 *     ani admin, a zamčenou instalaci nemá kdo odemknout. Patří sem celý
 *     přihlašovací tok včetně passkeys a MFA step-upu; kdyby chyběl jeden
 *     krok, uživatel s povinným MFA by uvízl na půl cesty.
 *  3. **Mazání (DELETE)** — jediná cesta ven vlastními silami. Zápis, který
 *     místo UVOLŇUJE, nesmí blokovat pravidlo o nedostatku místa.
 *  4. **Objednání většího prostoru** — druhá cesta ven. Zamknout zákazníkovi
 *     i tlačítko „chci víc místa" znamená, že se odemknout nedá vůbec.
 *  5. **Odeslání exportu** — zákazník musí mít možnost data dostat ven.
 *     Ano, export si chvíli místo vezme; proti tomu, že by se k datům nedostal
 *     vůbec, je to jednoznačně lepší obchod.
 *
 * ── Na co je to navázané ──────────────────────────────────────────────────
 * Na `app.managed` a NASTAVENOU kvótu, ne na volné místo na disku
 * ({@see StorageQuotaPolicy}). Na self-hosted instalaci se režim nesmí zapnout
 * sám — kvótu tam nikdo nenastavil.
 *
 * ⚠️ Nezměřená spotřeba (`usage = null`) NENÍ nula a nezamyká nic. Rozhodnutí
 * o tom drží {@see StorageQuotaPolicy}, tahle vrstva se jen ptá.
 *
 * ── Hlavičky ──────────────────────────────────────────────────────────────
 * Když je kvóta u konce, nese KAŽDÁ odpověď `X-Storage-Quota-State` (+ procenta
 * a bajty). Frontend z toho staví banner ještě předtím, než uživatel narazí na
 * první odmítnutý zápis — admin se o blížícím se zámku musí dozvědět dřív, než
 * přestane zapisovat, ne až tím.
 */
final class StorageQuotaReadOnlyMiddleware implements MiddlewareInterface
{
    /** Atribut requestu se stavem — ať si ho nemusí Action počítat znovu. */
    public const ATTR_STATUS = 'storage_quota.status';

    public const HEADER_STATE     = 'X-Storage-Quota-State';
    public const HEADER_PERCENT   = 'X-Storage-Quota-Percent';
    public const HEADER_USED      = 'X-Storage-Quota-Used-Bytes';
    public const HEADER_LIMIT     = 'X-Storage-Quota-Limit-Bytes';

    /** Metody, které nic nezapisují. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Přihlašovací a odhlašovací tok. Bez KOMPLETNÍHO toku (ne jen `/login`)
     * by se do zamčené instalace nedostal uživatel s passkey ani s povinným MFA.
     */
    private const AUTH_PATHS = [
        '/api/auth/login',
        '/api/auth/logout',
        '/api/auth/webauthn/login/options',
        '/api/auth/webauthn/login/verify',
        '/api/auth/domain-login/start',
        '/api/auth/domain-login/authorize',
        '/api/auth/domain-login/exchange',
        '/api/auth/mfa/step-up/totp',
        '/api/auth/mfa/step-up/recovery',
    ];

    /**
     * Cesty, které i v zamčeném stavu smí zapisovat, protože vedou VEN:
     * objednávka většího prostoru a export dat.
     */
    private const ESCAPE_PATHS = [
        // Objednání většího prostoru / navýšení tarifu.
        //
        // ⚠️ Cesty pro dokup MÍSTA se jmenují `quota`, ne `storage`: IIS má
        // `storage` mezi hidden segmenty, takže by na ně vracel 404. Kdo je tady
        // přejmenuje, musí je přejmenovat i v Routes.php — a naopak. Chybějící
        // řádek tady zamkne zákazníkovi právě to tlačítko, kterým se odemyká.
        '/api/license/upgrade',
        '/api/license/upgrade/quote',
        '/api/license/quota',
        '/api/license/quota/quote',
        '/api/license/tier',
        '/api/license/tier/quote',
        '/api/license/payroll',
        '/api/license/payroll/quote',
        '/api/license/change-status',
        '/api/license/activate',
        // Export instance — jediná cesta, jak zákazník dostane data ven.
        '/api/admin/instance-export/start',
    ];

    /**
     * Prefixy, pod kterými se povoluje cokoli (kvůli cestám s `{id}`).
     * Zrušení běžícího exportu i jeho úklid musí jít i v zamčeném stavu.
     */
    private const ESCAPE_PREFIXES = [
        '/api/admin/instance-export/',
    ];

    public function __construct(
        private readonly StorageQuotaPolicy $policy,
        private readonly ResponseFactory $responses,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Levná konfigurační brána: self-hosted instalace (bez `app.managed`
        // nebo bez nastavené kvóty) tudy projde bez jediného dotazu do DB.
        if (!$this->policy->isEnforceable()) {
            return $handler->handle($request);
        }

        $status = $this->policy->evaluate();
        $request = $request->withAttribute(self::ATTR_STATUS, $status);

        if (!$status->blocksWrites() || $this->isExempt($request)) {
            return self::withQuotaHeaders($handler->handle($request), $status);
        }

        return self::withQuotaHeaders(
            Json::error(
                $this->responses->createResponse(StorageQuotaPolicy::HTTP_STATUS),
                StorageQuotaPolicy::ERROR_CODE,
                $this->policy->readOnlyMessage(),
                StorageQuotaPolicy::HTTP_STATUS,
                [
                    'usage_bytes' => $status->usageBytes,
                    'quota_bytes' => $status->quotaBytes,
                    'percent'     => $status->percent,
                ],
            ),
            $status,
        );
    }

    /** Stav kvóty pro Action vrstvu (null = režim se neuplatňuje). */
    public static function status(Request $request): ?StorageQuotaStatus
    {
        $status = $request->getAttribute(self::ATTR_STATUS);

        return $status instanceof StorageQuotaStatus ? $status : null;
    }

    private function isExempt(Request $request): bool
    {
        $method = strtoupper($request->getMethod());
        $path   = RequestPath::normalize($request->getUri()->getPath());

        // Čtení projde vždy — zákazník musí dál vidět a vytisknout své doklady.
        if (in_array($method, self::SAFE_METHODS, true)) {
            return true;
        }

        if ($method === 'POST' && (in_array($path, ['/api/catalog/products/batch', '/api/catalog/prices/batch'], true)
            || preg_match('#^/api/stock/items/[0-9]+/neighbors$#', $path) === 1)) {
            return true;
        }

        // Hosting musí poznat, v jakém je instance stavu.
        if ($path === '/api/health') {
            return true;
        }

        // Mazání UVOLŇUJE místo — je to jediná cesta ven vlastními silami.
        if ($method === 'DELETE') {
            return true;
        }

        if (in_array($path, self::AUTH_PATHS, true)) {
            return true;
        }

        if (in_array($path, self::ESCAPE_PATHS, true)) {
            return true;
        }

        foreach (self::ESCAPE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hlavičky se přidávají jen když je co hlásit. Na zdravé instalaci by to
     * byla režie na každé odpovědi a v logu proxy šum, který nikdo nečte.
     */
    private static function withQuotaHeaders(Response $response, StorageQuotaStatus $status): Response
    {
        if (!$status->warns()) {
            return $response;
        }

        $response = $response
            ->withHeader(self::HEADER_STATE, $status->state->value)
            ->withHeader(self::HEADER_USED, (string) ($status->usageBytes ?? ''))
            ->withHeader(self::HEADER_LIMIT, (string) ($status->quotaBytes ?? ''));

        // ⚠️ Prázdná hodnota, ne "0": nezměřená spotřeba se do hlavičky nesmí
        // propsat jako nula. Sem se sice s UNKNOWN nedostaneme (ten nevaruje),
        // ale pravidlo musí platit i tady — jinak ho příští úprava poruší.
        return $response->withHeader(
            self::HEADER_PERCENT,
            $status->percent === null ? '' : number_format($status->percent, 1, '.', ''),
        );
    }
}
