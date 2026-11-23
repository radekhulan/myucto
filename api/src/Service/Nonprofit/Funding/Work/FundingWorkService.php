<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding\Work;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Pracovní evidence čerpání dotací a účelových darů (#403): průběžné přiřazení výnosových
 * a nákladových řádků deníku ke zdroji. Je to pracovní podklad vedle potvrzených zdrojů
 * a J6 ({@see \MyInvoice\Service\Nonprofit\Funding\FundingService}); daňový výpočet z něj nečte.
 *
 * Vazba na řádek účetnictví neblokuje. Přeúčtování řádek smaže (vazba ztratí line_id),
 * storno označí zápis `reversed_by`; obojí se při čtení ukáže jako vazba ke kontrole podle
 * snímku pořízeného při přiřazení a opraví se „Navázat znovu“ nebo odebráním.
 *
 * Zápisy běží v jedné transakci s pořadím zámků: období → zdroj → zápisy deníku (podle id).
 * Zámek období serializuje všechny zápisy evidence v období (rozpočet, kapacita řádku,
 * jedinečnost výnosu); čtení uvnitř zápisu jsou zamykací, aby viděla potvrzená data
 * souběžného uživatele.
 */
final class FundingWorkService
{
    public const STATE_OK = 'ok';
    public const STATE_CHANGED = 'changed';
    public const STATE_REMOVED = 'removed';
    public const STATE_REVERSED = 'reversed';
    private const KINDS = ['operating_grant', 'investment_grant', 'gift'];
    private const MAX_CENTS = 999999999999999;

    public function __construct(
        private readonly Connection $db,
        private readonly FundingWorkLedger $ledger,
        private readonly DimensionPeriodGuard $guard,
        private readonly FundingRepository $confirmed,
        private readonly ActivityLogger $activity,
    ) {}

    // ---------------------------------------------------------------- čtení

    /** Přehled zdrojů období se souhrnnými částkami. */
    public function overview(int $supplier, int $period): array
    {
        $periodRow = $this->period($supplier, $period, false);
        $sources = $this->sourceRows($supplier, 's.period_id = ?', [$period]);
        $ids = array_keys($sources);
        $incomes = $this->incomes($supplier, $ids);
        $allocations = $this->allocations($supplier, $sources);
        $out = [];
        foreach ($sources as $id => $source) {
            $out[] = $source + ['totals' => self::totals($source, $incomes[$id] ?? [], $allocations[$id] ?? [])];
        }
        return ['period' => $periodRow, 'editable' => !AccountingPeriodStatus::isClosed($periodRow['status']), 'sources' => $out];
    }

    /** Detail zdroje: výnosy a přiřazení se stavem, nabídka k opětovnému navázání a historie. */
    public function detail(int $supplier, int $period, int $sourceId): array
    {
        $periodRow = $this->period($supplier, $period, false);
        $source = $this->source($supplier, $period, $sourceId, false);
        $incomes = $this->incomes($supplier, [$sourceId])[$sourceId] ?? [];
        $allocations = $this->allocations($supplier, [$sourceId => $source])[$sourceId] ?? [];
        $this->attachRelinkCandidates($supplier, $source, $incomes, $allocations);
        $this->attachConfirmedLinks($supplier, $incomes);
        $this->attachPaymentState($supplier, $allocations);
        $history = $this->db->pdo()->prepare('SELECT h.id, h.income_id, h.allocation_id, h.action, h.before_json, h.after_json, h.reason, h.created_at,
                u.name AS actor_name
            FROM nonprofit_funding_work_history h LEFT JOIN users u ON u.id = h.created_by
            WHERE h.supplier_id = ? AND h.source_id = ? ORDER BY h.id DESC LIMIT 200');
        $history->execute([$supplier, $sourceId]);
        $rows = array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'income_id' => $r['income_id'] === null ? null : (int) $r['income_id'],
            'allocation_id' => $r['allocation_id'] === null ? null : (int) $r['allocation_id'], 'action' => $r['action'],
            'before' => $r['before_json'] === null ? null : json_decode($r['before_json'], true),
            'after' => $r['after_json'] === null ? null : json_decode($r['after_json'], true),
            'reason' => $r['reason'], 'created_at' => $r['created_at'], 'actor' => $r['actor_name'],
        ], $history->fetchAll(PDO::FETCH_ASSOC));
        return [
            'period' => $periodRow,
            'editable' => !AccountingPeriodStatus::isClosed($periodRow['status']) && $source['status'] === 'active',
            'source' => $source,
            'totals' => self::totals($source, $incomes, $allocations),
            'incomes' => $incomes,
            'allocations' => $allocations,
            'history' => $rows,
        ];
    }

    /** Poskytovatelé, druhy činnosti, nákladové účty a potvrzené zdroje pro formulář zdroje. */
    public function choices(int $supplier, int $period, string $search): array
    {
        $this->period($supplier, $period, false);
        if (mb_strlen($search) > 100) self::invalid('Hledaný poskytovatel je příliš dlouhý.');
        $like = '%' . addcslashes(trim($search), '%_\\') . '%';
        $q = $this->db->pdo()->prepare('SELECT id, company_name, first_name, last_name FROM clients WHERE supplier_id = ? AND (company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY company_name, first_name, last_name, id LIMIT 101');
        $q->execute([$supplier, $like, $like, $like]);
        $providers = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($providers) > 100;
        $providers = array_map(static fn(array $r) => ['id' => (int) $r['id'], 'label' => self::partyLabel($r)], array_slice($providers, 0, 100));
        $q = $this->db->pdo()->prepare("SELECT v.id, v.code, v.name FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id AND t.supplier_id = v.supplier_id WHERE v.supplier_id = ? AND t.system_key = 'activity_kind' ORDER BY v.code, v.id");
        $q->execute([$supplier]);
        $activities = array_map(static fn(array $r) => ['id' => (int) $r['id'], 'label' => $r['code'] . ' - ' . $r['name']], $q->fetchAll(PDO::FETCH_ASSOC));
        $q = $this->db->pdo()->prepare("SELECT account_code, name FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE '5%' AND account_code NOT LIKE '59%' AND is_active = 1 ORDER BY account_code");
        $q->execute([$supplier]);
        $accounts = array_map(static fn(array $r) => ['code' => $r['account_code'], 'label' => $r['account_code'] . ' - ' . $r['name']], $q->fetchAll(PDO::FETCH_ASSOC));
        $confirmed = [];
        foreach ($this->confirmed->latest($supplier) as $key => $row) $confirmed[] = ['key' => (string) $key, 'label' => (string) $row['policy']['title']];
        return ['providers' => $providers, 'providers_more' => $more, 'activities' => $activities, 'accounts' => $accounts, 'confirmed_sources' => $confirmed];
    }

    public function incomeCandidates(int $supplier, int $period, string $search, int $page): array
    {
        $this->period($supplier, $period, false);
        if (mb_strlen($search) > 100) self::invalid('Hledaný text je příliš dlouhý.');
        return $this->ledger->incomeCandidates($supplier, $period, $search, max(1, $page));
    }

    public function costCandidates(int $supplier, int $period, int $sourceId, string $search, int $page): array
    {
        $source = $this->source($supplier, $period, $sourceId, false);
        if (mb_strlen($search) > 100) self::invalid('Hledaný text je příliš dlouhý.');
        return $this->ledger->costCandidates($supplier, $source, $search, max(1, $page));
    }

    /** Rozpad nákladového řádku podle zdrojů (kolik z řádku nese který zdroj). */
    public function costLineAllocations(int $supplier, int $period, int $lineId): array
    {
        $this->period($supplier, $period, false);
        $line = $this->ledger->lines($supplier, [$lineId])[$lineId] ?? null;
        if ($line === null) throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404);
        $q = $this->db->pdo()->prepare("SELECT x.id, x.source_id, x.amount_cents, x.note, s.title, s.status AS source_status, s.period_id
            FROM nonprofit_funding_work_allocations x JOIN nonprofit_funding_work_sources s ON s.supplier_id = x.supplier_id AND s.id = x.source_id
            WHERE x.supplier_id = ? AND x.linked_line_id = ? AND x.status = 'active' ORDER BY x.id");
        $q->execute([$supplier, $lineId]);
        $rows = array_map(static fn(array $r) => ['id' => (int) $r['id'], 'source_id' => (int) $r['source_id'], 'period_id' => (int) $r['period_id'], 'title' => $r['title'],
            'source_status' => $r['source_status'], 'amount_cents' => (int) $r['amount_cents'], 'note' => $r['note']], $q->fetchAll(PDO::FETCH_ASSOC));
        $allocated = array_sum(array_map(static fn(array $r) => $r['source_status'] === 'active' ? $r['amount_cents'] : 0, $rows));
        return ['line' => $line, 'allocations' => $rows, 'allocated_cents' => $allocated, 'capacity_cents' => $line['amount_cents'] - $allocated];
    }

    // ---------------------------------------------------------------- zdroj

    /** @param array<string,mixed> $input */
    public function create(int $supplier, int $period, array $input, int $actor): array
    {
        $data = $this->normalize($supplier, $input, true);
        $incomeLines = $data['income_line_ids'];
        unset($data['income_line_ids']);
        $id = $this->guard->transaction(function () use ($supplier, $period, $data, $incomeLines, $actor): int {
            $this->lockPeriod($supplier, $period);
            $this->assertReferences($supplier, $data);
            $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_sources (supplier_id, period_id, provider_id, kind, title, contract_ref, budget_cents, valid_from, valid_to, activity_kind_ids, account_prefixes, legacy_source_key, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$supplier, $period, $data['provider_id'], $data['kind'], $data['title'], $data['contract_ref'], $data['budget_cents'],
                $data['valid_from'], $data['valid_to'], json_encode($data['activity_kind_ids']), json_encode($data['account_prefixes']), $data['legacy_source_key'], $actor]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $this->history($supplier, $id, null, null, 'source_created', null, $data, null, $actor);
            if ($incomeLines !== []) $this->attachIncomes($supplier, $this->source($supplier, $period, $id, true), $incomeLines, $actor);
            return $id;
        });
        return $this->detail($supplier, $period, $id);
    }

    /** @param array<string,mixed> $input */
    public function update(int $supplier, int $period, int $sourceId, array $input, int $expectedVersion, string $reason, int $actor): array
    {
        $data = $this->normalize($supplier, $input, false);
        $reason = self::reason($reason, false);
        $this->guard->transaction(function () use ($supplier, $period, $sourceId, $data, $expectedVersion, $reason, $actor): void {
            $this->lockPeriod($supplier, $period);
            $source = $this->writableSource($supplier, $period, $sourceId, $expectedVersion);
            $this->assertReferences($supplier, $data);
            $allocated = $this->allocatedOnSource($supplier, $sourceId, null);
            if ($data['budget_cents'] < $allocated) {
                throw new FundingWorkException('funding_work_budget_below_allocated', 'Rozpočet nesmí být nižší než již přiřazené náklady (' . self::money($allocated) . ').', 422, ['allocated_cents' => $allocated]);
            }
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_sources SET provider_id = ?, kind = ?, title = ?, contract_ref = ?, budget_cents = ?, valid_from = ?, valid_to = ?, activity_kind_ids = ?, account_prefixes = ?, legacy_source_key = ?,
                row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')->execute([$data['provider_id'], $data['kind'], $data['title'], $data['contract_ref'], $data['budget_cents'],
                $data['valid_from'], $data['valid_to'], json_encode($data['activity_kind_ids']), json_encode($data['account_prefixes']), $data['legacy_source_key'], $actor, $supplier, $sourceId]);
            $this->history($supplier, $sourceId, null, null, 'source_updated', self::sourceAudit($source), $data, $reason, $actor);
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    /** Zrušení zdroje jen bez aktivních přiřazení nákladů; výnosové řádky se uvolní. */
    public function cancel(int $supplier, int $period, int $sourceId, int $expectedVersion, string $reason, int $actor): array
    {
        $reason = self::reason($reason, true);
        $this->guard->transaction(function () use ($supplier, $period, $sourceId, $expectedVersion, $reason, $actor): void {
            $this->lockPeriod($supplier, $period);
            $source = $this->writableSource($supplier, $period, $sourceId, $expectedVersion);
            $q = $this->db->pdo()->prepare("SELECT COUNT(*) FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND status = 'active' LOCK IN SHARE MODE");
            $q->execute([$supplier, $sourceId]);
            $count = (int) $q->fetchColumn();
            if ($count > 0) throw new FundingWorkException('funding_work_source_has_allocations', 'Zdroj má přiřazené náklady. Nejdřív je odeberte.', 409, ['allocation_count' => $count]);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_incomes SET status = 'removed', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND source_id = ? AND status = 'active'")
                ->execute([$actor, $supplier, $sourceId]);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_sources SET status = 'cancelled', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?")
                ->execute([$actor, $supplier, $sourceId]);
            $this->history($supplier, $sourceId, null, null, 'source_cancelled', self::sourceAudit($source), null, $reason, $actor);
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    // ---------------------------------------------------------------- výnosy

    /** @param list<int> $lineIds */
    public function addIncomes(int $supplier, int $period, int $sourceId, array $lineIds, int $actor): array
    {
        $lineIds = self::ids($lineIds, 'Vyberte výnosové řádky.');
        $this->guard->transaction(function () use ($supplier, $period, $sourceId, $lineIds, $actor): void {
            $this->lockPeriod($supplier, $period);
            $source = $this->writableSource($supplier, $period, $sourceId, null);
            $this->attachIncomes($supplier, $source, $lineIds, $actor);
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function removeIncome(int $supplier, int $period, int $incomeId, int $expectedVersion, string $reason, int $actor): array
    {
        $reason = self::reason($reason, true);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $incomeId, $expectedVersion, $reason, $actor): int {
            $this->lockPeriod($supplier, $period);
            $income = $this->income($supplier, $incomeId);
            $this->writableSource($supplier, $period, $income['source_id'], null);
            $income = $this->income($supplier, $incomeId, true);
            self::assertVersion($income, $expectedVersion);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_incomes SET status = 'removed', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?")
                ->execute([$actor, $supplier, $incomeId]);
            $this->history($supplier, $income['source_id'], $incomeId, null, 'income_removed', self::lineAudit($income), null, $reason, $actor);
            return $income['source_id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function relinkIncome(int $supplier, int $period, int $incomeId, int $lineId, int $expectedVersion, int $actor): array
    {
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $incomeId, $lineId, $expectedVersion, $actor): int {
            $this->lockPeriod($supplier, $period);
            $income = $this->income($supplier, $incomeId);
            $source = $this->writableSource($supplier, $period, $income['source_id'], null);
            $this->ledger->lockEntries($supplier, [$income['entry_id'], ...$this->entryIdsOf($supplier, [$lineId])]);
            $income = $this->income($supplier, $incomeId, true);
            self::assertVersion($income, $expectedVersion);
            $line = $this->lineFor($supplier, $lineId);
            $this->assertRelinkTarget($income, $line);
            $this->assertIncomeLine($supplier, $source, $line, $incomeId);
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_incomes SET line_id = ?, linked_line_id = ?, entry_id = ?, account_id = ?, amount_cents = ?, entry_date = ?, row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$line['id'], $line['id'], $line['entry_id'], $line['account_id'], $line['amount_cents'], $line['date'], $actor, $supplier, $incomeId]);
            $this->history($supplier, $source['id'], $incomeId, null, 'income_relinked', self::lineAudit($income), self::lineSnapshot($line), null, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    // ---------------------------------------------------------------- náklady

    /**
     * Přiřazení více nákladových řádků najednou; buď projdou všechny, nebo žádný.
     * @param list<array{line_id:mixed,amount_cents:mixed,note?:mixed}> $items
     */
    public function allocate(int $supplier, int $period, int $sourceId, array $items, int $actor): array
    {
        if ($items === [] || count($items) > 500 || !array_is_list($items)) self::invalid('Vyberte 1 až 500 nákladových řádků.');
        $wanted = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['line_id', 'amount_cents', 'note']) !== []) self::invalid('Neplatná položka přiřazení.');
            $line = $item['line_id'] ?? null;
            if (!is_int($line) || $line <= 0) self::invalid('Neplatný řádek deníku.');
            if (isset($wanted[$line])) self::invalid('Řádek deníku je v požadavku vícekrát.');
            $wanted[$line] = ['amount_cents' => self::cents($item['amount_cents'] ?? null), 'note' => self::note($item['note'] ?? null)];
        }
        $this->guard->transaction(function () use ($supplier, $period, $sourceId, $wanted, $actor): void {
            $this->lockPeriod($supplier, $period);
            $source = $this->writableSource($supplier, $period, $sourceId, null);
            $lineIds = array_keys($wanted);
            $this->ledger->lockEntries($supplier, $this->entryIdsOf($supplier, $lineIds));
            $lines = $this->ledger->lines($supplier, $lineIds, true);
            $used = $this->ledger->allocatedOnLines($supplier, $lineIds, null, true);
            $budgetUsed = $this->allocatedOnSource($supplier, $sourceId, null);
            $q = $this->db->pdo()->prepare('SELECT active_line_id FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND active_line_id IN ('
                . implode(',', array_fill(0, count($lineIds), '?')) . ') LOCK IN SHARE MODE');
            $q->execute([$supplier, $sourceId, ...$lineIds]);
            $already = array_flip(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
            $total = 0;
            foreach ($wanted as $lineId => $item) {
                $line = $lines[$lineId] ?? throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404);
                $this->assertCostLine($source, $line);
                if (isset($already[$lineId])) throw new FundingWorkException('funding_work_line_already_allocated', 'Řádek je k tomuto zdroji už přiřazený. Upravte jeho částku.', 409);
                self::assertCapacity($line, $used[$lineId] ?? 0, $item['amount_cents']);
                $total += $item['amount_cents'];
            }
            self::assertBudget($source, $budgetUsed, $total);
            $insert = $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_allocations (supplier_id, source_id, line_id, linked_line_id, entry_id, account_id, line_amount_cents, entry_date, amount_cents, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($wanted as $lineId => $item) {
                $line = $lines[$lineId];
                $insert->execute([$supplier, $sourceId, $lineId, $lineId, $line['entry_id'], $line['account_id'], $line['amount_cents'], $line['date'], $item['amount_cents'], $item['note'], $actor]);
                $id = (int) $this->db->pdo()->lastInsertId();
                $this->history($supplier, $sourceId, null, $id, 'allocation_created', null, self::lineSnapshot($line) + $item, null, $actor);
            }
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function updateAllocation(int $supplier, int $period, int $allocationId, mixed $amount, mixed $note, int $expectedVersion, int $actor): array
    {
        $amount = self::cents($amount);
        $note = self::note($note);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $allocationId, $amount, $note, $expectedVersion, $actor): int {
            $this->lockPeriod($supplier, $period);
            $allocation = $this->allocation($supplier, $allocationId);
            $source = $this->writableSource($supplier, $period, $allocation['source_id'], null);
            $this->ledger->lockEntries($supplier, [$allocation['entry_id']]);
            $allocation = $this->allocation($supplier, $allocationId, true);
            self::assertVersion($allocation, $expectedVersion);
            if ($amount > $allocation['amount_cents']) {
                // Zvýšení musí projít kapacitou řádku i rozpočtem; snížení je vždy v pořádku.
                $line = $this->lineFor($supplier, (int) ($allocation['line_id'] ?? 0));
                $this->assertCostLine($source, $line);
                self::assertCapacity($line, $this->ledger->allocatedOnLines($supplier, [$line['id']], $allocationId, true)[$line['id']] ?? 0, $amount);
                self::assertBudget($source, $this->allocatedOnSource($supplier, $source['id'], $allocationId), $amount);
            }
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_allocations SET amount_cents = ?, note = ?, row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$amount, $note, $actor, $supplier, $allocationId]);
            $this->history($supplier, $source['id'], null, $allocationId, 'allocation_updated',
                ['amount_cents' => $allocation['amount_cents'], 'note' => $allocation['note']], ['amount_cents' => $amount, 'note' => $note], null, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function removeAllocation(int $supplier, int $period, int $allocationId, int $expectedVersion, string $reason, int $actor): array
    {
        $reason = self::reason($reason, true);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $allocationId, $expectedVersion, $reason, $actor): int {
            $this->lockPeriod($supplier, $period);
            $allocation = $this->allocation($supplier, $allocationId);
            $source = $this->writableSource($supplier, $period, $allocation['source_id'], null);
            $allocation = $this->allocation($supplier, $allocationId, true);
            self::assertVersion($allocation, $expectedVersion);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_allocations SET status = 'removed', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?")
                ->execute([$actor, $supplier, $allocationId]);
            $this->history($supplier, $source['id'], null, $allocationId, 'allocation_removed', self::lineAudit($allocation) + ['amount_cents' => $allocation['amount_cents']], null, $reason, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    /**
     * Navázání přiřazení na aktuální řádek po přeúčtování nebo změně řádku. Cíl je týž řádek
     * (potvrzení změny) nebo řádek téhož zápisu se stejným účtem a stranou; stornovaný zápis
     * se znovu nenavazuje, přiřazení se odebere.
     */
    public function relinkAllocation(int $supplier, int $period, int $allocationId, int $lineId, mixed $amount, int $expectedVersion, int $actor): array
    {
        $amount = $amount === null ? null : self::cents($amount);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $allocationId, $lineId, $amount, $expectedVersion, $actor): int {
            $this->lockPeriod($supplier, $period);
            $allocation = $this->allocation($supplier, $allocationId);
            $source = $this->writableSource($supplier, $period, $allocation['source_id'], null);
            $this->ledger->lockEntries($supplier, [$allocation['entry_id'], ...$this->entryIdsOf($supplier, [$lineId])]);
            $allocation = $this->allocation($supplier, $allocationId, true);
            self::assertVersion($allocation, $expectedVersion);
            $line = $this->lineFor($supplier, $lineId);
            $this->assertRelinkTarget($allocation, $line);
            $this->assertCostLine($source, $line);
            $amount ??= $allocation['amount_cents'];
            if ($lineId !== $allocation['linked_line_id']) {
                $q = $this->db->pdo()->prepare('SELECT 1 FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND active_line_id = ? LOCK IN SHARE MODE');
                $q->execute([$supplier, $source['id'], $lineId]);
                if ($q->fetchColumn() !== false) throw new FundingWorkException('funding_work_line_already_allocated', 'Řádek je k tomuto zdroji už přiřazený. Upravte jeho částku.', 409);
            }
            self::assertCapacity($line, $this->ledger->allocatedOnLines($supplier, [$lineId], $allocationId, true)[$lineId] ?? 0, $amount);
            self::assertBudget($source, $this->allocatedOnSource($supplier, $source['id'], $allocationId), $amount);
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_allocations SET line_id = ?, linked_line_id = ?, entry_id = ?, account_id = ?, line_amount_cents = ?, entry_date = ?, amount_cents = ?, row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$line['id'], $line['id'], $line['entry_id'], $line['account_id'], $line['amount_cents'], $line['date'], $amount, $actor, $supplier, $allocationId]);
            $this->history($supplier, $source['id'], null, $allocationId, 'allocation_relinked', self::lineAudit($allocation) + ['amount_cents' => $allocation['amount_cents']],
                self::lineSnapshot($line) + ['amount_cents' => $amount], null, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    // ---------------------------------------------------------------- kontroly

    private function attachIncomes(int $supplier, array $source, array $lineIds, int $actor): void
    {
        $this->ledger->lockEntries($supplier, $this->entryIdsOf($supplier, $lineIds));
        $lines = $this->ledger->lines($supplier, $lineIds, true);
        $insert = $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_incomes (supplier_id, source_id, line_id, linked_line_id, entry_id, account_id, amount_cents, entry_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lineIds as $lineId) {
            $line = $lines[$lineId] ?? throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404);
            $this->assertIncomeLine($supplier, $source, $line, null);
            $insert->execute([$supplier, $source['id'], $lineId, $lineId, $line['entry_id'], $line['account_id'], $line['amount_cents'], $line['date'], $actor]);
            $this->history($supplier, $source['id'], (int) $this->db->pdo()->lastInsertId(), null, 'income_added', null, self::lineSnapshot($line), null, $actor);
        }
    }

    private function assertIncomeLine(int $supplier, array $source, array $line, ?int $exceptIncome): void
    {
        $this->assertPostedLine($source, $line);
        if ($line['side'] !== 'credit' || !str_starts_with($line['account_code'], '6')) {
            throw new FundingWorkException('funding_work_line_ineligible', 'Výnos zdroje musí být řádek na straně Dal na účtu třídy 6.');
        }
        $q = $this->db->pdo()->prepare('SELECT source_id FROM nonprofit_funding_work_incomes WHERE supplier_id = ? AND active_line_id = ? AND id <> ? LOCK IN SHARE MODE');
        $q->execute([$supplier, $line['id'], $exceptIncome ?? 0]);
        if ($q->fetchColumn() !== false) throw new FundingWorkException('funding_work_income_used', 'Výnosový řádek už patří jinému zdroji.', 409);
    }

    private function assertCostLine(array $source, array $line): void
    {
        $this->assertPostedLine($source, $line);
        // Daň z příjmů (59x) není způsobilý náklad zdroje, shodně s čerpáním fondů a investičních zdrojů (CapitalLedger::cost).
        if ($line['side'] !== 'debit' || !str_starts_with($line['account_code'], '5') || str_starts_with($line['account_code'], '59')) {
            throw new FundingWorkException('funding_work_line_ineligible', 'Náklad zdroje musí být řádek na straně Má dáti na nákladovém účtu třídy 5.');
        }
        $problem = self::conditionProblem($source, $line);
        if ($problem !== null) throw new FundingWorkException('funding_work_line_ineligible', $problem);
    }

    private function assertPostedLine(array $source, array $line): void
    {
        if (!$line['posted']) throw new FundingWorkException('funding_work_line_ineligible', 'Řádek není zaúčtovaný.');
        if ($line['reversed'] || $line['is_reversal']) throw new FundingWorkException('funding_work_line_reversed', 'Stornovaný zápis ani storno nelze přiřadit.', 409);
        if ($line['amount_cents'] <= 0) throw new FundingWorkException('funding_work_line_ineligible', 'Řádek musí mít kladnou částku.');
        if ($line['period_id'] !== $source['period_id']) throw new FundingWorkException('funding_work_line_other_period', 'Řádek patří do jiného účetního období než zdroj.');
    }

    /** Podmínky zdroje pro náklad: datum, účet a druh činnosti. Null = splněno. */
    private static function conditionProblem(array $source, array $line): ?string
    {
        if ($line['date'] < $source['valid_from'] || $line['date'] > $source['valid_to']) return 'Datum nákladu je mimo období způsobilosti zdroje.';
        if ($source['account_prefixes'] !== []) {
            $match = false;
            foreach ($source['account_prefixes'] as $prefix) if (str_starts_with($line['account_code'], $prefix)) $match = true;
            if (!$match) return 'Účet nákladu nepatří mezi způsobilé účty zdroje.';
        }
        if ($source['activity_kind_ids'] !== [] && ($line['activity_split'] || !in_array($line['activity_kind_id'], $source['activity_kind_ids'], true))) {
            return 'Druh činnosti nákladu neodpovídá zdroji.';
        }
        return null;
    }

    private static function assertCapacity(array $line, int $usedByOthers, int $amount): void
    {
        $free = $line['amount_cents'] - $usedByOthers;
        if ($amount > $free) {
            throw new FundingWorkException('funding_work_capacity_exceeded', 'Částka převyšuje nepřiřazenou část řádku. Volné zbývá ' . self::money(max(0, $free)) . '.', 422, ['capacity_cents' => max(0, $free)]);
        }
    }

    private static function assertBudget(array $source, int $allocated, int $amount): void
    {
        $free = $source['budget_cents'] - $allocated;
        if ($amount > $free) {
            throw new FundingWorkException('funding_work_budget_exceeded', 'Přiřazení překračuje rozpočet zdroje. Zbývá ' . self::money(max(0, $free)) . '.', 422, ['remaining_cents' => max(0, $free)]);
        }
    }

    private static function assertVersion(array $row, int $expected): void
    {
        if ($row['row_version'] !== $expected) throw new FundingWorkException('funding_work_version_conflict', 'Záznam mezitím změnil jiný uživatel. Načtěte aktuální stav.', 409);
    }

    /** Cíl navázání: týž řádek, nebo řádek téhož zápisu se stejným účtem a stranou. */
    private function assertRelinkTarget(array $link, array $line): void
    {
        if ($line['reversed'] || $line['is_reversal']) throw new FundingWorkException('funding_work_line_reversed', 'Stornovaný zápis nelze znovu navázat. Vazbu odeberte.', 409);
        if ($line['id'] === $link['linked_line_id'] && $link['line_id'] !== null) return;
        if ($line['entry_id'] !== $link['entry_id'] || $line['account_id'] !== $link['account_id']) {
            throw new FundingWorkException('funding_work_relink_invalid', 'Znovu navázat lze jen na řádek téhož zápisu se stejným účtem.');
        }
    }

    // ---------------------------------------------------------------- stav vazeb

    /** @param list<int> $sourceIds @return array<int,list<array<string,mixed>>> */
    private function incomes(int $supplier, array $sourceIds): array
    {
        if ($sourceIds === []) return [];
        $q = $this->db->pdo()->prepare("SELECT i.*, a.account_code FROM nonprofit_funding_work_incomes i LEFT JOIN chart_of_accounts a ON a.supplier_id = i.supplier_id AND a.id = i.account_id
            WHERE i.supplier_id = ? AND i.status = 'active' AND i.source_id IN (" . implode(',', array_fill(0, count($sourceIds), '?')) . ') ORDER BY i.entry_date, i.id');
        $q->execute([$supplier, ...$sourceIds]);
        $rows = array_map(self::linkRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
        $current = $this->ledger->lines($supplier, array_values(array_filter(array_map(static fn(array $r) => $r['line_id'], $rows))));
        $out = [];
        foreach ($rows as $row) {
            $line = $row['line_id'] === null ? null : ($current[$row['line_id']] ?? null);
            $row['state'] = self::linkState($row, $line, $row['amount_cents'], null);
            $row['line'] = $line;
            $out[$row['source_id']][] = $row;
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $sources @return array<int,list<array<string,mixed>>> */
    private function allocations(int $supplier, array $sources): array
    {
        if ($sources === []) return [];
        $ids = array_keys($sources);
        $q = $this->db->pdo()->prepare("SELECT x.*, a.account_code FROM nonprofit_funding_work_allocations x LEFT JOIN chart_of_accounts a ON a.supplier_id = x.supplier_id AND a.id = x.account_id
            WHERE x.supplier_id = ? AND x.status = 'active' AND x.source_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY x.entry_date, x.id');
        $q->execute([$supplier, ...$ids]);
        $rows = array_map(self::linkRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
        $lineIds = array_values(array_filter(array_map(static fn(array $r) => $r['line_id'], $rows)));
        $current = $this->ledger->lines($supplier, $lineIds);
        $used = $this->ledger->allocatedOnLines($supplier, $lineIds);
        $out = [];
        foreach ($rows as $row) {
            $line = $row['line_id'] === null ? null : ($current[$row['line_id']] ?? null);
            $state = self::linkState($row, $line, $row['line_amount_cents'], $sources[$row['source_id']]);
            if ($state === self::STATE_OK && $line !== null && ($used[$line['id']] ?? 0) > $line['amount_cents']) $state = self::STATE_CHANGED;
            $row['state'] = $state;
            $row['line'] = $line;
            $out[$row['source_id']][] = $row;
        }
        return $out;
    }

    /** Stav vazby podle snímku: odstraněný řádek, storno, změna řádku nebo podmínek zdroje, jinak v pořádku. */
    private static function linkState(array $row, ?array $line, int $snapshotAmount, ?array $source): string
    {
        if ($line === null) return self::STATE_REMOVED;
        if ($line['reversed'] || $line['is_reversal']) return self::STATE_REVERSED;
        if (!$line['posted'] || $line['amount_cents'] !== $snapshotAmount || $line['account_id'] !== $row['account_id'] || $line['date'] !== $row['entry_date']
            || $line['entry_id'] !== $row['entry_id'] || $line['period_id'] !== ($source['period_id'] ?? $line['period_id'])) return self::STATE_CHANGED;
        if ($source !== null && self::conditionProblem($source, $line) !== null) return self::STATE_CHANGED;
        return self::STATE_OK;
    }

    private static function totals(array $source, array $incomes, array $allocations): array
    {
        $received = 0; $incomeReview = 0; $allocated = 0; $documented = 0; $review = 0; $reviewCents = 0;
        foreach ($incomes as $income) {
            if ($income['state'] === self::STATE_OK) $received += $income['amount_cents'];
            else $incomeReview++;
        }
        foreach ($allocations as $allocation) {
            $allocated += $allocation['amount_cents'];
            if ($allocation['state'] === self::STATE_OK) $documented += $allocation['amount_cents'];
            else { $review++; $reviewCents += $allocation['amount_cents']; }
        }
        return ['budget_cents' => $source['budget_cents'], 'received_cents' => $received, 'allocated_cents' => $allocated, 'documented_cents' => $documented,
            'remaining_cents' => $source['budget_cents'] - $allocated, 'review_count' => $review + $incomeReview, 'review_cents' => $reviewCents,
            'income_count' => count($incomes), 'allocation_count' => count($allocations)];
    }

    /** Pro vazby ke kontrole nabídne řádky téhož zápisu se stejným účtem (jedním dotazem pro všechny zápisy). */
    private function attachRelinkCandidates(int $supplier, array $source, array &$incomes, array &$allocations): void
    {
        $entryIds = [];
        foreach ([...$incomes, ...$allocations] as $row) if ($row['state'] === self::STATE_REMOVED || $row['state'] === self::STATE_CHANGED) $entryIds[] = $row['entry_id'];
        $byEntry = $this->ledger->entryLines($supplier, $entryIds);
        $pick = static function (array $row) use ($byEntry): array {
            if ($row['state'] !== self::STATE_REMOVED && $row['state'] !== self::STATE_CHANGED) return [];
            return array_values(array_filter($byEntry[$row['entry_id']] ?? [], static fn(array $line) => $line['account_id'] === $row['account_id']
                && $line['posted'] && !$line['reversed'] && !$line['is_reversal'] && $line['amount_cents'] > 0));
        };
        foreach ($incomes as &$income) $income['relink_candidates'] = $pick($income);
        unset($income);
        foreach ($allocations as &$allocation) $allocation['relink_candidates'] = $pick($allocation);
        unset($allocation);
    }

    /** Výnos, který je i členem potvrzeného zdroje (J6), nese jeho klíč; jde jen o upozornění. */
    private function attachConfirmedLinks(int $supplier, array &$incomes): void
    {
        $members = [];
        foreach ($this->confirmed->latest($supplier) as $key => $row) foreach ($row['policy']['source_members'] as $member) $members[$member] = (string) $key;
        foreach ($incomes as &$income) $income['confirmed_source_key'] = $members['line:' . $income['linked_line_id']] ?? null;
        unset($income);
    }

    /** Stav úhrady přijaté faktury, ze které náklad pochází (jen informace, SSOT PurchaseSettledExpr). */
    private function attachPaymentState(int $supplier, array &$allocations): void
    {
        $ids = [];
        foreach ($allocations as $row) if (($row['line']['source_type'] ?? null) === 'purchase_invoice' && $row['line']['source_id'] !== null) $ids[] = $row['line']['source_id'];
        $states = [];
        if ($ids !== []) {
            $ids = array_values(array_unique($ids));
            $q = $this->db->pdo()->prepare('SELECT pi.id, pi.amount_to_pay, ' . PurchaseSettledExpr::settled('pi') . ' AS settled FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute([$supplier, ...$ids]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $toPay = (float) $r['amount_to_pay']; $settled = (float) $r['settled'];
                $states[(int) $r['id']] = $settled >= $toPay - 0.005 ? 'paid' : ($settled > 0.005 ? 'partial' : 'unpaid');
            }
        }
        foreach ($allocations as &$allocation) {
            $line = $allocation['line'];
            $allocation['payment_state'] = ($line['source_type'] ?? null) === 'purchase_invoice' ? ($states[$line['source_id']] ?? null) : null;
        }
        unset($allocation);
    }

    // ---------------------------------------------------------------- data

    private function period(int $supplier, int $period, bool $lock): array
    {
        $q = $this->db->pdo()->prepare('SELECT id, starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $period]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new FundingWorkException('funding_work_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        return ['id' => (int) $row['id'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'status' => $row['status']];
    }

    /** Uzavřené období evidenci nemění; zámek podaného DPH (locked_until) ji neblokuje, nejde o účetní zápis. */
    private function lockPeriod(int $supplier, int $period): void
    {
        $row = $this->period($supplier, $period, true);
        if (AccountingPeriodStatus::isClosed($row['status'])) throw new FundingWorkException('funding_work_period_closed', 'V uzavřeném účetním období nelze měnit evidenci čerpání.', 409);
    }

    /** @return array<int,array<string,mixed>> */
    private function sourceRows(int $supplier, string $where, array $params, bool $lock = false): array
    {
        $q = $this->db->pdo()->prepare('SELECT s.*, c.company_name, c.first_name, c.last_name FROM nonprofit_funding_work_sources s
            LEFT JOIN clients c ON c.supplier_id = s.supplier_id AND c.id = s.provider_id WHERE s.supplier_id = ? AND ' . $where . ' ORDER BY s.status, s.title, s.id' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, ...$params]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'period_id' => (int) $r['period_id'], 'provider_id' => (int) $r['provider_id'], 'provider_label' => self::partyLabel($r),
                'kind' => $r['kind'], 'title' => $r['title'], 'contract_ref' => $r['contract_ref'], 'budget_cents' => (int) $r['budget_cents'],
                'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'],
                'activity_kind_ids' => array_map('intval', json_decode($r['activity_kind_ids'], true) ?: []),
                'account_prefixes' => array_map('strval', json_decode($r['account_prefixes'], true) ?: []),
                'legacy_source_key' => $r['legacy_source_key'], 'status' => $r['status'], 'row_version' => (int) $r['row_version'],
                'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
            ];
        }
        return $out;
    }

    private function source(int $supplier, int $period, int $id, bool $lock): array
    {
        return $this->sourceRows($supplier, 's.period_id = ? AND s.id = ?', [$period, $id], $lock)[$id]
            ?? throw new FundingWorkException('funding_work_not_found', 'Zdroj nebyl nalezen.', 404);
    }

    private function writableSource(int $supplier, int $period, int $id, ?int $expectedVersion): array
    {
        $source = $this->source($supplier, $period, $id, true);
        if ($source['status'] !== 'active') throw new FundingWorkException('funding_work_source_cancelled', 'Zrušený zdroj nelze měnit.', 409);
        if ($expectedVersion !== null) self::assertVersion($source, $expectedVersion);
        return $source;
    }

    private function income(int $supplier, int $id, bool $lock = false): array
    {
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_funding_work_incomes WHERE supplier_id = ? AND id = ? AND status = 'active'" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new FundingWorkException('funding_work_not_found', 'Výnos zdroje nebyl nalezen.', 404);
        return self::linkRow($row);
    }

    private function allocation(int $supplier, int $id, bool $lock = false): array
    {
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND id = ? AND status = 'active'" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new FundingWorkException('funding_work_not_found', 'Přiřazení nebylo nalezeno.', 404);
        return self::linkRow($row);
    }

    private function lineFor(int $supplier, int $lineId): array
    {
        return $this->ledger->lines($supplier, [$lineId], true)[$lineId]
            ?? throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404);
    }

    /** @param list<int> $lineIds @return list<int> */
    private function entryIdsOf(int $supplier, array $lineIds): array
    {
        $lineIds = array_values(array_filter($lineIds, static fn(int $id) => $id > 0));
        if ($lineIds === []) return [];
        $q = $this->db->pdo()->prepare('SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($lineIds), '?')) . ')');
        $q->execute([$supplier, ...$lineIds]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    private function allocatedOnSource(int $supplier, int $sourceId, ?int $exceptAllocation): int
    {
        $q = $this->db->pdo()->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND status = 'active' AND id <> ? LOCK IN SHARE MODE");
        $q->execute([$supplier, $sourceId, $exceptAllocation ?? 0]);
        return (int) $q->fetchColumn();
    }

    private function assertReferences(int $supplier, array $data): void
    {
        $q = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE supplier_id = ? AND id = ?');
        $q->execute([$supplier, $data['provider_id']]);
        if ($q->fetchColumn() === false) self::invalid('Poskytovatel nebyl nalezen.');
        if ($data['activity_kind_ids'] !== []) {
            $q = $this->db->pdo()->prepare("SELECT COUNT(*) FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id AND t.supplier_id = v.supplier_id
                WHERE v.supplier_id = ? AND t.system_key = 'activity_kind' AND v.id IN (" . implode(',', array_fill(0, count($data['activity_kind_ids']), '?')) . ')');
            $q->execute([$supplier, ...$data['activity_kind_ids']]);
            if ((int) $q->fetchColumn() !== count($data['activity_kind_ids'])) self::invalid('Druh činnosti nepatří této firmě.');
        }
        if ($data['legacy_source_key'] !== null && !array_key_exists($data['legacy_source_key'], $this->confirmed->latest($supplier))) self::invalid('Potvrzený zdroj nebyl nalezen.');
    }

    private function history(int $supplier, int $source, ?int $income, ?int $allocation, string $action, ?array $before, ?array $after, ?string $reason, int $actor): void
    {
        $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_history (supplier_id, source_id, income_id, allocation_id, action, before_json, after_json, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplier, $source, $income, $allocation, $action, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $reason, $actor]);
        $this->activity->log('nonprofit_funding_work.' . $action, $actor, 'nonprofit_funding_work_source', $source,
            array_filter(['income_id' => $income, 'allocation_id' => $allocation, 'before' => $before, 'after' => $after, 'reason' => $reason], static fn($v) => $v !== null), null, null, $supplier);
    }

    // ---------------------------------------------------------------- vstupy

    /** @return array<string,mixed> */
    private function normalize(int $supplier, array $input, bool $create): array
    {
        $fields = ['provider_id', 'kind', 'title', 'contract_ref', 'budget_cents', 'valid_from', 'valid_to', 'activity_kind_ids', 'account_prefixes', 'legacy_source_key'];
        $allowed = $create ? [...$fields, 'income_line_ids'] : $fields;
        if (array_diff(array_keys($input), $allowed) !== []) self::invalid('Neznámé pole zdroje.');
        $provider = $input['provider_id'] ?? null;
        if (!is_int($provider) || $provider <= 0) self::invalid('Vyberte poskytovatele.');
        if (!in_array($input['kind'] ?? null, self::KINDS, true)) self::invalid('Neznámý druh zdroje.');
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        if ($title === '' || mb_strlen($title) > 255) self::invalid('Název zdroje je povinný (nejvýše 255 znaků).');
        $contract = $input['contract_ref'] ?? null;
        if ($contract !== null && !is_string($contract)) self::invalid('Číslo smlouvy musí být text.');
        $contract = $contract === null || trim($contract) === '' ? null : trim($contract);
        if ($contract !== null && mb_strlen($contract) > 100) self::invalid('Číslo smlouvy je příliš dlouhé.');
        $budget = self::cents($input['budget_cents'] ?? null);
        foreach (['valid_from', 'valid_to'] as $field) {
            if (!is_string($input[$field] ?? null) || !IsoDate::isValid($input[$field])) self::invalid('Neplatné datum způsobilosti.');
        }
        if ($input['valid_from'] > $input['valid_to']) self::invalid('Období způsobilosti je obrácené.');
        $activities = $input['activity_kind_ids'] ?? [];
        if (!is_array($activities) || !array_is_list($activities) || count($activities) > 200) self::invalid('Neplatný seznam druhů činnosti.');
        foreach ($activities as $id) if (!is_int($id) || $id <= 0) self::invalid('Neplatný druh činnosti.');
        $prefixes = $input['account_prefixes'] ?? [];
        if (!is_array($prefixes) || !array_is_list($prefixes) || count($prefixes) > 200) self::invalid('Neplatný seznam účtů.');
        foreach ($prefixes as $code) if (!is_string($code) || !preg_match('/^5[0-8][0-9]?(?:[.0-9A-Za-z]{0,7})$/D', $code)) self::invalid('Způsobilé účty musí být nákladové účty třídy 5.');
        $legacy = $input['legacy_source_key'] ?? null;
        if ($legacy !== null && (!is_string($legacy) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $legacy))) self::invalid('Neplatný odkaz na potvrzený zdroj.');
        $activities = array_values(array_unique($activities));
        $prefixes = array_values(array_unique($prefixes));
        sort($activities, SORT_NUMERIC);
        sort($prefixes, SORT_STRING);
        $out = ['provider_id' => $provider, 'kind' => $input['kind'], 'title' => $title, 'contract_ref' => $contract, 'budget_cents' => $budget,
            'valid_from' => $input['valid_from'], 'valid_to' => $input['valid_to'], 'activity_kind_ids' => $activities, 'account_prefixes' => $prefixes, 'legacy_source_key' => $legacy];
        if ($create) $out['income_line_ids'] = isset($input['income_line_ids']) ? self::ids($input['income_line_ids'], 'Neplatné výnosové řádky.', true) : [];
        return $out;
    }

    /** @return list<int> */
    private static function ids(mixed $ids, string $message, bool $allowEmpty = false): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500 || (!$allowEmpty && $ids === [])) self::invalid($message);
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) self::invalid($message);
        if (count(array_unique($ids)) !== count($ids)) self::invalid($message);
        return $ids;
    }

    private static function cents(mixed $value): int
    {
        if (!is_int($value) || $value <= 0 || $value > self::MAX_CENTS) self::invalid('Částka musí být kladná, v haléřích.');
        return $value;
    }

    private static function note(mixed $note): ?string
    {
        if ($note === null) return null;
        if (!is_string($note)) self::invalid('Poznámka musí být text.');
        $note = trim($note);
        if (mb_strlen($note) > 500) self::invalid('Poznámka je příliš dlouhá (nejvýše 500 znaků).');
        return $note === '' ? null : $note;
    }

    private static function reason(string $reason, bool $required): ?string
    {
        $reason = trim($reason);
        if ($required && $reason === '') self::invalid('Uveďte důvod.');
        if (mb_strlen($reason) > 1000) self::invalid('Důvod je příliš dlouhý.');
        return $reason === '' ? null : $reason;
    }

    private static function linkRow(array $r): array
    {
        $out = [
            'id' => (int) $r['id'], 'source_id' => (int) $r['source_id'], 'line_id' => $r['line_id'] === null ? null : (int) $r['line_id'],
            'linked_line_id' => (int) $r['linked_line_id'], 'entry_id' => (int) $r['entry_id'], 'account_id' => (int) $r['account_id'],
            'account_code' => $r['account_code'] ?? null, 'entry_date' => $r['entry_date'], 'row_version' => (int) $r['row_version'],
            'amount_cents' => (int) $r['amount_cents'],
        ];
        if (array_key_exists('line_amount_cents', $r)) {
            $out['line_amount_cents'] = (int) $r['line_amount_cents'];
            $out['note'] = $r['note'];
        }
        return $out;
    }

    private static function lineSnapshot(array $line): array
    {
        return ['line_id' => $line['id'], 'entry_id' => $line['entry_id'], 'account_code' => $line['account_code'], 'line_amount_cents' => $line['amount_cents'],
            'date' => $line['date'], 'document_no' => $line['document_no']];
    }

    private static function lineAudit(array $link): array
    {
        return ['line_id' => $link['linked_line_id'], 'entry_id' => $link['entry_id'], 'line_amount_cents' => $link['line_amount_cents'] ?? $link['amount_cents'], 'date' => $link['entry_date']];
    }

    private static function sourceAudit(array $source): array
    {
        return array_intersect_key($source, array_flip(['provider_id', 'kind', 'title', 'contract_ref', 'budget_cents', 'valid_from', 'valid_to', 'activity_kind_ids', 'account_prefixes', 'legacy_source_key', 'status']));
    }

    private static function partyLabel(array $r): string
    {
        return (string) ($r['company_name'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')));
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', "\u{00a0}") . "\u{00a0}Kč";
    }

    private static function invalid(string $message): never
    {
        throw new FundingWorkException('funding_work_invalid', $message);
    }
}
