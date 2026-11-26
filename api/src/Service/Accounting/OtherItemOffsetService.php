<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalSettlementRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Invoice\InvoiceOtherItemPayments;
use PDO;

/**
 * Vyrovnání ostatní pohledávky nebo závazku zápočtem, tedy zápisem v deníku místo banky
 * a pokladny (migrace 2351).
 *
 * Typický případ: pojistné plnění na 315 se započte proti přijaté faktuře. Zápočet nese
 * interní doklad, saldokonto i agenda ho musí vidět jako úhradu položky.
 *
 * Dvě cesty:
 *   - {@see offset()}: zaúčtuje interní doklad druhu `offset` (pohledávka: protiúčet MD /
 *     účet položky D, závazek obráceně, dimenze a partner z položky) a jeho řádek na účtu
 *     položky k položce přiřadí,
 *   - {@see attach()}: zápočet je už zaúčtovaný jinde (ruční zápis, interní doklad,
 *     převzatý zápis); vybraný řádek deníku na účtu položky se jen přiřadí.
 *
 * Přiřazení = rozpad řádku na doklad saldokonta (`journal_settlement_allocations`, druh
 * `settlement`, doc_type `other_item`) + úhrada položky (`other_item_allocations`
 * s `journal_allocation_id`). Rozpad drží řádek deníku pod JournalSettlementUseGuard a dělí
 * jeho volnou částku s rozpady na faktury; úhrada nese uhrazenou část, kterou počítají stav
 * položky, splátky, měsíční kontrola, předpověď i saldokonto ({@see \MyInvoice\Repository\SaldoRepository}).
 * Proto ne `settled_outside_amount`: ten datum ani vazbu na zápis nenese a saldokonto
 * by vyrovnání nemohlo konfrontovat s hlavní knihou.
 *
 * Zápočet snižuje jen jistinu (saldo na účtu položky); úrok a poplatek rozdělené splátky
 * se účtují s úhradou, ne zápočtem.
 *
 * Zrušení: vlastní interní doklad se stornuje ({@see cancel()}, storno zápisu úhradu
 * stornuje v PostingService::reverse, historie zůstává), přiřazený cizí zápis se jen odebere
 * ({@see detach()}). Uzavřené období a zamčené datum hlídá zaúčtování a storno; přiřazení
 * k zápisu v uzavřeném období mění saldokonto k jeho konci, proto se odmítne.
 */
final class OtherItemOffsetService
{
    /** Předvolby protiúčtu zápočtu v dialogu (pořadí nabídky). */
    public const SUGGESTED_ACCOUNTS = ['395', '379', '321', '311'];
    private const MAX_CANDIDATES = 50;
    private const TOLERANCE = 0.005;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemService $items,
        private readonly JournalSettlementRepository $settlements,
        private readonly InternalDocumentService $internalDocuments,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly PostingService $posting,
    ) {}

    /**
     * Údaje pro dialog: účet položky, strana řádku, která saldo snižuje, zbývá k vyrovnání
     * a předvolby protiúčtu, které firma v osnově má.
     *
     * @return array<string,mixed>
     */
    public function context(int $supplierId, int $itemId): array
    {
        $item = $this->items->get($supplierId, $itemId);
        $account = $this->ledgerAccountOrNull($supplierId, $item);
        $suggested = [];
        foreach (self::SUGGESTED_ACCOUNTS as $code) {
            $row = $this->accounts->findByCode($supplierId, $code);
            if ($row !== null && $row['is_active'] && ($account === null || !SaldoAccountMatch::belongsTo($code, self::synthetic($account['code'])))) {
                $suggested[] = ['account_id' => (int) $row['id'], 'account_code' => $code, 'name' => (string) $row['name']];
            }
        }
        return [
            'supported'          => $account !== null && $item['currency'] === 'CZK'
                && in_array($item['status'], ['posted', 'confirmed'], true) && $this->items->isDoubleEntry($supplierId),
            'account'            => $account,
            'side'               => self::reduceSide((string) $item['side']),
            'remaining'          => round((float) $item['remaining_amount'], 2),
            'suggested_accounts' => $suggested,
        ];
    }

    /**
     * Volné řádky ručních zápisů na účtu položky a straně, která saldo snižuje (zápočet
     * zaúčtovaný jinde). Výběr i volnou částku řádku počítá stejně jako saldokonto.
     *
     * @return list<array<string,mixed>>
     */
    public function lineCandidates(int $supplierId, int $itemId, ?string $search = null): array
    {
        $item = $this->items->get($supplierId, $itemId);
        $account = $this->ledgerAccountOrNull($supplierId, $item);
        if ($account === null || $item['currency'] !== 'CZK') {
            return [];
        }
        return $this->settlements->freeLines($supplierId, [$account['id']], self::reduceSide((string) $item['side']),
            '9999-12-31', null, self::MAX_CANDIDATES, null, $search, true);
    }

    /**
     * Zápočet novým interním dokladem.
     *
     * @param array{account_id?:mixed, date?:mixed, amount?:mixed, note?:mixed} $input
     * @param array<string,mixed> $audit user_id, ip, user_agent
     * @return array{allocation_id:int, internal_document_id:int, entry_id:int}
     */
    public function offset(int $supplierId, int $itemId, array $input, array $audit): array
    {
        $date = trim((string) ($input['date'] ?? ''));
        if (!IsoDate::isValid($date)) {
            throw new OtherItemException('invalid_date', 'Zadejte datum zápočtu.', 422, 'date');
        }
        $amount = self::amount($input['amount'] ?? null);
        $accountId = filter_var($input['account_id'] ?? null, FILTER_VALIDATE_INT);
        if ($accountId === false || $accountId <= 0) {
            throw new OtherItemException('offset_account_required', 'Vyberte protiúčet zápočtu.', 422, 'account_id');
        }
        $note = trim(is_scalar($input['note'] ?? null) ? (string) $input['note'] : '');
        if (mb_strlen($note) > 500) {
            throw new OtherItemException('invalid_note', 'Poznámka může mít nejvýš 500 znaků.', 422, 'note');
        }
        $userId = isset($audit['user_id']) ? (int) $audit['user_id'] : null;

        return $this->transaction(function () use ($supplierId, $itemId, $date, $amount, $accountId, $note, $audit, $userId): array {
            $item = $this->lockOpenItem($supplierId, $itemId, $amount, $date);
            $ledger = $this->ledgerAccount($supplierId, $item);
            $counter = $this->accounts->findById($supplierId, (int) $accountId, $date)
                ?? throw new OtherItemException('offset_account_not_found', 'Protiúčet nebyl v účtové osnově nalezen.', 404, 'account_id');
            if (!$counter['is_active']) {
                throw new OtherItemException('offset_account_inactive', 'Protiúčet je v účtové osnově neaktivní.', 422, 'account_id');
            }
            $counterCode = (string) $counter['account_code'];
            if (!self::isCounterAccountType((string) $counter['account_type'])) {
                throw new OtherItemException('offset_account_not_balance',
                    'Protiúčet zápočtu musí být rozvahový účet (pohledávka, závazek, zúčtování), ne nákladový, výnosový, uzávěrkový ani podrozvahový.', 422, 'account_id');
            }
            if (SaldoAccountMatch::belongsTo($counterCode, '343')) {
                throw new OtherItemException('offset_account_vat',
                    'Účet DPH 343 nemůže být protiúčtem zápočtu: daň se vypořádává přiznáním, ne zápočtem.', 422, 'account_id');
            }
            $itemSynthetic = self::synthetic($ledger['code']);
            if (SaldoAccountMatch::belongsTo($counterCode, $itemSynthetic)
                || $this->posting->redirectedAccountCode($supplierId, $counterCode, $date)
                    === $this->posting->redirectedAccountCode($supplierId, $ledger['code'], $date)) {
                throw new OtherItemException('offset_account_same',
                    'Protiúčet nemůže být účet, na kterém položka visí (' . $ledger['code'] . ').', 422, 'account_id');
            }

            $receivable = $item['side'] === 'receivable';
            $dimensions = $this->itemDimensions($supplierId, $itemId);
            $lines = [
                ['account_code' => $receivable ? $counterCode : $ledger['code'], 'side' => 'debit', 'amount' => $amount, 'dimensions' => $dimensions],
                ['account_code' => $receivable ? $ledger['code'] : $counterCode, 'side' => 'credit', 'amount' => $amount, 'dimensions' => $dimensions],
            ];
            $number = (string) ($item['document_no'] ?? ('#' . $itemId));
            try {
                $document = $this->internalDocuments->create($supplierId, [
                    'kind'          => 'offset',
                    'title'         => mb_substr('Zápočet ' . $number . ': ' . (string) $item['title'], 0, 255),
                    'document_date' => $date,
                    'entry_date'    => $date,
                    'partner_id'    => $item['partner_id'] !== null ? (int) $item['partner_id'] : null,
                    'partner_name'  => $item['partner_name'],
                    'note'          => $note !== '' ? $note : null,
                    'lines'         => $lines,
                ], $userId);
                try {
                    $document = $this->internalDocuments->post($supplierId, (int) $document['id'], $audit + ['posted_by' => $userId]);
                } catch (\Throwable $e) {
                    // Ve vlastní transakci koncept odnese rollback; ve vnější transakci volajícího
                    // by po odmítnutém zaúčtování (uzavřené období, zamčené datum) zůstal viset.
                    if ($this->pdo()->inTransaction() && !TransactionFailure::aborted($e)) {
                        $this->internalDocuments->deleteDraft($supplierId, (int) $document['id']);
                    }
                    throw $e;
                }
            } catch (InternalDocumentException $e) {
                throw new OtherItemException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            $entryId = (int) $document['journal_entry_id'];
            $line = $this->pdo()->prepare(
                'SELECT id FROM journal_entry_lines
                  WHERE supplier_id = ? AND entry_id = ? AND account_id = ? AND side = ? AND is_red_storno = 0
                  ORDER BY line_no, id LIMIT 1'
            );
            $line->execute([$supplierId, $entryId, $ledger['id'], self::reduceSide((string) $item['side'])]);
            $lineId = $line->fetchColumn();
            if ($lineId === false) {
                throw new OtherItemException('offset_posting_mismatch',
                    'Zápis zápočtu nemá řádek na účtu položky ' . $ledger['code'] . '. Zkontrolujte přesměrování účtů.', 409);
            }
            $allocationId = $this->assign($supplierId, $item, $ledger, (int) $lineId, $amount, $date, $userId, (int) $document['id']);
            return ['allocation_id' => $allocationId, 'internal_document_id' => (int) $document['id'], 'entry_id' => $entryId];
        });
    }

    /**
     * Přiřadí položce řádek zápisu, který zápočet už nese (bez nového zápisu).
     *
     * @param array{line_id?:mixed, amount?:mixed} $input
     * @return array{allocation_id:int, entry_id:int}
     */
    public function attach(int $supplierId, int $itemId, array $input, ?int $userId): array
    {
        $lineId = filter_var($input['line_id'] ?? null, FILTER_VALIDATE_INT);
        if ($lineId === false || $lineId <= 0) {
            throw new OtherItemException('offset_line_required', 'Vyberte řádek zápisu v deníku.', 422, 'line_id');
        }
        $amount = self::amount($input['amount'] ?? null);
        return $this->transaction(function () use ($supplierId, $itemId, $lineId, $amount, $userId): array {
            $item = $this->lockOpenItem($supplierId, $itemId, $amount);
            $ledger = $this->ledgerAccount($supplierId, $item);
            $allocationId = $this->assign($supplierId, $item, $ledger, (int) $lineId, $amount, null, $userId, null);
            $entry = $this->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE id = ? AND supplier_id = ?');
            $entry->execute([$lineId, $supplierId]);
            return ['allocation_id' => $allocationId, 'entry_id' => (int) $entry->fetchColumn()];
        });
    }

    /**
     * Odebere přiřazení cizího zápisu (zápis zůstává). Vyrovnání s vlastním interním
     * dokladem se ruší stornem ({@see cancel()}).
     *
     * @return array<string,mixed> odebrané přiřazení
     */
    public function detach(int $supplierId, int $itemId, int $allocationId): array
    {
        return $this->transaction(function () use ($supplierId, $itemId, $allocationId): array {
            $this->items->lockForAllocation($supplierId, $itemId);
            $allocation = $this->lockAllocation($supplierId, $itemId, $allocationId);
            if ($allocation['internal_document_id'] !== null) {
                throw new OtherItemException('offset_cancel_required',
                    'Zápočet zaúčtovaný z položky zrušíte stornem jeho interního dokladu.', 409);
            }
            $status = $this->settlements->entryPeriodStatus($supplierId, (int) $allocation['entry_id']);
            if ($status !== null && AccountingPeriodStatus::isClosed($status)) {
                throw new OtherItemException('offset_period_closed',
                    'Zápis leží v uzavřeném účetním období. Přiřazení by změnilo saldokonto uzavřeného období.', 409);
            }
            $this->settlements->delete($supplierId, (int) $allocation['journal_allocation_id']);
            InvoiceOtherItemPayments::syncForItems($this->pdo(), $supplierId, [$itemId]);
            return $allocation;
        });
    }

    /**
     * Storno zápočtu zaúčtovaného z položky: storno interního dokladu. Úhradu stornuje
     * storno zápisu (PostingService::reverse), v detailu zůstane jako stornovaná.
     *
     * @param array<string,mixed> $audit
     * @return array<string,mixed> stornované přiřazení (s `reversal_entry_id`)
     */
    public function cancel(int $supplierId, int $itemId, int $allocationId, string $reason, ?string $entryDate, array $audit): array
    {
        if ($entryDate !== null && !IsoDate::isValid($entryDate)) {
            throw new OtherItemException('invalid_date', 'Neplatné datum storna.', 422, 'entry_date');
        }
        return $this->transaction(function () use ($supplierId, $itemId, $allocationId, $reason, $entryDate, $audit): array {
            $this->items->lockForAllocation($supplierId, $itemId);
            $allocation = $this->lockAllocation($supplierId, $itemId, $allocationId);
            if ($allocation['internal_document_id'] === null) {
                throw new OtherItemException('offset_not_own', 'Přiřazený zápis není zápočtem z položky. Odeberte jen přiřazení.', 409);
            }
            if ($allocation['reversed_on'] !== null) {
                throw new OtherItemException('offset_already_reversed', 'Zápočet je už stornovaný.', 409);
            }
            try {
                $document = $this->internalDocuments->reverse($supplierId, (int) $allocation['internal_document_id'],
                    $reason, $entryDate, $audit);
            } catch (InternalDocumentException $e) {
                throw new OtherItemException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            $after = $this->lockAllocation($supplierId, $itemId, $allocationId);
            if ($after['reversed_on'] === null) {
                throw new \LogicException('Storno zápočtu nestornovalo úhradu položky.');
            }
            return $after + ['reversal_entry_id' => $document['reversal_entry_id'] !== null ? (int) $document['reversal_entry_id'] : null];
        });
    }

    /**
     * Rozpad řádku na položku + úhrada položky. Volá se pod zámkem položky.
     *
     * @param array<string,mixed> $item zamčená položka ({@see OtherItemService::lockForAllocation()})
     * @param array{id:int, code:string} $ledger
     */
    private function assign(int $supplierId, array $item, array $ledger, int $lineId, float $amount, ?string $paymentOn, ?int $userId, ?int $internalDocumentId): int
    {
        $itemId = (int) $item['id'];
        $this->settlements->lockForAllocation($supplierId, [$lineId], []);
        $line = $this->settlements->lines($supplierId, [$lineId])[$lineId]
            ?? throw new OtherItemException('offset_line_not_found', 'Řádek zápisu nebyl nalezen.', 404, 'line_id');
        $ref = $line['document_no'] ?? ('#' . $line['entry_id']);
        if (!$line['posted'] || $line['reversed'] || $line['is_reversal']) {
            throw new OtherItemException('offset_line_not_live', 'Zápis ' . $ref . ' není zaúčtovaný, nebo je stornovaný.', 409, 'line_id', ['document_no' => $ref]);
        }
        if (!in_array($line['source_type'], JournalSettlementRepository::ALLOCATABLE_SOURCES, true) || $line['own_posting']) {
            throw new OtherItemException('offset_line_source', 'Přiřadit lze jen řádek ručního (interního) zápisu, ne zaúčtování dokladu.', 409, 'line_id', ['document_no' => $ref]);
        }
        if ($line['imported_settlement'] || $line['duplicate_line_no'] || $line['is_red_storno']) {
            throw new OtherItemException('offset_line_unusable', 'Řádek zápisu ' . $ref . ' nelze k položce přiřadit (zápočet z převodu, zdvojené pořadí nebo červené storno).', 409, 'line_id', ['document_no' => $ref]);
        }
        if ($line['currency_code'] !== null && strtoupper($line['currency_code']) !== 'CZK') {
            throw new OtherItemException('offset_line_currency', 'Řádek v cizí měně nelze k položce přiřadit.', 409, 'line_id', ['document_no' => $ref]);
        }
        if ($line['account_id'] !== $ledger['id'] || $line['side'] !== self::reduceSide((string) $item['side'])) {
            throw new OtherItemException('offset_line_account',
                'Řádek zápisu ' . $ref . ' není na účtu položky ' . $ledger['code'] . ' na straně, která saldo snižuje.', 409, 'line_id',
                ['document_no' => $ref, 'account' => $ledger['code']]);
        }
        if ($line['entry_date'] < self::itemDate($item)) {
            throw new OtherItemException('offset_date_before_item',
                'Zápis ' . $ref . ' předchází vzniku položky (' . self::itemDate($item) . ').', 409, 'line_id',
                ['document_no' => $ref, 'item_date' => self::itemDate($item)]);
        }
        if ($line['period_status'] !== null && AccountingPeriodStatus::isClosed($line['period_status'])) {
            throw new OtherItemException('offset_period_closed',
                'Zápis ' . $ref . ' leží v uzavřeném účetním období. Přiřazení by změnilo saldokonto uzavřeného období.', 409, 'line_id', ['document_no' => $ref]);
        }
        // Stará vazba zápisu na fakturu spotřebuje jen řádek na saldokontním účtu té faktury.
        if ($this->settlements->hasCountedLinkOnAccount($supplierId, $line['entry_id'], $line['account_id'])) {
            throw new OtherItemException('offset_entry_linked', 'Zápis ' . $ref . ' už vyrovnává fakturu vazbou v deníku na účtu ' . $line['account_code'] . '.', 409, 'line_id', ['document_no' => $ref, 'account' => $line['account_code']]);
        }
        $free = round($line['amount'] - $this->settlements->usedOnLine($supplierId, $line), 2);
        if ($amount > $free + self::TOLERANCE) {
            throw new OtherItemException('offset_line_overallocated',
                'Řádek zápisu ' . $ref . ' má volných jen ' . number_format($free, 2, ',', ' ') . ' Kč.', 409, 'amount', ['document_no' => $ref, 'free' => $free]);
        }
        $existing = $this->pdo()->prepare(
            "SELECT 1 FROM journal_settlement_allocations
              WHERE supplier_id = ? AND entry_id = ? AND line_no = ? AND account_id = ? AND side = ?
                AND doc_type = 'other_item' AND doc_id = ? LIMIT 1"
        );
        $existing->execute([$supplierId, $line['entry_id'], $line['line_no'], $line['account_id'], $line['side'], $itemId]);
        if ($existing->fetchColumn() !== false) {
            throw new OtherItemException('offset_line_used',
                'Řádek zápisu ' . $ref . ' už je k položce přiřazený. Odeberte přiřazení a přiřaďte ho znovu s celou částkou.', 409, 'line_id', ['document_no' => $ref]);
        }

        $journalAllocationId = $this->settlements->insert($supplierId, $line, 'settlement', 'other_item', $itemId, $amount, $userId);
        $coverage = $this->items->installmentCoverage($supplierId, $itemId);
        $this->pdo()->prepare(
            'INSERT INTO other_item_allocations
               (supplier_id, other_item_id, installment_id, journal_allocation_id, internal_document_id, amount, payment_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $itemId, OtherItemInstallmentCoverage::target($coverage, $amount), $journalAllocationId,
            $internalDocumentId, $amount, $paymentOn ?? $line['entry_date'], $userId]);
        $allocationId = (int) $this->pdo()->lastInsertId();
        InvoiceOtherItemPayments::syncForItems($this->pdo(), $supplierId, [$itemId]);
        return $allocationId;
    }

    /** @return array<string,mixed> */
    private function lockOpenItem(int $supplierId, int $itemId, float $amount, ?string $date = null): array
    {
        if (!$this->items->isDoubleEntry($supplierId)) {
            throw new OtherItemException('offset_double_entry_only', 'Vyrovnání zápočtem je dostupné jen v podvojném účetnictví.', 409);
        }
        $item = $this->items->lockForAllocation($supplierId, $itemId);
        if (!in_array($item['status'], ['posted', 'confirmed'], true)) {
            throw new OtherItemException('not_posted', 'Zápočtem lze vyrovnat jen potvrzený doklad.', 409);
        }
        if ($item['currency'] !== 'CZK') {
            throw new OtherItemException('currency_unsupported', 'Položku v cizí měně vyrovnejte kurzovým zápisem v účetnictví.', 409);
        }
        if ($date !== null && $date < self::itemDate($item)) {
            throw new OtherItemException('offset_date_before_item',
                'Zápočet nemůže předcházet vzniku položky (' . self::itemDate($item) . ').', 422, 'date',
                ['item_date' => self::itemDate($item)]);
        }
        if ($amount > round((float) $item['remaining_amount'], 2) + self::TOLERANCE) {
            throw new OtherItemException('overpayment', 'Částka převyšuje zbývající dluh '
                . number_format(max(0.0, (float) $item['remaining_amount']), 2, ',', ' ') . ' Kč.', 409, 'amount',
                ['remaining' => max(0.0, round((float) $item['remaining_amount'], 2))]);
        }
        return $item;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{id:int, code:string}
     */
    private function ledgerAccount(int $supplierId, array $item): array
    {
        return $this->ledgerAccountOrNull($supplierId, $item)
            ?? throw new OtherItemException('offset_not_posted', 'Položka nemá v deníku zaúčtovaný předpis, zápočtem ji vyrovnat nelze.', 409);
    }

    /**
     * @param array<string,mixed> $item
     * @return array{id:int, code:string}|null
     */
    private function ledgerAccountOrNull(int $supplierId, array $item): ?array
    {
        try {
            return $this->items->ledgerAccount($supplierId, $item);
        } catch (OtherItemException) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function lockAllocation(int $supplierId, int $itemId, int $allocationId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT a.id, a.other_item_id, a.journal_allocation_id, a.internal_document_id, a.amount, a.payment_on,
                    a.reversed_on, ja.entry_id
               FROM other_item_allocations a
               JOIN journal_settlement_allocations ja ON ja.id = a.journal_allocation_id AND ja.supplier_id = a.supplier_id
              WHERE a.id = ? AND a.supplier_id = ? AND a.other_item_id = ? FOR UPDATE'
        );
        $stmt->execute([$allocationId, $supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new OtherItemException('payment_not_found', 'Vyrovnání zápočtem nebylo nalezeno.', 404);
        }
        foreach (['id', 'other_item_id', 'journal_allocation_id', 'entry_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['internal_document_id'] = $row['internal_document_id'] !== null ? (int) $row['internal_document_id'] : null;
        $row['amount'] = round((float) $row['amount'], 2);
        return $row;
    }

    /**
     * Dimenze hlavičky položky (vlastní i výchozí) pro oba řádky zápočtu, jako je zápis
     * položky a její úhrady přebírají. Uzavřenou hodnotu zápočet nepřenese: nový zápis
     * by ji odmítl.
     *
     * @return array<int,int> typ => hodnota
     */
    private function itemDimensions(int $supplierId, int $itemId): array
    {
        $header = (new DimensionDefaults($this->db))->effectiveDimensions($supplierId, 'other_item', $itemId)['header'];
        if ($header === []) return [];
        $values = (new DimensionRepository($this->db))->valuesByIds($supplierId, array_values($header));
        return array_filter($header, static fn (int $valueId): bool => !empty($values[$valueId]['is_active']));
    }

    /** Protiúčet zápočtu je rozvahový účet; náklady, výnosy, uzávěrkové a podrozvahové účty ne. */
    public static function isCounterAccountType(string $accountType): bool
    {
        return in_array($accountType, ['asset', 'liability', 'equity'], true);
    }

    /** Strana řádku, která saldo položky snižuje (pohledávka: Dal, závazek: MD). */
    public static function reduceSide(string $itemSide): string
    {
        return $itemSide === 'receivable' ? 'credit' : 'debit';
    }

    /** Den vzniku položky v účetnictví (účetní případ, jinak vystavení). */
    private static function itemDate(array $item): string
    {
        return (string) ($item['accounting_on'] ?: $item['issued_on']);
    }

    private static function synthetic(string $code): string
    {
        return substr($code, 0, 3);
    }

    private static function amount(mixed $raw): float
    {
        $amount = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || $amount > 999999999999.99 || abs(round((float) $amount, 2) - (float) $amount) > 1e-9) {
            throw new OtherItemException('invalid_amount', 'Částka musí být kladná a zadaná na haléře.', 422, 'amount');
        }
        return round((float) $amount, 2);
    }

    /**
     * READ COMMITTED: zbytek položky i volná částka řádku se čtou až po zámcích (vzor
     * {@see JournalSettlementService::allocate()}). Odvolanou transakci zopakuje celou.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function transaction(callable $call): mixed
    {
        $pdo = $this->pdo();
        return TransactionFailure::retrying($pdo, function () use ($pdo, $call): mixed {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            if ($ownTx) $pdo->beginTransaction();
            try {
                $result = $call();
                if ($ownTx) $pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }

    private function pdo(): PDO
    {
        return $this->db->pdo();
    }
}
