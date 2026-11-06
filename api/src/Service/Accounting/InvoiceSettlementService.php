<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\InvoiceSettlementRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Support\Sql\PurchaseSettledExpr;

/**
 * Úhrada faktury ZÁPOČTEM proti zvolenému rozvahovému účtu (migrace 1126).
 *
 * Typický případ: faktura se nevyrovná penězi, ale zápočtem proti pohledávce nebo
 * závazku za společníkem (355 / 365). Protistranou tedy není jiný doklad (to řeší
 * {@see OffsetService} pro dvojice FV↔PF), ale JEDEN účet, který si účetní zvolí.
 * Posting rules `payment.receivable.settlement` / `payment.payable.settlement`
 * dávají jen PŘEDVOLBU protiúčtu do UI — závazný je účet z požadavku.
 *
 * Kontace:
 *   vydaná faktura  → <zvolený účet> MD / 311 D   (zálohová proforma → 324)
 *   přijatá faktura → 321 MD / <zvolený účet> D   (zálohová PF → 314)
 *
 * Vydaná faktura dostane řádek v `invoice_payments` (source='settlement'), takže
 * částečné úhrady i `paid_total` fungují stejně jako u banky a pokladny.
 *
 * Přijatá faktura `paid_total` nemá — úhrada k ní visí podle kanálu ve třech tabulkách
 * ({@see PurchaseSettledExpr}). ČÁSTEČNÝ zápočet přesto umí: zbytek se dopočítá z těch
 * tabulek a `invoice_settlements` je jednou z nich, takže se druhý zápočet už odečítá
 * od zbytku po prvním. Na 'paid' doklad překlopí teprve zápočet, který zbytek vynuluje;
 * storno ho vrátí zpět, pokud po odečtení zrušené částky zbytek zase vznikne.
 * Dřív směl být zápočet jen v plné výši — účetní, která započítávala část, musela
 * doklad označit jako uhrazený ručně, a tím ho vyřadila z evidence úplně.
 *
 * V daňové evidenci (§6) se nic neúčtuje — `journal_entry_id` zůstane NULL, ale
 * vyrovnání dokladu proběhne stejně (vzor {@see Cash\CashDocumentService}).
 */
final class InvoiceSettlementService
{
    private const TOLERANCE_CENTS = 1;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceSettlementRepository $repo,
        private readonly PostingService $posting,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly PurchaseInvoiceRepository $purchaseInvoices,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
    ) {}

    /**
     * Předvolba protiúčtu pro UI (z posting rule, s hardcoded fallbackem).
     *
     * @return array{account_id:?int, account_code:string, account_name:?string}
     */
    public function defaultAccount(int $supplierId, string $docType): array
    {
        $this->assertDocType($docType);
        $rule = $this->rules->resolve($supplierId, $this->ruleKey($docType));
        $code = $docType === 'invoice'
            ? (string) ($rule['debit_account_code'] ?? '355')
            : (string) ($rule['credit_account_code'] ?? '365');
        $account = $this->accounts->findByCode($supplierId, $code);
        return [
            'account_id'   => $account !== null ? (int) $account['id'] : null,
            'account_code' => $code,
            'account_name' => $account !== null ? (string) $account['name'] : null,
        ];
        // account_id === null → účet v osnově firmy chybí; UI si nechá účetní vybrat ručně.
    }

    /**
     * Zápočet dokladu proti zvolenému účtu.
     *
     * @param array{settled_on?:string, amount?:float|string, account_id?:int, note?:?string} $data
     * @return array<string,mixed> hlavička zápočtu (viz {@see get()})
     */
    public function create(int $supplierId, string $docType, int $docId, array $data, ?int $userId): array
    {
        $this->assertDocType($docType);
        $settledOn = trim((string) ($data['settled_on'] ?? ''));
        if (!self::isDate($settledOn)) {
            throw new SettlementException('invalid_date', 'Datum úhrady musí být ve tvaru RRRR-MM-DD.');
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new SettlementException('invalid_amount', 'Částka zápočtu musí být kladná.');
        }
        $accountId = (int) ($data['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new SettlementException('account_required', 'Vyberte protiúčet zápočtu.');
        }
        $note = self::nullableString($data['note'] ?? null);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $account = $this->accounts->findById($supplierId, $accountId);
            if ($account === null) {
                throw new SettlementException('account_not_found', 'Protiúčet nebyl v účtovém rozvrhu nalezen.', 404);
            }
            if (!($account['is_active'] ?? true)) {
                throw new SettlementException('account_inactive', 'Protiúčet je v účtovém rozvrhu neaktivní.');
            }
            $accountCode = (string) $account['account_code'];

            $doc = $docType === 'invoice'
                ? $this->prepareInvoice($supplierId, $docId, $amount)
                : $this->preparePurchase($supplierId, $docId, $amount);

            // Protiúčet nesmí být tatáž strana, kterou zápočet uzavírá — vznikl by
            // zápis "311 MD / 311 D", který nic nevyrovná.
            $docAccount = $doc['doc_account'];
            if ($accountCode === $docAccount || str_starts_with($accountCode, $docAccount . '.')) {
                throw new SettlementException(
                    'account_same_as_document',
                    'Protiúčet nemůže být tentýž účet, na kterém doklad visí (' . $docAccount . ').',
                );
            }

            $settlementId = $this->repo->insert($supplierId, [
                'doc_type'   => $docType,
                'doc_id'     => $docId,
                'settled_on' => $settledOn,
                'amount'     => $amount,
                'account_id' => $accountId,
                'note'       => $note,
                'created_by' => $userId,
            ]);

            // Daňová evidence (§6) nemá posting engine — vyrovnání dokladu ale platí i tam.
            $entryId = null;
            if ($this->supplierAccountingMode($supplierId) !== 'tax_evidence') {
                $lines = $this->buildLines($docType, $accountCode, $doc['counter_account'], $amount, $doc['fx'] ?? null);
                $entryId = $this->posting->postDocument($supplierId, 'settlement', $settlementId, $lines, [
                    'entry_date'  => $settledOn,
                    'document_no' => $doc['number'],
                    'description' => 'Zápočet ' . $doc['number'] . ' proti účtu ' . $accountCode
                        . ($note !== null ? ' — ' . $note : ''),
                    'posted_by'   => $userId,
                    'user_id'     => $userId,
                ]);
            }

            $paymentId = null;
            if ($docType === 'invoice') {
                $res = $this->invoicePayments->recordPayment($docId, $amount, $settledOn, [
                    'source'     => 'settlement',
                    'note'       => 'Zápočet — ' . $accountCode . ($note !== null ? ' (' . $note . ')' : ''),
                    'created_by' => $userId,
                ]);
                $paymentId = (int) $res['payment_id'];
            } elseif (($doc['settles_fully'] ?? true) && ($doc['status'] ?? '') !== 'paid') {
                // Částečný zápočet doklad NEUZAVÍRÁ — zůstává 'received'/'booked' a dál
                // se nabízí k úhradě (příkaz, další zápočet) už jen svým zbytkem.
                // Vyrovnání zbytku na dokladu, který už 'paid' je, stav ani datum úhrady
                // nepřepisuje: dorovnává jen saldokonto.
                $this->purchaseInvoices->setStatus($docId, 'paid', $supplierId, $settledOn);
            }

            $this->repo->setPosted($settlementId, $entryId, $paymentId);

            if ($ownTx) {
                $pdo->commit();
            }
            return $this->get($supplierId, $settlementId);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Uplatnění víceúčelového poukazu nebo čerpání kreditu na vydané faktuře: zápočet
     * pohledávky proti přijaté záloze, na kterou se poukaz při prodeji zaúčtoval
     * ({@see PostingService::voucherAdvanceAccount()}, 324 MD / 311 D). Daň z plnění
     * přiznává sama faktura podle svých řádků, zápočet DPH nemění.
     *
     * Daňová evidence zálohy neúčtuje, příjem tam vznikl už při prodeji poukazu (peníze
     * přišly tehdy). Uplatnění je proto jen vyrovnání faktury bez peněžního pohybu:
     * úhrada se zdrojem `settlement`, kterou peněžní deník
     * ({@see \MyInvoice\Repository\CashJournalRepository}, noha C) jako příjem nepočítá.
     * Ruční úhrada (`manual`) by tutéž částku započetla do příjmů podruhé.
     *
     * Vrací id úhrady faktury, nebo null, když se zápočet nepoužije a úhradu má zapsat
     * volající dosavadní cestou: proforma (sama visí na 324), doklad v cizí měně
     * (zápočet umí jen CZK), účet chybí v osnově.
     */
    public function settleVoucherRedemption(int $supplierId, int $invoiceId, float $amount, string $settledOn, ?string $note, ?int $userId): ?int
    {
        if ($this->supplierAccountingMode($supplierId) === 'tax_evidence') {
            return (int) $this->invoicePayments->recordPayment($invoiceId, $amount, $settledOn, [
                'source'     => 'settlement',
                'note'       => $note,
                'created_by' => $userId,
            ])['payment_id'];
        }
        $inv = $this->repo->lockInvoice($supplierId, $invoiceId);
        if ($inv === null || $inv['kind'] === 'proforma' || $inv['currency'] !== 'CZK') {
            return null;
        }
        $account = $this->accounts->findByCode($supplierId, $this->posting->voucherAdvanceAccount($supplierId));
        if ($account === null || !($account['is_active'] ?? true)) {
            return null;
        }
        $settlement = $this->create($supplierId, 'invoice', $invoiceId, [
            'settled_on' => $settledOn,
            'amount'     => $amount,
            'account_id' => (int) $account['id'],
            'note'       => $note,
        ], $userId);
        $row = $this->repo->find($supplierId, (int) $settlement['id']);
        if ($row === null || $row['invoice_payment_id'] === null) {
            throw new \LogicException('Zápočet poukazu #' . $settlement['id'] . ' nemá úhradu faktury.');
        }
        return (int) $row['invoice_payment_id'];
    }

    /**
     * Zruší zápočet: protizápis účetního zápisu + odvolání vyrovnání dokladu.
     * Hlavička zůstává (status='cancelled') kvůli auditní stopě.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function cancel(int $supplierId, int $settlementId, array $meta = []): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->repo->lockSettlement($supplierId, $settlementId);
            if ($row === null) {
                throw new SettlementException('not_found', 'Zápočet nenalezen.', 404);
            }
            if ((string) $row['status'] === 'cancelled') {
                throw new SettlementException('already_cancelled', 'Zápočet je už zrušený.');
            }

            if ($row['invoice_payment_id'] !== null) {
                $this->invoicePayments->deletePayment((int) $row['invoice_payment_id'], skipBankGuard: true);
            }
            if ((string) $row['doc_type'] === 'purchase_invoice') {
                // Status vracíme jen tehdy, když po odečtení rušené částky zase vznikne
                // zbytek. U částečných zápočtů doklad na 'paid' nikdy nepřešel (pak není
                // co vracet) a u dokladu doplaceného jiným kanálem by ho storno jednoho
                // zápočtu chybně otevřelo. Tvar návratu dle zápočtu: zaúčtovaný → 'booked',
                // journal-free v daňové evidenci → 'received' (vzor storna pokladny).
                $pf = $this->repo->lockPurchase($supplierId, (int) $row['doc_id']);
                $stillSettled = $pf !== null
                    && self::cents((float) $pf['remaining'] + (float) $row['amount']) <= self::TOLERANCE_CENTS;
                if ($pf !== null && (string) $pf['status'] === 'paid' && !$stillSettled) {
                    $this->purchaseInvoices->setStatus(
                        (int) $row['doc_id'],
                        $row['journal_entry_id'] !== null ? 'booked' : 'received',
                        $supplierId,
                        null,
                    );
                }
            }

            $reversalId = null;
            if ($row['journal_entry_id'] !== null) {
                $reversalId = $this->posting->reverse($supplierId, (int) $row['journal_entry_id'], $meta + [
                    'entry_date'  => $meta['entry_date'] ?? date('Y-m-d'),
                    'description' => 'Storno zápočtu #' . $settlementId,
                ]);
            }
            $this->repo->setCancelled($settlementId, $supplierId, $reversalId);

            if ($ownTx) {
                $pdo->commit();
            }
            return $this->get($supplierId, $settlementId);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Doúčtuje POTVRZENÉ zápočty, které nemají účetní zápis.
     *
     * Vzniká to dvěma cestami. Zápočet pořízený v daňové evidenci zápis nikdy nedostal
     * (deník tam není) a při přechodu na podvojné účetnictví ho nikdo nedoplnil. A hlavně:
     * `invoice_settlements.journal_entry_id` má `ON DELETE SET NULL`, takže hromadné
     * přeúčtování deníku — které zápočty neumělo — je odpojilo. Zůstala evidovaná úhrada
     * bez zápisu: doklad tvrdí „uhrazeno", saldokontní účet je otevřený, proklik z detailu
     * chybí a uzávěrková kontrola hlásí díru, kterou uživatel nemá jak zavřít.
     *
     * Zápis se skládá z ULOŽENÝCH údajů zápočtu (částka, protiúčet, datum), ne z aktuálního
     * stavu dokladu — ten už je dávno `paid` a znovu validovat by ho nešlo. Doklad se tu
     * proto ani nepřestavuje: mění se jen chybějící účetní stopa.
     *
     * @return array{candidates:int, posted:int, failed:int, errors:list<string>}
     */
    public function postMissingEntries(int $supplierId, ?int $userId = null): array
    {
        $report = ['candidates' => 0, 'posted' => 0, 'failed' => 0, 'errors' => []];
        if ($this->supplierAccountingMode($supplierId) === 'tax_evidence') {
            return $report;   // daňová evidence deník nemá — není kam doúčtovat
        }

        $rows = $this->repo->unpostedConfirmed($supplierId);
        $report['candidates'] = count($rows);

        foreach ($rows as $row) {   // @phpstan-ignore-line — tělo sdílí postRow()
            try {
                $this->postRow($supplierId, $row, $userId);
                $report['posted']++;
            } catch (\Throwable $e) {
                $report['failed']++;
                $report['errors'][] = sprintf('Zápočet #%d: %s', (int) $row['id'], $e->getMessage());
            }
        }

        return $report;
    }

    /**
     * Doúčtuje JEDEN zápočet — akce z detailu dokladu vedle štítku „Nezaúčtováno".
     *
     * Hromadná varianta {@see postMissingEntries()} je schovaná v backfillu a je pro
     * administrátora; tohle je cesta pro účetní, která má před sebou konkrétní doklad
     * s chybějícím zápisem a potřebuje ho zavřít, ne spouštět opravu celé firmy.
     *
     * @return array<string,mixed> hlavička zápočtu po doúčtování
     */
    public function postMissingEntry(int $supplierId, int $settlementId, ?int $userId = null): array
    {
        if ($this->supplierAccountingMode($supplierId) === 'tax_evidence') {
            throw new SettlementException('tax_evidence', 'V daňové evidenci se zápočet neúčtuje — deník tam není.');
        }
        $row = $this->repo->findUnpostedConfirmed($supplierId, $settlementId);
        if ($row === null) {
            throw new SettlementException(
                'not_unposted',
                'Zápočet nenalezen, je zrušený, nebo už účetní zápis má.',
                404,
            );
        }
        $this->postRow($supplierId, $row, $userId);
        return $this->get($supplierId, $settlementId);
    }

    /**
     * Zápis podle ULOŽENÝCH údajů zápočtu (částka, protiúčet, datum), ne podle aktuálního
     * stavu dokladu — ten je po zápočtu vyrovnaný a znovu ho validovat by nešlo. Doklad se
     * tu proto ani nepřestavuje: doplňuje se jen chybějící účetní stopa.
     *
     * @param array<string,mixed> $row řádek z {@see InvoiceSettlementRepository::unpostedConfirmed()}
     */
    private function postRow(int $supplierId, array $row, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $docType = (string) $row['doc_type'];
            $counter = $this->documentAccount($supplierId, $docType, (int) $row['doc_id']);
            $fx = $docType === 'purchase_invoice'
                ? $this->purchaseFx($supplierId, (int) $row['doc_id'], (string) ($this->repo->purchaseCurrency($supplierId, (int) $row['doc_id']) ?? 'CZK'))
                : null;
            $lines = $this->buildLines($docType, (string) $row['account_code'], $counter, (float) $row['amount'], $fx);
            $entryId = $this->posting->postDocument($supplierId, 'settlement', (int) $row['id'], $lines, [
                'entry_date'  => (string) $row['settled_on'],
                'document_no' => (string) ($row['doc_no'] ?? ''),
                'description' => 'Zápočet ' . (string) ($row['doc_no'] ?? '') . ' proti účtu '
                    . (string) $row['account_code']
                    . ($row['note'] !== null && $row['note'] !== '' ? ' — ' . (string) $row['note'] : ''),
                'posted_by'   => $userId,
                'user_id'     => $userId,
            ]);
            $this->repo->setPosted((int) $row['id'], $entryId, $row['invoice_payment_id'] !== null
                ? (int) $row['invoice_payment_id']
                : null);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Saldokontní účet dokladu, na kterém zápočet visí — táž volba jako při vzniku zápočtu
     * ({@see prepareInvoice()}, {@see preparePurchase()}), jen bez validace stavu dokladu:
     * u doúčtování je doklad dávno vyrovnaný a znovu ho posuzovat nedává smysl.
     */
    private function documentAccount(int $supplierId, string $docType, int $docId): string
    {
        if ($docType === 'invoice') {
            $isProforma = $this->repo->invoiceIsProforma($supplierId, $docId);
            return $isProforma
                ? $this->ruleAccount($supplierId, 'advance.received.collection', 'credit', '324')
                : $this->ruleAccount($supplierId, 'payment.receivable.settlement', 'credit', '311');
        }
        $isAdvance = $this->repo->purchaseIsAdvance($supplierId, $docId);
        return $isAdvance
            ? $this->ruleAccount($supplierId, 'advance.paid.payment', 'debit', '314')
            : $this->purchasePayable($supplierId, $docId);
    }

    /** Závazek přijatého dokladu, na kterém doklad vznikl ({@see PurchasePayableAccount::forPayment}). */
    private function purchasePayable(int $supplierId, int $docId): string
    {
        return (new PurchasePayableAccount($this->db))->forPayment(
            $supplierId,
            $docId,
            $this->ruleAccount($supplierId, 'payment.payable.settlement', 'debit', '321'),
        );
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $settlementId): array
    {
        $row = $this->repo->find($supplierId, $settlementId);
        if ($row === null) {
            throw new SettlementException('not_found', 'Zápočet nenalezen.', 404);
        }
        return self::shape($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForDocument(int $supplierId, string $docType, int $docId): array
    {
        $this->assertDocType($docType);
        return array_map(self::shape(...), $this->repo->listForDocument($supplierId, $docType, $docId));
    }

    /**
     * Kontrola vydané faktury + účet, na kterém pohledávka visí.
     *
     * @return array{number:string, doc_account:string, counter_account:string}
     */
    private function prepareInvoice(int $supplierId, int $docId, float $amount): array
    {
        $inv = $this->repo->lockInvoice($supplierId, $docId);
        if ($inv === null) {
            throw new SettlementException('doc_not_found', 'Faktura nenalezena.', 404);
        }
        if (in_array($inv['status'], ['draft', 'cancelled'], true)) {
            throw new SettlementException('doc_not_payable', 'Zápočet lze provést jen u vystavené faktury.');
        }
        if ($inv['currency'] !== 'CZK') {
            throw new SettlementException('foreign_currency', 'Zápočet je zatím podporovaný jen u dokladů v CZK.');
        }
        if (self::cents($amount) > self::cents($inv['remaining']) + self::TOLERANCE_CENTS) {
            throw new SettlementException(
                'amount_over_remaining',
                sprintf('Částka zápočtu (%.2f Kč) převyšuje zbytek k úhradě (%.2f Kč).', $amount, $inv['remaining']),
            );
        }
        // Zálohová proforma visí na přijatých zálohách (324), běžná faktura na 311.
        $counter = $inv['kind'] === 'proforma'
            ? $this->ruleAccount($supplierId, 'advance.received.collection', 'credit', '324')
            : $this->ruleAccount($supplierId, 'payment.receivable.settlement', 'credit', '311');
        return ['number' => $inv['number'], 'doc_account' => $counter, 'counter_account' => $counter];
    }

    /**
     * Kontrola přijaté faktury + účet, na kterém závazek visí.
     *
     * @return array{number:string, doc_account:string, counter_account:string}
     */
    private function preparePurchase(int $supplierId, int $docId, float $amount): array
    {
        $pf = $this->repo->lockPurchase($supplierId, $docId);
        if ($pf === null) {
            throw new SettlementException('doc_not_found', 'Přijatá faktura nenalezena.', 404);
        }
        // Zbytek k úhradě zamčený `FOR UPDATE` (viz lockPurchase) — dvě souběžné žádosti
        // o zápočet téhož dokladu se serializují a druhá vidí zbytek už snížený první.
        $remaining = round((float) $pf['remaining'], 2);
        // Uhrazený doklad s NEDOPLATKEM: platba odešla, ale nižší než zbývalo, a doklad byl
        // přesto označen jako uhrazený (dřív to ruční párování dělalo potichu). Na 321 visí
        // rozdíl, který jinak nejde zavřít — proto ho sem pouštíme, ale jen když nějaká
        // úhrada opravdu existuje. Doklad „uhrazený" ručně bez evidované úhrady sem nepatří:
        // jeho zbytek je celá faktura a vyrovnat ji zápočtem by byla jiná operace.
        $paidWithShortfall = $pf['status'] === 'paid'
            && self::cents((float) $pf['settled']) > 0
            && self::cents($remaining) > self::TOLERANCE_CENTS;
        if (!in_array($pf['status'], ['received', 'booked'], true) && !$paidWithShortfall) {
            throw new SettlementException(
                'doc_not_payable',
                'Zápočet lze provést jen u přijaté faktury ve stavu Přijatá nebo Zaúčtovaná.',
            );
        }
        $currency = strtoupper((string) $pf['currency']);
        $fx = $this->purchaseFx($supplierId, $docId, $currency);
        if (self::cents($remaining) <= 0) {
            // Doklad může být ve stavu Přijatá/Zaúčtovaná (třeba po odznačení úhrady)
            // a přesto celý pokrytý — typicky zápočtem, kterému chybí účetní zápis.
            // Bez téhle věty uživatel vidí „už je uhrazená" u dokladu, který se tváří
            // jako neuhrazený, a nemá jak zjistit, co ho vlastně pokrývá.
            throw new SettlementException(
                'already_settled',
                'Doklad je už celý pokrytý úhradami (banka, zápočet). Zbývá 0 Kč k započtení — '
                . 'zkontroluj přehled úhrad v detailu dokladu; zápočet bez účetního zápisu tam '
                . 'jde doúčtovat.',
            );
        }
        if (self::cents($amount) > self::cents($remaining) + self::TOLERANCE_CENTS) {
            throw new SettlementException(
                'amount_over_remaining',
                sprintf('Částka zápočtu (%.2f %s) převyšuje zbytek k úhradě (%.2f %s).', $amount, $currency, $remaining, $currency),
            );
        }
        // Zálohová PF visí na poskytnutých zálohách (314), běžná na svém závazku (321, 325 …).
        $counter = $pf['kind'] === 'advance'
            ? $this->ruleAccount($supplierId, 'advance.paid.payment', 'debit', '314')
            : $this->purchasePayable($supplierId, $docId);
        return [
            'number'          => $pf['number'],
            'doc_account'     => $counter,
            'counter_account' => $counter,
            'status'          => $pf['status'],
            'fx'              => $fx,
            // Vyrovnává zápočet doklad celý? Rozhoduje o překlopení na 'paid'.
            'settles_fully'   => self::cents($amount) >= self::cents($remaining) - self::TOLERANCE_CENTS,
        ];
    }

    /**
     * Cizoměnová přijatá faktura: zápočet se eviduje v měně DOKLADU (tak ho sčítá
     * {@see PurchaseSettledExpr} proti `amount_to_pay`), ale deník je v korunách. Závazek
     * se proto odúčtuje KURZEM PŘEDPISU — týmž, jakým na 321 vznikl — jinak by na
     * saldokontu zůstal kurzový drobek. Tak odúčtovává 321 i banka
     * (`BankPostingService::buildOutgoingMatchedFx`). Korunový doklad → null.
     *
     * @return array{currency:string, rate:float}|null
     */
    private function purchaseFx(int $supplierId, int $docId, string $currency): ?array
    {
        $currency = strtoupper($currency);
        if ($currency === 'CZK') {
            return null;
        }
        $rate = $this->repo->purchasePredpisRate($supplierId, $docId);
        if ($rate === null || $rate <= 0) {
            throw new SettlementException(
                'missing_exchange_rate',
                'Cizoměnová faktura nemá kurz, kterým by šel zápočet převést do korun.',
            );
        }
        return ['currency' => $currency, 'rate' => $rate];
    }

    /**
     * @param array{currency:string, rate:float}|null $fx cizoměnová přijatá faktura (viz {@see purchaseFx()})
     * @return list<array<string,mixed>>
     */
    private function buildLines(string $docType, string $accountCode, string $counterAccount, float $amount, ?array $fx = null): array
    {
        $amount = round($amount, 2);
        if ($docType === 'invoice') {
            // Pohledávka se uzavírá: <zvolený účet> MD / 311 (resp. 324) D.
            return [
                ['account_code' => $accountCode,    'side' => 'debit',  'amount' => $amount],
                ['account_code' => $counterAccount, 'side' => 'credit', 'amount' => $amount],
            ];
        }
        // Závazek se uzavírá: 321 (resp. 314) MD / <zvolený účet> D.
        $czk = $fx === null ? $amount : round($amount * $fx['rate'], 2);
        $payable = ['account_code' => $counterAccount, 'side' => 'debit', 'amount' => $czk];
        if ($fx !== null) {
            $payable['currency_code'] = $fx['currency'];
            $payable['fx_rate'] = $fx['rate'];
            $payable['amount_foreign'] = $amount;
        }
        return [
            $payable,
            ['account_code' => $accountCode, 'side' => 'credit', 'amount' => $czk],
        ];
    }

    private function ruleAccount(int $supplierId, string $ruleKey, string $side, string $fallback): string
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        $code = $rule[$side . '_account_code'] ?? null;
        return $code !== null && (string) $code !== '' ? (string) $code : $fallback;
    }

    private function ruleKey(string $docType): string
    {
        return $docType === 'invoice' ? 'payment.receivable.settlement' : 'payment.payable.settlement';
    }

    private function assertDocType(string $docType): void
    {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) {
            throw new SettlementException('invalid_doc_type', 'Neplatný typ dokladu.');
        }
    }

    /** Účetní režim firmy (Epic DE §2.1) — vzor CashDocumentService. */
    private function supplierAccountingMode(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        return $mode === 'tax_evidence' ? 'tax_evidence' : 'double_entry';
    }

    /** @param array<string,mixed> $row */
    private static function shape(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'doc_type'           => (string) $row['doc_type'],
            'doc_id'             => (int) $row['doc_id'],
            'settled_on'         => (string) $row['settled_on'],
            'amount'             => round((float) $row['amount'], 2),
            'account_id'         => (int) $row['account_id'],
            'account_code'       => (string) ($row['account_code'] ?? ''),
            'account_name'       => (string) ($row['account_name'] ?? ''),
            'note'               => $row['note'] !== null ? (string) $row['note'] : null,
            'status'             => (string) $row['status'],
            'journal_entry_id'   => $row['journal_entry_id'] !== null ? (int) $row['journal_entry_id'] : null,
            'reversal_entry_id'  => $row['reversal_entry_id'] !== null ? (int) $row['reversal_entry_id'] : null,
            'created_at'         => (string) $row['created_at'],
        ];
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
