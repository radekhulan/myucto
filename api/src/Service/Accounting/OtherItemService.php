<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Invoice\InvoiceOtherItemPayments;
use PDO;

final class OtherItemService
{
    private const KINDS = ['other', 'rent', 'loan', 'deposit', 'insurance', 'fee', 'claim'];

    /**
     * Peněžní částka úhrady (jistina, úrok a poplatek, migrace 2241). Čerpání platby
     * (kolik z pohybu či pokladního dokladu je už použito) se počítá z ní; saldo položky
     * jen z jistiny (`amount`).
     */
    public const ALLOCATION_TOTAL_SQL = 'amount + interest_amount + fee_amount';

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
        private readonly PostingService $posting,
        private readonly DocumentSeriesService $series,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly OtherItemKindService $kinds,
        private readonly OtherItemPostingResolver $postingResolver,
        private readonly OtherItemPostingProfileService $postingProfiles,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Detail položky. Podvojné účetnictví k němu dostane vlastní kontaci úhrad
     * (`posting_overrides`) a účinnou kontaci událostí s původem (`posting_rules`).
     */
    public function get(int $supplierId, int $id): array
    {
        $item = $this->items->find($supplierId, $id)
            ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
        $item['posting_overrides'] = (object) [];
        $item['posting_rules'] = (object) [];
        // Rozdělené splátky (migrace 2241): saldo položky je jistina, platit se má i úrok a poplatek.
        $coverage = $this->installmentCoverage($supplierId, $id);
        $item['has_split_installments'] = OtherItemInstallmentCoverage::hasSplit($coverage);
        $item['remaining_payment'] = self::payableOf($item, $coverage);
        $item['linked_invoice'] = $this->linkedInvoice($supplierId, $id);
        if ($this->isDoubleEntry($supplierId)) {
            $item['posting_overrides'] = (object) $this->postingProfiles->overridesForItem($supplierId, $id);
            $item['posting_rules'] = (object) $this->postingResolver->forItem($supplierId, $item);
        }
        return $item;
    }

    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        return $this->items->list($supplierId, $filters, $page, $perPage);
    }

    /**
     * @param array{kind:string,kind_id:?int}|null $templateKind druh, který nese šablona opakování —
     *                                                         výskyt ho dostane, i když druh mezitím skryli
     */
    public function create(int $supplierId, array $input, ?int $userId, ?array $templateKind = null): array
    {
        $data = $this->normalize($supplierId, $input, $templateKind);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $id = $this->items->insert($supplierId, $data, $userId);
            $this->items->replacePostingLines($supplierId, $id, $data['posting_lines']);
            if (array_key_exists('posting_overrides', $input) && $input['posting_overrides'] !== null) {
                $this->postingProfiles->saveItemOverrides($supplierId, ['id' => $id, 'side' => $data['side']],
                    $input['posting_overrides'], $userId);
            }
            // Výskyt opakování dostane dimenze zdroje ({@see OtherItemScheduleService::generate}),
            // výchozí dimenze druhu jen nově pořízený doklad.
            if ($templateKind === null) {
                $this->applyKindDimensions($supplierId, $id, $data);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Položka ze zůstatku bez dokladu: předpis už je v deníku (řádek převzatého nebo
     * interního zápisu), proto se položka NEúčtuje. Vzniká rovnou potvrzená, s číslem
     * z řady a bez vlastního zápisu; saldokonto ji čte z rozpadu řádku
     * (`journal_settlement_allocations` druhu `origin`), který zakládá volající
     * ({@see JournalSettlementService::createOtherItem}) v téže transakci. Storno takové
     * položky (`reverse`) ji jen zruší, žádný protizápis nevzniká.
     *
     * Účet položky nese účet řádku. Strana se řídí stranou řádku, ne druhem účtu: přeplatek
     * na 311 je závazek vedený na účtu pohledávek a saldokonto ho musí odečíst.
     */
    public function createFromJournalLine(int $supplierId, array $input, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('OtherItemService::createFromJournalLine vyžaduje transakci.');
        }
        $data = $this->normalize($supplierId, $input);
        $id = $this->items->insert($supplierId, $data, $userId);
        $number = $this->nextNumber($supplierId, $data, (string) $data['issued_on']);
        $this->setPosted($supplierId, $id, 'confirmed', $number, null);
        return $this->get($supplierId, $id);
    }

    /** Je položka založená ze zůstatku zápisu a její předpis ještě platí? */
    public function hasJournalOrigin(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_settlement_allocations
              WHERE supplier_id = ? AND doc_type = 'other_item' AND doc_id = ? AND kind = 'origin' AND released_on IS NULL LIMIT 1"
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Má položka aktivní úhradu? Úhrada samotného úroku či poplatku rozdělené splátky
     * (migrace 2241) má jistinu 0, takže ji `paid_amount` nevidí.
     */
    public function hasActivePayments(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM other_item_allocations
            WHERE supplier_id = ? AND other_item_id = ? AND reversed_on IS NULL LIMIT 1');
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function update(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $existing = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($existing['status'] !== 'draft') {
                throw new OtherItemException('not_draft', 'Upravovat lze jen koncept.', 409);
            }
            $data = $this->normalize($supplierId, $this->mergeStored($supplierId, $id, $existing, $input), $existing);
            // Částka položky je jistina; rozdělená splátka nese úrok a poplatek navíc (migrace 2241).
            $planned = $pdo->prepare('SELECT COALESCE(SUM(COALESCE(principal_amount, amount)), 0) AS total, MIN(due_on) AS first_due
                FROM other_item_installments WHERE supplier_id = ? AND other_item_id = ?');
            $planned->execute([$supplierId, $id]);
            $plan = $planned->fetch(PDO::FETCH_ASSOC);
            if ($plan['first_due'] !== null && (int) round((float) $plan['total'] * 100) !== (int) round($data['amount'] * 100)) {
                throw new OtherItemException('has_installments', 'Částka musí odpovídat součtu splátek. Před změnou částky zrušte splátkový kalendář.', 409);
            }
            if ($plan['first_due'] !== null && $data['issued_on'] > $plan['first_due']) {
                throw new OtherItemException('installment_date', 'Datum vzniku musí předcházet první splátce.', 409);
            }
            $this->items->updateDraft($supplierId, $id, $data, $userId);
            $this->items->replacePostingLines($supplierId, $id, $data['posting_lines']);
            if (array_key_exists('posting_overrides', $input) && $input['posting_overrides'] !== null) {
                $this->postingProfiles->saveItemOverrides($supplierId, ['id' => $id, 'side' => $data['side']],
                    $input['posting_overrides'], $userId);
            } elseif ($data['side'] !== $existing['side']) {
                $this->postingProfiles->flipItemOverrides($supplierId, $id, $userId);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Účet protistrany jde změnit i u potvrzeného nebo zaúčtovaného dokladu: zaúčtování
     * ani saldo neovlivní, slouží jen k párování úhrad. Stornovaný a zrušený doklad se už
     * nepáruje, koncept se upravuje celý ({@see update}). Bez kódu banky v těle se uložený
     * kód smaže stejně jako v {@see mergeStored}.
     *
     * @return array{item:array<string,mixed>,before:array{counterparty_account:?string,counterparty_bank_code:?string},
     *     after:array{counterparty_account:?string,counterparty_bank_code:?string}}
     */
    public function updateCounterpartyAccount(int $supplierId, int $id, array $input): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $existing = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($existing['status'], ['confirmed', 'posted'], true)) {
                throw new OtherItemException('counterparty_not_editable',
                    'Účet protistrany lze takto změnit jen u potvrzeného nebo zaúčtovaného dokladu.', 409);
            }
            $after = OtherItemCounterpartyAccount::normalize($input['counterparty_account'] ?? null,
                $input['counterparty_bank_code'] ?? null);
            $before = [
                'counterparty_account' => $existing['counterparty_account'] ?? null,
                'counterparty_bank_code' => $existing['counterparty_bank_code'] ?? null,
            ];
            $pdo->prepare('UPDATE other_items SET counterparty_account = ?, counterparty_bank_code = ?, row_version = row_version + 1
                WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL')
                ->execute([$after['counterparty_account'], $after['counterparty_bank_code'], $id, $supplierId]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['item' => $this->get($supplierId, $id), 'before' => $before, 'after' => $after];
    }

    /**
     * Částečná úprava: chybějící klíč = uložená hodnota, explicitní null/'' = smazat.
     * Rozpad protiúčtů (2+ řádky) bez klíče posting_lines zůstává, ledaže tělo posílá
     * jiný neprázdný protiúčet — to je přepnutí na jednu kontaci. Jediný uložený
     * protiřádek nese counter_account_code, takže jde s novou částkou.
     */
    private function mergeStored(int $supplierId, int $id, array $existing, array $input): array
    {
        $counter = trim((string) ($input['counter_account_code'] ?? ''));
        $keepLines = !array_key_exists('posting_lines', $input)
            && ($counter === '' || $counter === (string) ($existing['counter_account_code'] ?? ''));
        // Datum zaúčtování dosazené z data vzniku jde s novým datem vzniku (jako u nového dokladu).
        if (!array_key_exists('accounting_on', $input) && array_key_exists('issued_on', $input)
            && (string) $existing['accounting_on'] === (string) $existing['issued_on']) {
            $input['accounting_on'] = null;
        }
        // Jiný vestavěný druh než `other` v těle bez kind_id je přepnutí z vlastního druhu na vestavěný.
        // `other` bez kind_id vlastní druh nechá: tak hlavičku přeposílá klient, který kind_id nezná
        // (vlastní druh se v `kind` ukládá jako other). Zrušit ho jde výslovným kind_id: null.
        if (($input['kind'] ?? 'other') !== 'other' && !array_key_exists('kind_id', $input)) {
            $input['kind_id'] = null;
        }
        // Nový účet bez kódu banky nese kód v sobě (`číslo/kód`) nebo je to IBAN; uložený kód by mu odporoval.
        if (array_key_exists('counterparty_account', $input) && !array_key_exists('counterparty_bank_code', $input)) {
            $input['counterparty_bank_code'] = null;
        }
        foreach (['side', 'kind', 'kind_id', 'title', 'partner_id', 'partner_name', 'issued_on', 'accounting_on', 'due_on',
            'currency', 'amount', 'variable_symbol', 'counterparty_account', 'counterparty_bank_code',
            'account_code', 'counter_account_code', 'note'] as $key) {
            if (!array_key_exists($key, $input)) {
                $input[$key] = $existing[$key] ?? null;
            }
        }
        if ($keepLines) {
            $stored = $this->items->postingLines($supplierId, $id);
            if (count($stored) > 1) {
                $input['posting_lines'] = $stored;
            }
        }
        return $input;
    }

    public function deleteDraft(int $supplierId, int $id): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($item['status'] !== 'draft') {
                throw new OtherItemException('not_draft', 'Smazat lze jen koncept.', 409);
            }
            $scheduled = $pdo->prepare('SELECT occurrence_index FROM other_item_schedule_occurrences
                WHERE supplier_id = ? AND item_id = ? LIMIT 1');
            $scheduled->execute([$supplierId, $id]);
            $occurrence = $scheduled->fetch(PDO::FETCH_ASSOC);
            if ($occurrence !== false && (int) $occurrence['occurrence_index'] === 0) {
                throw new OtherItemException('has_schedule', 'Zdrojový doklad rozvrhu nelze smazat.', 409);
            }
            if ($occurrence !== false) {
                $this->items->cancelDraft($supplierId, $id);
            } else {
                $this->items->softDeleteDraft($supplierId, $id);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function post(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($item['status'] !== 'draft') {
                throw new OtherItemException('not_draft', 'Potvrdit lze jen koncept.', 409);
            }
            $doubleEntry = $this->isDoubleEntry($supplierId);
            $number = $this->nextNumber($supplierId, $item, (string) $item['issued_on']);
            $entryId = null;
            $origin = null;
            if ($doubleEntry) {
                // Prázdný účet položky a protiúčet doplní účetní profil druhu, bez profilu
                // platí dosavadní výchozí účet 315/325 ({@see OtherItemPostingResolver::origin}).
                $origin = $this->postingResolver->origin($supplierId, ['document_no' => $number] + $item);
                $postingLines = $origin['posting_lines'];
                if ($postingLines === []) {
                    throw new OtherItemException('counter_account_required', 'Před zaúčtováním vyberte protiúčet.');
                }
                $account = $origin['account_code'];
                $this->assertPostingAccounts($account, $postingLines);
                $this->assertBalanceAccount($supplierId, $account, (string) $item['side']);
                $amount = (float) $item['amount_czk'];
                $receivable = $item['side'] === 'receivable';
                $lines = [['account_code' => $account, 'side' => $receivable ? 'debit' : 'credit', 'amount' => $amount]];
                foreach ($postingLines as $line) {
                    $lines[] = ['account_code' => $line['account_code'],
                        'side' => $receivable ? 'credit' : 'debit', 'amount' => $line['amount']];
                }
                $entryId = $this->posting->postDocument($supplierId, 'other_item', $id, $lines, [
                    'entry_date' => (string) ($item['accounting_on'] ?: $item['issued_on']),
                    'document_date' => (string) $item['issued_on'],
                    'document_no' => $number,
                    'description' => $origin['description'],
                    'posted' => true,
                    'user_id' => $userId,
                    'posted_by' => $userId,
                ]);
            }
            $this->setPosted($supplierId, $id, $doubleEntry ? 'posted' : 'confirmed', $number, $entryId);
            if ($origin !== null && $origin['source'] === OtherItemPostingResolver::SOURCE_KIND) {
                // Položka si nese, co se zaúčtovalo: přeúčtování, saldokonto i opakování čtou
                // účet a protiúčet z ní, ne z profilu, který se mezitím může změnit.
                $this->items->storePostedAccounts($supplierId, $id, $origin['account_code'],
                    count($origin['posting_lines']) === 1 ? $origin['posting_lines'][0]['account_code'] : null);
                if ($origin['counter_source'] === OtherItemPostingResolver::SOURCE_KIND) {
                    $this->items->replacePostingLines($supplierId, $id, $origin['posting_lines']);
                }
                $this->logPostingProvenance($supplierId, $id, $origin, (int) $entryId, $userId);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function prepareEntryDeletion(int $supplierId, int $id, int $entryId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('OtherItemService::prepareEntryDeletion vyžaduje transakci.');
        }
        $item = $this->items->find($supplierId, $id, true)
            ?? throw new OtherItemException('not_found', 'Zdrojový doklad nebyl nalezen.', 404);
        if ($item['status'] !== 'posted' || (int) $item['journal_entry_id'] !== $entryId) {
            throw new OtherItemException('not_posted', 'Zápis není aktuálním zaúčtováním této položky.', 409);
        }
        $this->assertNoLinkedInvoice($supplierId, $id);
        $payments = $pdo->prepare('SELECT id FROM other_item_allocations
            WHERE supplier_id = ? AND other_item_id = ? AND reversed_on IS NULL FOR UPDATE');
        $payments->execute([$supplierId, $id]);
        if ($payments->fetchColumn() !== false) {
            throw new OtherItemException('has_payments', 'Doklad má spárované úhrady. Nejprve je odpojte.', 409);
        }
        $pdo->prepare("UPDATE other_item_schedules s SET status = 'paused'
            WHERE s.supplier_id = ? AND s.status = 'active'
              AND (s.source_item_id = ? OR EXISTS (
                  SELECT 1 FROM other_item_schedule_occurrences o
                  WHERE o.supplier_id = s.supplier_id AND o.schedule_id = s.id AND o.item_id = ?
              ))")->execute([$supplierId, $id, $id]);
        $this->items->setUnposted($supplierId, $id, $userId);
    }

    public function reverse(int $supplierId, int $id, string $reason, ?int $userId, ?string $entryDate = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new OtherItemException('reason_required', 'Uveďte důvod storna (alespoň 3 znaky).');
        }
        if ($entryDate !== null) self::assertDate($entryDate, 'entry_date');
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($item['status'], ['posted', 'confirmed'], true)) {
                throw new OtherItemException('not_posted', 'Stornovat lze jen potvrzený doklad.', 409);
            }
            if ((float) $item['paid_amount'] > 0 || $this->hasActivePayments($supplierId, $id)) {
                throw new OtherItemException('has_payments', 'Doklad má spárované úhrady. Nejprve je odpojte.', 409);
            }
            $this->assertNoLinkedInvoice($supplierId, $id);
            // Položka ze zůstatku bez dokladu nemá vlastní zápis, předpis nese řádek převzatého
            // zápisu. Storno ji proto ruší jen od svého dne (saldokonto k dřívějšímu dni ji dál
            // ukazuje) a ten den musí ležet v otevřeném účetním období, stejně jako protizápis.
            if ($item['journal_entry_id'] === null && $this->hasJournalOrigin($supplierId, $id)) {
                $releasedOn = $entryDate ?? date('Y-m-d');
                $period = $pdo->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ? ORDER BY starts_on DESC LIMIT 1 FOR UPDATE');
                $period->execute([$supplierId, $releasedOn, $releasedOn]);
                $status = $period->fetchColumn();
                if ($status === false || AccountingPeriodStatus::isClosed((string) $status)) {
                    throw new OtherItemException('period_closed', 'Storno položky ze zůstatku musí ležet v otevřeném účetním období.', 409);
                }
                (new \MyInvoice\Repository\JournalSettlementRepository($this->db))->releaseOrigin($supplierId, $id, $releasedOn);
            }
            $entryId = $item['journal_entry_id'] !== null
                ? $this->posting->reverse($supplierId, (int) $item['journal_entry_id'], [
                    'entry_date' => $entryDate,
                    'description' => 'Storno ' . $item['document_no'] . ': ' . $reason,
                    'user_id' => $userId,
                    'posted_by' => $userId,
                ])
                : null;
            $this->items->setReversed($supplierId, $id, $entryId !== null ? 'reversed' : 'cancelled', $entryId);
            $pdo->prepare("UPDATE other_item_schedules SET status = 'paused'
                WHERE supplier_id = ? AND source_item_id = ? AND status = 'active'")
                ->execute([$supplierId, $id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function repost(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        $date = (string) ($input['entry_date'] ?? '');
        $account = trim((string) ($input['account_code'] ?? ''));
        $counter = trim((string) ($input['counter_account_code'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw new OtherItemException('reason_required', 'Uveďte důvod přeúčtování (alespoň 3 znaky).');
        }
        self::assertDate($date, 'entry_date');
        if (strlen($counter) > 20 || strlen($account) > 20) {
            throw new OtherItemException('invalid_account', 'Vyberte platný účet a protiúčet.');
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($item['status'] !== 'posted' || $item['journal_entry_id'] === null) {
                throw new OtherItemException('not_posted', 'Přeúčtovat lze jen zaúčtovaný doklad.', 409);
            }
            if ((float) $item['paid_amount'] > 0 || $this->hasActivePayments($supplierId, $id)) {
                throw new OtherItemException('has_payments', 'Doklad má spárované úhrady. Nejprve je odpojte.', 409);
            }
            $account = $account ?: (string) ($item['account_code'] ?: ($item['side'] === 'receivable' ? '315' : '325'));
            $postingLines = array_key_exists('posting_lines', $input)
                ? $this->normalizePostingLines($input['posting_lines'], (float) $item['amount_czk'])
                : ($counter !== '' ? [['account_code' => $counter, 'amount' => (float) $item['amount_czk']]]
                    : $item['posting_lines']);
            if ($postingLines === []) {
                throw new OtherItemException('counter_account_required', 'Před přeúčtováním vyberte protiúčet.');
            }
            $this->assertPostingAccounts($account, $postingLines);
            $this->assertBalanceAccount($supplierId, $account, (string) $item['side']);
            if ($account === (string) ($item['account_code'] ?: ($item['side'] === 'receivable' ? '315' : '325'))
                && $postingLines === $item['posting_lines']) {
                throw new OtherItemException('unchanged_accounts', 'Změňte alespoň jeden účet.');
            }
            $reversalId = $this->posting->reverse($supplierId, (int) $item['journal_entry_id'], [
                'entry_date' => $date, 'description' => 'Přeúčtování ' . $item['document_no'] . ': ' . $reason,
                'user_id' => $userId, 'posted_by' => $userId,
            ]);
            $receivable = $item['side'] === 'receivable';
            $amount = (float) $item['amount_czk'];
            $lines = [['account_code' => $account, 'side' => $receivable ? 'debit' : 'credit', 'amount' => $amount]];
            foreach ($postingLines as $line) {
                $lines[] = ['account_code' => $line['account_code'],
                    'side' => $receivable ? 'credit' : 'debit', 'amount' => $line['amount']];
            }
            $entryId = $this->posting->postDocument($supplierId, 'other_item', $id, $lines, [
                'entry_date' => $date, 'document_date' => (string) $item['issued_on'],
                'document_no' => (string) $item['document_no'], 'description' => (string) $item['title'],
                'posted' => true, 'user_id' => $userId, 'posted_by' => $userId,
            ]);
            $this->items->setReposted($supplierId, $id, $account,
                count($postingLines) === 1 ? $postingLines[0]['account_code'] : null,
                $date, $entryId, $reversalId, $userId);
            $this->items->replacePostingLines($supplierId, $id, $postingLines);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Zápis pohledávky účtuje navázanou fakturu (migrace 2246). Storno nebo smazání
     * zápisu by fakturu nechalo bez předpisu, proto se nejdřív odpojí faktura.
     */
    private function assertNoLinkedInvoice(int $supplierId, int $id): void
    {
        $linked = $this->linkedInvoice($supplierId, $id);
        if ($linked !== null) {
            throw new OtherItemException('linked_invoice', sprintf(
                'Ostatní pohledávka účtuje fakturu %s. Nejprve fakturu od pohledávky odpojte.',
                $linked['varsymbol'] ?? ('#' . $linked['id'])), 409);
        }
    }

    /** @return array{id:int,varsymbol:?string,status:string}|null */
    public function linkedInvoice(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, varsymbol, status FROM invoices WHERE supplier_id = ? AND other_item_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null
            : ['id' => (int) $row['id'], 'varsymbol' => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null, 'status' => (string) $row['status']];
    }

    public function allocations(int $supplierId, int $id): array
    {
        $this->get($supplierId, $id);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, installment_id, bank_transaction_id, cash_document_id, amount, interest_amount, fee_amount,
                    amount + interest_amount + fee_amount AS total_amount, payment_on, auto_match_reason, reversed_on, created_at
               FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? ORDER BY payment_on, id'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Volné bankovní a pokladní platby k přiřazení položce. Řadí podle síly shody
     * ({@see OtherItemPaymentCandidateRanker}: VS, účet protistrany, částka splátky
     * v okně splatnosti, celý zbývající dluh), pak od nejnovější. Bez hledání nabízí
     * i nejnovější platby bez shody, jako dřív.
     */
    public function paymentCandidates(int $supplierId, int $id, string $search, int $limit,
        bool $includeBank = true, bool $includeCash = true): array
    {
        $item = $this->get($supplierId, $id);
        if ((!$includeBank && !$includeCash)
            || !in_array($item['status'], ['posted', 'confirmed'], true) || $item['currency'] !== 'CZK') return [];
        $limit = max(1, min(50, $limit));
        $term = '%' . addcslashes(trim($search), '%_\\') . '%';
        $coverage = $this->installmentCoverage($supplierId, $id);
        // Hledané částky: zbývající dluh a zbytky otevřených splátek (nejvýš 12 nejbližších).
        // U rozdělených splátek je zbývající dluh peněžní: jistina, úrok i poplatek.
        $amounts = [round((float) $item['remaining_payment'], 2)];
        foreach (OtherItemInstallmentCoverage::open($coverage) as $row) {
            $amounts[] = round((float) $row['remaining_amount'], 2);
        }
        $amounts = array_slice(array_values(array_unique($amounts)), 0, 13);
        $tolerance = sprintf('%.4F', OtherItemPaymentCandidateRanker::AMOUNT_TOLERANCE + 0.0001);
        $amountMatch = static fn (string $free): string => '(' . implode(' OR ',
            array_fill(0, count($amounts), "ABS({$free} - ?) <= {$tolerance}")) . ')';
        $vs = (string) ($item['variable_symbol'] ?? '');
        // Předvýběr účtu protistrany podle čísla bez předčíslí (GPC, národní tvar i IBAN ho obsahují);
        // přesnou shodu rozhodne OtherItemCounterpartyAccount v řazení.
        $account = (string) ($item['counterparty_account'] ?? '');
        $accountTerm = $account === '' ? '' : '%' . addcslashes(
            str_contains($account, '-') ? substr($account, strpos($account, '-') + 1) : $account, '%_\\') . '%';
        $rows = [];
        if ($includeBank) {
            $direction = $item['side'] === 'receivable' ? 'bt.amount > 0' : 'bt.amount < 0';
            $stmt = $this->db->pdo()->prepare(
                'SELECT bt.id, bt.posted_at payment_on, ABS(bt.amount) amount,
                        COALESCE(bt.currency, bs.currency, \'CZK\') currency,
                        COALESCE(bt.description, bt.counterparty_name, \'\') description,
                        bt.variable_symbol, bt.counterparty_account, bt.counterparty_bank,
                        COALESCE(a.used_amount, 0) used_amount
                   FROM bank_transactions bt
                   JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              LEFT JOIN (SELECT bank_transaction_id, SUM(' . self::ALLOCATION_TOTAL_SQL . ') used_amount FROM other_item_allocations
                         WHERE supplier_id = ? AND bank_transaction_id IS NOT NULL AND reversed_on IS NULL
                         GROUP BY bank_transaction_id) a
                     ON a.bank_transaction_id = bt.id
                  WHERE ' . $direction . '
                    AND ' . self::freeBankPaymentSql() . '
                    AND (
                        NOT EXISTS (SELECT 1 FROM other_item_allocations previous
                                     WHERE previous.supplier_id = bs.supplier_id
                                       AND previous.bank_transaction_id = bt.id AND previous.reversed_on IS NOT NULL)
                        OR EXISTS (SELECT 1 FROM other_item_allocations previous
                                    WHERE previous.supplier_id = bs.supplier_id
                                      AND previous.bank_transaction_id = bt.id
                                      AND previous.other_item_id = ? AND previous.reversed_on IS NOT NULL)
                    )
                    AND (bt.variable_symbol LIKE ? OR bt.counterparty_name LIKE ? OR bt.description LIKE ?)
                  ORDER BY COALESCE(? <> \'\' AND bt.variable_symbol = ?, 0) * 4
                         + COALESCE(' . $amountMatch('ABS(bt.amount) - COALESCE(a.used_amount, 0)') . ', 0) * 2
                         + COALESCE(? <> \'\' AND bt.counterparty_account LIKE ?, 0) DESC,
                           bt.posted_at DESC, bt.id DESC LIMIT ' . $limit
            );
            $stmt->execute([$supplierId, $supplierId, $id, $term, $term, $term, $vs, $vs, ...$amounts,
                $accountTerm, $accountTerm]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'source' => 'bank', 'id' => (int) $row['id'], 'payment_on' => $row['payment_on'],
                    'amount' => (float) $row['amount'] - (float) $row['used_amount'],
                    'currency' => $row['currency'], 'description' => $row['description'],
                    'used_amount' => (float) $row['used_amount'],
                    'variable_symbol' => $row['variable_symbol'], 'counterparty_account' => $row['counterparty_account'],
                    'counterparty_bank' => $row['counterparty_bank'],
                ];
            }
        }
        if ($includeCash) {
            $cashDirection = $item['side'] === 'receivable' ? 'in' : 'out';
            $stmt = $this->db->pdo()->prepare(
                'SELECT cd.id, cd.issue_date payment_on, cd.total_amount amount, cd.currency_code currency,
                        cd.description, COALESCE(a.used_amount, 0) used_amount
                   FROM cash_documents cd
              LEFT JOIN (SELECT cash_document_id, SUM(' . self::ALLOCATION_TOTAL_SQL . ') used_amount FROM other_item_allocations
                         WHERE supplier_id = ? AND cash_document_id IS NOT NULL AND reversed_on IS NULL
                         GROUP BY cash_document_id) a
                     ON a.cash_document_id = cd.id
                  WHERE cd.supplier_id = ? AND cd.doc_type = ? AND cd.purpose = \'other\'
                    AND cd.status = \'posted\' AND cd.currency_code = \'CZK\'
                    AND cd.invoice_id IS NULL AND cd.purchase_invoice_id IS NULL
                    AND cd.total_amount > COALESCE(a.used_amount, 0)
                    AND (
                        NOT EXISTS (SELECT 1 FROM other_item_allocations previous
                                     WHERE previous.supplier_id = cd.supplier_id
                                       AND previous.cash_document_id = cd.id AND previous.reversed_on IS NOT NULL)
                        OR EXISTS (SELECT 1 FROM other_item_allocations previous
                                    WHERE previous.supplier_id = cd.supplier_id
                                      AND previous.cash_document_id = cd.id
                                      AND previous.other_item_id = ? AND previous.reversed_on IS NOT NULL)
                    )
                    AND (cd.doc_number LIKE ? OR cd.partner_name LIKE ? OR cd.description LIKE ?)
                  ORDER BY COALESCE(' . $amountMatch('cd.total_amount - COALESCE(a.used_amount, 0)') . ', 0) DESC,
                           cd.issue_date DESC, cd.id DESC LIMIT ' . $limit
            );
            $stmt->execute([$supplierId, $supplierId, $cashDirection, $id, $term, $term, $term, ...$amounts]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'source' => 'cash', 'id' => (int) $row['id'], 'payment_on' => $row['payment_on'],
                    'amount' => (float) $row['amount'] - (float) $row['used_amount'],
                    'currency' => $row['currency'], 'description' => $row['description'],
                    'used_amount' => (float) $row['used_amount'],
                    'variable_symbol' => null, 'counterparty_account' => null, 'counterparty_bank' => null,
                ];
            }
        }
        if ($item['journal_entry_id'] !== null) {
            $accountId = $this->settlementAccountId($supplierId, $item);
            $rows = array_values(array_filter($rows, function (array $row) use ($supplierId, $accountId, $item): bool {
                try {
                    $this->assertPaymentPosting($supplierId, $row['source'], $row['id'],
                        $accountId, (string) $item['side'],
                        $this->allocatedOnAccount($supplierId, $row['source'], $row['id'], $accountId) + 0.01);
                    return true;
                } catch (OtherItemException) {
                    return false;
                }
            }));
        }
        $matchItem = [
            'variable_symbol' => $item['variable_symbol'] ?? null,
            'counterparty_account' => $item['counterparty_account'] ?? null,
            'counterparty_bank_code' => $item['counterparty_bank_code'] ?? null,
            'remaining_amount' => (float) $item['remaining_payment'],
            'installments' => $coverage,
        ];
        $rows = array_map(static function (array $row) use ($matchItem): array {
            $match = OtherItemPaymentCandidateRanker::evaluate([
                'amount' => (float) $row['amount'], 'variable_symbol' => $row['variable_symbol'],
                'counterparty_account' => $row['counterparty_account'], 'counterparty_bank' => $row['counterparty_bank'],
                'payment_on' => (string) $row['payment_on'],
            ], $matchItem);
            unset($row['used_amount'], $row['variable_symbol'], $row['counterparty_account'], $row['counterparty_bank']);
            return $row + [
                'match_score' => $match['score'], 'match_confidence' => $match['confidence'],
                'match_reasons' => $match['reasons'], 'installment' => $match['installment'],
            ];
        }, $rows);
        usort($rows, static fn (array $a, array $b): int =>
            [$b['match_score'], $b['payment_on'], $b['id']] <=> [$a['match_score'], $a['payment_on'], $a['id']]);
        return array_slice($rows, 0, $limit);
    }

    public function allocate(int $supplierId, int $id, array $input, ?int $userId): array
    {
        return TransactionFailure::retrying($this->db->pdo(),
            fn (): array => $this->allocateOnce($supplierId, $id, $input, $userId));
    }

    /**
     * Položka zamčená pro přiřazení úhrady. Zbytek počítá zamykacím čtením přiřazení až po
     * zámku položky: čtení ze snímku transakce by po čekání na zámek neviděl úhradu, kterou
     * mezitím potvrdil souběžný požadavek, a položka by se přeplatila.
     *
     * @return array<string,mixed>
     */
    public function lockForAllocation(int $supplierId, int $id): array
    {
        $item = $this->items->find($supplierId, $id, true)
            ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM other_item_allocations
              WHERE supplier_id = ? AND other_item_id = ? AND reversed_on IS NULL FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $allocated = (float) $stmt->fetchColumn();
        $item['paid_amount'] = round($allocated + (float) $item['settled_outside_amount'], 2);
        $item['remaining_amount'] = round((float) $item['amount'] - (float) $item['paid_amount'], 2);
        return $item;
    }

    private function allocateOnce(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $bankId = isset($input['bank_transaction_id']) ? (int) $input['bank_transaction_id'] : 0;
        $cashId = isset($input['cash_document_id']) ? (int) $input['cash_document_id'] : 0;
        if (($bankId > 0) === ($cashId > 0)) {
            throw new OtherItemException('invalid_payment', 'Zvolte právě jednu bankovní nebo pokladní platbu.');
        }
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || round((float) $amount, 2) !== (float) $amount) {
            throw new OtherItemException('invalid_amount', 'Párovaná částka musí být kladná a zadaná na haléře.');
        }
        $amount = round((float) $amount, 2);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->lockForAllocation($supplierId, $id);
            if (!in_array($item['status'], ['posted', 'confirmed'], true)) {
                throw new OtherItemException('not_posted', 'Úhradu lze přiřadit jen potvrzenému dokladu.', 409);
            }
            if ($item['currency'] !== 'CZK') {
                throw new OtherItemException('currency_unsupported', 'Párování cizoměnové položky zatím vyžaduje kurzové vypořádání v účetnictví.', 409);
            }
            $payment = $bankId > 0
                ? $this->bankPayment($supplierId, $bankId, (string) $item['side'])
                : $this->cashPayment($supplierId, $cashId, (string) $item['side']);
            if ((string) $payment['currency'] !== 'CZK') {
                throw new OtherItemException('currency_mismatch', 'Měna úhrady se liší od měny dokladu.');
            }
            $sourceColumn = $bankId > 0 ? 'bank_transaction_id' : 'cash_document_id';
            $sourceId = $bankId > 0 ? $bankId : $cashId;
            $sourceType = $bankId > 0 ? 'bank' : 'cash';
            // Opětovné přiřazení po stornu míří na původní splátku.
            $priorInstallment = $pdo->prepare("SELECT installment_id FROM other_item_allocations
                WHERE supplier_id = ? AND other_item_id = ? AND {$sourceColumn} = ? AND reversed_on IS NOT NULL");
            $priorInstallment->execute([$supplierId, $id, $sourceId]);
            $priorTarget = $priorInstallment->fetchColumn();
            $split = $this->splitPayment($supplierId, $item, $amount,
                $priorTarget !== false && $priorTarget !== null ? (int) $priorTarget : null);
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(' . self::ALLOCATION_TOTAL_SQL . '), 0) FROM other_item_allocations
                  WHERE supplier_id = ? AND reversed_on IS NULL AND ' . $sourceColumn . ' = ?'
            );
            $stmt->execute([$supplierId, $sourceId]);
            $used = (float) $stmt->fetchColumn();
            if ($amount + $used > abs((float) $payment['amount']) + 0.001) {
                throw new OtherItemException('payment_overallocated', 'Platba nemá dostatek volné částky.', 409);
            }
            if ($item['journal_entry_id'] !== null) {
                $accountId = $this->settlementAccountId($supplierId, $item);
                $this->assertPaymentPosting($supplierId, $sourceType, $sourceId, $accountId, (string) $item['side'],
                    $split['principal'] + $this->allocatedOnAccount($supplierId, $sourceType, $sourceId, $accountId));
            }
            if ($split['interest'] + $split['fee'] > 0 && $this->settlementAccountCode($supplierId, $item) !== null) {
                $this->assertComponentPosting($supplierId, $sourceType, $sourceId, $item, $split);
            }
            $previous = $pdo->prepare(
                "SELECT id, other_item_id, amount + interest_amount + fee_amount AS amount, reversed_on FROM other_item_allocations
                  WHERE supplier_id = ? AND {$sourceColumn} = ? FOR UPDATE"
            );
            $previous->execute([$supplierId, $sourceId]);
            $prior = false;
            $hasReversed = false;
            foreach ($previous->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
                if ($allocation['reversed_on'] !== null) $hasReversed = true;
                if ((int) $allocation['other_item_id'] === $id) $prior = $allocation;
            }
            if ($hasReversed && $prior === false) {
                throw new OtherItemException('payment_reallocation_conflict',
                    'Po stornu lze platbu znovu přiřadit jen k původní položce.', 409);
            }
            if ($prior !== false) {
                if ($prior['reversed_on'] === null) {
                    throw new OtherItemException('payment_used', 'Tato platba už je k dokladu přiřazena.', 409);
                }
                if (abs((float) $prior['amount'] - $amount) > 0.001) {
                    throw new OtherItemException('payment_reallocation_amount',
                        'Po stornu lze znovu přiřadit stejnou částku původní úhrady.', 409);
                }
                // Vazba na splátku zůstává; po novém splátkovém kalendáři (smazaná splátka) se dopočítá.
                // Složky se rozdělí znovu podle současného stavu splátek (stejně je ověřilo zaúčtování).
                $reactivate = $pdo->prepare(
                    'UPDATE other_item_allocations SET reversed_on = NULL, installment_id = COALESCE(installment_id, ?),
                            amount = ?, interest_amount = ?, fee_amount = ?
                      WHERE id = ? AND supplier_id = ? AND reversed_on IS NOT NULL'
                );
                $reactivate->execute([$split['installment_id'], $split['principal'], $split['interest'], $split['fee'],
                    (int) $prior['id'], $supplierId]);
                $this->posting->restampDimensions($supplierId, $sourceType, $sourceId, true);
                InvoiceOtherItemPayments::syncForItems($pdo, $supplierId, [$id]);
                if ($ownTx) $pdo->commit();
                return $this->get($supplierId, $id);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO other_item_allocations
                   (supplier_id, other_item_id, installment_id, bank_transaction_id, cash_document_id, amount,
                    interest_amount, fee_amount, payment_on, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$supplierId, $id, $split['installment_id'],
                $bankId ?: null, $cashId ?: null, $split['principal'], $split['interest'], $split['fee'],
                $payment['payment_on'], $userId]);
            // Úhrada přebírá dimenze položky (DimensionDefaults::bankDocuments / forCash).
            $this->posting->restampDimensions($supplierId, $bankId > 0 ? 'bank' : 'cash', $sourceId, true);
            InvoiceOtherItemPayments::syncForItems($pdo, $supplierId, [$id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Rozdělení peněžní částky úhrady na jistinu, úrok a poplatek a splátka, kterou hradí
     * ({@see OtherItemInstallmentCoverage::target}: přesná shoda s otevřenou splátkou, jinak
     * první otevřená). Volá se pod zámkem položky a jejích úhrad ({@see lockForAllocation}).
     *
     * Bez rozdělené splátky je celá částka jistina a přeplatek se měří zbývajícím dluhem,
     * přesně jako dřív. S rozdělenými splátkami pokryje částka nejdřív úrok, pak poplatek
     * a teprve potom jistinu splátky (obvyklé pořadí započtení u úvěrů), přebytek
     * stejně další splátky; částka nad zbytek všech splátek je přeplatek.
     *
     * @param array<string,mixed> $item zamčená položka s remaining_amount
     * @return array{installment_id:?int,principal:float,interest:float,fee:float}
     */
    public function splitPayment(int $supplierId, array $item, float $amount, ?int $target = null): array
    {
        $coverage = $this->items->installmentCoverage($supplierId, [(int) $item['id']])[(int) $item['id']] ?? [];
        if ($target !== null && !in_array($target, array_map(static fn (array $row): int => (int) $row['id'], $coverage), true)) {
            $target = null;
        }
        $target ??= OtherItemInstallmentCoverage::target($coverage, $amount);
        if (!OtherItemInstallmentCoverage::hasSplit($coverage)) {
            if ($amount > round((float) $item['remaining_amount'], 2)) {
                throw new OtherItemException('overpayment', 'Párovaná částka převyšuje zbývající dluh.', 409);
            }
            return ['installment_id' => $target, 'principal' => $amount, 'interest' => 0.0, 'fee' => 0.0];
        }
        $split = OtherItemInstallmentCoverage::split($coverage, $amount, $target);
        if ($split['unallocated'] > 0.001 || $split['principal'] > round((float) $item['remaining_amount'], 2) + 0.001) {
            throw new OtherItemException('overpayment', 'Párovaná částka převyšuje zbývající splátky včetně úroku a poplatku.', 409);
        }
        return ['installment_id' => $target, 'principal' => $split['principal'],
            'interest' => $split['interest'], 'fee' => $split['fee']];
    }

    /**
     * Kolik má protistrana ještě zaplatit: u rozdělených splátek zbytek všech splátek
     * (jistina, úrok i poplatek), jinak zbývající dluh (saldo).
     *
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $coverage
     */
    public static function payableOf(array $item, array $coverage): float
    {
        return OtherItemInstallmentCoverage::hasSplit($coverage)
            ? OtherItemInstallmentCoverage::remainingTotal($coverage)
            : round((float) $item['remaining_amount'], 2);
    }

    /**
     * Zaúčtovaná platba nese úrok a poplatek rozdělené splátky na jejich účtech
     * ({@see OtherItemPostingResolver::component}) aspoň v částce úhrady. Jinak by úhrada
     * snížila saldo jistiny, ale úrok by v účetnictví chyběl nebo ležel jinde.
     *
     * @param array<string,mixed> $item
     * @param array{principal:float,interest:float,fee:float} $split
     */
    private function assertComponentPosting(int $supplierId, string $sourceType, int $sourceId, array $item, array $split): void
    {
        $required = [];
        foreach ([OtherItemPostingEvent::Interest, OtherItemPostingEvent::Fee] as $event) {
            $amount = (int) round($split[$event->value] * 100);
            if ($amount <= 0) continue;
            $code = $this->postingResolver->component($supplierId, $item, $event)['account_code'];
            $required[$code] = ($required[$code] ?? 0) + $amount;
        }
        $side = $item['side'] === 'receivable' ? 'credit' : 'debit';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = ? THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = ? AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND (a.account_code = ? OR a.account_code LIKE ?)"
        );
        foreach ($required as $code => $cents) {
            $code = (string) $code;
            $stmt->execute([$side, $supplierId, $sourceType, $sourceId, $code, addcslashes($code, '%_\\') . '.%']);
            if ((int) round((float) $stmt->fetchColumn() * 100) < $cents) {
                throw new OtherItemException('payment_split_mismatch', sprintf(
                    'Zaúčtování platby neodpovídá rozdělení splátky: úrok %s Kč a poplatek %s Kč patří na účet %s, jistina %s Kč na účet položky. Zaúčtování platby opravte nebo ji nechte zaúčtovat při párování.',
                    number_format($split['interest'], 2, ',', ' '), number_format($split['fee'], 2, ',', ' '),
                    $code, number_format($split['principal'], 2, ',', ' ')), 409);
            }
        }
    }

    /**
     * Stav splátek položky (zaplaceno, zbývá, unpaid / partial / paid / overdue).
     *
     * @return list<array<string,mixed>>
     */
    public function installmentCoverage(int $supplierId, int $id): array
    {
        return $this->items->installmentCoverage($supplierId, [$id])[$id] ?? [];
    }

    public function unallocate(int $supplierId, int $id, int $allocationId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            $payment = $pdo->prepare('SELECT bank_transaction_id, cash_document_id FROM other_item_allocations
                WHERE id = ? AND supplier_id = ? AND other_item_id = ?');
            $payment->execute([$allocationId, $supplierId, $id]);
            $paymentRow = $payment->fetch(PDO::FETCH_ASSOC);
            // Dimenze, které úhrada od položky převzala; po odpojení se z jejích řádků odeberou.
            $itemDims = (new DimensionDefaults($this->db))->effectiveDimensions($supplierId, 'other_item', $id);
            $released = $itemDims['header'] + array_fill_keys(array_keys($itemDims['splits']), null);
            $stmt = $pdo->prepare('DELETE FROM other_item_allocations WHERE id = ? AND supplier_id = ? AND other_item_id = ?');
            $stmt->execute([$allocationId, $supplierId, $id]);
            if ($stmt->rowCount() === 0) throw new OtherItemException('payment_not_found', 'Úhrada nebyla nalezena.', 404);
            if ($paymentRow !== false && $paymentRow['bank_transaction_id'] !== null) {
                // Zrušené přiřazení automatika neobnoví ({@see OtherItemAutoMatcher}).
                $pdo->prepare('INSERT IGNORE INTO other_item_auto_match_blocks (supplier_id, bank_transaction_id, other_item_id)
                    VALUES (?, ?, ?)')->execute([$supplierId, (int) $paymentRow['bank_transaction_id'], $id]);
            }
            if ($paymentRow !== false) {
                $this->posting->restampDimensions($supplierId,
                    $paymentRow['bank_transaction_id'] !== null ? 'bank' : 'cash',
                    (int) ($paymentRow['bank_transaction_id'] ?? $paymentRow['cash_document_id']), true, $released);
            }
            InvoiceOtherItemPayments::syncForItems($pdo, $supplierId, [$id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    private function bankPayment(int $supplierId, int $id, string $side): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.amount, bt.posted_at payment_on, COALESCE(bt.currency, bs.currency, \'CZK\') currency,
                    bt.matched_invoice_id, bt.match_status
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              WHERE bt.id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new OtherItemException('payment_not_found', 'Bankovní platba nebyla nalezena.', 404);
        if ($side === 'receivable' && (float) $row['amount'] <= 0 || $side === 'payable' && (float) $row['amount'] >= 0) {
            throw new OtherItemException('payment_direction', 'Směr bankovní platby neodpovídá dokladu.');
        }
        if ($row['matched_invoice_id'] !== null || $row['match_status'] !== 'unmatched') {
            throw new OtherItemException('payment_used', 'Bankovní platba už je spárována s jiným dokladem.', 409);
        }
        foreach (['payment_matches' => 'bank_transaction_id', 'invoice_payments' => 'bank_transaction_id',
                  'payroll_payment_matches' => 'bank_transaction_id', 'tax_advance_schedules' => 'matched_transaction_id'] as $table => $column) {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) throw new OtherItemException('payment_used', 'Bankovní platba už je spárována s jiným dokladem.', 409);
        }
        return $row;
    }

    private function cashPayment(int $supplierId, int $id, string $side): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_amount amount, issue_date payment_on, currency_code currency,
                    doc_type, purpose, invoice_id, purchase_invoice_id
               FROM cash_documents WHERE id = ? AND supplier_id = ? AND status = \'posted\' FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new OtherItemException('payment_not_found', 'Pokladní platba nebyla nalezena.', 404);
        if ($row['purpose'] !== 'other' || $row['invoice_id'] !== null || $row['purchase_invoice_id'] !== null
            || $side === 'receivable' && $row['doc_type'] !== 'in'
            || $side === 'payable' && $row['doc_type'] !== 'out') {
            throw new OtherItemException('payment_used', 'Pokladní doklad není volnou úhradou této položky.', 409);
        }
        return $row;
    }

    /**
     * Bankovní pohyb je volný k přiřazení ostatní položce: CZK, nespárovaný s fakturou,
     * mzdou ani zálohou na daň a má nevyčerpanou částku. Předpokládá aliasy `bt`
     * (bank_transactions), `bs` (bank_statements) a `a` (součet aktivních přiřazení
     * pohybu jako `used_amount`). Sdílí ji výběr plateb z detailu položky i párování
     * z banky ({@see OtherItemBankMatchService}), aby se pravidla nerozešla.
     */
    public static function freeBankPaymentSql(): string
    {
        return 'COALESCE(bt.currency, bs.currency, \'CZK\') = \'CZK\'
                    AND bt.match_status = \'unmatched\' AND bt.matched_invoice_id IS NULL
                    AND ABS(bt.amount) > COALESCE(a.used_amount, 0)
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm
                                     WHERE pm.bank_transaction_id = bt.id AND pm.supplier_id = bs.supplier_id)
                    AND NOT EXISTS (SELECT 1 FROM invoice_payments ip
                                     WHERE ip.bank_transaction_id = bt.id AND ip.supplier_id = bs.supplier_id)
                    AND NOT EXISTS (SELECT 1 FROM payroll_payment_matches ppm
                                     WHERE ppm.bank_transaction_id = bt.id AND ppm.supplier_id = bs.supplier_id)
                    AND NOT EXISTS (SELECT 1 FROM tax_advance_schedules tas
                                     WHERE tas.matched_transaction_id = bt.id AND tas.supplier_id = bs.supplier_id)';
    }

    /**
     * Kód účtu pohledávky/závazku položky; null, když položka v účetnictví účet nemá.
     * Položka ze zůstatku zápisu vlastní zápis nemá: předpis je řádek cizího zápisu a jeho
     * účet nese sama položka ({@see createFromJournalLine}).
     */
    public function settlementAccountCode(int $supplierId, array $item): ?string
    {
        if ($item['journal_entry_id'] === null) {
            return $this->hasJournalOrigin($supplierId, (int) $item['id']) ? (string) $item['account_code'] : null;
        }
        $account = $this->accounts->findById($supplierId, $this->settlementAccountId($supplierId, $item));
        return $account === null ? null : (string) $account['account_code'];
    }

    /** Zaúčtování pohybu pokrývá částku na účtu položky; položka bez účtu se nekontroluje. */
    public function assertBankPaymentPosted(int $supplierId, int $txId, array $item, float $amount): void
    {
        $code = $this->settlementAccountCode($supplierId, $item);
        if ($code === null) return;
        $account = $this->accounts->findByCode($supplierId, $code)
            ?? throw new OtherItemException('payment_not_posted', 'Účet položky není v účtové osnově.', 409);
        $this->assertPaymentPosting($supplierId, 'bank', $txId, (int) $account['id'], (string) $item['side'], $amount);
    }

    private function settlementAccountId(int $supplierId, array $item): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.account_id
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              WHERE e.id = ? AND e.supplier_id = ? AND e.source_type = \'other_item\' AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.side = ?
              LIMIT 1'
        );
        $stmt->execute([(int) $item['journal_entry_id'], $supplierId, (int) $item['id'],
            $item['side'] === 'receivable' ? 'debit' : 'credit']);
        $accountId = $stmt->fetchColumn();
        if ($accountId === false) {
            throw new OtherItemException('payment_not_posted', 'Doklad nemá platný účetní zápis.', 409);
        }
        return (int) $accountId;
    }

    private function assertPaymentPosting(int $supplierId, string $sourceType, int $sourceId,
        int $accountId, string $itemSide, float $allocatedTotal): void
    {
        $requiredSide = $itemSide === 'receivable' ? 'credit' : 'debit';
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN l.side = ? THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = ? AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND l.account_id = ?'
        );
        $stmt->execute([$requiredSide, $supplierId, $sourceType, $sourceId, $accountId]);
        if ((float) $stmt->fetchColumn() + 0.001 < $allocatedTotal) {
            throw new OtherItemException('payment_not_posted', 'Platbu nejprve zaúčtujte proti účtu pohledávky nebo závazku.', 409);
        }
    }

    private function allocatedOnAccount(int $supplierId, string $sourceType, int $sourceId,
        int $accountId): float
    {
        $column = $sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(a.amount), 0)
               FROM other_item_allocations a
               JOIN other_items oi ON oi.id = a.other_item_id AND oi.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.{$column} = ? AND a.reversed_on IS NULL
                AND EXISTS (
                    SELECT 1 FROM journal_entry_lines l
                     WHERE l.entry_id = oi.journal_entry_id AND l.supplier_id = oi.supplier_id
                       AND l.account_id = ?
                       AND l.side = CASE WHEN oi.side = 'receivable' THEN 'debit' ELSE 'credit' END
                )"
        );
        $stmt->execute([$supplierId, $sourceId, $accountId]);
        return (float) $stmt->fetchColumn();
    }

    public function belongsToAutoPostSchedule(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM other_item_schedule_occurrences o
            JOIN other_item_schedules s ON s.id = o.schedule_id AND s.supplier_id = o.supplier_id
            WHERE o.supplier_id = ? AND o.item_id = ? AND s.auto_post = 1 LIMIT 1');
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Číslo položky z řady jejího druhu: vlastní řada druhu ({@see OtherItemKindService::seriesScope}),
     * jinak společná OP/OZ. Rok řady je rok vzniku.
     *
     * @param array<string,mixed> $item side, kind, kind_id
     */
    private function nextNumber(int $supplierId, array $item, string $issuedOn): string
    {
        $year = (int) substr($issuedOn, 0, 4);
        $side = (string) $item['side'];
        $kindId = $item['kind_id'] !== null ? (int) $item['kind_id'] : null;
        return $this->series->next($supplierId, OtherItemKindService::seriesCode($side), $year,
            $this->kinds->seriesScope($supplierId, $kindId, (string) $item['kind'], $side, $year));
    }

    /**
     * Čísla všech řad ostatních položek sdílejí unikátnost `uq_other_item_doc_no`. Kolizi
     * (čítač snížený pod vydané číslo, šablona bez prefixu) ohlásí jako opravitelnou
     * chybu řady, ne jako chybu serveru.
     */
    private function setPosted(int $supplierId, int $id, string $status, string $number, ?int $entryId): void
    {
        try {
            $this->items->setPosted($supplierId, $id, $status, $number, $entryId);
        } catch (\PDOException $e) {
            if (TransactionFailure::aborted($e) || (int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            throw new OtherItemException('document_no_taken',
                'Číslo ' . $number . ' už má jiný doklad. Upravte prefix nebo čítač řady v Nástrojích → Číselné řady.', 409);
        }
    }

    public function isDoubleEntry(int $supplierId): bool
    {
        return $this->postingResolver->isDoubleEntry($supplierId);
    }

    /**
     * Vlastní kontace úhrad položky mimo koncept (potvrzená či zaúčtovaná položka).
     * Platí pro budoucí úhrady, zaúčtované úhrady se nemění. Vznik se tu nemění: jeho
     * kontaci nese koncept, zaúčtovanou položku mění přeúčtování.
     *
     * @return array{item:array<string,mixed>,before:array<string,mixed>,after:array<string,mixed>}
     */
    public function setPostingOverrides(int $supplierId, int $id, mixed $input, ?int $userId): array
    {
        if (!$this->isDoubleEntry($supplierId)) {
            throw new OtherItemException('not_double_entry', 'Vlastní kontaci má jen podvojné účetnictví.', 409);
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($item['status'], ['draft', 'confirmed', 'posted'], true)) {
                throw new OtherItemException('invalid_status', 'Kontaci úhrad lze nastavit jen aktivnímu dokladu.', 409);
            }
            $changes = $this->postingProfiles->saveItemOverrides($supplierId, $item, $input, $userId);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['item' => $this->get($supplierId, $id)] + $changes;
    }

    /**
     * Výchozí dimenze z profilu vzniku druhu doplní hlavičce nového dokladu typy, které
     * ještě nemá (ruční volba má přednost).
     *
     * @param array<string,mixed> $data
     */
    private function applyKindDimensions(int $supplierId, int $id, array $data): void
    {
        if (!$this->isDoubleEntry($supplierId)) return;
        $origin = $this->postingResolver->origin($supplierId, $data + ['amount_czk' => 0, 'posting_lines' => []]);
        if ($origin['dimensions'] === []) return;
        $assignments = new DimensionAssignmentRepository($this->db);
        foreach ($origin['dimensions'] as $dimension) {
            $assignments->addHeaderDimensionIfMissing($supplierId, 'other_item', $id,
                $dimension['type_id'], $dimension['value_id'],
                ['origin' => 'rule_default', 'ref' => 'other_item_posting_profile:' . $origin['profile_id']]);
        }
    }

    /**
     * Původ kontace v auditu: zápis vznikl z účetního profilu druhu nebo z vlastní kontace
     * úhrad položky (výchozí kontace se nezapisuje, chová se jako dřív).
     *
     * @param array<string,mixed> $rule výsledek {@see OtherItemPostingResolver}
     */
    public function logPostingProvenance(int $supplierId, int $id, array $rule, int $entryId, ?int $userId): void
    {
        $this->activity->log('other_item.posting_profile_applied', $userId, 'other_item', $id, [
            'event' => $rule['event'],
            'source' => $rule['source'],
            'kind_id' => $rule['kind_id'],
            'profile_id' => $rule['profile_id'],
            'journal_entry_id' => $entryId,
        ], null, null, $supplierId);
    }

    private function assertBalanceAccount(int $supplierId, string $code, string $side): void
    {
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null || !$account['is_active'] || $account['account_type'] !== 'asset' && $account['account_type'] !== 'liability') {
            throw new OtherItemException('invalid_account', 'Pohledávka nebo závazek musí být veden na aktivním rozvahovém účtu.');
        }
        if ($side === 'receivable' && $account['account_type'] !== 'asset'
            || $side === 'payable' && $account['account_type'] !== 'liability') {
            throw new OtherItemException('invalid_account_side', 'Vybraný účet neodpovídá typu dokladu.');
        }
    }

    private function assertPostingAccounts(string $settlementAccount, array $postingLines): void
    {
        foreach ($postingLines as $line) {
            $code = $line['account_code'];
            if ($code === $settlementAccount || preg_match('/^(315|325)(?:$|[.\-])/', $code)) {
                throw new OtherItemException('same_accounts', 'Saldokontní účet smí být pouze na straně pohledávky nebo závazku.');
            }
        }
    }

    private function normalizePostingLines(mixed $raw, float $total): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 50) {
            throw new OtherItemException('invalid_posting_lines', 'Zadejte 1 až 50 protiřádků.');
        }
        $lines = [];
        $sum = 0;
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new OtherItemException('invalid_posting_lines', 'Neplatný řádek kontace.');
            }
            $code = trim((string) ($row['account_code'] ?? ''));
            $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($code === '' || strlen($code) > 20 || $amount === false || !is_finite((float) $amount)
                || $amount <= 0 || round((float) $amount, 2) !== (float) $amount) {
                throw new OtherItemException('invalid_posting_lines', 'Každý protiřádek potřebuje účet a kladnou částku na haléře.');
            }
            $sum += (int) round((float) $amount * 100);
            $lines[] = ['account_code' => $code, 'amount' => round((float) $amount, 2)];
        }
        if ($sum !== (int) round($total * 100)) {
            throw new OtherItemException('posting_lines_total', 'Součet protiřádků musí odpovídat celé částce dokladu.');
        }
        return $lines;
    }

    /**
     * @param array<string,mixed>|null $stored kind a kind_id, které koncept nebo šablona už nese —
     *                                         druh smí zůstat, i když ho mezitím skryli
     */
    private function normalize(int $supplierId, array $input, ?array $stored = null): array
    {
        $side = (string) ($input['side'] ?? '');
        if (!in_array($side, ['receivable', 'payable'], true)) {
            throw new OtherItemException('invalid_side', 'Vyberte pohledávku nebo závazek.');
        }
        $kind = (string) ($input['kind'] ?? 'other');
        if (!in_array($kind, self::KINDS, true)) {
            throw new OtherItemException('invalid_kind', 'Neplatný druh dokladu.');
        }
        $kindId = null;
        if (isset($input['kind_id']) && $input['kind_id'] !== '' && $input['kind_id'] !== 0 && $input['kind_id'] !== '0') {
            $kindId = filter_var($input['kind_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($kindId === false) {
                throw new OtherItemException('invalid_kind', 'Neplatný druh dokladu.');
            }
            $storedKindId = isset($stored['kind_id']) ? (int) $stored['kind_id'] : null;
            $this->kinds->assertUsable($supplierId, $kindId, $side, $kindId === $storedKindId);
            // Vlastní druh nemá vestavěný protějšek; filtr a API podle `kind` ho vedou jako ostatní.
            $kind = 'other';
        } else {
            $this->kinds->assertBuiltinUsable($supplierId, $kind, $side,
                $stored !== null && ($stored['kind_id'] ?? null) === null && ($stored['kind'] ?? null) === $kind
                    && ($stored['side'] ?? $side) === $side);
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new OtherItemException('invalid_title', 'Zadejte popis dokladu do 255 znaků.');
        }
        $issued = (string) ($input['issued_on'] ?? '');
        $due = (string) ($input['due_on'] ?? '');
        self::assertDate($issued, 'issued_on');
        self::assertDate($due, 'due_on');
        $accounting = $input['accounting_on'] ?? null;
        if ($accounting !== null && $accounting !== '') self::assertDate((string) $accounting, 'accounting_on');
        else $accounting = $issued;
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || $amount > 999999999999.99 || round((float) $amount, 2) !== (float) $amount) {
            throw new OtherItemException('invalid_amount', 'Částka musí být kladná a zadaná na haléře.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new OtherItemException('invalid_currency', 'Neplatná měna.');
        }
        if ($currency !== 'CZK') {
            throw new OtherItemException('currency_unsupported', 'Ostatní pohledávky a závazky lze nyní pořídit jen v CZK; cizoměnové položky vyžadují kurzové přecenění.');
        }
        $rate = 1.0;
        $partnerId = isset($input['partner_id']) && $input['partner_id'] !== '' ? (int) $input['partner_id'] : null;
        $partnerName = self::optional($input['partner_name'] ?? null, 190);
        if ($partnerId !== null) {
            $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$partnerId, $supplierId]);
            $clientName = $stmt->fetchColumn();
            if ($clientName === false) throw new OtherItemException('invalid_partner', 'Protistrana nebyla nalezena.', 404);
            // API/MCP posílá často jen partner_id; editor, přehled i deník zobrazují partner_name.
            $partnerName ??= self::optional(mb_substr((string) $clientName, 0, 190), 190);
        }
        $vs = trim((string) ($input['variable_symbol'] ?? ''));
        if ($vs !== '' && !preg_match('/^[0-9]{1,20}$/', $vs)) {
            throw new OtherItemException('invalid_variable_symbol', 'Variabilní symbol smí obsahovat jen číslice.');
        }
        $counterparty = OtherItemCounterpartyAccount::normalize($input['counterparty_account'] ?? null,
            $input['counterparty_bank_code'] ?? null);
        $account = trim((string) ($input['account_code'] ?? ''));
        $counter = trim((string) ($input['counter_account_code'] ?? ''));
        if (strlen($account) > 20 || strlen($counter) > 20) {
            throw new OtherItemException('invalid_account', 'Neplatný kód účtu.');
        }
        // null/[] = bez rozpadu (jediný protiúčet), stejně jako chybějící klíč u nového dokladu.
        $postingLines = ($input['posting_lines'] ?? []) !== []
            ? $this->normalizePostingLines($input['posting_lines'], round((float) $amount * $rate, 2))
            : [];
        if ($postingLines !== []) {
            $counter = count($postingLines) === 1 ? $postingLines[0]['account_code'] : '';
        }
        return [
            'side' => $side, 'kind' => $kind, 'kind_id' => $kindId, 'title' => $title,
            'partner_id' => $partnerId,
            'partner_name' => $partnerName,
            'issued_on' => $issued, 'accounting_on' => $accounting, 'due_on' => $due,
            'currency' => $currency, 'amount' => round((float) $amount, 2),
            'exchange_rate' => $rate, 'amount_czk' => round((float) $amount * $rate, 2),
            'variable_symbol' => $vs === '' ? null : $vs,
            'counterparty_account' => $counterparty['counterparty_account'],
            'counterparty_bank_code' => $counterparty['counterparty_bank_code'],
            'account_code' => $account === '' ? null : $account,
            'counter_account_code' => $counter === '' ? null : $counter,
            'posting_lines' => $postingLines,
            'note' => self::optional($input['note'] ?? null, 10000),
        ];
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        if (mb_strlen($text) > $limit) throw new OtherItemException('text_too_long', 'Text je příliš dlouhý.');
        return $text === '' ? null : $text;
    }

    private static function assertDate(string $date, string $field): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new OtherItemException('invalid_date', 'Neplatné datum ' . $field . '.');
        }
    }
}
