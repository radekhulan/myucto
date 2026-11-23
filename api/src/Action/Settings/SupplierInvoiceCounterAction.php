<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/settings/supplier/invoice-counter — nastaví counter číselné řady (admin).
 *
 * Body: { "type": "invoice"|"proforma"|"credit_note"|"simplified", "next_number": 42,
 *         "date": "2026-07-01"?, "client_id": 7?, "revenue_category_id": 3? }
 *
 * Nastaví counter tak, aby PŘÍŠTÍ vystavený doklad daného typu dostal číslo
 * `next_number`. `date` určuje, do kterého období (dle `invoice_number_period`)
 * se counter zapíše — default dnes. Umí counter i snížit; pokud by nové číslo
 * kolidovalo s už vystaveným dokladem, vystavení se samoopravně posune na první
 * volné číslo (viz VarsymbolGenerator::next()).
 *
 * Scope je volitelný: bez `client_id` i `revenue_category_id` jde o supplier-wide řadu,
 * s jedním z nich o řadu klienta, resp. kategorie tržby. Zůstává JEDNA routa záměrně —
 * je to tentýž zápis do téhož počítadla, jen na jiném řádku téhož klíče, a resolver
 * šablony ({@see VarsymbolGenerator::resolveTemplateAndPeriod()}) osy vyhodnocuje
 * rovněž jedním průchodem. Tři routy by vynutily tři kopie téže validace a rozešly by
 * se; volitelné pole navíc drží zpětnou kompatibilitu stávajících volajících.
 *
 * Typický use-case: napojení externího systému, který přebírá existující číselnou
 * řadu (import historie, migrace z jiného fakturačního software).
 *
 * Response: { "type": "invoice", "next_number": 42, "counter": 41, "client_id": 0,
 *             "revenue_category_id": 0, "period": "202607", "preview": "2607042" }
 */
final class SupplierInvoiceCounterAction
{
    public function __construct(
        private readonly VarsymbolGenerator $varsymbol,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        // Kontrola vlastnictví scope — cizí client_id / revenue_category_id nesmí projít
        // dál než sem (multi-tenant izolace, viz níže).
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }

        $b    = (array) ($request->getParsedBody() ?? []);
        $type = (string) ($b['type'] ?? 'invoice');
        // `simplified` = vlastní řada zjednodušených dokladů (migrace 2037); bez šablony
        // firmy ji generátor odmítne, protože by se psalo do počítadla faktur.
        if (!in_array($type, ['invoice', 'proforma', 'credit_note', VarsymbolGenerator::SIMPLIFIED], true)) {
            return Json::error($response, 'validation_failed', "Neplatný type (invoice|proforma|credit_note|simplified).", 400);
        }

        $next = (int) ($b['next_number'] ?? 0);
        if ($next < 1 || $next > 999999999) {
            return Json::error($response, 'validation_failed', 'next_number musí být celé číslo 1–999999999.', 400);
        }

        $for = null;
        if (trim((string) ($b['date'] ?? '')) !== '') {
            try {
                $for = new \DateTimeImmutable((string) $b['date']);
            } catch (\Throwable) {
                return Json::error($response, 'validation_failed', 'date musí být platné datum (YYYY-MM-DD).', 400);
            }
        }

        $clientId   = (int) ($b['client_id'] ?? 0);
        $categoryId = (int) ($b['revenue_category_id'] ?? 0);
        if ($clientId < 0 || $categoryId < 0) {
            return Json::error($response, 'validation_failed', 'client_id a revenue_category_id musí být >= 0.', 400);
        }
        if ($clientId > 0 && $categoryId > 0) {
            return Json::error(
                $response,
                'validation_failed',
                'client_id a revenue_category_id nelze kombinovat — řada patří vždy jedné ose.',
                400,
            );
        }

        // Multi-tenant izolace (CWE-639 / BOLA): obě id chodí od uživatele. Service je
        // sice čte výhradně v rámci supplier_id, takže cizí záznam by řadu stejně
        // nevyhrál — ale spolehnout se na to znamená hlásit „nemá vlastní šablonu"
        // u záznamu, který tomuhle dodavateli vůbec nepatří. Vazba se proto ověřuje
        // tady, a to týmž guardem jako u ostatních Action, ne vlastním dotazem.
        $bad = $this->tenantRefs->violations($supplierId, $b, ['client_id', 'revenue_category_id']);
        if ($bad !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
        }

        try {
            $result = $this->varsymbol->setCounter($supplierId, $type, $next, $for, $clientId, $categoryId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            'supplier.invoice_counter_set',
            (int) ($user['id'] ?? 0),
            'supplier',
            $supplierId,
            [
                'type'                => $type,
                'next_number'         => $next,
                'period'              => $result['period'],
                'client_id'           => $result['client_id'],
                'revenue_category_id' => $result['revenue_category_id'],
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, [
            'type'                => $type,
            'next_number'         => $next,
            'counter'             => $result['counter'],
            'period'              => $result['period'],
            'preview'             => $result['preview'],
            'client_id'           => $result['client_id'],
            'revenue_category_id' => $result['revenue_category_id'],
        ]);
    }

    /**
     * GET /api/settings/supplier/invoice-counter?type=invoice&client_id=&revenue_category_id=
     *
     * Vrátí, jaké číslo dostane PŘÍŠTÍ vystavený doklad řady (bez zápisu), aby pole
     * „Příští číslo" v nastavení ukazovalo aktuální stav i po načtení stránky.
     *
     * Response: { "type": "invoice", "next_number": 123, "counter": 122, "period": "2026",
     *             "preview": "20260123", "client_id": 0, "revenue_category_id": 0 }
     *           nebo { "type": "invoice", "next_number": null } u řady bez čítače.
     */
    public function peek(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }

        $q    = $request->getQueryParams();
        $type = (string) ($q['type'] ?? 'invoice');
        if (!in_array($type, ['invoice', 'proforma', 'credit_note', VarsymbolGenerator::SIMPLIFIED], true)) {
            return Json::error($response, 'validation_failed', "Neplatný type (invoice|proforma|credit_note|simplified).", 400);
        }

        $clientId   = (int) ($q['client_id'] ?? 0);
        $categoryId = (int) ($q['revenue_category_id'] ?? 0);
        if ($clientId < 0 || $categoryId < 0 || ($clientId > 0 && $categoryId > 0)) {
            return Json::error($response, 'validation_failed', 'Neplatná kombinace client_id / revenue_category_id.', 400);
        }

        $bad = $this->tenantRefs->violations($supplierId, $q, ['client_id', 'revenue_category_id']);
        if ($bad !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
        }

        $result = $this->varsymbol->peek($supplierId, $type, null, $clientId, $categoryId);
        if ($result === null) {
            return Json::ok($response, ['type' => $type, 'next_number' => null]);
        }

        return Json::ok($response, ['type' => $type] + $result);
    }
}
