<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enforce token scopes + path allowlist pro bearer-authed requesty.
 *
 * 1) Path allowlist — bearer token smí volat JEN dokumentovaný veřejný subset
 *    (`/api/v1/*`, po přepisu `ApiVersionRewriteMiddleware` = `/api/*`). Interní
 *    plocha (`/api/admin/*`, správa tokenů, login/totp/change-password, citlivá
 *    nastavení signing/email-branding/IMAP) je pro token nedostupná, i kdyby ho
 *    vytvořil admin. Tím se uniklý token nedostane mimo veřejné API.
 * 2) Scope:
 *      GET / HEAD                 → vyžaduje `read` (každý token splňuje)
 *      POST / PUT / PATCH / DELETE → vyžaduje `read_write`
 *    Nad rámec toho je účetní a daňová vrstva ({@see self::BEARER_READ_ONLY})
 *    pro token jednosměrná — zápis odmítne i `read_write`. Výjimkou jsou
 *    koncepty ostatních pohledávek a závazků a analytické účty osnovy
 *    ({@see self::BEARER_WRITE_EXCEPTIONS}).
 *
 * Session auth (browser SPA) tímto MW není dotčen — uživatel má plná práva své role.
 *
 * Běží AŽ PO AuthMiddleware (potřebuje načtený api_token attribute).
 */
final class ApiScopeMiddleware implements MiddlewareInterface
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Whitelist veřejných API cest dostupných přes bearer token. Zrcadlí
     * `openapi.yaml` na úrovni zdrojových skupin. Cokoliv, co sem nepasuje
     * (zejména `/api/admin/*`, `/api/auth/*` kromě `api-me`, a interní
     * `/api/settings/*` mimo číselníky), je pro token zakázané → 403.
     *
     * Pozn.: cesty jsou už přepsané z `/api/v1/...` na `/api/...` (viz
     * ApiVersionRewriteMiddleware, běží jako outermost).
     *
     * @var list<string>
     */
    private const BEARER_ALLOWED = [
        '#^/api/property/catalog/unit-legal-roles(/[0-9]+)?$#',
        '#^/api/property/catalog/openings$#',
        '#^/api/property/catalog/service-settlements(/[0-9]+(/current)?)?$#',
        '#^/api/property/catalog/service-lifecycles(/(?:[0-9]+|reconciliation))?$#',
        '#^/api/property/catalog/funds/(operations|balances)$#',
        '#^/api/property/catalog/payments/(card|operations|history|reconciliation(/parties)?|saldo)$#',
        '#^/api/property/catalog/charges/(components|versions(/[0-9]+)?|issues(/[0-9]+)?)$#',
        '#^/api/property/catalog/(profile|summary|houses|units|services)$#',
        // Systémové + dokumentace + connection-test
        '#^/api/health$#',
        '#^/api/version$#',
        '#^/api/openapi\.yaml$#',
        '#^/api/docs$#',
        '#^/api/reference$#',
        '#^/api/scalar$#',
        '#^/api/auth/api-me$#',
        // Byznys zdroje
        '#^/api/clients(/|$)#',
        '#^/api/projects(/|$)#',
        '#^/api/invoices(/|$)#',
        '#^/api/purchase-invoices(/|$)#',
        '#^/api/recurring(/|$)#',
        '#^/api/bank-statements(/|$)#',
        '#^/api/bank-transactions(/|$)#',
        '#^/api/bank-match-suggestions(/|$)#',
        '#^/api/logbook(/|$)#',
        '#^/api/documents(/|$)#',
        '#^/api/document-folders(/|$)#',
        // Vyžádání podkladů od klienta — sesterský zdroj k /documents (stejná
        // agenda, jen z druhé strany). Klientský pohled /portal/* sem nepatří:
        // ten je součástí webového rozhraní, ne veřejného API.
        '#^/api/document-requests(/|$)#',
        '#^/api/suppliers(/|$)#',
        '#^/api/slug$#',
        // Sklad a e-shop — MCP server nad nimi staví nástroje pro zboží, ceny
        // a dostupnost. Moduly jsou opt-in (`stock_enabled`) a PermissionMiddleware
        // nad `^/api/(stock|eshop)` platí dál, takže se tím nic neotevírá plošně.
        '#^/api/stock(/|$)#',
        '#^/api/eshop(/|$)#',
        '#^/api/catalog/(products|prices)/batch$#',
        '#^/api/catalog/facets$#',
        '#^/api/catalog/exports$#',
        '#^/api/catalog/exports/[0-9]+/download$#',
        '#^/api/catalog/changes$#',
        '#^/api/price-list-items(/|$)#',
        // EET a mobilní aplikace: čtení evidence (Domů, Tržby, detail tržby) a se scope
        // `read_write` přijetí platby, QR záměr, opakování, oprava a rychlá účtenka.
        // Nastavení, jednotky, zařízení, certifikáty, diagnostika a export zůstávají
        // jen pro webovou relaci; XML pokusů token nedostane ani v detailu záznamu
        // (RequestAuthorization::canViewEetAttemptXml).
        '#^/api/eet/(summary|queue|capabilities)$#',
        '#^/api/eet/records(/[0-9]+)?$#',
        '#^/api/eet/records/[0-9]+/(retry|corrections(/preview)?)$#',
        '#^/api/eet/payments(/preview)?$#',
        '#^/api/eet/payments/[0-9a-f-]{36}$#',
        '#^/api/eet/payment-intents(/[0-9a-f-]{36}/(confirm|cancel))?$#',
        '#^/api/mobile/receipts$#',
        // Mzdy - pouze úzký personální a vstupní kontrakt. Uzávěrky, schválení
        // běhů, platby, dokumenty a podání nemají v allowlistu žádnou cestu.
        '#^/api/payroll/people$#',
        '#^/api/payroll/people/[0-9]+$#',
        '#^/api/payroll/employments/[0-9]+/terms$#',
        '#^/api/payroll/employments/[0-9]+/terms/current$#',
        '#^/api/payroll/components$#',
        '#^/api/payroll/inputs$#',
        '#^/api/payroll/inputs/[0-9]+$#',
        '#^/api/payroll/runs$#',
        '#^/api/payroll/runs/[0-9]+$#',
        '#^/api/payroll/revisions/[0-9]+/net-results/[0-9]+$#',
        '#^/api/payroll/time/month$#',
        '#^/api/payroll/time/entries$#',
        '#^/api/payroll/time/absences$#',
        '#^/api/payroll/time/averages$#',
        // Dashboard / CRM / reporty (čtení + exporty)
        '#^/api/dashboard(/|$)#',
        '#^/api/crm(/|$)#',
        '#^/api/portfolio(/|$)#',
        '#^/api/reports(/|$)#',
        '#^/api/tax(/|$)#',
        '#^/api/tax-evidence(/|$)#',
        // Daňová přiznání. Vlastní vzor je nutnost: `#^/api/tax(/|$)#` po „tax"
        // vyžaduje lomítko, takže `/api/tax-return/...` nikdy nechytil a celá
        // agenda byla přes token nedostupná — v rozporu se záměrem níže, kde je
        // čtení daňové vrstvy výslovně žádoucí. Zápis blokuje BEARER_READ_ONLY.
        '#^/api/tax-return(/|$)#',
        '#^/api/accounting(/|$)#',
        // Přehledy automatizace čtou tatáž účetní data jako /api/accounting.
        // Zápis (wizard/apply) drží BEARER_READ_ONLY.
        '#^/api/automation(/|$)#',
        // Dostupnost AI návrhů k bankovním pohybům — jen GET, patří k bank doméně.
        // Samotné přijetí návrhu (/api/ai/suggestions/…) je zaúčtování, a to
        // zůstává mimo token vědomě.
        '#^/api/bank-ai-suggestion-availability$#',
        '#^/api/search$#',
        '#^/api/branding-profiles$#',
        // Číselníky
        '#^/api/codebooks(/|$)#',
        '#^/api/expense-categories(/|$)#',
        '#^/api/revenue-categories(/|$)#',
        '#^/api/vat-classifications(/|$)#',
        // Nastavení — JEN veřejný subset (supplier + číselníky), NE signing/
        // pdf-signing/email-branding/bank-email-notices.
        '#^/api/settings/supplier$#',
        '#^/api/settings/supplier/invoice-counter$#',
        '#^/api/settings/supplier/logo$#',
        '#^/api/settings/currencies(/|$)#',
        '#^/api/settings/vat-rates(/|$)#',
        '#^/api/settings/units(/|$)#',
        '#^/api/settings/countries(/|$)#',
        '#^/api/settings/nace-codes$#',
    ];

    /**
     * Citlivé session-only výjimky uvnitř jinak veřejné účetní read-only plochy.
     * Legacy mzdové routy vracejí personální identifikátory a mzdové listy, proto
     * je široký accounting allowlist nesmí zpřístupnit bearer tokenu.
     *
     * @var list<string>
     */
    private const BEARER_SESSION_ONLY = [
        '#^/api/accounting/periods/[0-9]+/nonprofit-tax/drafts?(/|$)#',
        '#^/api/accounting/nonprofit-dppo(/|$)#',
        '#^/api/accounting/nonprofit-membership(/|$)#',
        '#^/api/accounting/periods/[0-9]+/nonprofit-membership/reconciliation$#',
        '#^/api/accounting/nonprofit-(funding|capital)(/|$)#',
        '#^/api/accounting/periods/[0-9]+/nonprofit-(funding|capital)/choices$#',
        '#^/api/accounting/periods/[0-9]+/nonprofit-legal-profile(/|$)#',
        '#^/api/accounting/nonprofit-tax-savings(/|$)#',
        '#^/api/eshop/bulk(/|$)#',
        '#^/api/accounting/payroll(/|$)#',
        '#^/api/accounting/reports/payroll-sheet$#',
        '#^/api/accounting/periods/[0-9]+/closing/(asset-reserve|legal-provisions)/workspace$#',
        // Přečíslování přijatých dokladů přepisuje čísla i v deníku — jen účetní v aplikaci.
        '#^/api/purchase-invoices/renumber$#',
    ];

    /**
     * Cesty, které jsou přes bearer token dostupné VÝHRADNĚ KE ČTENÍ — i pro token
     * se scope `read_write`.
     *
     * Účetní a daňová vrstva je záměrně jednosměrná: zaúčtování, storno zápisu,
     * uzavření období, zaevidování opravy podle § 46 / § 74b nebo odeslání podání
     * na EPO jsou úkony s daňovou odpovědností. Chyba v nich znamená opravné
     * podání, takže je nesmí spustit integrace ani AI agent — dělá je člověk
     * v aplikaci, kde vidí kontext a potvrzuje krok.
     *
     * Čtení je naopak žádoucí: odhad DPH za období, obratovka, rozvaha, výsledovka
     * i saldo jsou přesně ta čísla, kvůli kterým se integrace staví.
     *
     * @var list<string>
     */
    private const BEARER_READ_ONLY = [
        '#^/api/property(/|$)#',
        // Účetní mutace, které historicky leží mimo /api/accounting. Široký
        // allowlist faktur a banky je nesmí omylem zpřístupnit read_write PAT.
        '#^/api/invoices/[0-9]+/book$#',
        '#^/api/invoices/[0-9]+/rebuild-snapshots$#',
        '#^/api/bank-transactions/[0-9]+/(post|unpost)$#',
        // Měnové účty nesou bankovní spojení firmy (číslo účtu, IBAN, BIC), které
        // se tiskne na faktury a do QR platby. Token je smí jen číst; změnu
        // příjemce plateb dělá správce ve webovém rozhraní (openapi: mutace jen přes UI).
        '#^/api/settings/currencies(/|$)#',
        '#^/api/bank-transactions/[0-9]+/unmatch$#',
        '#^/api/accounting(/|$)#',
        '#^/api/reports(/|$)#',
        '#^/api/tax(/|$)#',
        '#^/api/tax-evidence(/|$)#',
        // Přiznání: podat, znovuotevřít nebo přepsat zálohy smí jen člověk
        // v aplikaci — čtení (výpočet, XML náhled, přehled záloh) je naopak to,
        // kvůli čemu se integrace staví.
        '#^/api/tax-return(/|$)#',
        // Průvodce automatizací zapisuje pravidla zaúčtování — čtení přehledů ano,
        // změnu pravidel dělá člověk.
        '#^/api/automation(/|$)#',
        // Seznam zaměstnanců a běhů se sdílí s interními POST/DELETE routami.
        // Token smí osobu ZALOŽIT — onboarding z jiné agendy je přesně ta
        // automatizace, kvůli které se integrace staví, a nová osoba bez
        // pracovního vztahu do mzdy nevstoupí. SMAZAT ji nesmí: mazání
        // kaskáduje na pracovní vztahy a mzdovou historii. Detail osoby proto
        // zůstává jen ke čtení, kolekce ne.
        '#^/api/payroll/people/[0-9]+$#',
        '#^/api/payroll/components$#',
        '#^/api/payroll/runs$#',
        '#^/api/payroll/runs/[0-9]+$#',
        '#^/api/payroll/time/averages$#',
    ];

    /**
     * Úzké zápisové výjimky z {@see self::BEARER_READ_ONLY}: přesná dvojice metoda
     * a cesta, nikdy celá větev.
     *
     * Ostatní pohledávky a závazky (nájem, půjčka, leasing, pojistné, kauce) se
     * pořizují ze smluv a ty má integrace nebo AI asistent v ruce dřív než účetní.
     * Token proto smí založit a upravit KONCEPT, smazat koncept, nastavit splátkový
     * kalendář a založit nebo pozastavit opakování. Nic z toho nezapisuje do deníku:
     * koncept nemá zápis, splátky slouží jen k plánu cashflow a opakování bez
     * automatiky vyrábí zase jen koncepty. Úprava a smazání nekonceptu odmítne
     * služba (409 `not_draft`). Automatické účtování opakování token nezapne ani
     * neobnoví a koncept z automaticky účtovaného opakování neupraví ani nesmaže
     * (smazáním by potlačil jeho zaúčtování); to hlídá akce, protože závisí na
     * těle požadavku a stavu rozvrhu. Vypnout automatiku token smí, účtování
     * tím jen zastaví.
     *
     * Přiřazení úhrady (`POST /allocations`) smí token provést: spáruje existující bankovní
     * nebo pokladní platbu s potvrzenou položkou a nezapíše zápis do deníku (službu a práva
     * k bance či pokladně hlídá akce). Odpárování (`DELETE`) zůstává v UI.
     *
     * Zakázané zůstává vše, co účtuje: `post`, `reverse`, `repost`, odpárování úhrad
     * i `schedules/{id}/generate` (u rozvrhu s automatikou by
     * vzniklé koncepty rovnou zaúčtoval). Oprávnění uživatele (`other_items`
     * write) se dál ověřuje v PermissionMiddleware i v akci, výjimka ruší jen
     * read-only blokaci tokenu.
     *
     * Druhou výjimkou jsou analytické účty osnovy. Integrace nebo AI asistent
     * potřebuje analytiku (např. 378.800 pro zápůjčku) dřív, než na ni připraví
     * koncept, a založení účtu do deníku nic nezapisuje. Validaci (rodič, tvar
     * kódu, jedinečnost) dělá táž akce jako ve webovém rozhraní. Úprava (název,
     * deaktivace) je jen pro analytiku; syntetiku z šablony osnovy token jen čte,
     * což hlídá akce. Mazání účtu zůstává ve webovém rozhraní.
     *
     * Ke konceptu ostatní položky smí token zapsat i dimenze (středisko, projekt).
     * Koncept nemá řádky v deníku, takže se nic nepřerazítkuje; zaúčtovaný doklad
     * a koncept z automaticky účtovaného opakování odmítne DimensionAction.
     *
     * @var list<array{0:string,1:string}>
     */
    private const BEARER_WRITE_EXCEPTIONS = [
        ['POST', '#^/api/accounting/other-items$#'],
        ['PUT', '#^/api/accounting/other-items/[0-9]+$#'],
        ['DELETE', '#^/api/accounting/other-items/[0-9]+$#'],
        ['PUT', '#^/api/accounting/other-items/[0-9]+/installments$#'],
        ['POST', '#^/api/accounting/other-items/[0-9]+/allocations$#'],
        ['POST', '#^/api/accounting/other-items/[0-9]+/schedule$#'],
        ['PUT', '#^/api/accounting/other-items/schedules/[0-9]+/status$#'],
        ['POST', '#^/api/accounting/accounts$#'],
        ['PATCH', '#^/api/accounting/accounts/[0-9]+$#'],
        ['PUT', '#^/api/accounting/dimensions/documents/other-items/[0-9]+$#'],
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!RequestAuthorization::isBearerAuth($request)) {
            return $handler->handle($request);
        }

        // Allowlist i read-only denylist matchujeme na normalizované cestě (viz
        // RequestPath) — obojí musí platit přesně tam, kam router request doručí.
        $path   = RequestPath::normalize($request->getUri()->getPath());
        $method = strtoupper($request->getMethod());
        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        if (!empty($token['mcp_client_surface']) && $path !== '/mcp'
            && !(new \MyInvoice\Service\Tenant\ClientRoutePolicy())->allowsApiRequest($method, $path)) {
            return Json::error($this->responseFactory->createResponse(403),
                'token_endpoint_forbidden', 'Endpoint není součástí klientského MCP.', 403);
        }
        if ($path === '/mcp') {
            return $handler->handle($request);
        }

        // 1) Path allowlist — token mimo veřejný subset → 403 (vrací se 403, ne 404,
        //    aby integrátor dostal jasný signál; samotná existence interních cest
        //    není tajemství — jsou zdokumentované jako session-only).
        if (!$this->isBearerAllowed($path)) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'token_endpoint_forbidden',
                'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.',
                403,
            );
        }

        // 2) Scope
        if (in_array($method, self::READ_METHODS, true)
            // `/api/catalog/exports` tu je SCHVÁLNĚ, i když vrací 202 a staví frontu:
            // `CatalogExportAction::create()` vyžaduje `eshop` jen na úrovni READ a
            // výsledkem je asynchronní čtení dat, která token přečte i jinudy. Řádek
            // ve frontě není business mutace. Že tutéž cestu vyjímá i
            // DemoReadOnlyMiddleware, tenhle závěr nemění — tam jde o to, aby veřejná
            // ukázka nezakládala úlohy na pozadí, ne o úroveň oprávnění.
            || ($method === 'POST' && (in_array($path, ['/api/catalog/products/batch', '/api/catalog/prices/batch', '/api/catalog/exports', '/api/stock/items/quote'], true)
                || preg_match('#^/api/stock/items/[0-9]+/neighbors$#', $path) === 1
                || preg_match('#^/api/stock/intrastat/(preview|export)$#', $path) === 1))) {
            return $handler->handle($request);
        }

        // 2a) Účetní / daňová vrstva — zápis přes token nikdy, ani se `read_write`.
        //     Kontrola je PŘED scope kontrolou schválně: token s právem zápisu by
        //     jinak prošel a hláška „nemáte scope" by lhala o důvodu odmítnutí.
        if ($this->isReadOnlyForBearer($path) && !$this->isBearerWriteException($method, $path)) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'token_write_forbidden',
                'Tato část API je přes token dostupná pouze ke čtení. '
                . 'Zaúčtování, mzdové běhy, uzávěrky a podání lze řídit jen z webového rozhraní.',
                403,
            );
        }

        $apiToken = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $scope    = (string) ($apiToken['scope'] ?? '');

        if ($scope !== 'read_write') {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'insufficient_scope',
                'API token nemá scope `read_write` pro tuto operaci.',
                403,
            );
        }

        return $handler->handle($request);
    }

    private function isBearerAllowed(string $path): bool
    {
        foreach (self::BEARER_SESSION_ONLY as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }

        foreach (self::BEARER_ALLOWED as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isReadOnlyForBearer(string $path): bool
    {
        foreach (self::BEARER_READ_ONLY as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isBearerWriteException(string $method, string $path): bool
    {
        foreach (self::BEARER_WRITE_EXCEPTIONS as [$allowedMethod, $pattern]) {
            if ($method === $allowedMethod && preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
