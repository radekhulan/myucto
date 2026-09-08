<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\DbErrorLogger;
use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionCompletionService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use MyInvoice\Service\Ai\AiSuggestionService;
use MyInvoice\Support\AdvanceTaxDocumentText;
use MyInvoice\Support\ExchangeRateDate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices
 *
 * Vytvoří draft přijaté faktury + insertne items + přepočte sumy.
 * Vendor musí existovat a patřit aktuálnímu tenantovi.
 */
final class CreatePurchaseInvoiceAction
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
        private readonly CrpDphClient $crpdph,
        private readonly Connection $db,
        private readonly AiSuggestionService $aiSuggestions,
        private readonly CnbRateDeviationChecker $rateChecker,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly PurchaseInvoiceSubmissionCompletionService $submissionCompletion,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $submissionId = max(0, (int) ($body['submission_id'] ?? 0));
        if ($submissionId > 0) {
            if (RequestAuthorization::isClientType($request)
                || !RequestAuthorization::allows($request, 'documents.inbox', AccessLevel::WRITE)) {
                return Json::error(
                    $response,
                    'forbidden',
                    'Podání může na přijatou fakturu převést pouze účetní.',
                    403,
                );
            }
            $submission = $this->submissions->find($submissionId, $supplierId);
            if ($submission === null) {
                return Json::error($response, 'submission_not_found', 'Příchozí podání nebylo nalezeno.', 404);
            }
            if ((string) $submission['status'] !== 'submitted') {
                return Json::error($response, 'invalid_submission_status', 'Podání už nelze ručně zpracovat.', 409);
            }
        }

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // BOLA guard (security report 2026-08, R2 #5 / sweep F5) — vendor_id se váže hned
        // níž vlastní kontrolou (potřebuje i řádek klienta kvůli markAsVendor/DIČ), zbylé
        // tři FK z těla se dosud zapisovaly nevázané. PurchaseInvoiceValidation kontroluje
        // jen `> 0`.
        $badRefs = $this->tenantRefs->violations(
            $supplierId,
            $body,
            ['expense_category_id', 'currency_id', 'payment_currency_id', 'cash_register_id', 'project_id'],
        );
        if ($badRefs !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
        }

        // Vendor musí existovat a patřit tenantovi (anti-cross-tenant injection)
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }


        // Dodavatel neplátce DPH → odpočet nelze uplatnit. Když volající vat_deduction
        // explicitně neposlal, vynutíme 'none' (bezpečný default); když zvolil jinak,
        // respektujeme to (vědomý override v editoru), ale níže přidáme varování.
        // Plátcovství bereme ze snapshotu k datu plnění (`vendor_is_vat_payer` z těla, migrace
        // 0133) — ne z živého flagu klienta, aby historická faktura zůstala daňově správně
        // i když dodavatel dnes plátce už není. Fallback na živý flag jen když snapshot chybí.
        $vendorIsPayer = array_key_exists('vendor_is_vat_payer', $body)
            ? (bool) $body['vendor_is_vat_payer']
            : (isset($vendor['is_vat_payer']) ? (bool) $vendor['is_vat_payer'] : true);
        $vendorNonPayer = !$vendorIsPayer;
        if ($vendorNonPayer && !array_key_exists('vat_deduction', $body)) {
            $body['vat_deduction'] = 'none';
        }

        // Nespolehlivý plátce (CRPDPH, §109 ZDPH ručení za DPH) — audit 2026-07,
        // nález "Nespolehlivý plátce a zveřejněný účet se kontroluje až v platebním
        // příkazu, ne při pořízení FP". Dosud kontrolováno jen ve flow platebních
        // příkazů (PaymentOrderService); doplněno i sem, ať to uvidí i firmy platící
        // mimo modul příkazů. CrpDphClient má 24h cache a je fail-safe (síťová chyba
        // / nenakonfigurovaný endpoint → tiché 'error', žádná výjimka, uložení neblokuje).
        $vendorUnreliablePayer = false;
        $vendorDicDigits = preg_replace('/\D/', '', (string) ($vendor['dic'] ?? '')) ?? '';
        if (preg_match('/^\d{8,10}$/', $vendorDicDigits) === 1) {
            $crpResult = $this->crpdph->lookup($vendorDicDigits);
            $vendorUnreliablePayer = ($crpResult['unreliable'] ?? null) === true;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // H1: datum nového dokladu nesmí spadat do uzavřeného období (client 403, účetní 409).
        $refDate = DocumentLockService::purchaseRefDate($body);
        if ($refDate !== null) {
            $lock = $this->locks->forDate($supplierId, $refDate);
            if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', null)) {
                return $deny;
            }
        }

        // Klasifikaci DPH tady ZÁMĚRNĚ nedoplňujeme. Rozhoduje jediný country-aware SSOT
        // {@see PurchaseInvoiceRepository::defaultClassificationCode()} při ukládání řádků,
        // který zná zemi dodavatele, plátcovství tenanta i povahu plnění; hlavičku z řádků
        // převezme syncHeaderClassificationFromItems() po uložení. Dřív tu předsazený
        // VatClassificationDefaulter kód dosadil dřív, než se SSOT vůbec dostal ke slovu.

        // C6 (§ 73/1/a): ruční zadání data přijetí ve formuláři je vědomý úkon účetní
        // (i default dnešek z UI), ne slepý otisk importu → 'manual'. VatLedgerService pak
        // smí uplatnit odpočet dle skutečného držení dokladu. Importy zůstávají 'import'.
        // NEPRÁZDNÁ hodnota (issue #9): `received_at: null/""` znamená „nevím, kdy dorazil",
        // ne vědomé zadání — repo takový doklad stejně dopadne datem vystavení, takže by
        // příznak 'manual' jen předstíral informaci, kterou nikdo nezadal.
        if (array_key_exists('received_at', $body) && trim((string) ($body['received_at'] ?? '')) !== '') {
            $body['received_at_source'] = 'manual';
        }

        // Forma úhrady (migrace 1128): přišla-li z formuláře, je to vědomá volba účetní →
        // zdroj vždy 'manual', nikdy ne to, co poslal klient. Bez toho by šlo přes API
        // podstrčit slabý zdroj a nechat si hodnotu přepsat AI extrakcí.
        if (array_key_exists('payment_method', $body)) {
            $body['payment_method_source'] = 'manual';
        }

        // Vazba dobropisu na opravovanou fakturu (migrace 1096) — validace tenanta/druhu/self.
        if (array_key_exists('parent_purchase_invoice_id', $body)) {
            $body['parent_purchase_invoice_id'] = self::sanitizeParentLink(
                $this->db, $body['parent_purchase_invoice_id'] ?? null,
                (string) ($body['document_kind'] ?? 'invoice'), $supplierId, 0,
            );
        }

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            if ($submissionId > 0 && !$this->submissions->claimForManual($submissionId, $supplierId)) {
                throw new PurchaseInvoiceSubmissionException(
                    'submission_state_changed',
                    'Stav podání se mezitím změnil.',
                    409,
                );
            }
            // Obě kolize níž (interní číslo, doklad dodavatele) končí jako 409 —
            // uživatelská situace, ne chyba serveru. V logu proto nemají svítit
            // jako ERROR; jiný index ani cizí klíč se tím neztiší.
            $id = DbErrorLogger::expectingDuplicates(
                ['uq_pi_vendor_invoice', 'uq_pi_supplier_varsymbol'],
                fn (): int => $this->repo->createDraft($body, $userId, $supplierId),
            );
            // ZÁMĚRNĚ bezpodmínečně, na rozdíl od PUT ({@see \MyInvoice\Service\Invoice\DocumentItemsPayload}):
            // založení nemá co smazat a vzniká `draft`, kde je doklad bez řádků pracovní
            // stav — týž výklad, jaký import používá pro přijaté faktury. Ostatně u přijaté
            // faktury se hlavička často pořizuje z PDF dřív než řádky.
            $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
            // Volba „uhradit hotově z pokladny" (migrace 1327). Na draftu se jen ULOŽÍ —
            // koncept ještě není závazek, pokladní doklad z něj vyrobí až přechod na
            // 'received' ({@see TransitionPurchaseInvoiceStatusAction}).
            if (array_key_exists('cash_register_id', $body)) {
                $this->repo->setCashRegisterId(
                    $id,
                    $supplierId,
                    ($body['cash_register_id'] ?? null) !== null ? (int) $body['cash_register_id'] : null,
                );
            }
            if (array_key_exists('vat_overrides', $body)) {
                $this->repo->setVatOverrides($id, $supplierId, is_array($body['vat_overrides']) ? $body['vat_overrides'] : null);
            }
            $this->calc->recompute($id);
            // Hlavičková klasifikace se přebírá z řádků (SSOT je defaultClassificationCode()).
            // Až PO recompute — volba dominantního kódu váží podle total_with_vat, které
            // kalkulátor dopočítává.
            $this->repo->syncHeaderClassificationFromItems($id, $supplierId);
            $this->repo->replaceVatAllocations(
                $id,
                $supplierId,
                is_array($body['vat_allocations'] ?? null) ? $body['vat_allocations'] : [],
            );
            if ($submissionId > 0) {
                $this->submissionCompletion->complete(
                    $submissionId,
                    $supplierId,
                    $id,
                    $userId,
                    'manual',
                );
            }
            $this->clients->markAsVendor((int) $vendor['id'], $supplierId);
            if ($ownTransaction) $pdo->commit();
        } catch (PurchaseInvoiceSubmissionException $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
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
        $this->logger->log('purchase_invoice.created', $userId, 'purchase_invoice', $id, [
            'vendor_id'    => $body['vendor_id'],
            'document_kind' => $body['document_kind'] ?? 'invoice',
            'submission_id' => $submissionId > 0 ? $submissionId : null,
        ], $ip, $request->getHeaderLine('User-Agent'));
        if ($submissionId > 0) {
            $this->logger->log(
                'purchase_invoice_submission.processed',
                $userId,
                'purchase_invoice_submission',
                $submissionId,
                ['purchase_invoice_id' => $id, 'source' => 'manual'],
                $ip,
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }
        try {
            $this->aiSuggestions->enqueuePurchase($supplierId, $id);
        } catch (\Throwable) {
        }

        $invoice = $this->repo->find($id, $supplierId);
        // Non-blocking varování (např. dobropis s kladným součtem — viz issue #35).
        $warnings = PurchaseInvoiceValidation::warnings($invoice ?? []);
        // Neplátce + přesto uplatněn odpočet → upozorni (uživatel vědomě přepsal).
        // VÝJIMKA reverse charge (zahraniční služba/zboží): dodavatel je z pohledu české
        // DPH neplátce ZE SVÉ PODSTATY (nefakturuje českou DPH), ale příjemce si daň
        // samovyměří a smí ji odečíst (§ 72/73) — varování by tu bylo false positive.
        if ($vendorNonPayer && !PurchaseInvoiceValidation::isReverseCharge($invoice) && ($invoice['vat_deduction'] ?? 'full') !== 'none') {
            $warnings[] = 'vendor_non_payer_deduction';
        }
        if ($vendorUnreliablePayer) {
            $warnings[] = 'vendor_unreliable_payer';
        }
        // Finální faktura na zálohu s DDKP → DPH ze zálohy už odečtena (dvojí odpočet, viz helper).
        if (is_array($invoice) && (string) ($invoice['document_kind'] ?? 'invoice') === 'invoice'
            && self::advanceHasActiveTaxDocument($this->db, $invoice['advance_purchase_invoice_id'] ?? null, $supplierId)) {
            $warnings[] = 'advance_has_tax_document';
        }
        // Účtenka, která vypadá jako doklad k přijaté záloze → nejspíš má být DDKP/záloha.
        if (is_array($invoice)
            && self::receiptLooksLikePrepayment($this->db, $invoice['id'] ?? null, (string) ($invoice['document_kind'] ?? ''))) {
            $warnings[] = 'receipt_looks_like_prepayment';
        }
        // §C/K4: účetní kurz na dokladu odchýlen od denního ČNB kurzu k rozhodnému dni.
        // NEBLOKUJE (§24/7 pevný kurz je legitimní); §73/6 se netýká — kontroluje se JEN
        // účetní přepočet z hlavičky, korunová částka DPH z dokladu zůstává nedotčená.
        // Rozhodný den ze SSOT (ExchangeRateDate), ne `effective_cost_date` — to je
        // GREATEST(DUZP, vystavení) pro uznání nákladu (migrace 1010) a u dokladu s DUZP
        // dřív než vystavení by se ČNB ptalo na jiný den → falešná odchylka.
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
        if (!empty($warnings)) {
            $invoice['_warnings'] = $warnings;
        }
        return Json::ok($response, $invoice, 201);
    }

    /**
     * Vazba dokladu na rodičovský doklad přes parent_purchase_invoice_id (přetíženo dle
     * document_kind, jako parent_invoice_id na vydané straně). Vrací platné ID rodiče, nebo NULL:
     *   • dobropis (credit_note, migrace 1096) → opravovaná běžná faktura (document_kind='invoice'),
     *   • DDKP (tax_document, migrace 1138)   → poskytnutá záloha (document_kind='advance').
     * Rodič musí patřit témuž tenantovi, mít správný druh a nesmí to být tentýž doklad. Jiný
     * druh vazbu vyčistí. Neplatnou vazbu tiše zahazuje na NULL — je to nepovinné vodítko,
     * nesmí blokovat uložení.
     */
    public static function sanitizeParentLink(Connection $db, mixed $raw, string $documentKind, int $supplierId, int $selfId): ?int
    {
        $requiredParentKind = match ($documentKind) {
            'credit_note'  => 'invoice',
            'tax_document' => 'advance',
            default        => null,
        };
        if ($requiredParentKind === null) {
            return null;
        }
        $parentId = (int) ($raw ?? 0);
        if ($parentId <= 0 || $parentId === $selfId) {
            return null;
        }
        $stmt = $db->pdo()->prepare(
            "SELECT 1 FROM purchase_invoices
              WHERE id = ? AND supplier_id = ? AND document_kind = ? LIMIT 1"
        );
        $stmt->execute([$parentId, $supplierId, $requiredParentKind]);
        return $stmt->fetchColumn() !== false ? $parentId : null;
    }

    /**
     * Varování `advance_has_tax_document`: finální faktura navázaná na zálohu, která má DDKP
     * (daňový doklad k platbě). DPH ze zálohy už byla odečtena (343/314 na DDKP), takže
     * vyúčtovací faktura smí v DPH/KH nárokovat jen DOPLATKOVOU daň — jinak dvojí odpočet.
     * Deník to blokuje (advance_settlement_ambiguous), evidence DPH ale ne → měkké upozornění
     * při uložení. Vrací true, když má navázaná záloha aspoň jeden živý DDKP.
     */
    public static function advanceHasActiveTaxDocument(Connection $db, mixed $advanceId, int $supplierId): bool
    {
        $advId = (int) ($advanceId ?? 0);
        if ($advId <= 0) {
            return false;
        }
        // Daň už je uplatněná ve dvou případech: k záloze existuje DDKP — NEBO je „zálohou"
        // sám samostatný daňový doklad k platbě, který odpočet uplatňuje přímo. Bez té druhé
        // větve by uživatel u konečné faktury varování nedostal a nárokoval by DPH podruhé,
        // přestože ji z dokladu o platbě už uplatnil.
        $stmt = $db->pdo()->prepare(
            "SELECT 1 FROM purchase_invoices
              WHERE supplier_id = ?
                AND status <> 'cancelled'
                AND document_kind = 'tax_document'
                AND (
                      parent_purchase_invoice_id = ?
                   OR (id = ? AND parent_purchase_invoice_id IS NULL)
                )
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $advId, $advId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Heuristika `receipt_looks_like_prepayment`: účtenka (receipt), jejíž položky vypadají
     * jako daňový doklad k PŘIJATÉ PLATBĚ / ZÁLOZE (§28). Taková věc není paragon — patří jako
     * DDKP (tax_document) nebo záloha (advance); jako receipt zaúčtuje předčasný náklad 518, který
     * se pak zdvojí s konečnou fakturou. Měkké upozornění (neblokuje). Vrací true při shodě.
     */
    public static function receiptLooksLikePrepayment(Connection $db, mixed $invoiceId, string $documentKind): bool
    {
        $id = (int) ($invoiceId ?? 0);
        if ($documentKind !== 'receipt' || $id <= 0) {
            return false;
        }
        // Rozhoduje sdílený SSOT ({@see AdvanceTaxDocumentText}), ne LIKE v SQL: dřív tu
        // stálo mimo jiné `%daňový doklad k%`, což chytalo i „Daňový doklad k objednávce"
        // — a hlavně to bylo citlivé na diakritiku i velikost písmen. Popisy tahá do PHP
        // jeden dotaz (účtenka má jednotky řádků).
        $stmt = $db->pdo()->prepare(
            'SELECT description FROM purchase_invoice_items WHERE purchase_invoice_id = ? LIMIT 200'
        );
        $stmt->execute([$id]);
        return AdvanceTaxDocumentText::anyIndicatesAdvanceTaxDocument(
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

}
