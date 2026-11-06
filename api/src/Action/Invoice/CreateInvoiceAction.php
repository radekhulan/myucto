<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Service\Stock\InvoiceItemTracking;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockPriceLevelService;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Codebook\UnitPrecision;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceNoteAlias;
use MyInvoice\Service\Invoice\NewInvoiceVatClassification;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssDocumentCoherence;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Validation\InvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateInvoiceAction
{
    use HandlesVarsymbolDuplicate;
    use GuardsDocumentLock;
    // Derivace OSS pro payloady bez `oss_*` klíčů je SPOLEČNÁ s UpdateInvoiceAction —
    // dvě kopie by se rozešly a rozdíl by byl vidět až v přiznání.
    use DerivesMissingOssColumns;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly NewInvoiceVatClassification $vatClassification,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly DocumentLockService $locks,
        private readonly CnbRateDeviationChecker $rateChecker,
        private readonly \MyInvoice\Repository\PaymentScheduleRepository $paymentSchedule,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly OssItemDeriver $ossDeriver,
        private readonly OssItemPlanner $ossPlanner,
        private readonly StockPriceLevelService $priceLevels,
        private readonly InvoiceItemTracking $itemTracking,
        private readonly UnitPrecision $unitPrecision,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        // Generický `note` → `note_below_items` (issue #38). Musí být nad vším ostatním:
        // pod klíčem `note` není sloupec, takže by ho repository jinak tiše zahodila.
        $body = InvoiceNoteAlias::normalize($body);

        // Textový řádek (InvoiceItemKind::TEXT) hned v kanonickém tvaru: jen popis a sazba.
        // Derivace OSS, klasifikace DPH, sklad ani kontrola soudržnosti ho pak neuvidí jako
        // plnění (OSS klíč je vyplněný, karta prázdná).
        if (isset($body['items']) && is_array($body['items'])) {
            $body['items'] = InvoiceItemKind::normalizeItems($body['items']);
        }

        // Zjednodušený daňový doklad (§ 30 ZDPH) nemusí nést odběratele: bez klienta se
        // naváže na systémového koncového zákazníka firmy.
        if (empty($body['client_id']) && filter_var($body['is_simplified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $body['client_id'] = $this->clients->findOrCreateWalkInCustomer(SupplierGuard::currentId($request));
        }

        // BOLA guard (security report 2026-08, R2 #4 / sweep F4) — client_id se váže níž
        // na :59, ale revenue_category_id/project_id/currency_id se dosud forwardovaly
        // do createDraft() nevázané. Guard běží PŘED defaults->resolve() ze stejného
        // důvodu jako v UpdateInvoiceAction (resolve porovnává proti DODANÉMU klientovi).
        $badRefs = $this->tenantRefs->violations(
            SupplierGuard::currentId($request),
            $body,
            ['client_id', 'project_id', 'currency_id', 'revenue_category_id', 'cash_register_id'],
        );
        if ($badRefs !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
        }

        // Cenová hladina dokladu (1880): stejné pravidlo jako hladina na kartě odběratele,
        // musí patřit firmě a být aktivní; null / 0 / '' = hladina odběratele.
        if (array_key_exists('price_level_id', $body)) {
            try {
                $body['price_level_id'] = $this->priceLevels->assignableLevelId(
                    SupplierGuard::currentId($request),
                    $body['price_level_id'],
                    null,
                );
            } catch (StockException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }

        // Vybraná sériová čísla / šarže řádků (2016): `tracking_allocations` → ověřený
        // `tracking_json`. Úplnost a dostupnost kusů se kontroluje až při vystavení.
        if (isset($body['items']) && is_array($body['items'])) {
            try {
                $body['items'] = $this->itemTracking->normalizeItems(SupplierGuard::currentId($request), $body['items']);
            } catch (StockException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }

        // Desetinná místa množství podle měrné jednotky (2036). Bez nastavení u jednotky
        // se nic nekontroluje; přesnější vstup se odmítne, nic se potichu nezaokrouhlí.
        if (isset($body['items']) && is_array($body['items'])) {
            $tooPrecise = $this->unitPrecision->check($body['items']);
            if ($tooPrecise !== []) {
                return Json::error($response, 'validation_failed', UnitPrecision::ERROR_MESSAGE, 400, ['items' => $tooPrecise]);
            }
        }

        try {
            $body = $this->defaults->resolve($body, forNewInvoice: true);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        // Derivace OSS pro API klienty. Běží PŘED validací schválně: kontrola „zahraniční
        // sazbu jen na OSS řádku" čte `oss_applicable`, takže po derivaci posuzuje řádek
        // v podobě, ve které se opravdu uloží — jinak by 400 dostal právě ten integrátor,
        // jehož doklad do OSS patří.
        $ossNotes = $this->deriveMissingOssColumns($body, SupplierGuard::currentId($request));

        // Tuzemsko se bere ze země DODAVATELE, ne z natvrdo zapsané 'CZ' — táž definice,
        // se kterou pracuje derivace OSS ({@see OssItemDeriver::domesticCountry()}).
        // Dvě různá tuzemska by u dodavatele identifikovaného mimo ČR znamenala, že
        // validace zakáže sazbu, kterou import a výkazy považují za domácí.
        $allowRefund = $this->repo->refundInvoicesEnabled(SupplierGuard::currentId($request));
        $errors = InvoiceValidation::invoice(
            $body,
            $this->repo->vatRateMap(),
            $this->repo->vatRateCountryMap(),
            $this->ossDeriver->domesticCountry(SupplierGuard::currentId($request)),
            $allowRefund,
            $allowRefund ? $this->repo->currencyCode($body['currency_id'] ?? null) : null,
        );
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Klient musí existovat A patřit aktuálnímu supplier (proti cross-supplier injection)
        if (!SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // H1: datum nového dokladu nesmí spadat do uzavřeného období (client 403, účetní 409).
        $refDate = DocumentLockService::invoiceRefDate($body);
        if ($refDate !== null) {
            $lock = $this->locks->forDate(SupplierGuard::currentId($request), $refDate);
            if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', null)) {
                return $deny;
            }
        }

        // Auto-default VAT klasifikace pokud user nezadal (s multi-tenant scope)
        $this->applyVatClassificationDefaults($body, SupplierGuard::currentId($request));

        // SOUDRŽNOST DOKLADU (§ H1): doklad rozpadlý mezi OSS podání a tuzemské přiznání.
        // NEBLOKUJE — smíšený doklad je legitimní (viz {@see OssDocumentCoherence}) a
        // 400 na ruční zadání by uživatele poslalo naimportovat totéž jinudy. Chová se
        // proto stejně jako u importu: doklad se uloží, dotčené řádky dostanou příznak
        // k ručnímu posouzení a hláška jde do `_warnings` odpovědi — s tím rozdílem, že
        // u ručního zadání ji uživatel vidí hned při uložení, ne až v reportu importu.
        $items = (array) ($body['items'] ?? []);
        $contradiction = OssDocumentCoherence::flagItems($items, $this->repo->vatRateMap());
        $body['items'] = $items;

        try {
            // Číslo zadané volajícím (API, MCP) může už existovat — to je očekávaný stav
            // s odpovědí 409 níž, ne chyba do logu.
            $id = \MyInvoice\Infrastructure\Database\DbErrorLogger::expectingDuplicates(
                ['uq_inv_supplier_varsymbol'],
                fn () => $this->repo->createDraft($body, $userId),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }
        try {
            // ZÁMĚRNĚ bezpodmínečně, na rozdíl od PUT ({@see \MyInvoice\Service\Invoice\DocumentItemsPayload}):
            // založení nemá co smazat a vzniká `draft`, kde je doklad bez řádků pracovní
            // stav. Doklad bez jediné položky se zastaví až tam, kde by se stal účetním
            // faktem — při vystavení, a u migrace dat rovnou v importu.
            $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        } catch (\InvalidArgumentException $e) {
            // Neplatná vazba řádku na kartu majetku (1177). Rozdělaný draft po sobě uklidíme —
            // jinak by po každém odmítnutém pokusu zůstala prázdná faktura se spáleným číslem.
            $this->repo->delete($id);
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        // Volba „inkasovat hotově do pokladny" (migrace 1327). Na draftu se jen ULOŽÍ —
        // pokladní doklad z ní vznikne až při vystavení ({@see IssueInvoiceAction}).
        if (array_key_exists('cash_register_id', $body)) {
            $this->repo->setCashRegisterId(
                $id,
                SupplierGuard::currentId($request),
                ($body['cash_register_id'] ?? null) !== null ? (int) $body['cash_register_id'] : null,
            );
        }
        $this->paymentSchedule->saveFromPayload(SupplierGuard::currentId($request), $id, $body);
        $this->calc->recompute($id);
        $rateMeta = $this->rateApplier->applyToInvoice($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.created', $userId, 'invoice', $id, [
            'client_id' => $body['client_id'],
            'type'      => $body['invoice_type'] ?? 'invoice',
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id);
        if ($rateMeta !== null) {
            $invoice['_meta'] = ['exchange_rate' => $rateMeta];
        }
        // §C/K4: účetní kurz na dokladu odchýlen od denního ČNB kurzu k DUZP. NEBLOKUJE
        // (§24/7 pevný kurz legitimní); §73/6 se netýká — jen účetní přepočet 563/663.
        if (is_array($invoice)) {
            // Akumulovat, ne přiřazovat — jinak by poslední zapisovatel přebil ostatní.
            $warnings = InvoiceValidation::warnings($invoice);
            $dev = $this->rateChecker->deviationWarning(
                SupplierGuard::currentId($request),
                (string) ($invoice['currency'] ?? ''),
                (string) ($invoice['effective_tax_date'] ?? $invoice['tax_date'] ?? $invoice['issue_date'] ?? ''),
                ($invoice['exchange_rate'] ?? null) !== null ? (float) $invoice['exchange_rate'] : null,
            );
            if ($dev !== null) {
                $warnings[] = 'exchange_rate_cnb_deviation';
                $invoice['_warning_meta'] = ['exchange_rate_cnb_deviation' => $dev];
            }
            // Až ZA kurzem: ten `_warning_meta` přiřazuje celé, takže dřív zapsaný detail
            // by přepsal. Zápis přes klíč pole se s ním snese v obou pořadích.
            //
            // Zemi dodavatele si říkáme znovu, místo abychom si ji uložili do proměnné
            // nahoře: `domesticCountry()` cachuje nastavení dodavatele v rámci instance,
            // takže druhé volání nic nestojí — a hoisted proměnná by oslepila guard
            // {@see \MyInvoice\Tests\Architecture\InvoiceValidationDomesticCountryWiringTest},
            // který u volání validace hledá doslovný zdroj tuzemska.
            if ($contradiction !== null) {
                $warnings[] = 'oss_document_contradiction';
                $invoice['_warning_meta']['oss_document_contradiction'] = $contradiction->meta(
                    $this->ossDeriver->domesticCountry(SupplierGuard::currentId($request)),
                );
            }
            // Poznámky z derivace OSS. Do `_warnings` jde KÓD, ne věta: pole je smluvně
            // seznam kódů, které si UI překládá (`invoice.warning.<kód>`), takže vložená
            // česká věta by se v editoru zobrazila jako chybějící překlad. Celé znění
            // patří do `_warning_meta`, kam sahá integrátor — a ten je i jediný, kdo se
            // sem dostane (editor OSS sloupce posílá, takže derivace u něj neběží).
            if ($ossNotes !== []) {
                $warnings[] = 'oss_derived_notes';
                $invoice['_warning_meta']['oss_derived_notes'] = ['items' => $ossNotes];
            }
            if ($warnings !== []) {
                $invoice['_warnings'] = $warnings;
            }
        }
        return Json::ok($response, $invoice, 201);
    }

    /**
     * Auto-default vat_classification_code podle vat_rate na řádcích a header.
     * Aplikuje se jen pokud user nezadal (NULL nebo prázdný). Pravidlo drží
     * {@see NewInvoiceVatClassification}, sdílené s rychlou účtenkou.
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId): void
    {
        $this->vatClassification->apply($body, $supplierId);
    }
}
