<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Service\NumberSeries\NumberSeriesException;
use MyInvoice\Service\NumberSeries\NumberSeriesKinds;
use MyInvoice\Service\NumberSeries\NumberSeriesSelection;
use MyInvoice\Service\Stock\InvoiceItemTracking;
use MyInvoice\Service\Stock\IssuedInvoiceStockGuard;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockPriceLevelService;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Invoice\DocumentItemsPayload;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Codebook\UnitPrecision;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceNoteAlias;
use MyInvoice\Service\Invoice\TimeBilling;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssDocumentCoherence;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceValidation;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use MyInvoice\Support\ExchangeRateDate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateInvoiceAction
{
    use HandlesVarsymbolDuplicate;
    use GuardsDocumentLock;
    // Táž derivace jako u POST, ze stejného souboru — viz docblock traitu. PUT ji dlouho
    // neměl a payload integrátora bez `oss_*` klíčů tím OSS na dokladu tiše mazal.
    use DerivesMissingOssColumns;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly InvoicePdfRenderer $pdf,
        private readonly VatClassificationDefaulter $vatDefaulter,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly DocumentJournalSync $journalSync,
        private readonly CnbRateDeviationChecker $rateChecker,
        private readonly \MyInvoice\Repository\PaymentScheduleRepository $paymentSchedule,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly OssItemDeriver $ossDeriver,
        private readonly OssItemPlanner $ossPlanner,
        private readonly CashSettlementService $cashSettlement,
        private readonly StockPriceLevelService $priceLevels,
        private readonly \MyInvoice\Repository\ClientRepository $clients,
        private readonly IssuedInvoiceStockGuard $stockGuard,
        private readonly InvoiceItemTracking $itemTracking,
        private readonly UnitPrecision $unitPrecision,
        // Limit zjednodušeného dokladu podle roku dokladu (§ 30 ZDPH), viz simplifiedRejection().
        private readonly \MyInvoice\Repository\TaxConstantsRepository $taxConstants,
        private readonly FiledVatPeriodLookup $filedPeriods,
        private readonly NumberSeriesSelection $numberSeries,
        private readonly \MyInvoice\Service\Invoice\InvoiceIssueTaxGate $taxGate,
    ) {}

    /**
     * Účetní/amountová/DPH pole faktury (audit 2026-07 B11). Změna kteréhokoli z nich
     * v force-editu zaúčtovaného dokladu v uzavřeném období rozejde doklad × deník →
     * vyžaduje force_mode='reconcile'. force_mode='notes_only' povoluje jen zbytek
     * (poznámky, projekt, jazyk). exchange_rate zde ve výčtu není záměrně — řeší se
     * NUMERICKÝM porovnáním v financialFieldsChanged() (ruční override kurzu přepisuje
     * CZK hodnotu; string compare by falešně blokoval formátové neshody 25.00 vs 25).
     */
    private const FINANCIAL_FIELDS = [
        'client_id', 'currency_id', 'revenue_category_id',
        'issue_date', 'tax_date', 'due_date', 'varsymbol',
        'invoice_type', 'payment_method', 'rounding_mode', 'discount_percent', 'advance_paid_amount',
        'reverse_charge', 'prices_include_vat', 'vat_classification_code', 'income_tax_exempt',
        'is_simplified',
        // „Netisknout odběratele" (migrace 2037) mění tištěný daňový doklad, takže v uzavřeném
        // období neprojde jako poznámková úprava.
        'hide_customer_on_pdf',
        // Volba hotovostního vyrovnání (migrace 1327) zakládá/ruší ZAÚČTOVANÝ pokladní
        // doklad, takže je to účetní pole jako každé jiné — notes_only ji nesmí pustit.
        'cash_register_id',
    ];

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isForce = $request->getQueryParams()['force'] ?? null;
        $canEditIssued = RequestAuthorization::canEditIssuedInvoice($request);

        // Splátka sedí na řádek vytištěného kalendáře, opravuje se dobropisem.
        if (!empty($existing['calendar_id'])) {
            return Json::error($response, 'calendar_instalment_locked', 'Doklad splátky kalendáře nejde upravit. Opravte ho dobropisem.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if ((isset($body['currency_id']) && (int) $body['currency_id'] !== (int) $existing['currency_id'])
            || (isset($body['currency']) && (string) $body['currency'] !== (string) $existing['currency'])) {
            $billingReport = (new \MyInvoice\Repository\WorkReportRepository($this->db))->findByInvoice($id, (int) $existing['supplier_id']);
            if (($billingReport['billing_snapshot'] ?? null) !== null) {
                return Json::error($response, 'billing_currency_mismatch', 'Nejprve zrušte cenové ujednání výkazu; jeho měna musí odpovídat faktuře.', 400);
            }
        }

        // effective_tax_date je generovaný sloupec (COALESCE(tax_date, issue_date)) a z těla se
        // neukládá. invoiceRefDate() ho bere přednostně, takže podvržená hodnota by zámek
        // období i kontrolu podaného DPH období vyhodnotila nad jiným datem, než se uloží.
        unset($body['effective_tax_date']);

        // Generický `note` → `note_below_items` (issue #38). Musí být hned tady, ještě nad
        // guardy: force_mode="notes_only" i auditní diff čtou už konkrétní klíč.
        $body = InvoiceNoteAlias::normalize($body);

        // Textový řádek (InvoiceItemKind::TEXT) hned v kanonickém tvaru: jen popis a sazba.
        // Derivace OSS, klasifikace DPH, sklad ani kontrola soudržnosti ho pak neuvidí jako
        // plnění (OSS klíč je vyplněný, karta prázdná).
        if (isset($body['items']) && is_array($body['items'])) {
            $body['items'] = InvoiceItemKind::normalizeItems($body['items']);
            // Příznak „cenu zadá člověk" patří jen konceptu: u vystavené faktury by obešel
            // kontrolu kladné částky ({@see \MyInvoice\Service\Validation\InvoiceAmountPolicy}).
            if ($existing['status'] !== 'draft') {
                $body['items'] = array_map(static function (mixed $item): mixed {
                    if (is_array($item)) {
                        unset($item['manual_price']);
                    }
                    return $item;
                }, $body['items']);
            }
        }

        // Částečný PUT (#113): vynechaný klíč = uložená hodnota, ne default nového dokladu.
        // Musí předcházet guardům (zámek, notes_only, BOLA) i resolveru defaultů.
        $body = self::mergeStoredHeader($body, $existing);

        // Zámek dokladu (Epic F6, H1) — PŘED status guardem (klient dostane 403
        // document_locked, ne 409 not_editable): kontrola staré I nové refDate —
        // klient nesmí datem do uzavřeného období „utéct" ani ho tam přesunout.
        $lock = $this->locks->forInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', $id)) {
            return $deny;
        }
        if ($existing['status'] !== 'draft') {
            // Vynucená úprava vystaveného dokladu (u zaúčtovaného přeúčtuje deník) jen z relace.
            if ($isForce
                && ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Vynucená úprava vystaveného dokladu'))) {
                return $deny;
            }
            // Vystavenou fakturu upraví jen správce nebo role s právem invoices.edit_issued,
            // a to jen s explicitním ?force=1.
            if (!$canEditIssued || !$isForce) {
                return Json::error($response, 'not_editable', 'Vystavenou fakturu nelze editovat.', 409);
            }
            // Cancellation/credit_note jsou implicitně chráněné (auditní stopa)
            if (in_array($existing['invoice_type'], ['cancellation'], true)) {
                return Json::error($response, 'not_editable', 'Storno doklad nelze editovat.', 409);
            }
        }

        // Vyprázdnění položek mimo koncept → 422 ({@see DocumentItemsPayload}).
        // 422, ne 400: tělo je tvarem v pořádku (`items` JE pole), odmítá se až to, co
        // požadavek znamená proti STAVU tohohle dokladu — táž třída jako `force_mode_required`
        // níž. 400 `validation_failed` je tu vyhrazené pro schéma pole po poli (mapa `fields`).
        // Musí to být PŘED přečíslováním při změně typu: to už sahá na čítač řady, takže
        // pozdější odmítnutí by po sobě nechalo spálené číslo.
        if (DocumentItemsPayload::emptiesExisting($body, (array) ($existing['items'] ?? []), (string) $existing['status'])) {
            return Json::error(
                $response,
                DocumentItemsPayload::EMPTY_ERROR_CODE,
                DocumentItemsPayload::emptyErrorMessage(),
                422,
            );
        }

        // Vybraná sériová čísla / šarže řádků (2016) — před přečíslováním, ať odmítnutí
        // nespálí číslo řady. Bez klíče `items` zůstane uložený výběr (replay níž).
        if (DocumentItemsPayload::replaces($body) && is_array($body['items'])) {
            try {
                $body['items'] = $this->itemTracking->normalizeItems((int) $existing['supplier_id'], $body['items']);
            } catch (StockException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }

        // Desetinná místa množství podle měrné jednotky (2036), jen u NOVÝCH a ZMĚNĚNÝCH
        // řádků: vynechaný klíč `items` nechá uložené řádky, jak jsou, a řádek poslaný
        // beze změny množství a jednotky ({@see UnitPrecision::unchangedIndexes()}) se
        // nekontroluje, jinak by doklad uložený před nastavením jednotky nešel uložit
        // (a u vystaveného by ho IssuedInvoiceStockGuard nedovolil ani opravit).
        // Před přečíslováním, ať odmítnutí nespálí číslo řady.
        if (DocumentItemsPayload::replaces($body) && is_array($body['items'])) {
            $tooPrecise = $this->unitPrecision->check($body['items'], 'quantity', (array) ($existing['items'] ?? []));
            if ($tooPrecise !== []) {
                return Json::error($response, 'validation_failed', UnitPrecision::ERROR_MESSAGE, 400, ['items' => $tooPrecise]);
            }
        }

        // Skladová část vystaveného dokladu, který už pohnul skladem, je pevná
        // ({@see IssuedInvoiceStockGuard}): výdej běží jen při vystavení, úprava by se
        // se skladem rozešla. Před přečíslováním, ať odmítnutí nespálí číslo řady.
        if ($existing['status'] !== 'draft' && DocumentItemsPayload::replaces($body)) {
            $lockedBy = $this->stockGuard->lockedBy(
                (int) $existing['supplier_id'],
                $id,
                (array) ($existing['items'] ?? []),
                (array) $body['items'],
            );
            if ($lockedBy !== []) {
                return Json::error($response, 'stock_items_locked', IssuedInvoiceStockGuard::message($lockedBy), 409);
            }
            // Totéž platí pro výběr kusů: výdejka vydala konkrétní kusy a úprava ji znovu
            // nevystaví, takže by doklad a sklad tvrdily každý jiné sériové číslo (a dobropis
            // by vracel kus, který neodešel). Oprava jde dobropisem.
            if (InvoiceItemTracking::signature((array) $body['items']) !== InvoiceItemTracking::signature((array) ($existing['items'] ?? []))
                && $this->stockGuard->postedDocuments((int) $existing['supplier_id'], $id) !== []) {
                return Json::error(
                    $response,
                    'tracking_locked',
                    'Faktura už vydala konkrétní kusy ze skladu. Sériová čísla ani šarže u vystavené faktury nejde změnit, '
                        . 'opravte ji dobropisem a novou fakturou.',
                    409,
                );
            }
        }

        // parent_invoice_id se nikdy nemění při update (vazba dobropisu na původní doklad).
        $body['parent_invoice_id'] = $existing['parent_invoice_id'];

        $validTypes    = ['invoice', 'proforma', 'credit_note'];
        // Kalendář (§ 31) smí měnit typ jen DRAFT: vlastní číselnou řadu nemá, takže
        // přečíslování vystaveného dokladu na kalendář by spadlo na generátoru varsymbolu.
        $draftTypes    = [...$validTypes, 'payment_calendar', 'rent_calendar', 'advance_calendar'];
        $requestedType = (string) ($body['invoice_type'] ?? '');
        $isIssued      = $existing['status'] !== 'draft';
        $existingType  = (string) $existing['invoice_type'];

        // Změna TYPU u VYSTAVENÉ faktury (force-edit: správce nebo právo invoices.edit_issued) = přečíslování.
        // Každý typ má vlastní číselnou řadu, takže staré číslo uvolníme z původní
        // řady (releaseIfLatest — dekrement counteru, je-li poslední) a přidělíme nové
        // v řadě cílového typu (next()). Typické užití: vystavená zálohová faktura
        // (proforma) → faktura. U draftu se typ mění bez přečíslování (číslo se přidělí
        // až při vystavení), takže ten jede beze změny v else větvi.
        $renumber = null;
        if ($isIssued
            && in_array($requestedType, $validTypes, true)
            && $requestedType !== $existingType
            && in_array($existingType, $validTypes, true)  // ne cancellation/tax_document
        ) {
            $supplierId = (int) ($existing['supplier_id'] ?? 0);
            $oldVs      = (string) ($existing['varsymbol'] ?? '');

            // Pojistka proti dvojímu zdanění: zálohovou fakturu s navázaným finálem nebo
            // daňovým dokladem k platbě nelze in-place překlopit na fakturu — § 37a odpočty
            // jsou zafixované a stejná úplata by se zdanila podruhé. Admin musí nejdřív
            // navázané doklady rozpojit/stornovat (nebo použít standardní vyúčtování zálohy).
            if ($existingType === 'proforma') {
                $link = $this->db->pdo()->prepare(
                    "SELECT 1 FROM invoices
                      WHERE parent_invoice_id = ? AND invoice_type IN ('invoice', 'tax_document')
                        AND status <> 'cancelled' LIMIT 1"
                );
                $link->execute([$id]);
                if ($link->fetchColumn() !== false) {
                    return Json::error(
                        $response,
                        'has_linked_documents',
                        'K této zálohové faktuře už existuje finální nebo daňový doklad k platbě — nelze ji překlopit na fakturu (úplata by se zdanila podruhé). Nejdřív rozpoj nebo stornuj navázané doklady.',
                        409,
                    );
                }
            }

            // Staré číslo se uvolní z původní řady a nové přidělí v řadě cílového typu
            // až v transakci se zápisem dokladu (u updateDraft níž). Odmítnutí mezi tím
            // (validace, cizí reference, zámek) nesmí po sobě nechat spálené nové číslo
            // ani uvolnit staré, které doklad dál drží.
            //
            // Kategorie tržby je další osa scope counteru (migrace 1333) — uvolňovat
            // se musí ze STEJNÉ scope, ve které se číslo přidělilo, tedy z té staré.
            $oldDate     = !empty($existing['issue_date']) ? new \DateTimeImmutable((string) $existing['issue_date']) : null;
            $oldClient   = (int) ($existing['client_id'] ?? 0);
            $oldCategory = (int) ($existing['revenue_category_id'] ?? 0);

            // Datum/klient/kategorie cílové řady z payloadu, fallback na staré. Změna
            // kategorie sama o sobě NENÍ důvod k přečíslování — tady jen bereme aktuální
            // hodnotu, protože přečíslování už nastalo kvůli změně typu dokladu.
            $newDate     = !empty($body['issue_date']) ? new \DateTimeImmutable((string) $body['issue_date']) : ($oldDate ?? new \DateTimeImmutable('today'));
            $newClient   = isset($body['client_id']) ? (int) $body['client_id'] : $oldClient;
            $newCategory = array_key_exists('revenue_category_id', $body) ? (int) $body['revenue_category_id'] : $oldCategory;

            $body['invoice_type'] = $requestedType;
            $renumber = ['from' => $oldVs, 'to' => null, 'from_type' => $existingType, 'to_type' => $requestedType];
            // Řada se bere podle typu A příznaku zjednodušeného dokladu (vlastní řada
            // zjednodušených dokladů, migrace 2037): staré číslo se vrací do řady, ze které
            // přišlo, nové se bere z řady, do které doklad po změně patří.
            $newSimplified = array_key_exists('is_simplified', $body)
                ? filter_var($body['is_simplified'], FILTER_VALIDATE_BOOLEAN)
                : !empty($existing['is_simplified']);
            $renumberScope = [
                'supplier_id' => $supplierId,
                'old'         => [$oldDate, $oldClient, $oldCategory],
                'new'         => [$newDate, $newClient, $newCategory],
                'old_series'  => VarsymbolGenerator::seriesTypeFor($existing),
                'new_series'  => VarsymbolGenerator::seriesTypeFor([
                    'invoice_type'  => $requestedType,
                    'is_simplified' => $newSimplified,
                ]),
            ];
        } else {
            // Bez přečíslování: typ je u vystavené faktury immutable (číslo + auditní stopa),
            // u draftu lze přepnout mezi invoice/proforma/dobropis (ne na storno/cancellation).
            if ($isIssued || !in_array($requestedType, $draftTypes, true)) {
                $body['invoice_type'] = $existingType;
            }
            // Varsymbol vystavené faktury je immutable bez změny typu (snapshot pro účetní
            // evidenci a PDF); ruční přepis čísla se řeší dobropisem/stornem.
            if ($isIssued) {
                unset($body['varsymbol']);
            }
        }
        // Zjednodušený doklad (§ 30 ZDPH) bez odběratele → systémový koncový zákazník firmy.
        // Příznak se bere z těla, jinak z uloženého dokladu (částečný update ho nemusí poslat).
        $simplified = array_key_exists('is_simplified', $body)
            ? filter_var($body['is_simplified'], FILTER_VALIDATE_BOOLEAN)
            : !empty($existing['is_simplified']);
        $walkInClient = null;
        if (array_key_exists('client_id', $body) && empty($body['client_id']) && $simplified) {
            $walkInClient = $this->clients->prepareWalkInCustomer(SupplierGuard::currentId($request));
            $body['client_id'] = (int) $walkInClient['id'];
        }
        // BOLA guard (security report 2026-08, R2 #1) — cizí klíče z TĚLA requestu musí
        // patřit volajícímu. Nutně PŘED defaults->resolve(): resolve() sice vynucuje
        // project.client_id === client_id a currency.supplier_id === client.supplier_id,
        // ale obojí vyhodnocuje proti DODANÉMU klientovi — konzistentní cizí trojice
        // (client + project + currency ze supplieru B) by mu prošla.
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
        // Nezměněná hodnota projde i tehdy, když hladinu mezitím někdo deaktivoval nebo
        // smazal; jinak by koncept nešel uložit, dokud uživatel hladinu ručně nezruší.
        $currentLevelId = isset($existing['price_level_id']) ? (int) $existing['price_level_id'] : null;
        $unchangedLevel = array_key_exists('price_level_id', $body) && $currentLevelId !== null
            && is_numeric($body['price_level_id']) && (int) $body['price_level_id'] === $currentLevelId;
        if (array_key_exists('price_level_id', $body) && !$unchangedLevel) {
            try {
                $body['price_level_id'] = $this->priceLevels->assignableLevelId(
                    SupplierGuard::currentId($request),
                    $body['price_level_id'],
                    $currentLevelId,
                );
            } catch (StockException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }

        try {
            $body = $this->defaults->resolve($body, resolvedClient: $walkInClient);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        // Derivace OSS pro API klienty — TOTÉŽ místo v pořadí jako u POST (viz
        // {@see DerivesMissingOssColumns}): před validací, protože kontrola „zahraniční
        // sazbu jen na OSS řádku" čte `oss_applicable` a musí posuzovat řádek v podobě,
        // ve které se opravdu uloží.
        //
        // Bez tohohle kroku PUT OSS na dokladu MAZAL: `replaceItems()` je DELETE + INSERT,
        // takže payload bez `oss_*` klíčů vyrobil z OSS řádku tuzemský. Že to nespadlo na
        // validaci, není záruka — guard „zahraniční sazba jen v OSS" stojí na
        // `vat_rates.country`, a ten je u zákazníkovy „PL-23" sazby vyplněný jako CZ.
        $ossNotes = $this->deriveMissingOssColumns(
            $body,
            SupplierGuard::currentId($request),
            $walkInClient !== null && (int) $walkInClient['id'] === 0
                ? \MyInvoice\Service\Oss\OssClientContext::fromArray($walkInClient)
                : null,
        );

        $newRefDate = DocumentLockService::invoiceRefDate($body) ?? DocumentLockService::invoiceRefDate($existing);
        if ($newRefDate !== null) {
            $newLock = $this->locks->forDate((int) $existing['supplier_id'], $newRefDate);
            if ($deny = $this->denyIfLocked($request, $response, $newLock, 'invoice', $id)) {
                return $deny;
            }
        }

        // B11 (audit 2026-07): force-edit dokladu s AKTIVNÍM zaúčtovaným zápisem v UZAVŘENÉM
        // období rozejde doklad × deník (zápis v closed období nelze přepsat). Sem dojde jen
        // správce, uzavřené období ostatním odmítne už denyIfLocked(). Vynucená volba:
        //   reconcile  → doklad se opraví a zápis se stornuje + přeúčtuje do běžného období
        //   notes_only → povolena jen neúčetní pole (poznámky/projekt/jazyk)
        $forceMode = null;
        if ($canEditIssued && $isForce && $lock->inClosedPeriod && $lock->posted) {
            $forceMode = trim((string) ($request->getQueryParams()['force_mode'] ?? $body['force_mode'] ?? ''));
            if (!in_array($forceMode, ['reconcile', 'notes_only'], true)) {
                return Json::error(
                    $response,
                    'force_mode_required',
                    'Doklad má aktivní zaúčtovaný zápis v uzavřeném období. Zvolte force_mode: '
                        . '"reconcile" (doklad se opraví, původní zápis se stornuje a doklad se přeúčtuje do '
                        . 'aktuálního otevřeného období — čísla uzavřeného roku zůstanou nedotčena), nebo '
                        . '"notes_only" (povolí změnu jen neúčetních polí: poznámky, projekt, jazyk).',
                    422,
                );
            }
            if ($forceMode === 'notes_only') {
                $changed = self::financialFieldsChanged($body, $existing);
                if ($changed !== []) {
                    return Json::error(
                        $response,
                        'financial_change_not_allowed',
                        'force_mode="notes_only" povoluje jen neúčetní pole. Účetní/částková/DPH pole ke změně: '
                            . implode(', ', $changed) . '. Pro opravu účetních polí použijte force_mode="reconcile".',
                        422,
                    );
                }
            }
        }
        $repostOpenForceEdit = $canEditIssued
            && (bool) $isForce
            && $lock->posted
            && !$lock->inClosedPeriod
            && self::financialFieldsChanged($body, $existing) !== [];
        // Finanční změna zaúčtovaného dokladu přeúčtuje deník. Role s právem upravit vystavenou
        // fakturu, ale bez práva zaúčtovat, ji neprovede; neúčetní změny projdou. Před
        // přečíslováním a zápisem, ať odmítnutí nic nezmění.
        if (($repostOpenForceEdit || $forceMode === 'reconcile')
            && !RequestAuthorization::canRepostIssuedInvoice($request)) {
            return Json::error(
                $response,
                'journal_post_forbidden',
                'Změna částek, DPH nebo položek zaúčtované faktury přeúčtuje deník. K tomu je potřeba '
                    . 'i oprávnění „Zaúčtovat doklad“; bez něj lze měnit jen neúčetní údaje (texty, poznámky, splatnost).',
                403,
            );
        }

        // Podané DPH období (přiznání, KH, u OSS dokladu i OSS) mění jen správce: delegovaná role
        // s právem upravit vystavenou fakturu v něm nezmění částky, data ani položky, a to ani
        // přesunem data do podaného období. Oprava podaného období jde opravným nebo dodatečným
        // tvrzením. Stejná brána jako import vystaveného dokladu a hromadná změna OSS.
        if ($existing['status'] !== 'draft'
            && $isForce
            && !RequestAuthorization::canChangeFiledVatPeriod($request)
            && self::financialFieldsChanged($body, $existing) !== []
            && ($filed = $this->filedPeriodOf($existing, $body)) !== null) {
            return Json::error(
                $response,
                'period_filed',
                'Období faktury už bylo podáno (' . $filed . '). Částky, data ani položky v něm může změnit '
                    . 'jen správce firmy; opravu podaného období řešte opravným nebo dodatečným tvrzením.',
                409,
            );
        }

        if ($walkInClient !== null && (int) $body['client_id'] === 0) {
            $body['client_id'] = $this->clients->findOrCreateWalkInCustomer(SupplierGuard::currentId($request), prepared: $walkInClient);
        }

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
            $allowRefund ? $this->repo->currencyCode($body['currency_id'] ?? $existing['currency_id'] ?? null) : null,
        );
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Auto-default VAT klasifikace pokud user nezadal (s multi-tenant scope)
        $this->applyVatClassificationDefaults($body, \MyInvoice\Http\SupplierGuard::currentId($request), (array) ($existing['items'] ?? []));

        // § 30 ZDPH: vystavený zjednodušený doklad musí po úpravě splňovat totéž co při
        // vystavení (limit 10 000 Kč s daní, ne v přenesené povinnosti, ne ř. 20/25).
        // Jinak by úprava správcem obešla kontrolu, kterou IssueInvoiceAction hlídá.
        // Před přečíslováním, ať odmítnutí nespálí číslo řady.
        if ($existing['status'] !== 'draft'
            && ($reason = $this->simplifiedRejection($body, $existing)) !== null
        ) {
            return Json::error($response, 'simplified_document_not_allowed', $reason, 422);
        }
        // Drobný zákazník bez adresy (#310): vystavený doklad nesmí úpravou přestat být
        // zjednodušeným ani přejít na takovou kartu jako běžná faktura. Limit hlídá
        // simplifiedRejection() výše, proto se tu částka neposílá. U dobropisu brána
        // ověří vazbu na zjednodušený původní doklad (§ 45 odst. 2 ZDPH).
        if ($existing['status'] !== 'draft') {
            $rejected = $this->taxGate->simplifiedOnlyClientRejection([
                'id'            => (int) $existing['id'],
                'parent_invoice_id' => $existing['parent_invoice_id'] ?? null,
                'client_id'     => (int) ($body['client_id'] ?? $existing['client_id']),
                'supplier_id'   => (int) $existing['supplier_id'],
                'invoice_type'  => (string) ($body['invoice_type'] ?? $existing['invoice_type']),
                'is_simplified' => array_key_exists('is_simplified', $body)
                    ? filter_var($body['is_simplified'], FILTER_VALIDATE_BOOLEAN)
                    : !empty($existing['is_simplified']),
                'issue_date'    => (string) ($body['issue_date'] ?? $existing['issue_date']),
                'tax_date'      => $body['tax_date'] ?? $existing['tax_date'] ?? null,
                'total_with_vat' => 0,
            ]);
            if ($rejected !== null) {
                return Json::error($response, $rejected['code'], $rejected['message'], $rejected['status']);
            }
        }

        // SOUDRŽNOST DOKLADU (§ H1) — táž kontrola jako u importu a u založení dokladu,
        // z téhož SSOT ({@see OssDocumentCoherence}). Počítá se při KAŽDÉM uložení, takže
        // příznak vzniká i zaniká podle toho, jak doklad vypadá teď: opravou sazby rozpor
        // zmizí a tuzemský řádek se odznačí sám (`replaceItems()` je DELETE + INSERT).
        //
        // Jen když tělo položky opravdu poslalo: bezpodmínečné `$body['items'] = $items`
        // dosadilo prázdné pole i do těla, které klíč vůbec nemělo, a `replaceItems()` níž
        // pak doklad vyprázdnil ({@see DocumentItemsPayload}).
        $contradiction = null;
        if (DocumentItemsPayload::replaces($body)) {
            $items = (array) $body['items'];
            $contradiction = OssDocumentCoherence::flagItems($items, $this->repo->vatRateMap());
            $body['items'] = $items;
        }

        // Přečíslování při změně typu: nové číslo se přidělí ve stejné transakci, která
        // ho zapíše na doklad, a staré se uvolní až po úspěšném zápisu. Odmítnutí
        // zápisu vrátí rollback vše do původního stavu.
        // Volba řady z číselníku jde měnit jen na konceptu; vystavený doklad má řadu danou číslem.
        $seriesKind = NumberSeriesKinds::issuedKind((string) ($body['invoice_type'] ?? $existingType));
        if ($isIssued) {
            unset($body['number_series_id']);
        } else {
            try {
                $this->numberSeries->fromBody((int) ($existing['supplier_id'] ?? 0), $body, $seriesKind);
            } catch (NumberSeriesException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            }
        }
        $pdo = $this->db->pdo();
        $renumberTx = false;
        $abortRenumber = static function (): void {};
        if ($renumber !== null) {
            $renumberTx = !$pdo->inTransaction();
            if ($renumberTx) {
                // Viz NumberSeriesGapGuard: zámek počítadla pod REPEATABLE READ by po
                // souběžném vystavení skončil chybou 1020.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
            }
            [$newDate, $newClient, $newCategory] = $renumberScope['new'];
            try {
                // Cílová řada = výchozí řada nového druhu v číselníku; řada vybraná pro původní
                // typ k novému druhu nepatří.
                [$newVs, $newSeriesId] = $this->varsymbol->nextForInvoice($renumberScope['supplier_id'], [
                    'invoice_type'        => $renumber['to_type'],
                    'is_simplified'       => $renumberScope['new_series'] === VarsymbolGenerator::SIMPLIFIED,
                    'client_id'           => $newClient,
                    'revenue_category_id' => $newCategory,
                ], $newDate);
            } catch (\MyInvoice\Service\NumberSeries\NumberSeriesException $e) {
                if ($renumberTx && $pdo->inTransaction()) $pdo->rollBack();
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                if (TransactionFailure::rollbackIfAborted($pdo, $e)) throw $e;
                if ($renumberTx && $pdo->inTransaction()) $pdo->rollBack();
                return Json::internalError($response, 'varsymbol_failed', $e->getMessage(), 500);
            }
            $body['varsymbol'] = $newVs;
            $renumber['to'] = $newVs;
            $abortRenumber = function () use ($pdo, $renumberTx, $renumberScope, $newVs, $newDate, $newClient, $newCategory, $newSeriesId): void {
                if ($renumberTx) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    return;
                }
                $this->varsymbol->releaseIfLatest($renumberScope['supplier_id'], $renumberScope['new_series'], $newVs, $newDate, $newClient, $newCategory, $newSeriesId);
            };
        }
        try {
            // Optimistický zámek (L1): pro klienta UPDATE podmíněný booked_at IS NULL —
            // účetní mohla doklad zaúčtovat mezi guard-checkem a zápisem.
            $requireUnbooked = RequestAuthorization::isClientType($request);
            // Kolize čísla, které poslal volající, je očekávaná (→ 409 níž). Přečíslování
            // generátorem zůstává ERROR — tam by kolize znamenala skutečný souběh.
            $expectedDuplicates = trim((string) ($body['varsymbol'] ?? '')) !== '' ? ['uq_inv_supplier_varsymbol'] : [];
            $updated = \MyInvoice\Infrastructure\Database\DbErrorLogger::expectingDuplicates(
                $expectedDuplicates,
                fn () => $this->repo->updateDraft($id, $body, $requireUnbooked),
            );
            if (!$updated) {
                $abortRenumber();
                return Json::error(
                    $response,
                    'document_locked',
                    'Doklad byl mezitím zaúčtován — změny vyřídí vaše účetní.',
                    409,
                );
            }
        } catch (\InvalidArgumentException $e) {
            $abortRenumber();
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            $abortRenumber();
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }
        if ($renumber !== null) {
            [$oldDate, $oldClient, $oldCategory] = $renumberScope['old'];
            if ($renumberScope['supplier_id'] > 0 && $renumber['from'] !== '') {
                $this->varsymbol->releaseIfLatest($renumberScope['supplier_id'], $renumberScope['old_series'], $renumber['from'], $oldDate, $oldClient, $oldCategory,
                    isset($existing['number_series_id']) ? (int) $existing['number_series_id'] : null);
            }
            $this->varsymbol->recordSeries($id, $existing, $newSeriesId);
            if ($renumberTx) $pdo->commit();
        }
        if (!$isIssued) {
            $this->numberSeries->apply('invoices', (int) ($existing['supplier_id'] ?? 0), $id, $body, $seriesKind);
        }
        try {
            if (DocumentItemsPayload::replaces($body)) {
                $this->repo->replaceItems($id, (array) $body['items']);
            } elseif (($replay = self::storedItemsToReplay($body, $existing)) !== null) {
                $this->repo->replaceItems($id, InvoiceItemTracking::fromStored($replay));
            }
        } catch (\InvalidArgumentException $e) {
            // Neplatná vazba řádku na kartu majetku (1177) — hlavička je už uložená, ale položky
            // zůstaly nedotčené (validace běží před DELETE), takže doklad je konzistentní.
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        // Volba „inkasovat hotově do pokladny" (migrace 1327) — jen když klíč v těle JE,
        // ať částečný PUT volbu tiše nesmaže.
        if (array_key_exists('cash_register_id', $body)) {
            $this->repo->setCashRegisterId(
                $id,
                SupplierGuard::currentId($request),
                ($body['cash_register_id'] ?? null) !== null ? (int) $body['cash_register_id'] : null,
            );
        }
        $this->paymentSchedule->saveFromPayload(\MyInvoice\Http\SupplierGuard::currentId($request), $id, $body);
        $this->calc->recompute($id);

        // Exchange rate logika:
        //   1. User manuálně nastavil kurz v payloadu → uložit (ruční override má prioritu)
        //   2. Vystavená faktura (force-edit) — NIKDY auto-přefetch (klient ji už má)
        //   3. Draft + změna currency NEBO issue_date NEBO tax_date (DUZP) → fetch nový kurz
        //   4. Jinak → kurz beze změny, jen ensureRate pro backfill když chybí
        $wasDraft = $existing['status'] === 'draft';
        $currencyChanged = (int) ($existing['currency_id'] ?? 0) !== (int) ($body['currency_id'] ?? 0);
        $issueDateChanged = (string) ($existing['issue_date'] ?? '') !== (string) ($body['issue_date'] ?? '');
        // Kurz se váže k DUZP (tax_date) — změna DUZP musí vyvolat přefetch stejně jako
        // změna vystavení. Jen když payload tax_date obsahuje (jinak by prázdný payload
        // falešně signalizoval změnu proti uloženému DUZP).
        $taxDateChanged = array_key_exists('tax_date', $body)
            && (string) ($existing['tax_date'] ?? '') !== (string) ($body['tax_date'] ?? '');
        $rateMeta = null;

        $userRate = $body['exchange_rate'] ?? null;
        $userRateProvided = $userRate !== null && $userRate !== '' && is_numeric($userRate) && (float) $userRate > 0;

        if ($userRateProvided) {
            // Manuální override z UI. `rate_date` musí být rozhodný den dokladu (DUZP
            // s fallbackem na vystavení, SSOT ExchangeRateDate) — ne slepě issue_date.
            // Jinak by doklad s DUZP tvrdil, že kurz platí ke dni vystavení, a kontrola
            // odchylky od ČNB by ho porovnávala se špatným dnem.
            $this->repo->setExchangeRate(
                $id,
                (float) $userRate,
                ExchangeRateDate::forInvoice($body) ?? (string) $body['issue_date'],
            );
        } elseif ($wasDraft && ($currencyChanged || $issueDateChanged || $taxDateChanged)) {
            $rateMeta = $this->rateApplier->applyToInvoice($id);
        } else {
            $this->rateApplier->ensureRate($id);
        }

        // Force update vystavené faktury → revenue cache musí přijmout nové total/currency
        $this->stats->recomputeForInvoiceId($id);

        // Přesun faktury na jiného klienta/projekt: recomputeForInvoiceId čte vazbu z už
        // zapsaného řádku, takže přepočte jen nového. Původnímu by v client_revenue_cache
        // zůstal nafouknutý invoice_count/revenue → dopočítej i jeho.
        $prevClientId  = (int) ($existing['client_id'] ?? 0);
        $prevProjectId = (int) ($existing['project_id'] ?? 0);
        $movedClient   = $prevClientId > 0 && array_key_exists('client_id', $body)
            && $prevClientId !== (int) $body['client_id'];
        $movedProject  = $prevProjectId > 0 && array_key_exists('project_id', $body)
            && $prevProjectId !== (int) $body['project_id'];
        if ($movedClient || $movedProject) {
            $this->stats->recomputeForIds(
                $movedClient ? $prevClientId : null,
                $movedProject ? $prevProjectId : null,
            );
        }

        // Force-edit vystavené faktury: přepiš snapshoty z opravených live dat, aby se
        // změny v údajích odběratele/dodavatele/banky promítly do nově generovaného PDF.
        // UI to uživateli avizuje („Změny přepíšou snapshoty"). U draftu se snapshoty
        // nepoužívají (renderer bere live data), takže rebuild řešíme jen pro vystavené.
        if ($existing['status'] !== 'draft') {
            $this->pdf->rebuildSnapshots($id);
        }

        // Invalidate cached PDF — data faktury se změnila, starý soubor je nepoužitelný.
        // Cache freshness check v rendereru zohledňuje jen mtime šablon/CSS, ne dat,
        // takže bez explicit invalidate by se starý PDF dál servíroval.
        // Náhled zápisu v editoru ukládá v transakci, která se odvolá: soubor PDF
        // mimo databázi se mu proto zahazovat nesmí.
        if ($request->getAttribute(\MyInvoice\Action\Accounting\DraftPostingPreviewAction::ATTR) !== true) {
            $this->pdf->invalidate($id, 'invalidate_update');
        }

        // Hotovostní vyrovnání (migrace 1327): forma úhrady „Hotově" + zvolená pokladna →
        // příjmový pokladní doklad; odebraná volba ten dřívější zruší. Na draftu se volba
        // jen uloží (inkasovat se dá až vystavený doklad). Měkká brána — chyba pokladny
        // NESMÍ shodit uložení faktury, jen se ohlásí warningem.
        $settlement = $this->cashSettlement->maybeSettle(
            SupplierGuard::currentId($request),
            'invoice',
            $id,
            isset($user['id']) ? (int) $user['id'] : null,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        $invoice = $this->repo->find($id);
        if (is_array($invoice) && $settlement['status'] !== CashSettlementService::NOOP) {
            $invoice['_cash_settlement'] = $settlement;
        }

        // Audit detail: která pole se opravila (zobrazí se v historii u faktury).
        $changed = self::diffFields($existing, $invoice);
        $payload = $changed !== [] ? ['changed' => $changed] : null;
        // Přečíslování při změně typu (proforma → faktura apod.): zaznamenej staré/nové
        // číslo a řady — uvolnění z původní řady + přidělení v nové je auditně podstatné.
        if ($renumber !== null) {
            $payload = ($payload ?? []) + ['renumber' => $renumber];
        }
        // B11: zvolený režim force-editu (reconcile/notes_only) do auditní stopy.
        if ($forceMode !== null) {
            $payload = ($payload ?? []) + ['force_mode' => $forceMode];
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'invoice.force_updated' : 'invoice.updated';
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));

        // B11 reconcile: po opravě dokladu stornuj původní zápis a přeúčtuj opravený doklad
        // do aktuálního otevřeného období (deník začne sedět na nový doklad; PostingService
        // sám zaloguje accounting.reversed/posted).
        if ($forceMode === 'reconcile') {
            try {
                $reconcile = $this->journalSync->reconcileForceEdit(
                    (int) $existing['supplier_id'],
                    'invoice',
                    $id,
                    ['user_id' => $user['id'] ?? null, 'posted_by' => $user['id'] ?? null,
                     'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent')],
                );
            } catch (PostingException | UnbalancedEntryException $e) {
                $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
                $status = $e instanceof PostingException ? $e->httpStatus : 422;
                return Json::error($response, $code,
                    'Doklad byl opraven, ale přeúčtování deníku selhalo: ' . $e->getMessage()
                        . ' Deník dorovnej ručně (storno + zaúčtování do otevřeného období).',
                    $status);
            }
            if ($reconcile !== null) {
                $invoice['_reconcile'] = $reconcile;
            }
        } elseif ($repostOpenForceEdit) {
            try {
                $repostedEntryId = $this->journalSync->repostForceEdit(
                    (int) $existing['supplier_id'],
                    'invoice',
                    $id,
                    [
                        'entry_date' => (string) ($invoice['tax_date'] ?? $invoice['issue_date']),
                        'document_date' => (string) ($invoice['tax_date'] ?? $invoice['issue_date']),
                        'document_no' => (string) ($invoice['varsymbol'] ?? ''),
                        'user_id' => $user['id'] ?? null,
                        'posted_by' => $user['id'] ?? null,
                        'ip' => $ip,
                        'user_agent' => $request->getHeaderLine('User-Agent'),
                    ],
                );
            } catch (PostingException | UnbalancedEntryException $e) {
                $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
                $status = $e instanceof PostingException ? $e->httpStatus : 422;
                return Json::error(
                    $response,
                    $code,
                    'Doklad byl opraven, ale přeúčtování deníku selhalo: ' . $e->getMessage()
                        . ' Deník dorovnej ručně.',
                    $status,
                );
            }
            if ($repostedEntryId !== null) {
                $invoice['_repost'] = ['entry_id' => $repostedEntryId];
            }
        }

        if ($rateMeta !== null) {
            $invoice['_meta'] = ['exchange_rate' => $rateMeta];
        }
        // §C/K4: účetní kurz na dokladu odchýlen od denního ČNB kurzu k DUZP. NEBLOKUJE
        // (§24/7 pevný kurz legitimní); §73/6 se netýká — jen účetní přepočet 563/663.
        if (is_array($invoice)) {
            // Akumulovat, ne přiřazovat — jinak by poslední zapisovatel přebil ostatní.
            $warnings = InvoiceValidation::warnings($invoice);
            if ($settlement['status'] === CashSettlementService::FAILED) {
                $warnings[] = CashSettlementService::WARNING;
            }
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
            // Poznámky z derivace OSS — shodně s POST. Do `_warnings` jde KÓD, ne věta:
            // pole je smluvně seznam kódů, které si UI překládá (`invoice.warning.<kód>`),
            // takže vložená česká věta by se v editoru zobrazila jako chybějící překlad.
            // Celé znění patří do `_warning_meta`, kam sahá integrátor — a ten je i jediný,
            // kdo se sem dostane (editor OSS sloupce posílá, takže derivace u něj neběží).
            if ($ossNotes !== []) {
                $warnings[] = 'oss_derived_notes';
                $invoice['_warning_meta']['oss_derived_notes'] = ['items' => $ossNotes];
            }
            if ($warnings !== []) {
                $invoice['_warnings'] = $warnings;
            }
        }
        return Json::ok($response, $invoice);
    }

    /**
     * Sloupce hlavičky, které `InvoiceRepository::updateDraft()` zapisuje bezpodmínečně
     * (a `InvoiceDefaults::resolve()` jinak doplní defaulty nového dokladu). Sloupce
     * zapisované jen při přítomnosti klíče (varsymbol, payment_method, rounding_mode,
     * branding_profile_id, price_level_id, is_simplified …) tu nejsou, ty se zachovávají samy.
     */
    private const MERGED_HEADER_FIELDS = [
        'client_id', 'project_id', 'issue_date', 'tax_date', 'due_date', 'currency_id',
        'reverse_charge', 'prices_include_vat', 'language',
        'note_above_items', 'note_below_items', 'supplier_order_number',
        'advance_paid_amount', 'discount_percent', 'vat_classification_code',
        'revenue_category', 'revenue_category_id',
        'income_tax_exempt', 'income_tax_exempt_reason', 'auto_send_reminders',
    ];

    /**
     * @param  array<string,mixed> $body
     * @param  array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private static function mergeStoredHeader(array $body, array $existing): array
    {
        // Nové datum vystavení bez due_date posune splatnost o uloženou lhůtu.
        if (!array_key_exists('due_date', $body) && !empty($body['issue_date'])
            && !empty($existing['issue_date']) && !empty($existing['due_date'])) {
            $newIssue = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $body['issue_date']);
            $oldIssue = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $existing['issue_date']);
            $oldDue   = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $existing['due_date']);
            if ($newIssue !== false && $oldIssue !== false && $oldDue !== false) {
                $days = (int) $oldIssue->diff($oldDue)->format('%r%a');
                $body['due_date'] = $newIssue->modify(sprintf('%+d days', $days))->format('Y-m-d');
            }
        }
        $skip = [];
        // Zakázka patří klientovi — při změně klienta se uložená nepřebírá.
        if (array_key_exists('client_id', $body) && (int) $body['client_id'] !== (int) ($existing['client_id'] ?? 0)) {
            $skip[] = 'project_id';
        }
        // Kategorie tržby (legacy text + id) je jedna hodnota: poslaná půlka ruší i druhou.
        if (array_key_exists('revenue_category', $body) || array_key_exists('revenue_category_id', $body)) {
            $skip[] = 'revenue_category';
            $skip[] = 'revenue_category_id';
        }
        // Klasifikace DPH je odvozená z položek, RC, klienta a DUZP: změní-li se vstup
        // a kód v těle není, dopočítá se znovu (applyVatClassificationDefaults).
        if (self::vatClassificationInputsChanged($body, $existing)) {
            $skip[] = 'vat_classification_code';
        }
        foreach (self::MERGED_HEADER_FIELDS as $col) {
            if (!array_key_exists($col, $body) && array_key_exists($col, $existing) && !in_array($col, $skip, true)) {
                $body[$col] = $existing[$col];
            }
        }
        return $body;
    }

    /**
     * @param  array<string,mixed> $body
     * @param  array<string,mixed> $existing
     */
    private static function vatClassificationInputsChanged(array $body, array $existing): bool
    {
        if (DocumentItemsPayload::replaces($body)
            && DocumentItemsPayload::changed((array) ($existing['items'] ?? []), (array) $body['items'])) {
            return true;
        }
        if (array_key_exists('reverse_charge', $body) && !empty($body['reverse_charge']) !== !empty($existing['reverse_charge'])) {
            return true;
        }
        if (array_key_exists('client_id', $body) && (int) $body['client_id'] !== (int) ($existing['client_id'] ?? 0)) {
            return true;
        }
        return array_key_exists('tax_date', $body)
            && (string) ($body['tax_date'] ?? '') !== (string) ($existing['tax_date'] ?? '');
    }

    /**
     * Částečný PUT bez položek, který mění slevu v hlavičce: slevové řádky se generují jen
     * v `replaceItems()`, takže se uložené položky přehrají znovu s novou slevou.
     *
     * @param  array<string,mixed> $body
     * @param  array<string,mixed> $existing
     * @return list<array<string,mixed>>|null
     */
    private static function storedItemsToReplay(array $body, array $existing): ?array
    {
        if (DocumentItemsPayload::replaces($body)) {
            return null;
        }
        if (round((float) ($body['discount_percent'] ?? 0), 2) === round((float) ($existing['discount_percent'] ?? 0), 2)) {
            return null;
        }
        $items = array_values(array_filter(
            (array) ($existing['items'] ?? []),
            static fn ($it): bool => is_array($it) && ($it['item_kind'] ?? 'standard') !== 'discount',
        ));
        return $items !== [] ? $items : null;
    }

    /**
     * Porovná starou a novou verzi faktury a vrátí sémantické klíče změněných polí
     * (frontend je lokalizuje přes invoice.changed_fields.*). Slouží jako audit detail
     * v activity logu — „co konkrétně se opravilo".
     *
     * @return list<string>
     */
    private static function diffFields(array $old, array $new): array
    {
        // Sloupce porovnávané pro audit. Sufix *_id se v UI klíči zkracuje na čitelný
        // název (client_id → client); ostatní pole se mapují sama na sebe. Drž v sync
        // s editovatelnými sloupci v InvoiceRepository::updateDraft().
        $columns = [
            'client_id', 'currency_id', 'project_id', 'revenue_category_id', 'branding_profile_id',
            'issue_date', 'tax_date', 'due_date', 'varsymbol', 'payment_variable_symbol',
            'invoice_type', 'payment_method', 'rounding_mode', 'supplier_order_number', 'note_above_items', 'note_below_items',
            'discount_percent', 'advance_paid_amount', 'reverse_charge',
            'prices_include_vat', 'vat_classification_code', 'income_tax_exempt', 'language',
            'is_simplified', 'hide_customer_on_pdf', 'price_level_id',
        ];

        $changed = [];
        foreach ($columns as $col) {
            // String cast sjednotí int/float/null/bool porovnání napříč PDO casty.
            if ((string) ($old[$col] ?? '') !== (string) ($new[$col] ?? '')) {
                $changed[] = preg_replace('/_id$/', '', $col); // client_id → client
            }
        }
        if (DocumentItemsPayload::changed((array) ($old['items'] ?? []), (array) ($new['items'] ?? []))) {
            $changed[] = 'items';
        }
        return $changed;
    }

    /**
     * Popis podaného výkazu, který pokrývá původní nebo nové datum plnění faktury, nebo `null`
     * ({@see FiledVatPeriodLookup}). OSS přiznání se počítá jen u faktury s OSS položkou.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $body tělo po sloučení s uloženou hlavičkou
     */
    private function filedPeriodOf(array $existing, array $body): ?string
    {
        $items = [...(array) ($existing['items'] ?? []), ...(DocumentItemsPayload::replaces($body) ? (array) $body['items'] : [])];
        $includeOss = array_filter($items, static fn ($item): bool => !empty($item['oss_applicable'])) !== [];
        $supplierId = (int) $existing['supplier_id'];
        $dates = array_unique(array_filter([
            DocumentLockService::invoiceRefDate($existing),
            DocumentLockService::invoiceRefDate($body),
        ]));
        foreach ($dates as $refDate) {
            if (($filed = $this->filedPeriods->describe($supplierId, $refDate, $includeOss)) !== null) {
                return $filed;
            }
        }

        return null;
    }

    /**
     * B11: seznam ÚČETNÍCH polí, která se v requestu reálně mění proti uloženému dokladu.
     * Porovnává hodnotu (ne pouhou přítomnost) — FE smí poslat celý objekt, blokujeme jen
     * skutečnou změnu částkového/DPH/položkového pole (režim notes_only).
     *
     * @return list<string>
     */
    private static function financialFieldsChanged(array $body, array $existing): array
    {
        $changed = [];
        foreach (self::FINANCIAL_FIELDS as $col) {
            if (array_key_exists($col, $body)
                && (string) ($body[$col] ?? '') !== (string) ($existing[$col] ?? '')
            ) {
                $changed[] = $col;
            }
        }
        // exchange_rate: ruční override kurzu přepíše CZK hodnotu dokladu (PostingService:
        // total_czk = total_with_vat * rate), takže reálná změna kurzu pod notes_only rozejde
        // doklad × deník stejně jako změna currency. Porovnáváme NUMERICKY (ne stringem), aby
        // formátové neshody (25.00 vs 25) legitimní notes_only neblokovaly — jen skutečná změna.
        if (array_key_exists('exchange_rate', $body)
            && is_numeric($body['exchange_rate'])
            && abs((float) $body['exchange_rate'] - (float) ($existing['exchange_rate'] ?? 0)) > 1e-9
        ) {
            $changed[] = 'exchange_rate';
        }
        if (DocumentItemsPayload::replaces($body)
            && DocumentItemsPayload::changed((array) ($existing['items'] ?? []), (array) $body['items'])
        ) {
            $changed[] = 'items';
        }
        return $changed;
    }

    /**
     * Důvod, proč doklad v podobě PO úpravě nemůže být zjednodušeným daňovým dokladem;
     * `null` = může, nebo zjednodušený není. Částka se počítá z položek, které se uloží
     * (z těla, jinak uložené), stejným výpočtem jako {@see InvoiceCalculator::recompute()},
     * protože součty se přepočítají až po zápisu.
     *
     * @param array<string,mixed> $body     tělo po sloučení s uloženou hlavičkou a defaulty
     * @param array<string,mixed> $existing
     */
    private function simplifiedRejection(array $body, array $existing): ?string
    {
        $simplified = array_key_exists('is_simplified', $body)
            ? filter_var($body['is_simplified'], FILTER_VALIDATE_BOOLEAN)
            : !empty($existing['is_simplified']);
        if (!$simplified) {
            return null;
        }

        $rates = $this->repo->vatRateMap();
        $source = DocumentItemsPayload::replaces($body) ? (array) $body['items'] : (array) ($existing['items'] ?? []);
        $source = array_values(array_filter(
            $source,
            static fn ($item): bool => is_array($item)
                && \MyInvoice\Service\Invoice\InvoiceItemKind::of($item) !== \MyInvoice\Service\Invoice\InvoiceItemKind::DISCOUNT,
        ));
        // applyVatClassificationDefaults běží i nad lokální kopií uložených položek,
        // takže preview seskupí řádky stejně jako replaceItems při header-only PUTu.
        $previewBody = $body;
        $previewBody['items'] = $source;
        $this->applyVatClassificationDefaults($previewBody, (int) $existing['supplier_id'], (array) ($existing['items'] ?? []));
        $source = (array) $previewBody['items'];
        $items = [];
        foreach ($source as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item = TimeBilling::normalizeInvoiceItem(InvoiceItemKind::normalize($item));
            $rateId = (int) ($item['vat_rate_id'] ?? 0);
            $item['vat_rate_snapshot'] = $rateId > 0 && isset($rates[$rateId])
                ? $rates[$rateId]
                : (float) ($item['vat_rate_snapshot'] ?? 0);
            $item['quantity'] = (float) ($item['quantity'] ?? 0);
            $item['unit_price_without_vat'] = (float) ($item['unit_price_without_vat'] ?? 0);
            $items[] = $item;
        }
        $discountPercent = (float) ($body['discount_percent'] ?? $existing['discount_percent'] ?? 0);
        $items = array_merge($items, $this->repo->previewHeaderDiscountItems($items, $discountPercent));
        $reverseCharge = !empty($body['reverse_charge']);
        $totals = \MyInvoice\Service\Invoice\InvoiceMath::compute($items, $reverseCharge, !empty($body['prices_include_vat']))['totals'];
        $type = (string) ($body['invoice_type'] ?? $existing['invoice_type'] ?? 'invoice');
        $withVat = $totals['with_vat'] + \MyInvoice\Service\Invoice\InvoiceRounding::adjustment(
            $totals['with_vat'] - (float) ($body['advance_paid_amount'] ?? 0),
            (string) ($body['rounding_mode'] ?? $existing['rounding_mode'] ?? 'auto'),
            (string) ($this->repo->currencyCode($body['currency_id'] ?? $existing['currency_id'] ?? null) ?? ''),
            (string) ($body['payment_method'] ?? $existing['payment_method'] ?? ''),
            $type,
        );

        $code = (string) ($body['vat_classification_code'] ?? $existing['vat_classification_code'] ?? '');
        $lineStmt = $this->db->pdo()->prepare(
            'SELECT dphdp3_line FROM vat_classifications
              WHERE code = ? AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY supplier_id IS NULL LIMIT 1'
        );
        $lineStmt->execute([$code, (int) $existing['supplier_id']]);
        $line = $lineStmt->fetchColumn();

        $taxDate = (string) (($body['tax_date'] ?? null) ?: ($body['issue_date'] ?? $existing['issue_date'] ?? ''));
        $year = (int) substr($taxDate, 0, 4);
        return \MyInvoice\Service\Invoice\SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => $withVat, 'reverse_charge' => $reverseCharge],
            $line === false || $line === null ? null : (string) $line,
            (float) ($this->taxConstants->forYear($year)['simplified_document_limit_with_vat']
                ?? \MyInvoice\Service\Invoice\SimplifiedDocumentPolicy::LIMIT_WITH_VAT),
        );
    }

    /**
     * Auto-default vat_classification_code (sale direction) podle vat_rate na řádcích a header.
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId, array $storedItems = []): void
    {
        // Částečný PUT bez položek a bez kódu (mergeStoredHeader ho nepřevzal, protože se
        // změnil vstup klasifikace) — hlavička se dopočítá z uložených položek.
        $headerItems = !empty($body['items']) ? InvoiceItemKind::amountLines((array) $body['items']) : [];
        if ($headerItems === [] && !array_key_exists('vat_classification_code', $body)) {
            $headerItems = array_values(array_filter(
                $storedItems,
                static fn ($it): bool => is_array($it) && InvoiceItemKind::of($it) === InvoiceItemKind::STANDARD,
            ));
        }
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);
        // Country-aware RC: tuzemský odběratel → §92a (ř.25), zahraniční EU → dodání do JČS (ř.20).
        $customerEuForeign = $reverseCharge
            && (int) ($body['client_id'] ?? 0) > 0
            && $this->repo->clientIsEuForeign((int) $body['client_id']);

        if (!empty($body['items']) && is_array($body['items'])) {
            // Víceúčelový poukaz klasifikaci nenese (MultiPurposeVoucher), stejně jako při založení.
            $annotated = \MyInvoice\Service\Invoice\MultiPurposeVoucher::annotate($this->db->pdo(), $supplierId, $body['items']);
            foreach ($body['items'] as $key => &$item) {
                if (is_array($annotated[$key] ?? null) && \MyInvoice\Service\Invoice\MultiPurposeVoucher::isLine($annotated[$key])) {
                    $item['vat_classification_code'] = null;
                    continue;
                }
                if (!empty($item['vat_classification_code']) || InvoiceItemKind::isText($item)) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                // Měrná jednotka řádku je signál zboží/služba pro RC prodej do EU (ř.20 vs ř.21).
                $units = ((string) ($item['unit'] ?? '') !== '') ? [(string) $item['unit']] : [];
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units);
            }
            unset($item);
        }

        // O kódu hlavičky rozhodují jen plnění; doklad jen z poukazů zůstane bez kódu.
        $headerSupplies = \MyInvoice\Service\Invoice\MultiPurposeVoucher::withoutLines($this->db->pdo(), $supplierId, $headerItems);
        if (empty($body['vat_classification_code']) && $headerItems !== [] && $headerSupplies !== []) {
            $headerItems = $headerSupplies;
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100), 'unit' => (string) ($it['unit'] ?? '')];
            }, $headerItems);
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'sale',
                $body['tax_date'] ?? $body['issue_date'] ?? null,
                $supplierId,
                $customerEuForeign,
            );
        }
    }
}
