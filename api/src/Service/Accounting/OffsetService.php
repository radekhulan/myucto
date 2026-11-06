<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OffsetRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Invoice\InvoicePaymentService;

/**
 * Vzájemné zápočty pohledávek a závazků (Fáze F, § 1982 obč. zák.).
 *
 * Sestaví dohodu o zápočtu mezi firmou a jedním partnerem: proti sobě postaví
 * otevřené vydané faktury (pohledávky, 311) a přijaté faktury (závazky, 321). Σ
 * započtených pohledávek se MUSÍ rovnat Σ započtených závazků (kontrola v haléřích).
 *
 * Po POTVRZENÍ (confirm) vznikne JEDEN idempotentní účetní zápis 321 MD / 311 D
 * na započtenou částku (source_type='offset', source_id = id dohody) přes
 * {@see PostingService} a obě strany dokladů se vyrovnají:
 *   - vydaná faktura: evidovaná platba (invoice_payments) na započtenou částku
 *     (částečná i plná — status faktury přepne InvoicePaymentService),
 *   - přijatá faktura: status 'paid', pokud zápočet pokryje celý zbytek dokladu.
 *
 * Idempotence: confirm je gate-ovaný stavem dohody (draft → confirmed). Druhé
 * potvrzení už jen znovu (idempotentně) přepíše týž účetní zápis, nezdvojí platby
 * ani nevyrovná doklady podruhé.
 *
 * V1 rozsah: jen CZK doklady (booking = měna dokladu). Přijatá faktura vyrovnaná
 * částečně (zápočet < zbytek) zůstává ve stavu 'received'/'booked' — účetní zápis
 * 321 ji přesto sníží; plné označení 'paid' se děje jen při úplném pokrytí.
 */
final class OffsetService
{
    private const TOLERANCE_CENTS = 1; // 1 hal. — kontrola vyváženosti stran

    public function __construct(
        private readonly Connection $db,
        private readonly OffsetRepository $repo,
        private readonly PostingService $posting,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly PurchaseInvoiceRepository $purchaseInvoices,
        private readonly DocumentSeriesService $series,
        private readonly PostingRuleRepository $rules,
    ) {}

    /**
     * Otevřené pohledávky + závazky partnera pro sestavení zápočtu.
     *
     * @return array{partner_id:int, partner_name:string, receivables:list<array<string,mixed>>, payables:list<array<string,mixed>>}
     */
    public function openItemsForPartner(int $supplierId, int $partnerId): array
    {
        $name = $this->repo->partnerName($supplierId, $partnerId);
        if ($name === null) {
            throw new OffsetException('partner_not_found', 'Partner nenalezen.', 404);
        }
        return [
            'partner_id'   => $partnerId,
            'partner_name' => $name,
            'receivables'  => $this->repo->openReceivables($supplierId, $partnerId),
            'payables'     => $this->repo->openPayables($supplierId, $partnerId),
        ];
    }

    /**
     * Sestaví dohodu o zápočtu (stav draft, číslo z řady ZAP). Nevyrovnává ještě
     * doklady — to dělá až confirm.
     *
     * @param list<array{doc_type:string, doc_id:int, amount:float}> $rawItems
     * @return array<string,mixed> detail dohody (viz build)
     */
    public function create(int $supplierId, int $partnerId, string $date, array $rawItems, ?string $note, ?int $userId): array
    {
        if (!self::isDate($date)) {
            throw new OffsetException('validation_failed', 'agreement_date musí být datum (YYYY-MM-DD).');
        }
        $partnerName = $this->repo->partnerName($supplierId, $partnerId);
        if ($partnerName === null) {
            throw new OffsetException('partner_not_found', 'Partner nenalezen.', 404);
        }

        $recv = $this->indexByDoc($this->repo->openReceivables($supplierId, $partnerId));
        $pay  = $this->indexByDoc($this->repo->openPayables($supplierId, $partnerId));

        $invoiceItems = [];
        $purchaseItems = [];
        $invoiceCents = 0;
        $purchaseCents = 0;

        foreach ($rawItems as $i => $it) {
            $docType = (string) ($it['doc_type'] ?? '');
            $docId   = (int) ($it['doc_id'] ?? 0);
            $amount  = round((float) ($it['amount'] ?? 0), 2);
            if ($docId <= 0 || self::cents($amount) <= 0) {
                throw new OffsetException('validation_failed', 'Řádek #' . $i . ': neplatný doklad nebo částka.');
            }
            if ($docType === 'invoice') {
                $open = $recv[$docId] ?? null;
                if ($open === null) {
                    throw new OffsetException('doc_not_open', 'Vydaná faktura ' . $docId . ' není otevřená pohledávka partnera (nebo není CZK).');
                }
                if (self::cents($amount) > self::cents($open['remaining']) + self::TOLERANCE_CENTS) {
                    throw new OffsetException('amount_exceeds_remaining', 'Započtená částka ' . $amount . ' přesahuje zbytek faktury ' . $open['doc_no'] . ' (' . $open['remaining'] . ').');
                }
                $invoiceItems[] = ['doc_id' => $docId, 'amount' => $amount];
                $invoiceCents += self::cents($amount);
            } elseif ($docType === 'purchase_invoice') {
                $open = $pay[$docId] ?? null;
                if ($open === null) {
                    throw new OffsetException('doc_not_open', 'Přijatá faktura ' . $docId . ' není otevřený závazek partnera (nebo není CZK).');
                }
                if (self::cents($amount) > self::cents($open['remaining']) + self::TOLERANCE_CENTS) {
                    throw new OffsetException('amount_exceeds_remaining', 'Započtená částka ' . $amount . ' přesahuje zbytek faktury ' . $open['doc_no'] . ' (' . $open['remaining'] . ').');
                }
                $purchaseItems[] = ['doc_id' => $docId, 'amount' => $amount];
                $purchaseCents += self::cents($amount);
            } else {
                throw new OffsetException('validation_failed', 'Řádek #' . $i . ': doc_type musí být invoice nebo purchase_invoice.');
            }
        }

        if ($invoiceItems === [] || $purchaseItems === []) {
            throw new OffsetException('empty_side', 'Zápočet musí obsahovat aspoň jednu pohledávku i jeden závazek.');
        }
        if (abs($invoiceCents - $purchaseCents) > self::TOLERANCE_CENTS) {
            throw new OffsetException(
                'unbalanced_offset',
                sprintf(
                    'Strany zápočtu se nerovnají: pohledávky %.2f Kč vs. závazky %.2f Kč — sjednoť započtené částky.',
                    $invoiceCents / 100,
                    $purchaseCents / 100,
                ),
            );
        }
        $total = $invoiceCents / 100;

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $fiscalYear = (int) substr($date, 0, 4);
            $documentNo = $this->series->next($supplierId, 'offset', $fiscalYear);
            $agreementId = $this->repo->insertAgreement($supplierId, $partnerId, $date, $documentNo, $total, $note, $userId);
            foreach ($invoiceItems as $it) {
                $this->repo->insertItem($agreementId, $supplierId, 'invoice', $it['doc_id'], $it['amount']);
            }
            foreach ($purchaseItems as $it) {
                $this->repo->insertItem($agreementId, $supplierId, 'purchase_invoice', $it['doc_id'], $it['amount']);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->build($supplierId, $agreementId);
    }

    /**
     * Potvrdí zápočet: idempotentní zápis 321/311 + vyrovnání dokladů. Opakované
     * volání nezdvojí platby (gate na stavu draft), jen znovu idempotentně přepíše
     * účetní zápis.
     *
     * Adversariální review 2026-07 (KRITICKÝ + STŘEDNÍ nález core-posting):
     *   - Řádek dohody se zamyká FOR UPDATE HNED na začátku (uvnitř transakce) —
     *     serializuje dvě souběžná confirm() na TÝŽ zápočet. Bez zámku by obě volání
     *     přečetla status 'draft' dřív, než první stihne zapsat 'confirmed', a platba
     *     / zaúčtování by se provedly dvakrát.
     *   - Než se doklady vyrovnají, ZNOVU (s řádkovým zámkem na dokladu) se ověří, že
     *     započítávaná částka nepřesahuje AKTUÁLNÍ zbytek — draft nese zbytek ze
     *     sestavení dohody, ne z okamžiku potvrzení. Mezitím mohla dorazit jiná úhrada
     *     (banka) nebo se potvrdit JINÁ dohoda na týž doklad (create() kontroluje zbytek
     *     jen proti CONFIRMED zápočtům, takže dvě souběžné drafty na stejný doklad obě
     *     projdou). Bez tohoto re-checku by recordPayment vytvořil tichý přeplatek,
     *     resp. doklad by se naúčtoval dvakrát.
     *
     * @param array{entry_date?:string, description?:?string, posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function confirm(int $supplierId, int $agreementId, array $meta = []): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $agreement = $this->repo->lockAgreement($supplierId, $agreementId);
            if ($agreement === null) {
                throw new OffsetException('not_found', 'Dohoda o zápočtu nenalezena.', 404);
            }
            if ((string) $agreement['status'] === 'cancelled') {
                throw new OffsetException('agreement_cancelled', 'Zrušený zápočet nelze potvrdit.');
            }

            $items = $this->repo->itemsFor($agreementId);
            $total = (float) $agreement['total_amount'];
            $date  = (string) $agreement['agreement_date'];
            $wasDraft = (string) $agreement['status'] === 'draft';

            if ($wasDraft) {
                foreach ($items as $item) {
                    $this->assertStillOpen($supplierId, $agreementId, $item);
                }
            }

            $lines = $this->buildLines($supplierId, $total, $items);
            $postMeta = array_merge($meta, [
                'entry_date'  => $date,
                'document_no' => (string) $agreement['document_no'],
                'description' => $meta['description'] ?? ('Vzájemný zápočet ' . $agreement['document_no'] . ' — ' . $agreement['partner_name']),
            ]);

            // Idempotentní zápis 321/311 (přepis in-place při re-runu).
            $entryId = $this->posting->postDocument($supplierId, 'offset', $agreementId, $lines, $postMeta);

            // Vyrovnání dokladů jen při PRVNÍM potvrzení (draft) — jinak by se platby zdvojily.
            if ($wasDraft) {
                foreach ($items as $item) {
                    if ($item['doc_type'] === 'invoice') {
                        $this->settleInvoice($item, $date, (string) $agreement['document_no'], $meta['posted_by'] ?? null);
                    } else {
                        $this->settlePurchase($supplierId, $agreementId, $item, $date);
                    }
                }
                $this->repo->setConfirmed($agreementId, $supplierId, $entryId);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->build($supplierId, $agreementId);
    }

    /**
     * Re-validace zbytku dokladu k okamžiku potvrzení (viz KRITICKÝ nález v docblocku
     * {@see confirm()}). Volá se JEN pro draft dohody, uvnitř transakce confirm() —
     * repo metody drží řádkový zámek (FOR UPDATE) po zbytek transakce.
     *
     * @param array{doc_type:string, doc_id:int, amount:float, doc_no?:string} $item
     */
    private function assertStillOpen(int $supplierId, int $agreementId, array $item): void
    {
        $remaining = $item['doc_type'] === 'invoice'
            ? $this->repo->lockInvoiceRemaining($supplierId, $item['doc_id'])
            : $this->repo->lockPurchaseRemaining($supplierId, $item['doc_id'], $agreementId);

        $docLabel = $item['doc_no'] ?? ('#' . $item['doc_id']);
        if ($remaining === null) {
            throw new OffsetException('doc_not_found', 'Doklad ' . $docLabel . ' zápočtu nebyl nalezen.', 404);
        }
        if (self::cents((float) $item['amount']) > self::cents($remaining) + self::TOLERANCE_CENTS) {
            throw new OffsetException(
                'remaining_changed_since_draft',
                sprintf(
                    'Zbývající hodnota dokladu %s se od sestavení dohody změnila (aktuálně %.2f Kč, v dohodě %.2f Kč) '
                        . '— mezitím zřejmě přišla jiná úhrada nebo souběžný zápočet. Zruš dohodu a sestav ji znovu.',
                    $docLabel,
                    $remaining,
                    $item['amount'],
                ),
            );
        }
    }

    /**
     * Zruší zápočet: storno účetního zápisu + odvolání vyrovnání dokladů.
     *
     * Adversariální review 2026-07 (STŘEDNÍ nález): řádek dohody se zamyká FOR
     * UPDATE hned na začátku (stejně jako confirm()) — serializuje souběžné
     * cancel()/confirm() volání téže dohody.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function cancel(int $supplierId, int $agreementId, array $meta = []): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $agreement = $this->repo->lockAgreement($supplierId, $agreementId);
            if ($agreement === null) {
                throw new OffsetException('not_found', 'Dohoda o zápočtu nenalezena.', 404);
            }
            if ((string) $agreement['status'] === 'cancelled') {
                throw new OffsetException('already_cancelled', 'Zápočet je už zrušený.');
            }

            if ((string) $agreement['status'] === 'confirmed') {
                foreach ($this->repo->itemsFor($agreementId) as $item) {
                    if ($item['doc_type'] === 'invoice' && $item['invoice_payment_id'] !== null) {
                        $this->invoicePayments->deletePayment($item['invoice_payment_id'], skipBankGuard: true);
                    } elseif ($item['doc_type'] === 'purchase_invoice' && $this->purchaseStatus($supplierId, $item['doc_id']) === 'paid') {
                        $this->purchaseInvoices->setStatus($item['doc_id'], 'received', $supplierId);
                    }
                }
                if ($agreement['journal_entry_id'] !== null) {
                    $this->posting->reverse($supplierId, (int) $agreement['journal_entry_id'], $meta);
                }
            }
            $this->repo->setCancelled($agreementId, $supplierId);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->build($supplierId, $agreementId);
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $agreementId): array
    {
        $agreement = $this->repo->findAgreement($supplierId, $agreementId);
        if ($agreement === null) {
            throw new OffsetException('not_found', 'Dohoda o zápočtu nenalezena.', 404);
        }
        $items = $this->repo->itemsFor($agreementId);
        $receivables = array_values(array_filter($items, static fn (array $i): bool => $i['doc_type'] === 'invoice'));
        $payables    = array_values(array_filter($items, static fn (array $i): bool => $i['doc_type'] === 'purchase_invoice'));
        return [
            'agreement'   => $agreement,
            'receivables' => $receivables,
            'payables'    => $payables,
        ];
    }

    /** @param array{status?:string} $filters @return list<array<string,mixed>> */
    public function list(int $supplierId, array $filters = []): array
    {
        return $this->repo->listAgreements($supplierId, $filters);
    }

    // ── interní ────────────────────────────────────────────────────────────────

    /**
     * @param array{doc_id:int, amount:float, id:int} $item
     */
    private function settleInvoice(array $item, string $date, string $documentNo, ?int $createdBy): void
    {
        try {
            $res = $this->invoicePayments->recordPayment(
                $item['doc_id'],
                $item['amount'],
                $date,
                ['source' => 'manual', 'note' => 'Zápočet ' . $documentNo, 'created_by' => $createdBy],
            );
        } catch (\RuntimeException $e) {
            throw new OffsetException('settlement_failed', 'Vyrovnání vydané faktury selhalo: ' . $e->getMessage());
        }
        $this->repo->setItemPaymentId($item['id'], $res['payment_id']);
    }

    /**
     * @param array{doc_id:int, amount:float} $item
     */
    private function settlePurchase(int $supplierId, int $agreementId, array $item, string $date): void
    {
        $remaining = $this->repo->purchaseRemaining($supplierId, $item['doc_id'], $agreementId);
        // Plné pokrytí → 'paid'. Částečné → doklad zůstává (v1 nemá paid_total pro PF),
        // účetní zápis 321 ho přesto sníží.
        if (self::cents($item['amount']) >= self::cents($remaining) - self::TOLERANCE_CENTS) {
            $this->purchaseInvoices->setStatus($item['doc_id'], 'paid', $supplierId, $date);
        }
    }

    /**
     * Strana závazků se rozpadne podle účtu, na kterém závazek každé přijaté faktury
     * vznikl ({@see PurchasePayableAccount::forPayment}) — účtenka na 325 se odúčtuje z 325.
     * Leží-li všechny na účtu z kontace, zůstává jeden řádek jako dosud.
     *
     * @param list<array<string,mixed>> $items položky dohody (doc_type, doc_id, amount)
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float}>
     */
    private function buildLines(int $supplierId, float $total, array $items = []): array
    {
        $rule = $this->rules->resolve($supplierId, 'offset.mutual');
        $debit  = (string) ($rule['debit_account_code'] ?? '321');
        $credit = $rule['credit_account_code'] ?? '311';

        $payables = new PurchasePayableAccount($this->db);
        $byAccount = [];
        foreach ($items as $item) {
            if (($item['doc_type'] ?? '') !== 'purchase_invoice') {
                continue;
            }
            $account = $payables->forPayment($supplierId, (int) $item['doc_id'], $debit);
            if ($account !== $debit) {
                $byAccount[$account] = round(($byAccount[$account] ?? 0.0) + (float) $item['amount'], 2);
            }
        }
        $lines = [];
        $rest = round($total, 2);
        foreach ($byAccount as $account => $amount) {
            $lines[] = ['account_code' => (string) $account, 'side' => 'debit', 'amount' => $amount];
            $rest = round($rest - $amount, 2);
        }
        if ($byAccount === [] || self::cents($rest) !== 0) {
            array_unshift($lines, ['account_code' => $debit, 'side' => 'debit', 'amount' => $rest]);
        }
        $lines[] = ['account_code' => (string) $credit, 'side' => 'credit', 'amount' => round($total, 2)];
        return $lines;
    }

    private function purchaseStatus(int $supplierId, int $docId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$docId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    /**
     * @param list<array{doc_id:int, remaining:float, doc_no:string}> $rows
     * @return array<int, array{doc_id:int, remaining:float, doc_no:string}>
     */
    private function indexByDoc(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['doc_id']] = $r;
        }
        return $out;
    }

    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
