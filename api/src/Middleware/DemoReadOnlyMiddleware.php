<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class DemoReadOnlyMiddleware implements MiddlewareInterface
{
    public const ATTR_ENABLED = 'demo.enabled';

    private const ALLOWED_MUTATIONS = [
        'POST /api/auth/login',
        'POST /api/auth/logout',
    ];

    /**
     * Cesty, které demo nesmí přečíst ani GETem.
     *
     * Demo účet jede na roli superadmin, aby byla vidět celá aplikace. Tím se ale
     * otevřela i sekce Systém, jejíž guard je pouze `isSuperadmin()` — a část jejích
     * GET endpointů nevydává business data, nýbrž obsah SERVERU. Bez tohohle seznamu
     * by si kdokoli z veřejného dema stáhl `GET /api/admin/backups/download/{name}`,
     * tedy kompletní dump databáze s `users.password_hash`, `totp_secret`, hashi API
     * tokenů, licenčním klíčem a šifrovanými credentials; Diagnostika vydává 14 dní
     * aplikačního logu s absolutními cestami a URL a Log dokonce IP adresy ostatních
     * návštěvníků dema, což je osobní údaj.
     *
     * Metoda tedy na rozhodnutí nestačí: zákaz zápisu níž je o bezpečnosti změn,
     * tenhle seznam o bezpečnosti ČTENÍ. Syntetická demo data na tom nic nemění,
     * protože uvedené endpointy čtou hostitele, ne účetnictví.
     */
    private const DENIED_READS = [
        '/api/admin/backups',
        '/api/admin/diagnostics',
        '/api/admin/cron-jobs',
        '/api/admin/update',
        '/api/admin/instance-export',
        // Správa SMTP instance a klientů obsahuje provozní údaje odesílatele.
        '/api/admin/instance-smtp',
        '/api/instance-admin',
        '/api/admin/smtp-log-analysis',
        // Adresář účtů a odeslaná pošta: „syntetická demo data" tu neplatí, protože
        // v demo databázi je i reálný provozní účet, kterým se ukázka spravuje.
        // `sent-emails` čte tentýž `activity_log` jako `activity-log` níž — bez něj
        // by byl seznam nekonzistentní sám se sebou.
        '/api/admin/users',
        '/api/admin/roles',
        '/api/admin/sent-emails',
        '/api/admin/email-templates',
        '/api/admin/activity-log',
        // Bankovní napojení: OAuth callbacky ČS a KB jsou GET, ale persistují stav
        // napojení. Dokud demo jelo na readonly roli, kryla je absence práva
        // `settings.bank_accounts` WRITE; superadmin ho má.
        '/api/settings/bank-connections',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly RoutePermissionMap $routes,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Demo režim ve spravované zákaznické instalaci nemá co dělat; zámek sedí
        // tady, u vynucení, ne jen u zobrazení — jinak by přestalo hlásit demo,
        // ale zápisy by dál blokoval.
        if (!(new \MyInvoice\Service\System\ManagedModeGuard($this->config))->effectiveFlag(
            \MyInvoice\Service\System\ManagedModeGuard::KEY_DEMO_ENABLED,
            (bool) $this->config->get('demo.enabled', false),
        )) {
            return $handler->handle($request);
        }

        $request = $request->withAttribute(self::ATTR_ENABLED, true);
        $method = strtoupper($request->getMethod());
        // Normalizovaná cesta (viz RequestPath) — demo výjimky i RoutePermissionMap
        // musí matchovat totéž, co doručí router.
        $path = RequestPath::normalize($request->getUri()->getPath());

        // Systémové čtení hostitele se odmítá bez ohledu na metodu (viz DENIED_READS).
        if ($this->deniedRead($path)) {
            return Json::error(
                $this->responseFactory->createResponse(403),
                'demo_read_only',
                'Ukázková verze tuhle systémovou sekci nezpřístupňuje.',
                403,
            );
        }

        // Demo ochrana předpokládá safe-method semantiku. Každý endpoint, který by
        // při GET zapisoval, musí mít vlastní demo větev bez persistence.
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        if (in_array($method . ' ' . $path, self::ALLOWED_MUTATIONS, true)) {
            return $handler->handle($request);
        }

        $route = $this->routes->match($method, $path);
        if ($route !== null
            && $path !== '/api/catalog/exports'
            && !$this->deniedReadLevelMutation($path)
            && $route->kind === RoutePermissionMap::PERMISSION
            && $route->minimum === AccessLevel::READ
        ) {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(403),
            'demo_read_only',
            'Demo režim umožňuje funkce vyzkoušet, změny se ale neukládají.',
            403,
        );
    }

    /**
     * Non-GET cesty, které `RoutePermissionMap` vede jako READ, takže by je výjimka níž
     * pustila — demo je ale pustit nesmí.
     *
     * Výjimka „minimum == READ" vznikla pro čtecí operace, které kvůli délce parametrů
     * musí jít POSTem. Jenže READ je úroveň PRÁVA, ne důkaz, že akce nic nedělá:
     * `sensitive-reveal` dešifruje rodné číslo a zapisuje auditní stopu, `download-grant`
     * vydává stahovací grant a `*-jobs` zakládají úlohy. Dokud demo jelo na readonly roli,
     * krylo je samo právo (`payroll.person.read_sensitive` readonly nemá); superadmin ho
     * ale má, takže tuhle ztracenou pojistku musí nahradit seznam.
     */
    private function deniedReadLevelMutation(string $path): bool
    {
        foreach (['/api/automation/recommendations/', '/api/stock/reports/valuation-jobs'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        // `/reveal` je vlastní položka, ne zkrácení `/sensitive-reveal`: tam je
        // před slovem pomlčka, takže jeden suffix obě cesty nepokryje.
        foreach ([
            '/sensitive-reveal',
            '/reveal',
            '/download-grant',
            '/jmhz-protocol-reverify',
        ] as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }
        return str_starts_with($path, '/api/accounting/setup-assistant/jobs/');
    }

    /**
     * Prefixová shoda, ne `str_starts_with` nad holým řetězcem: `/api/admin/updates-x`
     * není podstrom `/api/admin/update` a blokovat se nemá. Navíc SMTP přepis konkrétní
     * faktury (`/api/admin/invoices/{id}/smtp-log`) sedí až na konci cesty.
     */
    private function deniedRead(string $path): bool
    {
        foreach (self::DENIED_READS as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return str_starts_with($path, '/api/admin/invoices/') && str_ends_with($path, '/smtp-log');
    }

    public static function enabled(Request $request): bool
    {
        return $request->getAttribute(self::ATTR_ENABLED) === true;
    }
}
