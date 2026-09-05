<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
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
use MyInvoice\Service\Invoice\DocumentItemsPayload;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceNoteAlias;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssDocumentCoherence;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceValidation;
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
        'invoice_type', 'payment_method', 'discount_percent', 'advance_paid_amount',
        'reverse_charge', 'prices_include_vat', 'vat_classification_code', 'income_tax_exempt',
        'is_simplified',
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
        $isAdmin = RequestAuthorization::isCompanyAdmin($request);

        $body = (array) ($request->getParsedBody() ?? []);

        // Generický `note` → `note_below_items` (issue #38). Musí být hned tady, ještě nad
        // guardy: force_mode="notes_only" i auditní diff čtou už konkrétní klíč.
        $body = InvoiceNoteAlias::normalize($body);

        // Zámek dokladu (Epic F6, H1) — PŘED status guardem (klient dostane 403
        // document_locked, ne 409 not_editable): kontrola staré I nové refDate —
        // klient nesmí datem do uzavřeného období „utéct" ani ho tam přesunout.
        $lock = $this->locks->forInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', $id)) {
            return $deny;
        }
        $newRefDate = DocumentLockService::invoiceRefDate($body) ?? DocumentLockService::invoiceRefDate($existing);
        if ($newRefDate !== null) {
            $newLock = $this->locks->forDate((int) $existing['supplier_id'], $newRefDate);
            if ($deny = $this->denyIfLocked($request, $response, $newLock, 'invoice', $id)) {
                return $deny;
            }
        }

        if ($existing['status'] !== 'draft') {
            // Pouze admin smí upravovat vystavenou fakturu, a to jen s explicit ?force=1.
            if (!$isAdmin || !$isForce) {
                return Json::error($response, 'not_editable', 'Vystavenou fakturu nelze editovat.', 409);
            }
            // Cancellation/credit_note jsou implicitně chráněné (auditní stopa)
            if (in_array($existing['invoice_type'], ['cancellation'], true)) {
                return Json::error($response, 'not_editable', 'Storno doklad nelze editovat.', 409);
            }
        }

        // Vyprázdnění položek dokladu, který nějaké má → 422 ({@see DocumentItemsPayload}).
        // 422, ne 400: tělo je tvarem v pořádku (`items` JE pole), odmítá se až to, co
        // požadavek znamená proti STAVU tohohle dokladu — táž třída jako `force_mode_required`
        // níž. 400 `validation_failed` je tu vyhrazené pro schéma pole po poli (mapa `fields`).
        // Musí to být PŘED přečíslováním při změně typu: to už sahá na čítač řady, takže
        // pozdější odmítnutí by po sobě nechalo spálené číslo.
        if (DocumentItemsPayload::emptiesExisting($body, (array) ($existing['items'] ?? []))) {
            return Json::error(
                $response,
                DocumentItemsPayload::EMPTY_ERROR_CODE,
                DocumentItemsPayload::emptyErrorMessage(),
                422,
            );
        }

        // B11 (audit 2026-07): force-edit dokladu s AKTIVNÍM zaúčtovaným zápisem v UZAVŘENÉM
        // období rozejde doklad × deník (zápis v closed období nelze přepsat). Vynucená volba:
        //   reconcile  → doklad se opraví a zápis se stornuje + přeúčtuje do běžného období
        //   notes_only → povolena jen neúčetní pole (poznámky/projekt/jazyk)
        $forceMode = null;
        if ($isAdmin && $isForce && $lock->inClosedPeriod && $lock->posted) {
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
        $repostOpenForceEdit = $isAdmin
            && (bool) $isForce
            && $lock->posted
            && !$lock->inClosedPeriod
            && self::financialFieldsChanged($body, $existing) !== [];

        // parent_invoice_id se nikdy nemění při update (vazba dobropisu na původní doklad).
        $body['parent_invoice_id'] = $existing['parent_invoice_id'];

        $validTypes    = ['invoice', 'proforma', 'credit_note'];
        // Kalendář (§ 31) smí měnit typ jen DRAFT: vlastní číselnou řadu nemá, takže
        // přečíslování vystaveného dokladu na kalendář by spadlo na generátoru varsymbolu.
        $draftTypes    = [...$validTypes, 'payment_calendar'];
        $requestedType = (string) ($body['invoice_type'] ?? '');
        $isIssued      = $existing['status'] !== 'draft';
        $existingType  = (string) $existing['invoice_type'];

        // Změna TYPU u VYSTAVENÉ faktury (force-edit, admin) = přečíslování.
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

            // 1) Uvolni staré číslo z původní řady (jen je-li poslední v counteru).
            //    Kategorie tržby je další osa scope counteru (migrace 1333) — uvolňovat
            //    se musí ze STEJNÉ scope, ve které se číslo přidělilo, tedy z té staré.
            $oldDate     = !empty($existing['issue_date']) ? new \DateTimeImmutable((string) $existing['issue_date']) : null;
            $oldClient   = (int) ($existing['client_id'] ?? 0);
            $oldCategory = (int) ($existing['revenue_category_id'] ?? 0);
            if ($supplierId > 0 && $oldVs !== '') {
                $this->varsymbol->releaseIfLatest($supplierId, $existingType, $oldVs, $oldDate, $oldClient, $oldCategory);
            }

            // 2) Přiděl nové číslo v řadě cílového typu (datum/klient/kategorie z payloadu,
            //    fallback na staré). Změna kategorie sama o sobě NENÍ důvod k přečíslování —
            //    tady jen bereme aktuální hodnotu, protože přečíslování už nastalo kvůli
            //    změně typu dokladu.
            $newDate     = !empty($body['issue_date']) ? new \DateTimeImmutable((string) $body['issue_date']) : ($oldDate ?? new \DateTimeImmutable('today'));
            $newClient   = isset($body['client_id']) ? (int) $body['client_id'] : $oldClient;
            $newCategory = array_key_exists('revenue_category_id', $body) ? (int) $body['revenue_category_id'] : $oldCategory;
            try {
                $newVs = $this->varsymbol->next($supplierId, $requestedType, $newDate, $newClient, $newCategory);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                return Json::error($response, 'varsymbol_failed', $e->getMessage(), 500);
            }

            $body['invoice_type'] = $requestedType;
            $body['varsymbol']    = $newVs;
            $renumber = ['from' => $oldVs, 'to' => $newVs, 'from_type' => $existingType, 'to_type' => $requestedType];
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

        try {
            $body = $this->defaults->resolve($body);
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
        $ossNotes = $this->deriveMissingOssColumns($body, SupplierGuard::currentId($request));

        // Tuzemsko se bere ze země DODAVATELE, ne z natvrdo zapsané 'CZ' — táž definice,
        // se kterou pracuje derivace OSS ({@see OssItemDeriver::domesticCountry()}).
        // Dvě různá tuzemska by u dodavatele identifikovaného mimo ČR znamenala, že
        // validace zakáže sazbu, kterou import a výkazy považují za domácí.
        $errors = InvoiceValidation::invoice(
            $body,
            $this->repo->vatRateMap(),
            $this->repo->vatRateCountryMap(),
            $this->ossDeriver->domesticCountry(SupplierGuard::currentId($request)),
        );
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Auto-default VAT klasifikace pokud user nezadal (s multi-tenant scope)
        $this->applyVatClassificationDefaults($body, \MyInvoice\Http\SupplierGuard::currentId($request));

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

        try {
            // Optimistický zámek (L1): pro klienta UPDATE podmíněný booked_at IS NULL —
            // účetní mohla doklad zaúčtovat mezi guard-checkem a zápisem.
            $requireUnbooked = RequestAuthorization::isClientType($request);
            if (!$this->repo->updateDraft($id, $body, $requireUnbooked)) {
                return Json::error(
                    $response,
                    'document_locked',
                    'Doklad byl mezitím zaúčtován — změny vyřídí vaše účetní.',
                    409,
                );
            }
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }
        try {
            if (DocumentItemsPayload::replaces($body)) {
                $this->repo->replaceItems($id, (array) $body['items']);
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
        $this->pdf->invalidate($id, 'invalidate_update');

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
            'issue_date', 'tax_date', 'due_date', 'varsymbol',
            'invoice_type', 'payment_method', 'supplier_order_number', 'note_above_items', 'note_below_items',
            'discount_percent', 'advance_paid_amount', 'reverse_charge',
            'prices_include_vat', 'vat_classification_code', 'income_tax_exempt', 'language',
            'is_simplified',
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
     * Auto-default vat_classification_code (sale direction) podle vat_rate na řádcích a header.
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);
        // Country-aware RC: tuzemský odběratel → §92a (ř.25), zahraniční EU → dodání do JČS (ř.20).
        $customerEuForeign = $reverseCharge
            && (int) ($body['client_id'] ?? 0) > 0
            && $this->repo->clientIsEuForeign((int) $body['client_id']);

        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as &$item) {
                if (!empty($item['vat_classification_code'])) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                // Měrná jednotka řádku je signál zboží/služba pro RC prodej do EU (ř.20 vs ř.21).
                $units = ((string) ($item['unit'] ?? '') !== '') ? [(string) $item['unit']] : [];
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units);
            }
            unset($item);
        }

        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100), 'unit' => (string) ($it['unit'] ?? '')];
            }, (array) $body['items']);
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
