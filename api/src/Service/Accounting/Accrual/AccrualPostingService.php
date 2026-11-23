<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\PostingService;

/**
 * Zaúčtování agendy Časové rozlišení: rozpuštění řádku plánu, jeho storno a předčasné
 * ukončení položky k datu (náhled, zaúčtování, zrušení).
 *
 * Zápis rozpuštění má `source_type = accrual_release`, ukončení `accrual_termination`,
 * `source_id` je řádek plánu. Unikátní aktivní zdroj zápisu tak zastaví dvojí zaúčtování
 * téhož řádku. Období, zámek data a vyváženost hlídá {@see PostingService::postDocument()};
 * náhled ukazuje stav období předem ({@see lockInfo()}).
 *
 * Datum zaúčtovaného zápisu nejde potvrzením obejít: na rozdíl od mazání zápisů v deníku
 * nemá účtování do uzamčeného data výjimku, proto agenda dialog potvrzení zámku
 * nepoužívá a uzamčené datum jen ukáže a zaúčtování odmítne.
 */
final class AccrualPostingService
{
    /** Dnešní den pro splatnost a rozlišení budoucích zápisů (testy a dávky ho mohou určit). */
    private ?string $today = null;

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualItemRepository $items,
        private readonly AccrualItemService $service,
        private readonly PostingService $posting,
    ) {}

    public function setToday(?string $date): void
    {
        $this->today = $date;
    }

    private function today(): string
    {
        return $this->today ?? date('Y-m-d');
    }

    /** @return array<string,mixed> */
    public function previewRow(int $supplierId, int $itemId, int $rowId): array
    {
        [$item, $row] = $this->itemAndRow($supplierId, $itemId, $rowId, false);
        return [
            'row' => $row,
            'entry_date' => $row['due_on'],
            'lines' => AccrualKinds::releaseLines((string) $item['kind'], (string) $item['accrual_account_code'],
                (string) $item['counter_account_code'], (float) $row['amount_planned'], $item['cost_center']),
            'lock' => $this->lockInfo($supplierId, (string) $row['due_on']),
        ];
    }

    /**
     * Zaúčtuje splatný řádek plánu (datum zápisu = konec úseku).
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string, allow_closing_period?:bool} $meta
     * @return array<string,mixed> položka po zaúčtování
     */
    public function postRow(int $supplierId, int $itemId, int $rowId, array $meta = []): array
    {
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->inTransaction(function () use ($supplierId, $itemId, $rowId, $meta): void {
            $this->postRowLocked($supplierId, $itemId, $rowId, $meta);
        }));
        return $this->service->get($supplierId, $itemId);
    }

    /** @param array<string,mixed> $meta */
    public function postRowLocked(int $supplierId, int $itemId, int $rowId, array $meta): int
    {
        [$item, $row] = $this->itemAndRow($supplierId, $itemId, $rowId, true);
        if ($row['kind'] !== 'release' || $row['status'] !== 'planned') {
            throw new AccrualException('row_not_planned', 'Řádek plánu už je zaúčtovaný nebo zrušený.', 409);
        }
        if ($row['due_on'] > $this->today() && empty($meta['allow_future'])) {
            throw new AccrualException('row_not_due', 'Rozpuštění lze zaúčtovat nejdřív v den konce úseku.', 409);
        }
        $amount = (float) $row['amount_planned'];
        $lines = AccrualKinds::releaseLines((string) $item['kind'], (string) $item['accrual_account_code'],
            (string) $item['counter_account_code'], $amount, $item['cost_center']);
        $entryId = $this->posting->postDocument($supplierId, 'accrual_release', $rowId, $lines, [
            'entry_date' => (string) $row['due_on'],
            'document_date' => (string) $row['due_on'],
            'document_no' => (string) $item['item_no'],
            'description' => self::description($item, 'Rozpuštění'),
            'posted' => true,
            'user_id' => $meta['user_id'] ?? null,
            'posted_by' => $meta['user_id'] ?? null,
            'ip' => $meta['ip'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
        ] + (!empty($meta['allow_closing_period']) ? ['allow_closing_period' => true] : []));
        $this->items->setScheduleStatus($supplierId, $rowId, 'posted', $entryId, date('Y-m-d H:i:s'));
        $accountId = $this->accountId($supplierId, (string) $item['accrual_account_code']);
        $this->items->addLink($supplierId, $itemId, $entryId, $accountId, AccrualKinds::releaseLinkAmount((string) $item['kind'], $amount),
            'release', 'schedule', $rowId, $meta['user_id'] ?? null);
        $this->syncCompletion($supplierId, $itemId);
        return $entryId;
    }

    /**
     * Storno zaúčtovaného rozpuštění protizápisem ke dni původního zápisu (jen otevřené
     * a neuzamčené období). Řádek se vrátí do plánu.
     *
     * @param array<string,mixed> $meta
     */
    public function unpostRow(int $supplierId, int $itemId, int $rowId, array $meta = []): array
    {
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->inTransaction(function () use ($supplierId, $itemId, $rowId, $meta): void {
            [$item, $row] = $this->itemAndRow($supplierId, $itemId, $rowId, true);
            if ($row['kind'] !== 'release' || $row['status'] !== 'posted' || !in_array($row['entry_source_type'], ['accrual_release'], true)) {
                throw new AccrualException('row_not_posted', 'Stornovat lze jen rozpuštění zaúčtované agendou.', 409);
            }
            if ($item['status'] === 'terminated') {
                throw new AccrualException('terminated', 'Položka je ukončená. Nejdřív zrušte ukončení.', 409);
            }
            $this->posting->reverse($supplierId, (int) $row['journal_entry_id'], [
                'entry_date' => (string) $row['entry_date'],
                'description' => 'Storno: ' . self::description($item, 'Rozpuštění'),
                'user_id' => $meta['user_id'] ?? null,
                'posted_by' => $meta['user_id'] ?? null,
            ]);
            $this->items->setScheduleStatus($supplierId, $rowId, 'planned', null, null);
            if ($item['status'] === 'completed') {
                $this->items->update($supplierId, $itemId, ['status' => 'active']);
            }
        }));
        return $this->service->get($supplierId, $itemId);
    }

    /**
     * Náhled předčasného ukončení k datu: rozpuštění za dny od posledního pokrytého dne do
     * data ukončení a zbytek zůstatku na protiúčet ukončení, oboje jedním zápisem. Ukáže
     * zaúčtované a převzaté řádky plánu po datu ukončení, které je třeba stornovat.
     *
     * @return array<string,mixed>
     */
    public function terminationPreview(int $supplierId, int $itemId, string $date, ?string $terminationAccount = null): array
    {
        $item = $this->items->find($supplierId, $itemId) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
        return $this->terminationPlan($supplierId, $item, $this->items->schedule($supplierId, $itemId), $date, $terminationAccount);
    }

    /**
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $schedule
     * @return array<string,mixed>
     */
    private function terminationPlan(int $supplierId, array $item, array $schedule, string $date, ?string $terminationAccount): array
    {
        if ($item['status'] !== 'active' || $item['managed_by'] !== 'agenda') {
            throw new AccrualException('not_active', 'Předčasně ukončit lze jen aktivní položku agendy.', 409);
        }
        if (!\MyInvoice\Http\IsoDate::isValid($date)) {
            throw new AccrualException('invalid_date', 'Neplatné datum ukončení.', 422, 'date');
        }
        if ($item['period_from'] !== null && $date < (string) $item['period_from'] && $item['opening_as_of'] === null) {
            throw new AccrualException('before_period', 'Datum ukončení je před začátkem období položky.', 422, 'date');
        }
        $account = $terminationAccount !== null && trim($terminationAccount) !== ''
            ? trim($terminationAccount)
            : (string) ($item['termination_account_code'] ?? $item['counter_account_code'] ?? '');
        if ($account === '' || str_starts_with($account, '38')) {
            throw new AccrualException('invalid_counter_account', 'Zvolte protiúčet ukončení mimo účty časového rozlišení.', 422, 'termination_account_code');
        }
        if ($this->items->accountId($supplierId, $account) === null) {
            throw new AccrualException('account_not_found', 'Účet ' . $account . ' není v účtové osnově.', 422, 'termination_account_code');
        }

        // Zaúčtovaný či převzatý řádek s datem zápisu po dnešku i po datu ukončení je budoucí
        // (POHODA zápisy předgeneruje do konce roku): ukončení ho nabídne ke stornu. Řádek
        // zaúčtovaný do dneška, jehož zápis nebo pokrytý úsek leží po datu ukončení, ukončení
        // nepřipustí: nesmí jít před poslední zaúčtované rozpuštění.
        $future = [];
        $kept = [];
        $today = $this->today();
        foreach ($schedule as $row) {
            if ($row['kind'] !== 'release' || !in_array($row['status'], ['posted', 'linked'], true)) {
                continue;
            }
            $entryDate = (string) ($row['entry_date'] ?? $row['due_on']);
            $end = (string) ($row['to_on'] ?? $row['due_on']);
            if ($entryDate > $date && $entryDate > $today) {
                $future[] = $row;
            } elseif ($entryDate > $date || $end > $date) {
                throw new AccrualException('before_last_release',
                    'Datum ukončení nesmí být před posledním zaúčtovaným rozpuštěním (' . max($entryDate, $end) . '). Zvolte pozdější datum nebo rozpuštění nejdřív stornujte.',
                    409, 'date', ['last_release_to' => max($entryDate, $end)]);
            } else {
                $kept[] = $row;
            }
        }
        $covered = AccrualItemService::coveredThrough($item, $kept);
        $sign = AccrualKinds::normalSign((string) $item['kind']);
        $balance = round(($this->items->balances($supplierId, [(int) $item['id']], $date)[(int) $item['id']]['balance'] ?? 0.0) * $sign, 2);
        $release = 0.0;
        if ($covered !== null && $item['period_from'] !== null && $item['period_to'] !== null && $item['counter_account_code'] !== null) {
            $release = AccrualPlanBuilder::releaseThrough((string) $item['method'], (float) $item['basis_amount'],
                (string) $item['period_from'], (string) $item['period_to'], $covered, min($date, (string) $item['period_to']));
        }
        if ($balance > 0 && $release > $balance) {
            $release = $balance;
        }
        if ($balance <= 0) {
            $release = 0.0;
        }
        $remainder = round($balance - $release, 2);
        $lines = [];
        if ((int) round($release * 100) !== 0) {
            array_push($lines, ...AccrualKinds::releaseLines((string) $item['kind'], (string) $item['accrual_account_code'],
                (string) $item['counter_account_code'], $release, $item['cost_center']));
        }
        if ((int) round($remainder * 100) !== 0) {
            array_push($lines, ...AccrualKinds::releaseLines((string) $item['kind'], (string) $item['accrual_account_code'],
                $account, $remainder, $item['cost_center']));
        }
        $lines = self::mergeLines($lines);
        $hasLinked = array_any($future, static fn (array $r): bool => $r['status'] === 'linked'
            || !in_array($r['entry_source_type'], ['accrual_release'], true));
        $lock = $this->lockInfo($supplierId, $date);
        foreach ($future as $row) {
            $rowLock = $this->lockInfo($supplierId, (string) $row['entry_date']);
            if (!$rowLock['postable']) {
                $lock['postable'] = false;
                $lock['future_locked'] = true;
            }
        }
        return [
            'item_id' => (int) $item['id'],
            'date' => $date,
            'covered_through' => $covered,
            'balance' => $balance,
            'release_amount' => round($release, 2),
            'remainder_amount' => $remainder,
            'total' => round($release + $remainder, 2),
            'counter_account_code' => $item['counter_account_code'],
            'termination_account_code' => $account,
            'lines' => $lines,
            'future_rows' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'due_on' => $r['due_on'], 'amount' => $r['amount_planned'], 'status' => $r['status'],
                'journal_entry_id' => $r['journal_entry_id'], 'entry_date' => $r['entry_date'], 'document_no' => $r['entry_document_no'],
                'imported' => $r['status'] === 'linked' || $r['entry_source_type'] !== 'accrual_release',
            ], $future),
            'requires_linked_confirmation' => $hasLinked,
            'lock' => $lock,
        ];
    }

    /**
     * Zaúčtuje předčasné ukončení. Zaúčtované řádky po datu ukončení se stornují jen
     * s `reverse_future`, převzaté navíc s `confirm_linked`.
     *
     * @param array{date:string, termination_account_code?:?string, reason?:?string, reverse_future?:bool, confirm_linked?:bool} $input
     * @param array<string,mixed> $meta
     */
    public function terminate(int $supplierId, int $itemId, array $input, array $meta = []): array
    {
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->inTransaction(function () use ($supplierId, $itemId, $input, $meta): void {
            $item = $this->items->find($supplierId, $itemId, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
            $date = substr((string) ($input['date'] ?? ''), 0, 10);
            $plan = $this->terminationPlan($supplierId, $item, $this->items->schedule($supplierId, $itemId, true), $date,
                isset($input['termination_account_code']) ? (string) $input['termination_account_code'] : null);
            if ($plan['future_rows'] !== []) {
                if (empty($input['reverse_future'])) {
                    throw new AccrualException('future_rows', 'Po datu ukončení jsou zaúčtovaná rozpuštění. Potvrďte jejich storno.', 409, null,
                        ['future_rows' => $plan['future_rows']]);
                }
                if ($plan['requires_linked_confirmation'] && empty($input['confirm_linked'])) {
                    throw new AccrualException('linked_confirmation', 'Storno převzatých zápisů vyžaduje výslovné potvrzení.', 409, null,
                        ['future_rows' => $plan['future_rows']]);
                }
            }
            if ($plan['lines'] === []) {
                throw new AccrualException('nothing_to_terminate', 'Položka nemá k datu ukončení žádný zůstatek.', 409);
            }
            foreach ($plan['future_rows'] as $row) {
                $this->posting->reverse($supplierId, (int) $row['journal_entry_id'], [
                    'entry_date' => (string) $row['entry_date'],
                    'description' => 'Storno při ukončení: ' . self::description($item, 'Rozpuštění'),
                    'user_id' => $meta['user_id'] ?? null,
                    'posted_by' => $meta['user_id'] ?? null,
                ]);
                $this->items->setScheduleStatus($supplierId, (int) $row['id'], 'cancelled', (int) $row['journal_entry_id']);
            }
            $rowId = $this->items->upsertScheduleRow($supplierId, $itemId, [
                'kind' => 'termination', 'due_on' => $date, 'from_on' => $plan['covered_through'] === null ? null : AccrualPlanBuilder::dayAfter($plan['covered_through']),
                'to_on' => $date, 'days' => null, 'amount_planned' => $plan['total'], 'status' => 'planned',
            ]);
            $entryId = $this->posting->postDocument($supplierId, 'accrual_termination', $rowId, $plan['lines'], [
                'entry_date' => $date,
                'document_date' => $date,
                'document_no' => (string) $item['item_no'],
                'description' => self::description($item, 'Předčasné ukončení'),
                'posted' => true,
                'user_id' => $meta['user_id'] ?? null,
                'posted_by' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ]);
            $this->items->setScheduleStatus($supplierId, $rowId, 'posted', $entryId, date('Y-m-d H:i:s'));
            $this->items->addLink($supplierId, $itemId, $entryId, $this->accountId($supplierId, (string) $item['accrual_account_code']),
                AccrualKinds::releaseLinkAmount((string) $item['kind'], (float) $plan['total']), 'termination', 'schedule', $rowId, $meta['user_id'] ?? null);
            $this->items->cancelPlannedAfter($supplierId, $itemId, '1000-01-01');
            $this->items->update($supplierId, $itemId, [
                'status' => 'terminated',
                'terminated_on' => $date,
                'termination_entry_id' => $entryId,
                'termination_reason' => isset($input['reason']) && trim((string) $input['reason']) !== '' ? mb_substr(trim((string) $input['reason']), 0, 500) : null,
                'termination_account_code' => $plan['termination_account_code'],
                'updated_by' => $meta['user_id'] ?? null,
            ]);
        }));
        return $this->service->get($supplierId, $itemId);
    }

    /**
     * Zruší předčasné ukončení stornem jeho zápisu ke dni ukončení; jde jen v otevřeném
     * a neuzamčeném období. Položka se vrátí mezi aktivní a plán se přepočte.
     *
     * @param array<string,mixed> $meta
     */
    public function cancelTermination(int $supplierId, int $itemId, array $meta = []): array
    {
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->inTransaction(function () use ($supplierId, $itemId, $meta): void {
            $item = $this->items->find($supplierId, $itemId, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
            if ($item['status'] !== 'terminated' || $item['termination_entry_id'] === null) {
                throw new AccrualException('not_terminated', 'Položka není ukončená zápisem agendy.', 409);
            }
            $entry = $this->db->pdo()->prepare('SELECT entry_date, source_type, source_id, reversed_by FROM journal_entries WHERE supplier_id = ? AND id = ?');
            $entry->execute([$supplierId, (int) $item['termination_entry_id']]);
            $e = $entry->fetch(\PDO::FETCH_ASSOC);
            if ($e === false || $e['source_type'] !== 'accrual_termination') {
                throw new AccrualException('not_terminated', 'Ukončení nezaúčtovala agenda, zrušte ho v deníku.', 409);
            }
            $lock = $this->lockInfo($supplierId, (string) $e['entry_date']);
            if (!$lock['postable']) {
                throw new AccrualException('period_locked', 'Ukončení je v uzavřeném nebo uzamčeném období, zrušit ho nelze.', 409, null, ['lock' => $lock]);
            }
            if ($e['reversed_by'] === null) {
                $this->posting->reverse($supplierId, (int) $item['termination_entry_id'], [
                    'entry_date' => (string) $e['entry_date'],
                    'description' => 'Storno: ' . self::description($item, 'Předčasné ukončení'),
                    'user_id' => $meta['user_id'] ?? null,
                    'posted_by' => $meta['user_id'] ?? null,
                ]);
            }
            if ($e['source_id'] !== null) {
                $this->items->setScheduleStatus($supplierId, (int) $e['source_id'], 'cancelled', (int) $item['termination_entry_id']);
            }
            $this->items->update($supplierId, $itemId, [
                'status' => 'active', 'terminated_on' => null, 'termination_entry_id' => null, 'termination_reason' => null,
                'updated_by' => $meta['user_id'] ?? null,
            ]);
            $this->service->rebuildPlan($supplierId, $itemId);
        }));
        return $this->service->get($supplierId, $itemId);
    }

    /**
     * Krok uzávěrky: nezaúčtované řádky plánu splatné do rozvahového dne. Roční řádky
     * zaúčtuje uzávěrka sama ({@see postYearlyForClosing()}), měsíční jen ukáže jako
     * kontrolu (zaúčtuje je účetní v agendě).
     *
     * @return array{yearly: list<array<string,mixed>>, monthly: list<array<string,mixed>>, yearly_total: float, monthly_total: float}
     */
    public function closingPending(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id AS schedule_id, s.due_on, s.amount_planned, ai.id AS item_id, ai.item_no, ai.title, ai.kind, ai.method,
                    ai.accrual_account_code, ai.counter_account_code
               FROM accrual_schedule s
               JOIN accrual_items ai ON ai.id = s.accrual_item_id AND ai.supplier_id = s.supplier_id
          LEFT JOIN journal_entries je ON je.id = s.journal_entry_id AND je.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND s.due_on >= ? AND s.due_on <= ? AND s.kind = 'release'
                AND ai.status = 'active' AND ai.managed_by = 'agenda'
                AND (s.status = 'planned' OR (s.status IN ('posted', 'linked') AND (je.id IS NULL OR je.reversed_by IS NOT NULL)))
              ORDER BY s.due_on, ai.item_no"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        $out = ['yearly' => [], 'monthly' => [], 'yearly_total' => 0.0, 'monthly_total' => 0.0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $key = $r['method'] === 'yearly' ? 'yearly' : 'monthly';
            $r['schedule_id'] = (int) $r['schedule_id'];
            $r['item_id'] = (int) $r['item_id'];
            $r['amount_planned'] = round((float) $r['amount_planned'], 2);
            $out[$key][] = $r;
            $out[$key . '_total'] = round($out[$key . '_total'] + $r['amount_planned'], 2);
        }
        return $out;
    }

    /**
     * Zaúčtuje roční rozpuštění položek agendy k rozvahovému dni (krok uzávěrky
     * „Časové rozlišení"). Účtuje i do období ve stavu uzavírání, jako ostatní kroky.
     *
     * @param array<string,mixed> $meta
     * @return array{posted:int, total:float}
     */
    public function postYearlyForClosing(int $supplierId, string $startsOn, string $endsOn, array $meta = []): array
    {
        $pending = $this->closingPending($supplierId, $startsOn, $endsOn);
        $posted = 0;
        $total = 0.0;
        TransactionFailure::retrying($this->db->pdo(), function () use ($supplierId, $pending, $meta, &$posted, &$total): void {
            $this->inTransaction(function () use ($supplierId, $pending, $meta, &$posted, &$total): void {
                $posted = 0;
                $total = 0.0;
                foreach ($pending['yearly'] as $row) {
                    $this->postRowLocked($supplierId, $row['item_id'], $row['schedule_id'], $meta + ['allow_closing_period' => true, 'allow_future' => true]);
                    $posted++;
                    $total = round($total + $row['amount_planned'], 2);
                }
            });
        });
        return ['posted' => $posted, 'total' => $total];
    }

    /** Položka bez nezaúčtovaných řádků a s nulovým zůstatkem je dokončená. */
    private function syncCompletion(int $supplierId, int $itemId): void
    {
        $item = $this->items->find($supplierId, $itemId);
        if ($item === null || $item['status'] !== 'active') {
            return;
        }
        foreach ($this->items->schedule($supplierId, $itemId) as $row) {
            if ($row['kind'] === 'release' && $row['status'] === 'planned') {
                return;
            }
        }
        $balance = $this->items->balances($supplierId, [$itemId], '9999-12-31')[$itemId]['balance'] ?? 0.0;
        if ((int) round($balance * 100) === 0) {
            $this->items->update($supplierId, $itemId, ['status' => 'completed']);
        }
    }

    /**
     * Stav období a zámku data pro náhled a pojistky. Čte vždy aktuální stav (zámek se může
     * změnit během jednoho požadavku i mezi požadavky sdílené instance služby).
     *
     * @return array{postable:bool, closed:bool, closing:bool, date_locked:bool, period_status:?string}
     */
    public function lockInfo(int $supplierId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ? LIMIT 1');
        $stmt->execute([$supplierId, $date, $date]);
        $status = $stmt->fetchColumn();
        $status = $status === false ? null : (string) $status;
        $lock = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $dateLocked = $lockedUntil !== false && $lockedUntil !== null && $date <= (string) $lockedUntil;
        $closed = $status !== null && \MyInvoice\Service\Accounting\AccountingPeriodStatus::isClosed($status);
        $closing = $status === 'closing';
        return [
            'postable' => !$closed && !$closing && !$dateLocked,
            'closed' => $closed,
            'closing' => $closing,
            'date_locked' => $dateLocked,
            'period_status' => $status,
        ];
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function itemAndRow(int $supplierId, int $itemId, int $rowId, bool $lock): array
    {
        $item = $this->items->find($supplierId, $itemId, $lock) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
        if ($item['managed_by'] !== 'agenda' || !in_array($item['status'], ['active', 'completed', 'terminated'], true)) {
            throw new AccrualException('not_active', 'Rozpouštět lze jen aktivní položku agendy.', 409);
        }
        if ($item['counter_account_code'] === null) {
            throw new AccrualException('counter_account_required', 'Položka nemá protiúčet rozpouštění.', 422, 'counter_account_code');
        }
        foreach ($this->items->schedule($supplierId, $itemId, $lock) as $row) {
            if ($row['id'] === $rowId) {
                return [$item, $row];
            }
        }
        throw new AccrualException('row_not_found', 'Řádek plánu neexistuje.', 404);
    }

    private function accountId(int $supplierId, string $code): int
    {
        return $this->items->accountId($supplierId, $code)
            ?? throw new AccrualException('account_not_found', 'Účet ' . $code . ' není v účtové osnově.', 422);
    }

    /** @param array<string,mixed> $item */
    private static function description(array $item, string $what): string
    {
        return mb_substr('Časové rozlišení ' . $item['item_no'] . ' — ' . $what . ': ' . $item['title'], 0, 255);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private static function mergeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $key = $line['account_code'] . '|' . $line['side'] . '|' . ($line['cost_center'] ?? '');
            if (isset($out[$key])) {
                $out[$key]['amount'] = round($out[$key]['amount'] + $line['amount'], 2);
            } else {
                $out[$key] = $line;
            }
        }
        return array_values($out);
    }

    private function inTransaction(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $result = $fn();
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
