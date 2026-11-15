<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ruční účetní úkon jen z přihlášené relace, nikdy přes API token.
 *
 * {@see \MyInvoice\Middleware\ApiScopeMiddleware} drží účetní vrstvu pro token jen ke
 * čtení po CESTÁCH (/api/accounting/*, /api/invoices/{id}/book …). Některé akce mimo ně
 * ale účetní úkon provedou samy: přechod přijaté faktury na `booked`, druh nákladu
 * u zaúčtovaného dokladu (přeúčtuje deník), vynucená úprava nebo smazání vystaveného či
 * zaúčtovaného dokladu, storno zaúčtované faktury (vydané i přijaté, stornuje zápis),
 * zrušení storna zaúčtované faktury (maže zápisy) a přebití zámku uzavřeného období
 * přes `?force=1`. Cesta je tam
 * sdílená s běžným workflow, které token smí, takže rozhoduje až akce podle toho, co
 * požadavek znamená. Tady je to pravidlo jednou a dá se zavolat.
 *
 * Automatické zaúčtování, které si firma nastavila, sem nepatří: u vystavení faktury
 * ho token spouští stejně jako uživatel. Přijetí přijaté faktury tokenem automatické
 * zaúčtování odloží ({@see \MyInvoice\Service\Accounting\DocumentAutoPoster::withDeferredPosting()},
 * `posting: {action: deferred, reason: api_token}`), stejně jako párování banky.
 *
 * Brána je `!actsAsSession()`, ne `isBearerAuth()`: požadavek bez metody ověření je
 * anonymní a projít nesmí (viz {@see RequestAuthorization::isSessionAuth()}). Token
 * s plným zápisem (`system_write`, #371) úkon provede jako uživatel v aplikaci; přebití
 * uzavřeného nebo podaného období ale nepovoluje ani jemu
 * ({@see RequestAuthorization::mayOverridePeriodProtection()}). Kód chyby je tentýž
 * jako u ApiScopeMiddleware, integrace tak obě odmítnutí rozpozná stejně.
 */
final class SessionOnlyAccountingAct
{
    public const ERROR_CODE = 'token_write_forbidden';

    /**
     * @param string $act Název úkonu s velkým počátečním písmenem, např. „Ruční zaúčtování přijaté faktury".
     */
    public static function deny(Request $request, Response $response, string $act): ?Response
    {
        if (RequestAuthorization::actsAsSession($request)) {
            return null;
        }

        return Json::error(
            $response,
            self::ERROR_CODE,
            $act . ' je účetní úkon, přes API token ho nelze provést; proveďte ho v aplikaci.',
            403,
        );
    }
}
