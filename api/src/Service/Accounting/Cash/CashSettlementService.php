<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Cash;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\CashRegisterRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Support\PaymentMethods;
use PDO;

/**
 * Hotovostní vyrovnání faktury zvolené PŘÍMO V EDITORU DOKLADU (migrace 1327).
 *
 * Účetní zvolí formu úhrady „Hotově" a k ní pokladnu (`*.cash_register_id`);
 * uložení dokladu z toho vyrobí a zaúčtuje pokladní doklad — VPD u přijaté faktury,
 * PPD u vydané. Bez zvolené pokladny se NEDĚJE NIC (volba je nepovinná).
 *
 * ## Žádná paralelní účtovací cesta
 *
 * Tahle třída sama NIC neúčtuje: zakládá přes {@see CashDocumentService::create()}
 * (create + post v jedné transakci) a ruší přes {@see CashDocumentService::reverse()}
 * — u dokladu bez vydaného čísla (draft) přes {@see CashDocumentService::deleteDocument()}
 * (protizápis/úklid invoice_payments/návrat stavu PF). Tím dědí VŠECHNY pojistky
 * pokladny — kontrolu otevřenosti období, `locked_until` soft zámek, analytiku 211
 * z `cash_registers.account_code` (nikdy natvrdo 211), daňovou evidenci (journal-free
 * větev) i idempotenci `PostingService` na klíči (supplier_id, 'cash', doc.id).
 *
 * ## Vratnost a „co jsem vyrobil, to smím zrušit"
 *
 * Rušit se smí jen doklad s `auto_settlement = 1`, tedy ten, který vznikl tímhle
 * vyrovnáním. Pokladní doklad pořízený ručně v modulu Pokladna zůstane nedotčený,
 * i kdyby byl na tutéž fakturu navázaný.
 *
 * Reconcile při každém uložení: shoda pokladny + částky + data → doklad se nechá být
 * (žádná duplicita při opakovaném uložení); rozdíl → starý se STORNUJE a založí se nový;
 * odebraná volba nebo přepnutí formy úhrady z „Hotově" → doklad se stornuje. Storno,
 * ne smazání, protože vydané číslo řady se nevrací a řada musí zůstat souvislá (§ 11 ZoÚ).
 *
 * ## Nikdy neshodí uložení faktury
 *
 * Volající hook chytá {@see CashException} i ostatní chyby a překládá je na warning
 * (`cash_settlement_failed`) — stejný přístup jako `DocumentAutoPoster`. Uzavřené
 * období, cizí měna nebo nevhodný stav dokladu tedy uložení faktury nezablokují,
 * jen se vyrovnání neprovede a uživatel to uvidí.
 */
final class CashSettlementService
{
    /** Výsledky reconcile — hodnota `status` ve vráceném poli. */
    public const NOOP      = 'noop';
    public const CREATED   = 'created';
    public const REMOVED   = 'removed';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED   = 'skipped';
    public const FAILED    = 'failed';

    /** Klíč warningu, který si Action přidá do `_warnings` odpovědi. */
    public const WARNING = 'cash_settlement_failed';

    /** Důvod storna pokladního dokladu, který vyrovnání ruší (reverse() vyžaduje ≥ 3 znaky). */
    private const REVERSAL_REASON = 'Zrušeno hotovostní vyrovnání dokladu';

    /**
     * Typy vydaných dokladů, které se hotovostně vyrovnat NESMÍ.
     *
     * `proforma` záměrně: úhrada zálohové faktury spouští v pokladně vystavení
     * finálního dokladu ({@see CashDocumentService::applySideEffects()}), a ten už
     * by zrušení volby v editoru nesmazalo — vyrovnání by po sobě nechalo doklad,
     * který neumí vzít zpět. Zálohu proto hotově hraď v modulu Pokladna, kde je ten
     * důsledek vidět. `cancellation` a `payment_calendar` nejsou platební cíl.
     *
     * @var list<string>
     */
    private const NON_SETTLEABLE_INVOICE_TYPES = ['proforma', 'cancellation', 'payment_calendar', 'rent_calendar', 'advance_calendar'];

    public function __construct(
        private readonly Connection $db,
        private readonly CashDocumentService $documents,
        private readonly CashRegisterRepository $registers,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchaseInvoices,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * MĚKKÁ brána pro hooky v Action vrstvě — zrcadlo {@see DocumentAutoPoster::maybeAutoPost()}.
     * Chybu vyrovnání zaloguje (`cash.settlement_failed`) a spolkne: uložení ani
     * vystavení faktury kvůli pokladně spadnout nesmí. Vrácené pole jde do odpovědi
     * jako `_cash_settlement`, ať uživatel vidí, co se (ne)stalo.
     *
     * @param 'invoice'|'purchase_invoice' $kind
     * @return array{status:string, cash_document_id:?int, doc_number:?string, reason:?string, message?:string}
     */
    public function maybeSettle(
        int $supplierId,
        string $kind,
        int $documentId,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $pdo = $this->db->pdo();
        $foreign = $pdo->inTransaction();
        try {
            // Storno starého a založení nového dokladu je jedna jednotka. Odvolanou
            // transakci (1020/1213) zopakuje vlastník; jinak by vyrovnání tiše chybělo.
            $result = TransactionFailure::retrying($pdo, fn (): array => $this->syncAtomically($supplierId, $kind, $documentId, $userId));
        } catch (CashException $e) {
            $this->logFailure($supplierId, $kind, $documentId, $userId, $e->errorCode, $e->getMessage(), $ip, $userAgent);

            return self::result(self::FAILED, null, null, $e->errorCode) + ['message' => $e->getMessage()];
        } catch (PostingException | UnbalancedEntryException $e) {
            // Typicky uzavřené/schválené období — doklad zůstane neuhrazený, což je
            // správně: do zavřeného období se nesmí zaúčtovat nic.
            $code = $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry';
            $this->logFailure($supplierId, $kind, $documentId, $userId, $code, $e->getMessage(), $ip, $userAgent);

            return self::result(self::FAILED, null, null, $code) + ['message' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($foreign && TransactionFailure::aborted($e)) {
                throw $e;
            }
            $this->logFailure($supplierId, $kind, $documentId, $userId, 'internal', $e->getMessage(), $ip, $userAgent);

            return self::result(self::FAILED, null, null, 'internal') + ['message' => $e->getMessage()];
        }

        if (in_array($result['status'], [self::CREATED, self::REMOVED], true)) {
            $this->activity->log(
                'cash.settlement_' . $result['status'],
                $userId,
                $kind,
                $documentId,
                ['cash_document_id' => $result['cash_document_id'], 'doc_number' => $result['doc_number']],
                $ip,
                $userAgent,
                $supplierId,
            );
        }

        return $result;
    }

    /** @return array{status:string, cash_document_id:?int, doc_number:?string, reason:?string} */
    private function syncAtomically(int $supplierId, string $kind, int $documentId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $result = $kind === 'invoice'
                ? $this->syncInvoice($supplierId, $documentId, $userId)
                : $this->syncPurchase($supplierId, $documentId, $userId);
            if ($own) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function logFailure(
        int $supplierId,
        string $kind,
        int $documentId,
        ?int $userId,
        string $code,
        string $message,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $this->activity->log(
            'cash.settlement_failed',
            $userId,
            $kind,
            $documentId,
            ['error_code' => $code, 'message' => $message],
            $ip,
            $userAgent,
            $supplierId,
        );
    }

    /**
     * Sesouhlasí hotovostní vyrovnání PŘIJATÉ faktury s volbou na dokladu.
     *
     * @return array{status:string, cash_document_id:?int, doc_number:?string, reason:?string}
     */
    public function syncPurchase(int $supplierId, int $purchaseInvoiceId, ?int $userId): array
    {
        $pf = $this->purchaseInvoices->find($purchaseInvoiceId, $supplierId);
        if ($pf === null) {
            return self::result(self::NOOP);
        }

        $existing = $this->existingSettlement($supplierId, 'purchase_invoice_id', $purchaseInvoiceId);
        $registerId = self::chosenRegisterId($pf);
        $blocked = $registerId === null
            ? null
            : ($this->registerBlockReason($supplierId, $registerId) ?? $this->purchaseBlockReason($pf, $existing !== null));

        if ($registerId === null) {
            // Uživatel volbu odebral (nebo přepnul formu úhrady) → doklad zrušit.
            if ($existing !== null) {
                $this->remove($supplierId, (int) $existing["id"], $existing);
                return self::result(self::REMOVED, (int) $existing['id']);
            }
            return self::result(self::NOOP);
        }
        if ($blocked !== null) {
            // Překážka NENÍ důvod rušit už zaúčtovaný pokladní doklad — ten vznikl
            // v době, kdy překážka nebyla, a je platnou úhradou. Jen to ohlásíme.
            return self::result(
                self::SKIPPED,
                $existing !== null ? (int) $existing['id'] : null,
                $existing['doc_number'] ?? null,
                $blocked,
            );
        }

        // H-1: vyrovnává se ZBYTEK (brutto − uhrazená záloha − už zaúčtované úhrady),
        // ne brutto. Vlastní doklad vyrovnání se z výpočtu vynechá, jinak by si po
        // prvním zaúčtování odečetl sám sebe a chtěl se zrušit.
        $total = $this->documents->purchaseRemaining(
            $supplierId,
            $purchaseInvoiceId,
            $existing !== null ? (int) $existing['id'] : null,
        );
        $issueDate = (string) $pf['issue_date'];

        if (self::cents($total) <= 0) {
            return self::result(
                self::SKIPPED,
                $existing !== null ? (int) $existing['id'] : null,
                $existing['doc_number'] ?? null,
                'nothing_to_settle',
            );
        }

        if ($existing !== null) {
            if (self::matches($existing, $registerId, $total, $issueDate)) {
                return self::result(self::UNCHANGED, (int) $existing['id'], $existing['doc_number']);
            }
            $this->remove($supplierId, (int) $existing["id"], $existing);
        }

        $created = $this->documents->create($supplierId, [
            'register_id'         => $registerId,
            'doc_type'            => 'out',
            'purpose'             => 'purchase_payment',
            'issue_date'          => $issueDate,
            'description'         => self::description(
                'Úhrada přijaté faktury',
                (string) ($pf['vendor_invoice_number'] ?? $pf['varsymbol'] ?? ''),
            ),
            'partner_name'        => $pf['vendor_company_name'] ?? null,
            'partner_ic'          => $pf['vendor_ic'] ?? null,
            'partner_dic'         => $pf['vendor_dic'] ?? null,
            'total_amount'        => $total,
            'purchase_invoice_id' => $purchaseInvoiceId,
            'post'                => true,
        ], $userId);
        $this->markAuto($supplierId, (int) $created['id']);

        return self::result(self::CREATED, (int) $created['id'], $created['doc_number'] ?? null);
    }

    /**
     * Sesouhlasí hotovostní vyrovnání VYDANÉ faktury s volbou na dokladu.
     *
     * @return array{status:string, cash_document_id:?int, doc_number:?string, reason:?string}
     *
     * @param ?int $eetDeviceId pokladní zařízení EET pro tržbu z dokladu (rychlá účtenka
     *                          ho zná); bez něj rozhodne mapování pokladny / výchozí zařízení
     */
    public function syncInvoice(int $supplierId, int $invoiceId, ?int $userId, ?int $eetDeviceId = null): array
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || (int) $invoice['supplier_id'] !== $supplierId) {
            return self::result(self::NOOP);
        }

        $existing = $this->existingSettlement($supplierId, 'invoice_id', $invoiceId);
        $registerId = self::chosenRegisterId($invoice);
        $blocked = $registerId === null
            ? null
            : ($this->registerBlockReason($supplierId, $registerId) ?? $this->invoiceBlockReason($invoice, $existing !== null));

        if ($registerId === null) {
            if ($existing !== null) {
                $this->remove($supplierId, (int) $existing["id"], $existing);
                return self::result(self::REMOVED, (int) $existing['id']);
            }
            return self::result(self::NOOP);
        }
        if ($blocked !== null) {
            return self::result(
                self::SKIPPED,
                $existing !== null ? (int) $existing['id'] : null,
                $existing['doc_number'] ?? null,
                $blocked,
            );
        }

        $issueDate = (string) $invoice['issue_date'];
        // Doklad k vyplacení (dobropis, vyúčtování se zápornou částkou) se se zapnutou
        // volbou dodavatele vyplatí výdajovým dokladem. Bez volby platí dosavadní cesta,
        // která pro zápornou částku skončí na „nothing_to_settle".
        $refund = RefundDocument::isRefundDocument($invoice)
            && RefundDocument::enabledForSupplier($this->db->pdo(), $supplierId);
        if ($refund) {
            $docType = 'out';
            $remaining = RefundDocument::refundAmount($invoice);
        } else {
            $docType = 'in';
            // Doklad už jednou vyrovnaný drží svou vlastní úhradu v `paid_total`, takže
            // „zbývá" by u něj vyšlo 0 a přepočet částky by ho chtěl zrušit. Zbytek se proto
            // počítá BEZ vlastní úhrady vyrovnání.
            $ownPaid = $existing !== null ? round((float) $existing['total_amount'], 2) : 0.0;
            $remaining = round(
                (float) $invoice['amount_to_pay'] - (float) $invoice['paid_total'] + $ownPaid,
                2,
            );
        }

        if (self::cents($remaining) <= 0) {
            return self::result(
                self::SKIPPED,
                $existing !== null ? (int) $existing['id'] : null,
                $existing['doc_number'] ?? null,
                'nothing_to_settle',
            );
        }

        if ($existing !== null) {
            if (self::matches($existing, $registerId, $remaining, $issueDate, $docType)) {
                return self::result(self::UNCHANGED, (int) $existing['id'], $existing['doc_number']);
            }
            $this->remove($supplierId, (int) $existing["id"], $existing);
        }

        $created = $this->documents->create($supplierId, [
            'register_id'  => $registerId,
            'doc_type'     => $docType,
            'purpose'      => 'invoice_payment',
            'issue_date'   => $issueDate,
            'description'  => self::description(
                $refund ? 'Vyplacení dokladu' : 'Úhrada vydané faktury',
                (string) ($invoice['varsymbol'] ?? ''),
            ),
            'partner_name' => $invoice['client_company_name'] ?? null,
            'partner_ic'   => $invoice['client_ic'] ?? null,
            'partner_dic'  => $invoice['client_dic'] ?? null,
            'total_amount' => $remaining,
            'invoice_id'   => $invoiceId,
            'post'         => true,
        ] + ($eetDeviceId !== null ? ['eet_device_id' => $eetDeviceId] : []), $userId);
        $this->markAuto($supplierId, (int) $created['id']);

        return self::result(self::CREATED, (int) $created['id'], $created['doc_number'] ?? null);
    }

    /**
     * Zruší hotovostní vyrovnání dokladu bez ohledu na volbu (storno faktury).
     * Volá se UVNITŘ transakce storna, aby se doklad i pokladní doklad hnuly společně.
     *
     * @param 'invoice'|'purchase_invoice' $kind
     */
    public function detach(int $supplierId, string $kind, int $documentId): bool
    {
        $column = $kind === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $existing = $this->existingSettlement($supplierId, $column, $documentId);
        if ($existing === null) {
            return false;
        }
        $this->remove($supplierId, (int) $existing["id"], $existing);

        return true;
    }

    /** Pokladní doklad, který k tomuhle dokladu vyrobilo vyrovnání (aktivní, ne stornovaný). */
    private function existingSettlement(int $supplierId, string $column, int $documentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, register_id, doc_type, total_amount, issue_date, doc_number, status
               FROM cash_documents
              WHERE supplier_id = ? AND {$column} = ? AND auto_settlement = 1 AND status <> 'reversed'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Pokladna zvolená na dokladu — jen když je forma úhrady „Hotově". Null = uživatel
     * hotovostní vyrovnání nechce (jiná forma úhrady nebo prázdná pokladna), tedy
     * legitimní důvod dřív založený pokladní doklad zrušit.
     *
     * @param array<string,mixed> $document
     */
    private static function chosenRegisterId(array $document): ?int
    {
        if (PaymentMethods::normalize($document['payment_method'] ?? null) !== 'cash') {
            return null;
        }
        $registerId = (int) ($document['cash_register_id'] ?? 0);

        return $registerId > 0 ? $registerId : null;
    }

    /**
     * Důvod, proč zvolenou pokladnu použít nelze (null = lze). Valutová pokladna:
     * úhradu faktury z ní {@see CashDocumentService::validateDoc()} odmítá
     * (`foreign_register_purpose_unsupported`), tak ji odmítneme se srozumitelným
     * důvodem už tady.
     */
    private function registerBlockReason(int $supplierId, int $registerId): ?string
    {
        $register = $this->registers->find($supplierId, $registerId);
        if ($register === null) {
            return 'register_not_found';
        }
        if (empty($register['is_active'])) {
            return 'register_inactive';
        }

        return strtoupper((string) ($register['currency_code'] ?? 'CZK')) === 'CZK'
            ? null
            : 'register_not_czk';
    }

    /**
     * Důvod, proč přijatou fakturu hotově vyrovnat nelze (null = lze). Pojmenované
     * důvody jdou do warningu, ať uživatel ví, PROČ se pokladní doklad nezaložil.
     *
     * @param array<string,mixed> $pf
     */
    private function purchaseBlockReason(array $pf, bool $hasSettlement): ?string
    {
        if (in_array((string) $pf['status'], ['draft', 'cancelled'], true)) {
            // Koncept ještě není závazek; volba se uloží a vyrovná se při přijetí dokladu.
            return 'document_not_payable';
        }
        if ((string) ($pf['currency'] ?? 'CZK') !== 'CZK') {
            return 'foreign_currency';
        }
        // DDKP navázaný na zálohovou fakturu není platební cíl (§ 28) — peníze odešly
        // už na záloze. Zrcadlo guardu v CashDocumentService::buildPurchasePayment().
        if ((string) ($pf['document_kind'] ?? '') === 'tax_document'
            && ($pf['parent_purchase_invoice_id'] ?? null) !== null
        ) {
            return 'ddkp_not_payable';
        }
        if (self::cents($pf['total_with_vat'] ?? 0) <= 0) {
            return 'nothing_to_settle';
        }
        // Faktura označená za uhrazenou mimo pokladnu — vyrovnání by z ní udělalo
        // druhou úhradu. Vlastní (auto_settlement) doklad je z toho vyňatý, jinak by
        // se po prvním vyrovnání sám zablokoval.
        if ((string) $pf['status'] === 'paid' && !$hasSettlement) {
            return 'already_paid';
        }

        return null;
    }

    /**
     * Důvod, proč vydanou fakturu hotově vyrovnat nelze (null = lze).
     *
     * @param array<string,mixed> $invoice
     */
    private function invoiceBlockReason(array $invoice, bool $hasSettlement): ?string
    {
        $status = (string) $invoice['status'];
        if ($status === 'cancelled') {
            return 'document_not_payable';
        }
        // Koncept se inkasovat nedá; volba se uloží a vyrovná se při vystavení.
        // Výjimka: doklad, který VYROVNÁNÍ samo označilo za uhrazený, se sem nedostane
        // jako draft, takže tahle větev řeší jen skutečné koncepty.
        if ($status === 'draft') {
            return 'document_not_payable';
        }
        if ((string) ($invoice['currency'] ?? 'CZK') !== 'CZK') {
            return 'foreign_currency';
        }
        if (in_array((string) ($invoice['invoice_type'] ?? 'invoice'), self::NON_SETTLEABLE_INVOICE_TYPES, true)) {
            return 'document_type_not_settleable';
        }
        // Doklad označený za uhrazený mimo pokladnu (bankou, ručně) — hotovostní
        // vyrovnání by tu vyrobilo druhou úhradu. Vlastní vyrovnání je z toho vyňaté.
        if ($status === 'paid' && !$hasSettlement && self::cents($invoice['paid_total'] ?? 0) > 0) {
            return 'already_paid';
        }
        // Doklad k vyplacení nemá `paid_total` (vrácení nese stav dokladu), takže
        // vyplacení bankou nebo ručně pozná vyrovnání jen podle stavu.
        if ($status === 'paid' && !$hasSettlement && RefundDocument::isRefundDocument($invoice)
            && RefundDocument::enabledForSupplier($this->db->pdo(), (int) $invoice['supplier_id'])
        ) {
            return 'already_paid';
        }

        return null;
    }

    /**
     * Zruší pokladní doklad vyrovnání i s jeho zápisem a úklidem vazeb.
     *
     * H-6: doklad, kterému už bylo VYDÁNO číslo řady, se NESMÍ tvrdě smazat — číslo se
     * nevrací (`DocumentSeriesService` dekrement nemá), takže by každá oprava částky na
     * faktuře udělala v řadě PPD/VPD další díru a smazala deníkový zápis beze stopy
     * (§ 11 ZoÚ). Místo toho se stornuje: protizápis i původní doklad zůstanou v evidenci
     * a nový doklad dostane další číslo. Tvrdé smazání zůstává jen pro draft bez čísla.
     */
    private function remove(int $supplierId, int $cashDocumentId, ?array $existing = null): void
    {
        $doc = $existing ?? $this->documents->get($supplierId, $cashDocumentId);
        // EET-07: vyrovnání s evidovanou tržbou se samo neruší. Storno PPD vytváří opravu
        // EET (vrácení peněz), a to nesmí proběhnout jako vedlejší účinek úpravy nebo
        // storna faktury. Uživatel doklad stornuje vědomě v Pokladně.
        if ((new \MyInvoice\Service\Eet\EetHistoryGuard($this->db))->cashDocumentConflict($supplierId, $cashDocumentId) !== null) {
            throw new CashException(
                \MyInvoice\Service\Eet\EetHistoryGuard::ERROR_CODE,
                'Pokladní doklad ' . (string) ($doc['doc_number'] ?? '') . ' má evidovanou tržbu EET a automaticky se nezruší. '
                    . 'Pokud jste peníze vrátili, stornujte ho v Pokladně (storno vytvoří opravu EET).',
                409,
            );
        }
        $numbered = trim((string) ($doc['doc_number'] ?? '')) !== '';
        $posted = (string) ($doc['status'] ?? '') === 'posted';

        if ($numbered && $posted) {
            $this->documents->reverse(
                $supplierId,
                $cashDocumentId,
                ['reason' => self::REVERSAL_REASON],
                null,
            );
            return;
        }
        $this->documents->deleteDocument($supplierId, $cashDocumentId);
    }

    /** L-1: scope na `supplier_id` stejně jako zbytek zápisů nad `cash_documents`. */
    private function markAuto(int $supplierId, int $cashDocumentId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE cash_documents SET auto_settlement = 1 WHERE id = ? AND supplier_id = ?')
            ->execute([$cashDocumentId, $supplierId]);
    }

    /** @param array<string,mixed> $existing */
    private static function matches(array $existing, int $registerId, float $total, string $issueDate, ?string $docType = null): bool
    {
        return ($docType === null || (string) $existing['doc_type'] === $docType)
            && (int) $existing['register_id'] === $registerId
            && self::cents($existing['total_amount']) === self::cents($total)
            && substr((string) $existing['issue_date'], 0, 10) === substr($issueDate, 0, 10)
            && (string) $existing['status'] === 'posted';
    }

    private static function description(string $prefix, string $number): string
    {
        $number = trim($number);

        return $number === '' ? $prefix . ' hotově' : $prefix . ' ' . $number . ' hotově';
    }

    /**
     * @return array{status:string, cash_document_id:?int, doc_number:?string, reason:?string}
     */
    private static function result(string $status, ?int $id = null, ?string $docNumber = null, ?string $reason = null): array
    {
        return [
            'status'           => $status,
            'cash_document_id' => $id,
            'doc_number'       => $docNumber,
            'reason'           => $reason,
        ];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
