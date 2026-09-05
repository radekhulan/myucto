<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use MyInvoice\Service\Currency\PurchaseInvoiceRateReloader;
use MyInvoice\Service\Invoice\DocumentItemsPayload;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use MyInvoice\Service\Ai\AiSuggestionService;
use MyInvoice\Support\ExchangeRateDate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}
 *
 * Update přijaté faktury. Standardně lze editovat pouze draft.
 * Admin může s `?force=1` upravit i received / booked / paid — cancelled zůstává immutable
 * (storná jsou součástí auditní stopy a nemají se editovat).
 */
final class UpdatePurchaseInvoiceAction
{
    use HandlesVarsymbolDuplicate;
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
        private readonly DocumentJournalSync $journalSync,
        private readonly Connection $db,
        private readonly AiSuggestionService $aiSuggestions,
        private readonly SmallAssetService $smallAssets,
        private readonly CnbRateDeviationChecker $rateChecker,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly PurchaseInvoiceRateReloader $rateReloader,
        private readonly CashSettlementService $cashSettlement,
    ) {}

    /**
     * Účetní/amountová/DPH pole přijaté faktury (audit 2026-07 B11) — jejich změna ve
     * force-editu zaúčtované PF v uzavřeném období vyžaduje force_mode='reconcile'.
     * notes_only povoluje jen zbytek (poznámky, jazyk).
     */
    private const FINANCIAL_FIELDS = [
        'vendor_id', 'vendor_invoice_number', 'document_kind',
        'issue_date', 'tax_date', 'due_date', 'received_at', 'currency_id',
        'reverse_charge', 'prices_include_vat', 'advance_paid_amount',
        'vat_classification_code', 'vat_deduction', 'vat_deduction_percent',
        'tax_deductible', 'is_fixed_asset', 'expense_category_id', 'varsymbol',
        // Zakázka (issue #29) je analytická dimenze zaúčtovaného nákladu — její změna
        // přepisuje journal_entry_lines.project_id, takže do notes_only nepatří.
        'project_id',
        // Volba hotovostního vyrovnání (migrace 1327) zakládá/ruší ZAÚČTOVANÝ pokladní
        // doklad, takže je to účetní pole jako každé jiné — notes_only ji nesmí pustit.
        'cash_register_id',
    ];

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = RequestAuthorization::isCompanyAdmin($request);
        $isForce = !empty($request->getQueryParams()['force']);

        $body = (array) ($request->getParsedBody() ?? []);

        // Zámek dokladu (Epic F6, H1) — PŘED status guardem (klient dostane 403
        // document_locked, ne 409 not_editable): kontrola staré I nové refDate —
        // klient nesmí datem do uzavřeného období „utéct" ani ho tam přesunout.
        $lock = $this->locks->forPurchaseInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id)) {
            return $deny;
        }
        $newRefDate = DocumentLockService::purchaseRefDate($body) ?? DocumentLockService::purchaseRefDate($existing);
        if ($newRefDate !== null) {
            $newLock = $this->locks->forDate($supplierId, $newRefDate);
            if ($deny = $this->denyIfLocked($request, $response, $newLock, 'purchase_invoice', $id)) {
                return $deny;
            }
        }

        if ($existing['status'] !== 'draft') {
            // Force-update: admin smí upravit received / booked / paid (s ?force=1).
            // cancelled zůstává immutable (storno = auditní stopa, nemá se editovat).
            if (!$isAdmin || !$isForce || $existing['status'] === 'cancelled') {
                return Json::error($response, 'not_editable',
                    "Faktura ve stavu '{$existing['status']}' nelze upravit. Admin může upravit received/booked/paid s ?force=1.",
                    409);
            }
        }

        // B11 (audit 2026-07): force-edit PF s AKTIVNÍM zaúčtovaným zápisem v UZAVŘENÉM
        // období rozejde doklad × deník — vynucený force_mode (reconcile | notes_only).
        $forceMode = null;
        if ($isAdmin && $isForce && $lock->inClosedPeriod && $lock->posted) {
            $forceMode = trim((string) ($request->getQueryParams()['force_mode'] ?? $body['force_mode'] ?? ''));
            if (!in_array($forceMode, ['reconcile', 'notes_only'], true)) {
                return Json::error(
                    $response,
                    'force_mode_required',
                    'Přijatá faktura má aktivní zaúčtovaný zápis v uzavřeném období. Zvolte force_mode: '
                        . '"reconcile" (doklad se opraví, původní zápis se stornuje a doklad se přeúčtuje do '
                        . 'aktuálního otevřeného období), nebo "notes_only" (jen neúčetní pole: poznámky, jazyk).',
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
        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vyprázdnění položek dokladu, který nějaké má → 422 ({@see DocumentItemsPayload}).
        // 422, ne 400: tělo je tvarem v pořádku (`items` JE pole), odmítá se až to, co
        // požadavek znamená proti STAVU tohohle dokladu — táž třída jako `force_mode_required`
        // a `financial_change_not_allowed` níž. 400 `validation_failed` je v téhle codebase
        // vyhrazené pro schéma pole po poli (nese mapu `fields`).
        if (DocumentItemsPayload::emptiesExisting($body, (array) ($existing['items'] ?? []))) {
            return Json::error(
                $response,
                DocumentItemsPayload::EMPTY_ERROR_CODE,
                DocumentItemsPayload::emptyErrorMessage(),
                422,
            );
        }

        // BOLA guard (security report 2026-08, R2 #5 / sweep F5) — vendor_id má vlastní
        // kontrolu hned pod tím, zbylé tři FK z těla se dosud zapisovaly nevázané
        // (PurchaseInvoiceValidation kontroluje jen `> 0`).
        $badRefs = $this->tenantRefs->violations(
            $supplierId,
            $body,
            ['expense_category_id', 'currency_id', 'payment_currency_id', 'cash_register_id', 'project_id'],
        );
        if ($badRefs !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
        }

        // Vendor scope check — pokud se mění vendor, musí patřit tenantovi
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        // Dodavatel neplátce DPH → bez nároku na odpočet. Default 'none' když neposláno;
        // explicitní volbu respektujeme (vědomý override), ale níže přidáme varování.
        // Plátcovství bereme ze snapshotu k datu plnění (`vendor_is_vat_payer` z těla, migrace
        // 0133) — ne z živého flagu klienta, aby u historické faktury šlo dodavatele označit
        // za plátce, i když dnes plátce není. Fallback na živý flag jen když snapshot chybí.
        $vendorIsPayer = array_key_exists('vendor_is_vat_payer', $body)
            ? (bool) $body['vendor_is_vat_payer']
            : (isset($vendor['is_vat_payer']) ? (bool) $vendor['is_vat_payer'] : true);
        $vendorNonPayer = !$vendorIsPayer;
        if ($vendorNonPayer && !array_key_exists('vat_deduction', $body)) {
            $body['vat_deduction'] = 'none';
        }

        // Auto-default VAT klasifikace pokud uživatel nezadal — na header i items (s multi-tenant scope).
        // Klasifikaci DPH doplní až SSOT při ukládání řádků + syncHeaderClassificationFromItems()
        // po uložení — viz CreatePurchaseInvoiceAction.

        // C6 (§ 73/1/a) — issue #9: na 'manual' překlápíme jen SKUTEČNOU změnu data přijetí.
        //
        // Editor posílá `received_at` v KAŽDÉM uložení, i když na pole nikdo nesáhl. Původní
        // `array_key_exists` proto označilo za „vědomé zadání účetní" i pouhé přeuložení AI
        // vytěženého dokladu (kde je received_at jen otisk dne skenování) — a tenhle otisk
        // pak přes VatLedgerService tiše převzal řízení období odpočtu. Doklad, který uživatel
        // otevřel a uložil, tak skončil v jiném období DPH než jeho neupravené dvojče se
        // stejnými daty. To je ta nekonzistence, ne § 73: pravidlo pro odvození období musí
        // platit stejně pro upravený i neupravený doklad a rozhodovat smí jen to, zda účetní
        // datum přijetí opravdu zadala.
        if (array_key_exists('received_at', $body)) {
            if (self::dateOnly($body['received_at']) !== self::dateOnly($existing['received_at'] ?? null)) {
                $body['received_at_source'] = 'manual';
            } else {
                // Beze změny → sloupec vůbec nepřepisovat (repo ho bez klíče nechá být),
                // ať se dřívější vědomé 'manual' nedegraduje ani otisk 'import' nepovýší.
                unset($body['received_at_source']);
            }
        }

        // Forma úhrady (migrace 1128): přišla-li z formuláře, je to vědomá volba účetní →
        // zdroj vždy 'manual', nikdy ne to, co poslal klient. Ruční volba pak přebije
        // předvolbu dodavatele i AI a už ji nic automatického nepřepíše.
        if (array_key_exists('payment_method', $body)) {
            $body['payment_method_source'] = 'manual';
        }

        // Vazba dobropisu na opravovanou fakturu (migrace 1096) — validace tenanta/druhu/self.
        if (array_key_exists('parent_purchase_invoice_id', $body)) {
            $body['parent_purchase_invoice_id'] = CreatePurchaseInvoiceAction::sanitizeParentLink(
                $this->db, $body['parent_purchase_invoice_id'] ?? null,
                (string) ($body['document_kind'] ?? ''), $supplierId, $id,
            );
        }

        // Přenačtení kurzu po změně rozhodného dne / měny (migrace 1303). VĚDOMĚ PŘED
        // beginTransaction(): resolve sahá na ČNB (síť/cache), a síťové volání se nesmí
        // dostat dovnitř zápisové transakce. Výsledek se vloží do těla, takže ho zapíše
        // existující updateDraft() jedním atomickým UPDATE.
        $rateDecision = $this->rateReloader->resolveForUpdate($supplierId, $existing, $body);
        foreach ($rateDecision['apply'] as $col => $value) {
            $body[$col] = $value;
        }

        // B11 / open-period repost: kurz mění korunovou hodnotu zápisu stejně jako změna
        // částky. `financialFieldsChanged()` ho ale nevidí — porovnává REQUEST proti DB
        // a kurz do requestu doplnil až server, takže bez `rate_will_change` by deníku
        // zůstaly staré korunové částky.
        $repostOpenForceEdit = $isAdmin
            && (bool) $isForce
            && $lock->posted
            && !$lock->inClosedPeriod
            && (self::financialFieldsChanged($body, $existing) !== [] || $rateDecision['rate_will_change']);

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            // Optimistický zámek (L1): pro klienta UPDATE podmíněný booked_at IS NULL —
            // účetní mohla doklad zaúčtovat mezi guard-checkem a zápisem.
            $requireUnbooked = RequestAuthorization::isClientType($request);
            if (!$this->repo->updateDraft($id, $body, $supplierId, $requireUnbooked)) {
                if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return Json::error(
                    $response,
                    'document_locked',
                    'Doklad byl mezitím zaúčtován — změny vyřídí vaše účetní.',
                    409,
                );
            }
            // Jen když tělo položky opravdu poslalo — jinak by částečný PUT (samotné DUZP,
            // poznámka) doklad vyprázdnil ({@see DocumentItemsPayload}).
            if (DocumentItemsPayload::replaces($body)) {
                $this->repo->replaceItems($id, (array) $body['items']);
            }
            // Volba „uhradit hotově z pokladny" (migrace 1327) — jen když klíč v těle JE,
            // ať částečný PUT (samotné DUZP, poznámka) volbu tiše nesmaže.
            if (array_key_exists('cash_register_id', $body)) {
                $this->repo->setCashRegisterId(
                    $id,
                    $supplierId,
                    ($body['cash_register_id'] ?? null) !== null ? (int) $body['cash_register_id'] : null,
                );
            }
            // Ruční rekapitulace DPH dle dokladu (§ 73) — uložit PŘED recompute, aby ji
            // kalkulátor zapekl do řádkových totálů.
            if (array_key_exists('vat_overrides', $body)) {
                $this->repo->setVatOverrides($id, $supplierId, is_array($body['vat_overrides']) ? $body['vat_overrides'] : null);
            }
            $this->calc->recompute($id);
            // Hlavičková klasifikace se přebírá z řádků (SSOT je defaultClassificationCode()).
            // Až PO recompute — volba dominantního kódu váží podle total_with_vat, které
            // kalkulátor dopočítává.
            $this->repo->syncHeaderClassificationFromItems($id, $supplierId);
            if (array_key_exists('rounding', $body)) {
                $this->repo->setRounding($id, $supplierId, (float) $body['rounding']);
            }
            if (array_key_exists('vat_allocations', $body)) {
                $this->repo->replaceVatAllocations(
                    $id,
                    $supplierId,
                    is_array($body['vat_allocations']) ? $body['vat_allocations'] : [],
                );
            }
            $this->repo->reprefixVarsymbol($id, $supplierId);
            if ($ownTransaction) $pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $code = str_contains($e->getMessage(), 'alokac') ? 'invalid_vat_allocations' : 'integrity_violation';
            return Json::error($response, $code, $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            // Ruční interní číslo koliduje s existujícím (uq_pi_supplier_varsymbol) → 409.
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            // Přesný duplikát PF: stejný dodavatel + číslo dokladu + datum (uq_pi_vendor_invoice) → 409.
            if ($dupMsg = self::vendorInvoiceDuplicateMessage($e, $body['vendor_invoice_number'] ?? null)) {
                return Json::error($response, 'vendor_invoice_duplicate', $dupMsg, 409);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'purchase_invoice.force_updated' : 'purchase_invoice.updated';
        // B11: zvolený režim force-editu do auditní stopy.
        $auditPayload = $forceMode !== null ? ['force_mode' => $forceMode] : null;
        $this->logger->log($action, $user['id'] ?? null, 'purchase_invoice', $id, $auditPayload, $ip, $request->getHeaderLine('User-Agent'));
        // Kurz přepsal SERVER, ne uživatel — do auditní stopy patří samostatně, ať je
        // v historii dokladu vidět, proč se změnila korunová hodnota.
        if (in_array($rateDecision['reason'], [
            PurchaseInvoiceRateReloader::REASON_RELOADED,
            PurchaseInvoiceRateReloader::REASON_CZK_RESET,
        ], true)) {
            $this->logger->log(
                'purchase_invoice.exchange_rate_reloaded',
                $user['id'] ?? null,
                'purchase_invoice',
                $id,
                [
                    'reason' => $rateDecision['reason'],
                    'previous_rate' => $rateDecision['kept_rate'],
                    'previous_rate_date' => $rateDecision['kept_rate_date'],
                    'previous_source' => $rateDecision['kept_source'],
                    'rate' => $rateDecision['apply']['exchange_rate'] ?? null,
                    'rate_date' => $rateDecision['apply']['exchange_rate_date'] ?? null,
                    'source' => $rateDecision['apply']['exchange_rate_source'] ?? null,
                ],
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        }
        if ($existing['status'] === 'draft') {
            try {
                $this->aiSuggestions->invalidatePurchase($supplierId, $id);
                $this->aiSuggestions->enqueuePurchase($supplierId, $id);
            } catch (\Throwable) {
            }
        }

        // B11 reconcile: stornuj původní zápis a přeúčtuj opravenou PF do otevřeného období.
        $reconcile = null;
        $repostedEntryId = null;
        if ($forceMode === 'reconcile') {
            try {
                $reconcile = $this->journalSync->reconcileForceEdit($supplierId, 'purchase_invoice', $id, [
                    'user_id' => $user['id'] ?? null, 'posted_by' => $user['id'] ?? null,
                    'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent'),
                ]);
            } catch (PostingException | UnbalancedEntryException $e) {
                $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
                $status = $e instanceof PostingException ? $e->httpStatus : 422;
                return Json::error($response, $code,
                    'Doklad byl opraven, ale přeúčtování deníku selhalo: ' . $e->getMessage()
                        . ' Deník dorovnej ručně (storno + zaúčtování do otevřeného období).',
                    $status);
            }
        } elseif ($repostOpenForceEdit) {
            $updated = $this->repo->find($id, $supplierId);
            try {
                $repostedEntryId = $this->journalSync->repostForceEdit($supplierId, 'purchase_invoice', $id, [
                    'entry_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_date' => (string) ($updated['tax_date'] ?? $updated['issue_date']),
                    'document_no' => (string) ($updated['varsymbol'] ?? ''),
                    'user_id' => $user['id'] ?? null, 'posted_by' => $user['id'] ?? null,
                    'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent'),
                ]);
            } catch (PostingException | UnbalancedEntryException $e) {
                $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
                $status = $e instanceof PostingException ? $e->httpStatus : 422;
                return Json::error($response, $code,
                    'Doklad byl opraven, ale přeúčtování deníku selhalo: ' . $e->getMessage()
                        . ' Deník dorovnej ručně.',
                    $status);
            }
        }

        // Sesynchronizuj evidenci drobného majetku s aktuálními položkami. Bez toho se
        // změna klasifikace (uživatel označí položku jako majetek) do evidence nepromítne
        // — karta by nevznikla, dokud někdo ručně nespustí generování. Draft se přeskakuje:
        // rozpracovaný doklad ještě není pořízení, karty by vznikaly a mizely při každém
        // rozmyšlení. Nesmí shodit uložení faktury — chyba evidence je vedlejší.
        // Klasifikaci udělanou v draftu dožene přechod draft→received
        // (TransitionPurchaseInvoiceStatusAction), aby se neztratila.
        if (($existing['status'] ?? '') !== 'draft') {
            try {
                $this->smallAssets->syncFromPurchaseInvoice($supplierId, $id, $user['id'] ?? null);
            } catch (\Throwable $e) {
                // Uložení faktury shodit nesmí, ale tiché spolknutí znamenalo, že o
                // nezaložené kartě nevěděl nikdo — ani uživatel, ani audit.
                $this->logger->log('purchase_invoice.small_asset_sync_failed', $user['id'] ?? null,
                    'purchase_invoice', $id, ['error' => $e->getMessage()],
                    $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        // Hotovostní vyrovnání (migrace 1327): je-li forma úhrady „Hotově" a je zvolená
        // pokladna, vznikne (nebo se zaktualizuje) výdajový pokladní doklad; odebraná
        // volba ten dřívější zruší. Měkká brána — chyba pokladny NESMÍ shodit uložení
        // faktury, jen se ohlásí warningem (stejně jako auto-post).
        $settlement = $this->cashSettlement->maybeSettle(
            $supplierId,
            'purchase_invoice',
            $id,
            isset($user['id']) ? (int) $user['id'] : null,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice !== null && $settlement['status'] !== CashSettlementService::NOOP) {
            $invoice['_cash_settlement'] = $settlement;
        }
        if ($reconcile !== null && $invoice !== null) {
            $invoice['_reconcile'] = $reconcile;
        }
        if ($repostedEntryId !== null && $invoice !== null) {
            $invoice['_repost'] = ['entry_id' => $repostedEntryId];
        }
        if ($rateDecision['meta'] !== null && $invoice !== null) {
            $invoice['_meta']['exchange_rate'] = $rateDecision['meta'];
        }
        // Non-blocking varování (např. dobropis s kladným součtem — viz issue #35).
        $warnings = PurchaseInvoiceValidation::warnings($invoice ?? []);
        if ($settlement['status'] === CashSettlementService::FAILED) {
            $warnings[] = CashSettlementService::WARNING;
        }
        // Neplátce + přesto uplatněn odpočet → upozorni (uživatel vědomě přepsal).
        // VÝJIMKA reverse charge (zahraniční služba/zboží): dodavatel je z pohledu české
        // DPH neplátce ZE SVÉ PODSTATY (nefakturuje českou DPH), ale příjemce si daň
        // samovyměří a smí ji odečíst (§ 72/73) — varování by tu bylo false positive.
        if ($vendorNonPayer && !PurchaseInvoiceValidation::isReverseCharge($invoice) && ($invoice['vat_deduction'] ?? 'full') !== 'none') {
            $warnings[] = 'vendor_non_payer_deduction';
        }
        // Rozhodný den nebo měna se změnily, ale kurz zůstal starý — buď ho drží silnější
        // zdroj (uživatel / import / historický zápis neznámého původu), nebo se nepovedlo
        // sáhnout na ČNB. U legacy 'manual' dat je tohle jediná viditelná část featury.
        if (is_array($invoice) && $rateDecision['blocked']) {
            $warnings[] = 'exchange_rate_not_reloaded';
            $invoice['_warning_meta']['exchange_rate_not_reloaded'] = [
                'reason' => $rateDecision['reason'],
                'rate' => $rateDecision['kept_rate'],
                'rate_date' => $rateDecision['kept_rate_date'],
                'source' => $rateDecision['kept_source'],
            ];
        }
        // §C/K4: účetní kurz na dokladu odchýlen od denního ČNB kurzu k rozhodnému dni.
        // NEBLOKUJE (§24/7 pevný kurz legitimní); §73/6 se netýká — jen účetní přepočet.
        // Rozhodný den bere ze SSOT (ExchangeRateDate), ne z `effective_cost_date` —
        // to je GREATEST(...) pro uznání nákladu (migrace 1010) a u dokladu s DUZP dřív
        // než vystavení by se ČNB ptalo na jiný den → falešná odchylka.
        if (is_array($invoice)) {
            $dev = $this->rateChecker->deviationWarning(
                $supplierId,
                (string) ($invoice['currency'] ?? ''),
                ExchangeRateDate::forPurchase($invoice),
                ($invoice['exchange_rate'] ?? null) !== null ? (float) $invoice['exchange_rate'] : null,
            );
            if ($dev !== null) {
                $warnings[] = 'exchange_rate_cnb_deviation';
                $invoice['_warning_meta']['exchange_rate_cnb_deviation'] = $dev;
            }
        }
        // Finální faktura na zálohu s DDKP → DPH ze zálohy už odečtena (dvojí odpočet).
        if (is_array($invoice) && (string) ($invoice['document_kind'] ?? 'invoice') === 'invoice'
            && CreatePurchaseInvoiceAction::advanceHasActiveTaxDocument($this->db, $invoice['advance_purchase_invoice_id'] ?? null, $supplierId)) {
            $warnings[] = 'advance_has_tax_document';
        }
        // Účtenka, která vypadá jako doklad k přijaté záloze → nejspíš má být DDKP/záloha.
        if (is_array($invoice)
            && CreatePurchaseInvoiceAction::receiptLooksLikePrepayment($this->db, $invoice['id'] ?? null, (string) ($invoice['document_kind'] ?? ''))) {
            $warnings[] = 'receipt_looks_like_prepayment';
        }
        if (!empty($warnings)) {
            $invoice['_warnings'] = $warnings;
        }
        return Json::ok($response, $invoice);
    }

    /**
     * Datum na tvar `YYYY-MM-DD` pro porovnání „změnil uživatel pole?". DB vrací DATE,
     * klient posílá string z `<input type="date">`, ale přes API může dorazit i DATETIME
     * nebo prázdno — bez normalizace by se tvarový rozdíl vydával za změnu hodnoty.
     */
    private static function dateOnly(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : substr($s, 0, 10);
    }

    /**
     * B11: účetní pole PF, která se v requestu reálně mění proti uloženému dokladu.
     * Porovnává hodnotu (ne přítomnost) — a to včetně POLOŽEK ({@see DocumentItemsPayload::changed()}).
     * Ruční rekapitulace DPH (`vat_overrides`) a alokace (`vat_allocations`) zůstávají
     * účetní změnou už svou přítomností: jsou to vědomé ruční zásahy, které přijdou jen
     * tehdy, když s nimi někdo hýbe, takže konzervativní čtení tu nic neblokuje.
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
        // exchange_rate: updateDraft() zapisuje kurz z body při každém update a PostingService
        // z něj počítá CZK (total_with_vat * rate). Reálná změna kurzu pod notes_only proto
        // rozejde doklad × deník stejně jako změna currency. Porovnáváme NUMERICKY, aby
        // formátové neshody (25.00 vs 25) legitimní notes_only neblokovaly.
        if (array_key_exists('exchange_rate', $body)
            && is_numeric($body['exchange_rate'])
            && abs((float) $body['exchange_rate'] - (float) ($existing['exchange_rate'] ?? 0)) > 1e-9
        ) {
            $changed[] = 'exchange_rate';
        }
        // Položky se porovnávají OBSAHEM, ne přítomností klíče. Dřív tu stálo pouhé
        // `array_key_exists`, takže editor — který položky posílá vždycky — vyráběl
        // „finanční pole se změnila" u každého force-editu, i když se nezměnilo nic.
        if (DocumentItemsPayload::replaces($body)
            && DocumentItemsPayload::changed((array) ($existing['items'] ?? []), (array) $body['items'])
        ) {
            $changed[] = 'items';
        }
        if (array_key_exists('vat_overrides', $body)) {
            $changed[] = 'vat_overrides';
        }
        if (array_key_exists('vat_allocations', $body)) {
            $changed[] = 'vat_allocations';
        }
        if (array_key_exists('rounding', $body)
            && (string) ($body['rounding'] ?? '') !== (string) ($existing['rounding'] ?? '')
        ) {
            $changed[] = 'rounding';
        }
        return $changed;
    }

}
