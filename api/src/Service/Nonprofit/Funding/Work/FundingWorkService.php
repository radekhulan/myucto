<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding\Work;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Pracovní evidence čerpání dotací a účelových darů (#403): průběžné přiřazení výnosových
 * a nákladových řádků deníku ke zdroji. Je to pracovní podklad vedle potvrzených zdrojů
 * a J6 ({@see FundingService}); daňový výpočet z něj nečte.
 *
 * Vazba na řádek účetnictví neblokuje. Přeúčtování řádek smaže (vazba ztratí line_id),
 * storno označí zápis `reversed_by`, dobropis přijaté faktury přibude; vše se při čtení ukáže
 * jako vazba ke kontrole podle snímku pořízeného při přiřazení ({@see self::linksSql()}) a opraví
 * se „Navázat znovu“ (týž zápis nebo týž doklad) nebo odebráním.
 *
 * Zápisy běží v jedné transakci s pořadím zámků: období → zdroj → zápisy deníku (podle id),
 * stejně jako zaúčtování (období před zápisy). Zámek období serializuje zápisy evidence v období;
 * čtení uvnitř zápisu jsou zamykací, aby viděla potvrzená data souběžného uživatele.
 */
final class FundingWorkService
{
    public const STATE_OK = 'ok';
    public const STATE_CHANGED = 'changed';
    public const STATE_REMOVED = 'removed';
    public const STATE_REVERSED = 'reversed';
    public const PAGE = 100;
    private const KINDS = ['operating_grant', 'investment_grant', 'gift'];
    private const MAX_CENTS = 999999999999999;
    private const PROBLEMS = [
        'not_posted' => ['funding_work_line_ineligible', 'Řádek není zaúčtovaný.', 422],
        'reversed' => ['funding_work_line_reversed', 'Stornovaný zápis ani storno nelze přiřadit.', 409],
        'other_period' => ['funding_work_line_other_period', 'Řádek patří do jiného účetního období než zdroj.', 422],
        'amount' => ['funding_work_line_ineligible', 'Řádek musí mít kladnou částku.', 422],
        'not_cost' => ['funding_work_line_ineligible', 'Náklad zdroje musí být řádek na straně Má dáti na nákladovém účtu třídy 5 (mimo daň z příjmů a uzávěrkové zápisy).', 422],
        'not_income' => ['funding_work_line_ineligible', 'Výnos zdroje musí být řádek na straně Dal na výnosovém účtu třídy 6.', 422],
        'date' => ['funding_work_line_ineligible', 'Datum nákladu je mimo období způsobilosti zdroje.', 422],
        'account' => ['funding_work_line_ineligible', 'Účet nákladu nepatří mezi způsobilé účty zdroje.', 422],
        'activity' => ['funding_work_line_ineligible', 'Druh činnosti nákladu neodpovídá zdroji.', 422],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly FundingWorkLedger $ledger,
        private readonly DimensionPeriodGuard $guard,
        private readonly FundingRepository $confirmed,
        private readonly ActivityLogger $activity,
        // Čerpání nákladů v potvrzeném J6 jen jako upozornění (rozhodnutí uživatele 23. 11.).
        private readonly FundingService $confirmedUse,
    ) {}

    // ---------------------------------------------------------------- čtení

    /** Přehled zdrojů období; souhrny počítá databáze, řádky deníku se nenačítají. */
    public function overview(int $supplier, int $period): array
    {
        $periodRow = $this->period($supplier, $period, false);
        $sources = $this->sourceRows($supplier, 's.period_id = ?', [$period]);
        $totals = $this->totals($supplier, $sources);
        $out = [];
        foreach ($sources as $id => $source) $out[] = $source + ['totals' => $totals[$id]];
        return ['period' => $periodRow, 'editable' => !AccountingPeriodStatus::isClosed($periodRow['status']), 'sources' => $out];
    }

    /** Detail zdroje: souhrn, výnosy, stránka přiřazených nákladů se stavem, nabídka k opětovnému navázání a historie. */
    public function detail(int $supplier, int $period, int $sourceId, int $page = 1): array
    {
        $periodRow = $this->period($supplier, $period, false);
        $source = $this->source($supplier, $period, $sourceId, false);
        $incomes = $this->links($supplier, 'income', [$sourceId], null);
        $allocations = $this->links($supplier, 'cost', [$sourceId], max(1, $page));
        $more = count($allocations) > self::PAGE;
        $allocations = array_slice($allocations, 0, self::PAGE);
        $this->attachRelinkCandidates($supplier, $period, $incomes, $allocations);
        $this->attachConfirmedLinks($supplier, $incomes, $allocations);
        $this->attachPaymentState($supplier, $allocations);
        $history = $this->db->pdo()->prepare('SELECT h.id, h.income_id, h.allocation_id, h.action, h.before_json, h.after_json, h.reason, h.created_at, u.name AS actor_name
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
            'totals' => $this->totals($supplier, [$sourceId => $source])[$sourceId],
            'incomes' => $incomes,
            'allocations' => $allocations,
            'allocations_page' => max(1, $page),
            'allocations_more' => $more,
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

    public function incomeCandidates(int $supplier, int $period, int $sourceId, string $search, int $page): array
    {
        $source = $this->source($supplier, $period, $sourceId, false);
        if (mb_strlen($search) > 100) self::invalid('Hledaný text je příliš dlouhý.');
        return $this->ledger->candidates($supplier, $source, $this->period($supplier, $period, false), 'income', $search, max(1, $page));
    }

    public function costCandidates(int $supplier, int $period, int $sourceId, string $search, int $page): array
    {
        $source = $this->source($supplier, $period, $sourceId, false);
        if (mb_strlen($search) > 100) self::invalid('Hledaný text je příliš dlouhý.');
        $result = $this->ledger->candidates($supplier, $source, $this->period($supplier, $period, false), 'cost', $search, max(1, $page));
        $this->ledger->attachCreditNotes($supplier, $result['rows']);
        $uses = $this->confirmedCostUses($supplier);
        foreach ($result['rows'] as &$row) $row['confirmed_source_key'] = $uses[$row['id']] ?? null;
        unset($row);
        return $result;
    }

    /** Rozpad nákladového řádku podle zdrojů (kolik z řádku nese který zdroj). */
    public function costLineAllocations(int $supplier, int $period, int $lineId): array
    {
        $this->period($supplier, $period, false);
        $q = $this->db->pdo()->prepare('SELECT ' . FundingWorkLedger::LINE_COLUMNS . ' FROM journal_entry_lines l ' . FundingWorkLedger::LINE_JOINS . ' WHERE l.supplier_id = ? AND l.id = ?');
        $q->execute([$supplier, $lineId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $line = $row ? FundingWorkLedger::line($row) : null;
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
        $data = $this->normalize($input, true);
        $incomeLines = $data['income_line_ids'];
        unset($data['income_line_ids']);
        $id = $this->guard->transaction(function () use ($supplier, $period, $data, $incomeLines, $actor): int {
            $this->lockPeriod($supplier, $period);
            $data = $this->assertReferences($supplier, $data, null);
            $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_sources (supplier_id, period_id, provider_id, kind, title, contract_ref, budget_cents, valid_from, valid_to, activity_kind_ids, account_prefixes, legacy_source_key, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$supplier, $period, $data['provider_id'], $data['kind'], $data['title'], $data['contract_ref'], $data['budget_cents'],
                $data['valid_from'], $data['valid_to'], json_encode($data['activity_kind_ids']), json_encode($data['account_prefixes']), $data['legacy_source_key'], $actor]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $this->history($supplier, $id, [['source_created', null, null, null, $data]], null, $actor);
            if ($incomeLines !== []) $this->attachIncomes($supplier, $this->source($supplier, $period, $id, true), $incomeLines, $actor);
            return $id;
        });
        return $this->detail($supplier, $period, $id);
    }

    /** @param array<string,mixed> $input */
    public function update(int $supplier, int $period, int $sourceId, array $input, int $expectedVersion, string $reason, int $actor): array
    {
        $data = $this->normalize($input, false);
        $reason = self::reason($reason, false);
        $this->guard->transaction(function () use ($supplier, $period, $sourceId, $data, $expectedVersion, $reason, $actor): void {
            $this->lockPeriod($supplier, $period);
            $source = $this->writableSource($supplier, $period, $sourceId, $expectedVersion);
            $data = $this->assertReferences($supplier, $data, $source);
            $allocated = $this->allocatedOnSource($supplier, $sourceId, null);
            if ($data['budget_cents'] < $allocated) {
                throw new FundingWorkException('funding_work_budget_below_allocated', 'Rozpočet nesmí být nižší než již přiřazené náklady (' . self::money($allocated) . ').', 422, ['allocated_cents' => $allocated]);
            }
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_sources SET provider_id = ?, kind = ?, title = ?, contract_ref = ?, budget_cents = ?, valid_from = ?, valid_to = ?, activity_kind_ids = ?, account_prefixes = ?, legacy_source_key = ?,
                row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')->execute([$data['provider_id'], $data['kind'], $data['title'], $data['contract_ref'], $data['budget_cents'],
                $data['valid_from'], $data['valid_to'], json_encode($data['activity_kind_ids']), json_encode($data['account_prefixes']), $data['legacy_source_key'], $actor, $supplier, $sourceId]);
            $this->history($supplier, $sourceId, [['source_updated', null, null, self::sourceAudit($source), $data]], $reason, $actor);
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    /** Zrušení zdroje jen bez aktivních přiřazení nákladů; výnosové řádky se uvolní a každý zapíše do historie. */
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
            $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_funding_work_incomes WHERE supplier_id = ? AND source_id = ? AND status = 'active' ORDER BY id FOR UPDATE");
            $q->execute([$supplier, $sourceId]);
            $released = array_map(self::linkRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_incomes SET status = 'removed', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND source_id = ? AND status = 'active'")
                ->execute([$actor, $supplier, $sourceId]);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_sources SET status = 'cancelled', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?")
                ->execute([$actor, $supplier, $sourceId]);
            $events = array_map(static fn(array $i) => ['income_removed', $i['id'], null, self::lineAudit($i), null], $released);
            $events[] = ['source_cancelled', null, null, self::sourceAudit($source), null];
            $this->history($supplier, $sourceId, $events, $reason, $actor);
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
            $income = $this->link($supplier, 'income', $incomeId, false);
            $this->writableSource($supplier, $period, $income['source_id'], null);
            $income = $this->link($supplier, 'income', $incomeId, true);
            self::assertVersion($income, $expectedVersion);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_incomes SET status = 'removed', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?")
                ->execute([$actor, $supplier, $incomeId]);
            $this->history($supplier, $income['source_id'], [['income_removed', $incomeId, null, self::lineAudit($income), null]], $reason, $actor);
            return $income['source_id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function relinkIncome(int $supplier, int $period, int $incomeId, int $lineId, int $expectedVersion, int $actor): array
    {
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $incomeId, $lineId, $expectedVersion, $actor): int {
            $this->lockPeriod($supplier, $period);
            $income = $this->link($supplier, 'income', $incomeId, false);
            $source = $this->writableSource($supplier, $period, $income['source_id'], null);
            $this->ledger->lockEntries($supplier, [$income['entry_id'], ...$this->ledger->entryIdsOf($supplier, [$lineId])]);
            $income = $this->link($supplier, 'income', $incomeId, true);
            self::assertVersion($income, $expectedVersion);
            $line = $this->lineFor($supplier, $source['id'], 'income', $lineId);
            self::assertRelinkTarget($income, $line);
            self::assertEligible($line);
            $this->assertIncomeFree($supplier, [$lineId], $incomeId);
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_incomes SET line_id = ?, linked_line_id = ?, entry_id = ?, entry_source_type = ?, entry_source_id = ?, account_id = ?, amount_cents = ?, entry_date = ?, row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$line['id'], $line['id'], $line['entry_id'], $line['source_type'], $line['source_id'], $line['account_id'], $line['amount_cents'], $line['date'], $actor, $supplier, $incomeId]);
            $this->history($supplier, $source['id'], [['income_relinked', $incomeId, null, self::lineAudit($income), self::lineSnapshot($line)]], null, $actor);
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
            $this->ledger->lockEntries($supplier, $this->ledger->entryIdsOf($supplier, $lineIds));
            $lines = $this->ledger->linesFor($supplier, $sourceId, 'cost', $lineIds);
            $used = $this->ledger->allocatedOnLines($supplier, $lineIds);
            $q = $this->db->pdo()->prepare('SELECT active_line_id FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND active_line_id IN (' . self::marks($lineIds) . ') LOCK IN SHARE MODE');
            $q->execute([$supplier, $sourceId, ...$lineIds]);
            $already = array_flip(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
            $total = 0;
            foreach ($wanted as $lineId => $item) {
                $line = $lines[$lineId] ?? throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404);
                self::assertEligible($line);
                if (isset($already[$lineId])) throw new FundingWorkException('funding_work_line_already_allocated', 'Řádek je k tomuto zdroji už přiřazený. Upravte jeho částku.', 409);
                self::assertCapacity($line, $used[$lineId] ?? 0, $item['amount_cents']);
                $total += $item['amount_cents'];
            }
            self::assertBudget($source, $this->allocatedOnSource($supplier, $sourceId, null), $total);
            $values = [];
            $params = [];
            foreach ($wanted as $lineId => $item) {
                $line = $lines[$lineId];
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                // Dobropis zaúčtovaný už před přiřazením se počítá jako nepotvrzený (credit_notes_seen = 0), shodně
                // s J6 (NativeCostCoverage dá ke kontrole každý dobropis); potvrzení = Navázat znovu.
                array_push($params, $supplier, $sourceId, $lineId, $lineId, $line['entry_id'], $line['source_type'], $line['source_id'], $line['account_id'],
                    $line['amount_cents'], $line['date'], 0, $item['amount_cents'], $item['note'], $actor);
            }
            $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_allocations (supplier_id, source_id, line_id, linked_line_id, entry_id, entry_source_type, entry_source_id, account_id, line_amount_cents, entry_date, credit_notes_seen, amount_cents, note, created_by)
                VALUES ' . implode(', ', $values))->execute($params);
            $ids = $this->activeIds($supplier, 'cost', $sourceId, $lineIds);
            $this->history($supplier, $sourceId, array_map(static fn(int $lineId) => ['allocation_created', null, $ids[$lineId], null, self::lineSnapshot($lines[$lineId]) + $wanted[$lineId]], $lineIds), null, $actor);
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function updateAllocation(int $supplier, int $period, int $allocationId, mixed $amount, mixed $note, int $expectedVersion, int $actor): array
    {
        $amount = self::cents($amount);
        $note = self::note($note);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $allocationId, $amount, $note, $expectedVersion, $actor): int {
            $this->lockPeriod($supplier, $period);
            $allocation = $this->link($supplier, 'cost', $allocationId, false);
            $source = $this->writableSource($supplier, $period, $allocation['source_id'], null);
            $this->ledger->lockEntries($supplier, [$allocation['entry_id']]);
            $allocation = $this->link($supplier, 'cost', $allocationId, true);
            self::assertVersion($allocation, $expectedVersion);
            if ($amount > $allocation['amount_cents']) {
                // Zvýšení musí projít kapacitou řádku i rozpočtem; snížení je vždy v pořádku.
                if ($allocation['line_id'] === null) throw new FundingWorkException('funding_work_relink_required', 'Řádek deníku se změnil. Nejdřív vazbu navažte znovu.', 409);
                $line = $this->lineFor($supplier, $source['id'], 'cost', $allocation['line_id']);
                self::assertEligible($line);
                self::assertCapacity($line, $this->ledger->allocatedOnLines($supplier, [$line['id']], $allocationId)[$line['id']] ?? 0, $amount);
                self::assertBudget($source, $this->allocatedOnSource($supplier, $source['id'], $allocationId), $amount);
            }
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_allocations SET amount_cents = ?, note = ?, row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$amount, $note, $actor, $supplier, $allocationId]);
            $this->history($supplier, $source['id'], [['allocation_updated', null, $allocationId,
                ['amount_cents' => $allocation['amount_cents'], 'note' => $allocation['note']], ['amount_cents' => $amount, 'note' => $note]]], null, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    public function removeAllocation(int $supplier, int $period, int $allocationId, int $expectedVersion, string $reason, int $actor): array
    {
        $reason = self::reason($reason, true);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $allocationId, $expectedVersion, $reason, $actor): int {
            $this->lockPeriod($supplier, $period);
            $allocation = $this->link($supplier, 'cost', $allocationId, false);
            $source = $this->writableSource($supplier, $period, $allocation['source_id'], null);
            $allocation = $this->link($supplier, 'cost', $allocationId, true);
            self::assertVersion($allocation, $expectedVersion);
            $this->db->pdo()->prepare("UPDATE nonprofit_funding_work_allocations SET status = 'removed', row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?")
                ->execute([$actor, $supplier, $allocationId]);
            $this->history($supplier, $source['id'], [['allocation_removed', null, $allocationId, self::lineAudit($allocation) + ['amount_cents' => $allocation['amount_cents']], null]], $reason, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    /**
     * Navázání přiřazení na aktuální řádek po přeúčtování, změně řádku nebo dobropisu. Cíl je týž řádek
     * (potvrzení změny), řádek téhož zápisu, nebo řádek zápisu téhož dokladu, vždy se stejným účtem.
     * Stornovaný zápis se znovu nenavazuje, přiřazení se odebere.
     */
    public function relinkAllocation(int $supplier, int $period, int $allocationId, int $lineId, mixed $amount, int $expectedVersion, int $actor): array
    {
        $amount = $amount === null ? null : self::cents($amount);
        $sourceId = $this->guard->transaction(function () use ($supplier, $period, $allocationId, $lineId, $amount, $expectedVersion, $actor): int {
            $this->lockPeriod($supplier, $period);
            $allocation = $this->link($supplier, 'cost', $allocationId, false);
            $source = $this->writableSource($supplier, $period, $allocation['source_id'], null);
            $this->ledger->lockEntries($supplier, [$allocation['entry_id'], ...$this->ledger->entryIdsOf($supplier, [$lineId])]);
            $allocation = $this->link($supplier, 'cost', $allocationId, true);
            self::assertVersion($allocation, $expectedVersion);
            $line = $this->lineFor($supplier, $source['id'], 'cost', $lineId);
            self::assertRelinkTarget($allocation, $line);
            self::assertEligible($line);
            $amount ??= $allocation['amount_cents'];
            if ($lineId !== $allocation['linked_line_id']) {
                $q = $this->db->pdo()->prepare('SELECT 1 FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND active_line_id = ? LOCK IN SHARE MODE');
                $q->execute([$supplier, $source['id'], $lineId]);
                if ($q->fetchColumn() !== false) throw new FundingWorkException('funding_work_line_already_allocated', 'Řádek je k tomuto zdroji už přiřazený. Upravte jeho částku.', 409);
            }
            self::assertCapacity($line, $this->ledger->allocatedOnLines($supplier, [$lineId], $allocationId)[$lineId] ?? 0, $amount);
            self::assertBudget($source, $this->allocatedOnSource($supplier, $source['id'], $allocationId), $amount);
            $this->db->pdo()->prepare('UPDATE nonprofit_funding_work_allocations SET line_id = ?, linked_line_id = ?, entry_id = ?, entry_source_type = ?, entry_source_id = ?, account_id = ?, line_amount_cents = ?, entry_date = ?, credit_notes_seen = ?, amount_cents = ?,
                row_version = row_version + 1, updated_by = ?, updated_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$line['id'], $line['id'], $line['entry_id'], $line['source_type'], $line['source_id'], $line['account_id'], $line['amount_cents'], $line['date'], $line['credit_notes'], $amount, $actor, $supplier, $allocationId]);
            $this->history($supplier, $source['id'], [['allocation_relinked', null, $allocationId, self::lineAudit($allocation) + ['amount_cents' => $allocation['amount_cents']],
                self::lineSnapshot($line) + ['amount_cents' => $amount]]], null, $actor);
            return $source['id'];
        });
        return $this->detail($supplier, $period, $sourceId);
    }

    // ---------------------------------------------------------------- kontroly

    /** @param list<int> $lineIds */
    private function attachIncomes(int $supplier, array $source, array $lineIds, int $actor): void
    {
        $this->ledger->lockEntries($supplier, $this->ledger->entryIdsOf($supplier, $lineIds));
        $lines = $this->ledger->linesFor($supplier, $source['id'], 'income', $lineIds);
        foreach ($lineIds as $lineId) self::assertEligible($lines[$lineId] ?? throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404));
        $this->assertIncomeFree($supplier, $lineIds, null);
        $values = [];
        $params = [];
        foreach ($lineIds as $lineId) {
            $line = $lines[$lineId];
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($params, $supplier, $source['id'], $lineId, $lineId, $line['entry_id'], $line['source_type'], $line['source_id'], $line['account_id'], $line['amount_cents'], $line['date'], $actor);
        }
        $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_incomes (supplier_id, source_id, line_id, linked_line_id, entry_id, entry_source_type, entry_source_id, account_id, amount_cents, entry_date, created_by) VALUES ' . implode(', ', $values))
            ->execute($params);
        $ids = $this->activeIds($supplier, 'income', $source['id'], $lineIds);
        $this->history($supplier, $source['id'], array_map(static fn(int $lineId) => ['income_added', $ids[$lineId], null, null, self::lineSnapshot($lines[$lineId])], $lineIds), null, $actor);
    }

    /** Výnosový řádek smí patřit jen jednomu aktivnímu zdroji (pojistkou je i unikátní index). @param list<int> $lineIds */
    private function assertIncomeFree(int $supplier, array $lineIds, ?int $exceptIncome): void
    {
        $q = $this->db->pdo()->prepare('SELECT 1 FROM nonprofit_funding_work_incomes WHERE supplier_id = ? AND id <> ? AND active_line_id IN (' . self::marks($lineIds) . ') LIMIT 1 LOCK IN SHARE MODE');
        $q->execute([$supplier, $exceptIncome ?? 0, ...$lineIds]);
        if ($q->fetchColumn() !== false) throw new FundingWorkException('funding_work_income_used', 'Výnosový řádek už patří jinému zdroji.', 409);
    }

    /** Důvod nezpůsobilosti z FundingWorkLedger::problemSql (jediné pravidlo pro nabídku, stav i zápis). */
    private static function assertEligible(array $line): void
    {
        if ($line['problem'] === null) return;
        [$code, $message, $status] = self::PROBLEMS[$line['problem']] ?? ['funding_work_line_ineligible', 'Řádek nelze přiřadit.', 422];
        throw new FundingWorkException($code, $message, $status);
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

    /** Cíl navázání: týž řádek, nebo řádek téhož zápisu či zápisu téhož dokladu, se stejným účtem. */
    private static function assertRelinkTarget(array $link, array $line): void
    {
        if ($line['reversed'] || $line['is_reversal']) throw new FundingWorkException('funding_work_line_reversed', 'Stornovaný zápis nelze znovu navázat. Vazbu odeberte.', 409);
        if ($line['id'] === $link['linked_line_id'] && $link['line_id'] !== null) return;
        if ($line['account_id'] === $link['account_id'] && self::sameDocument($link, $line)) return;
        throw new FundingWorkException('funding_work_relink_invalid', 'Znovu navázat lze jen na řádek téhož zápisu nebo dokladu se stejným účtem.');
    }

    private static function sameDocument(array $link, array $line): bool
    {
        return $line['entry_id'] === $link['entry_id']
            || ($link['entry_source_id'] !== null && $line['source_type'] === $link['entry_source_type'] && $line['source_id'] === $link['entry_source_id']);
    }

    // ---------------------------------------------------------------- stav vazeb

    /**
     * Vazby zdrojů se stavem podle snímku (jediný výpočet stavu pro přehled i detail). Důvod ke kontrole:
     * removed (řádek smazán přeúčtováním), reversed (storno), line_changed (zápis, účet, datum nebo částka),
     * důvod nezpůsobilosti z FundingWorkLedger::problemSql (např. zúžené podmínky zdroje), overallocated
     * (řádek nese víc, než má) a credit_note (nový dobropis k přijaté faktuře).
     */
    private static function linksSql(string $kind, int $count): string
    {
        $table = $kind === 'income' ? 'nonprofit_funding_work_incomes x FORCE INDEX (idx_nfwi_source)' : 'nonprofit_funding_work_allocations x FORCE INDEX (idx_nfwa_source)';
        $snapshot = $kind === 'income' ? 'x.amount_cents' : 'x.line_amount_cents';
        $current = 'ROUND(IF(l.is_red_storno = 1, -l.amount, l.amount) * 100)';
        $marks = implode(',', array_fill(0, $count, '?'));
        $extra = $kind === 'income' ? 'NULL' : "CASE WHEN COALESCE(ov.used, 0) > $current THEN 'overallocated'
            WHEN " . FundingWorkLedger::creditNotesSql() . " > x.credit_notes_seen THEN 'credit_note' ELSE NULL END";
        // Přečerpání řádku: součet přiřazení všech aktivních zdrojů jednou za řádek (stejný výpočet jako
        // FundingWorkLedger::allocatedSql), ne korelovaně pro každou vazbu (110 tis. vazeb: ~4 s navíc).
        $used = $kind === 'income' ? '' : "LEFT JOIN (SELECT y.linked_line_id, SUM(y.amount_cents) AS used FROM nonprofit_funding_work_allocations y
                JOIN nonprofit_funding_work_sources ys ON ys.supplier_id = y.supplier_id AND ys.id = y.source_id AND ys.status = 'active'
                WHERE y.supplier_id = ? AND y.status = 'active' AND y.linked_line_id IN (SELECT x2.linked_line_id FROM nonprofit_funding_work_allocations x2
                    WHERE x2.supplier_id = ? AND x2.status = 'active' AND x2.source_id IN ($marks))
                GROUP BY y.linked_line_id) ov ON ov.linked_line_id = x.linked_line_id";
        return 'SELECT x.*, xa.account_code AS snapshot_account_code, ' . FundingWorkLedger::LINE_COLUMNS . ",
            CASE WHEN l.id IS NULL THEN 'removed'
                WHEN e.reversed_by IS NOT NULL OR EXISTS(SELECT 1 FROM journal_entries r3 WHERE r3.supplier_id = e.supplier_id AND r3.reversed_by = e.id) THEN 'reversed'
                WHEN l.entry_id <> x.entry_id OR l.account_id <> x.account_id OR e.entry_date <> x.entry_date OR $current <> $snapshot THEN 'line_changed'
                ELSE COALESCE((" . FundingWorkLedger::problemSql($kind) . "), $extra) END AS review_reason
            FROM $table
            JOIN nonprofit_funding_work_sources s ON s.supplier_id = x.supplier_id AND s.id = x.source_id
            LEFT JOIN chart_of_accounts xa ON xa.supplier_id = x.supplier_id AND xa.id = x.account_id
            LEFT JOIN journal_entry_lines l ON l.id = x.line_id AND l.supplier_id = x.supplier_id
            LEFT JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            LEFT JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            LEFT JOIN dimension_types t ON t.supplier_id = x.supplier_id AND t.system_key = 'activity_kind'
            LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = t.id
            $used
            WHERE x.supplier_id = ? AND x.status = 'active' AND x.source_id IN ($marks)";
    }

    /** Parametry k linksSql (u nákladů nejdřív součet přiřazení po řádcích). @param list<int> $sourceIds */
    private static function linksParams(string $kind, int $supplier, array $sourceIds): array
    {
        return $kind === 'income' ? [$supplier, ...$sourceIds] : [$supplier, $supplier, ...$sourceIds, $supplier, ...$sourceIds];
    }

    /** @param list<int> $sourceIds @return list<array<string,mixed>> */
    private function links(int $supplier, string $kind, array $sourceIds, ?int $page): array
    {
        if ($sourceIds === []) return [];
        $sql = self::linksSql($kind, count($sourceIds)) . ' ORDER BY x.entry_date, x.id';
        if ($page !== null) $sql .= ' LIMIT ' . (self::PAGE + 1) . ' OFFSET ' . (($page - 1) * self::PAGE);
        $q = $this->db->pdo()->prepare($sql);
        $q->execute(self::linksParams($kind, $supplier, $sourceIds));
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = self::linkRow($r);
            $row['account_code'] = $r['snapshot_account_code'];
            $row['line'] = FundingWorkLedger::line($r);
            $row['review_reason'] = $r['review_reason'];
            $row['state'] = self::state($r['review_reason']);
            $out[] = $row;
        }
        return $out;
    }

    private static function state(?string $reason): string
    {
        return match ($reason) { null => self::STATE_OK, 'removed' => self::STATE_REMOVED, 'reversed' => self::STATE_REVERSED, default => self::STATE_CHANGED };
    }

    /** Souhrny zdrojů v SQL nad stejným výpočtem stavu jako detail. @param array<int,array<string,mixed>> $sources */
    private function totals(int $supplier, array $sources): array
    {
        $ids = array_keys($sources);
        $out = [];
        foreach ($sources as $id => $source) {
            $out[$id] = ['budget_cents' => $source['budget_cents'], 'received_cents' => 0, 'allocated_cents' => 0, 'documented_cents' => 0, 'remaining_cents' => $source['budget_cents'],
                'review_count' => 0, 'review_cents' => 0, 'income_count' => 0, 'allocation_count' => 0];
        }
        if ($ids === []) return $out;
        foreach (['income', 'cost'] as $kind) {
            $q = $this->db->pdo()->prepare('SELECT z.source_id, COUNT(*) AS cnt, SUM(z.amount_cents) AS total, SUM(IF(z.review_reason IS NULL, z.amount_cents, 0)) AS ok_cents,
                SUM(z.review_reason IS NOT NULL) AS review_cnt, SUM(IF(z.review_reason IS NULL, 0, z.amount_cents)) AS review_cents
                FROM (' . self::linksSql($kind, count($ids)) . ') z GROUP BY z.source_id');
            $q->execute(self::linksParams($kind, $supplier, $ids));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = &$out[(int) $r['source_id']];
                if ($kind === 'income') {
                    $t['income_count'] = (int) $r['cnt'];
                    $t['received_cents'] = (int) $r['ok_cents'];
                } else {
                    $t['allocation_count'] = (int) $r['cnt'];
                    $t['allocated_cents'] = (int) $r['total'];
                    $t['documented_cents'] = (int) $r['ok_cents'];
                    $t['review_cents'] = (int) $r['review_cents'];
                    $t['remaining_cents'] = $t['budget_cents'] - (int) $r['total'];
                }
                $t['review_count'] += (int) $r['review_cnt'];
                unset($t);
            }
        }
        return $out;
    }

    /**
     * Pro vazby ke kontrole nabídne řádky téhož zápisu nebo dokladu se stejným účtem (jedním dotazem).
     * Stornovaná vazba dostane nabídku jen z nového zápisu téhož dokladu (storno a nové zaúčtování).
     */
    private function attachRelinkCandidates(int $supplier, int $period, array &$incomes, array &$allocations): void
    {
        $open = static fn(array $row) => $row['state'] !== self::STATE_OK;
        $pending = array_values(array_filter([...$incomes, ...$allocations], $open));
        $lines = $this->ledger->relinkCandidates($supplier, $period, $pending);
        $pick = static fn(array $row) => $open($row) ? array_values(array_filter($lines, static fn(array $line) => $line['account_id'] === $row['account_id'] && self::sameDocument($row, $line))) : [];
        foreach ($incomes as &$income) $income['relink_candidates'] = $pick($income);
        unset($income);
        foreach ($allocations as &$allocation) $allocation['relink_candidates'] = $pick($allocation);
        unset($allocation);
    }

    /** Výnos ve zdroji J6 i náklad čerpaný v potvrzeném J6 nesou klíč zdroje; jde jen o upozornění. */
    private function attachConfirmedLinks(int $supplier, array &$incomes, array &$allocations): void
    {
        $members = [];
        foreach ($this->confirmed->latest($supplier) as $key => $row) foreach ($row['policy']['source_members'] as $member) $members[$member] = (string) $key;
        foreach ($incomes as &$income) $income['confirmed_source_key'] = $members['line:' . $income['linked_line_id']] ?? null;
        unset($income);
        $uses = $allocations === [] ? [] : $this->confirmedCostUses($supplier);
        foreach ($allocations as &$allocation) $allocation['confirmed_source_key'] = $uses[$allocation['linked_line_id']] ?? null;
        unset($allocation);
    }

    /** Nákladové řádky čerpané potvrzenými zdroji J6 (aktuální i neaktuální posouzení). @return array<int,string> */
    private function confirmedCostUses(int $supplier): array
    {
        $out = [];
        foreach ($this->confirmedUse->read($supplier)['sources'] as $source) {
            foreach ($source['uses'] as $use) {
                if (preg_match('/^line:([1-9][0-9]*)$/D', (string) ($use['cost']['id'] ?? ''), $m)) $out[(int) $m[1]] ??= (string) $source['source_key'];
            }
        }
        return $out;
    }

    /** Stav úhrady přijaté faktury, ze které náklad pochází (jen informace, SSOT PurchaseSettledExpr). */
    private function attachPaymentState(int $supplier, array &$allocations): void
    {
        $ids = [];
        foreach ($allocations as $row) if (($row['line']['source_type'] ?? null) === 'purchase_invoice' && $row['line']['source_id'] !== null) $ids[] = $row['line']['source_id'];
        $states = [];
        if ($ids !== []) {
            $ids = array_values(array_unique($ids));
            $q = $this->db->pdo()->prepare('SELECT pi.id, pi.amount_to_pay, ' . PurchaseSettledExpr::settled('pi') . ' AS settled FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.id IN (' . self::marks($ids) . ')');
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

    /**
     * Zdroje s popisem poskytovatele. Zamykací čtení se týká jen tabulky zdrojů; popis poskytovatele
     * se čte zvlášť bez zámku.
     * @return array<int,array<string,mixed>>
     */
    private function sourceRows(int $supplier, string $where, array $params, bool $lock = false): array
    {
        $q = $this->db->pdo()->prepare('SELECT s.* FROM nonprofit_funding_work_sources s WHERE s.supplier_id = ? AND ' . $where . ' ORDER BY s.status, s.title, s.id' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, ...$params]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $labels = [];
        $providers = array_values(array_unique(array_map(static fn(array $r) => (int) $r['provider_id'], $rows)));
        if ($providers !== []) {
            $q = $this->db->pdo()->prepare('SELECT id, company_name, first_name, last_name FROM clients WHERE supplier_id = ? AND id IN (' . self::marks($providers) . ')');
            $q->execute([$supplier, ...$providers]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) $labels[(int) $c['id']] = self::partyLabel($c);
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'period_id' => (int) $r['period_id'], 'provider_id' => (int) $r['provider_id'], 'provider_label' => $labels[(int) $r['provider_id']] ?? '',
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

    private function link(int $supplier, string $kind, int $id, bool $lock): array
    {
        $table = $kind === 'income' ? 'nonprofit_funding_work_incomes' : 'nonprofit_funding_work_allocations';
        $q = $this->db->pdo()->prepare("SELECT * FROM $table WHERE supplier_id = ? AND id = ? AND status = 'active'" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new FundingWorkException('funding_work_not_found', $kind === 'income' ? 'Výnos zdroje nebyl nalezen.' : 'Přiřazení nebylo nalezeno.', 404);
        return self::linkRow($row);
    }

    private function lineFor(int $supplier, int $sourceId, string $kind, int $lineId): array
    {
        return $this->ledger->linesFor($supplier, $sourceId, $kind, [$lineId])[$lineId]
            ?? throw new FundingWorkException('funding_work_line_not_found', 'Řádek deníku nebyl nalezen.', 404);
    }

    /** Id právě vložených aktivních vazeb podle řádku (jeden dotaz místo lastInsertId v cyklu). @return array<int,int> */
    private function activeIds(int $supplier, string $kind, int $sourceId, array $lineIds): array
    {
        $table = $kind === 'income' ? 'nonprofit_funding_work_incomes' : 'nonprofit_funding_work_allocations';
        $q = $this->db->pdo()->prepare("SELECT active_line_id, id FROM $table WHERE supplier_id = ? AND source_id = ? AND active_line_id IN (" . self::marks($lineIds) . ')');
        $q->execute([$supplier, $sourceId, ...$lineIds]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$line, $id]) $out[(int) $line] = (int) $id;
        return $out;
    }

    private function allocatedOnSource(int $supplier, int $sourceId, ?int $exceptAllocation): int
    {
        $q = $this->db->pdo()->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND status = 'active' AND id <> ? LOCK IN SHARE MODE");
        $q->execute([$supplier, $sourceId, $exceptAllocation ?? 0]);
        return (int) $q->fetchColumn();
    }

    /**
     * Poskytovatel, druhy činnosti a potvrzený zdroj patří firmě. Druh činnosti, který mezitím zmizel
     * z číselníku, se u úpravy zdroje tiše vypustí: smazat jde jen hodnotu bez řádků deníku (FK RESTRICT),
     * takže jeho vypuštění nemění, které náklady zdroj splňují.
     */
    private function assertReferences(int $supplier, array $data, ?array $existing): array
    {
        $q = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE supplier_id = ? AND id = ?');
        $q->execute([$supplier, $data['provider_id']]);
        if ($q->fetchColumn() === false) self::invalid('Poskytovatel nebyl nalezen.');
        if ($data['activity_kind_ids'] !== []) {
            $q = $this->db->pdo()->prepare("SELECT v.id FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id AND t.supplier_id = v.supplier_id
                WHERE v.supplier_id = ? AND t.system_key = 'activity_kind' AND v.id IN (" . self::marks($data['activity_kind_ids']) . ')');
            $q->execute([$supplier, ...$data['activity_kind_ids']]);
            $found = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_diff($data['activity_kind_ids'], $found);
            if ($missing !== [] && array_diff($missing, $existing['activity_kind_ids'] ?? []) !== []) self::invalid('Druh činnosti nepatří této firmě.');
            $data['activity_kind_ids'] = array_values(array_intersect($data['activity_kind_ids'], $found));
        }
        if ($data['legacy_source_key'] !== null && !array_key_exists($data['legacy_source_key'], $this->confirmed->latest($supplier))) self::invalid('Potvrzený zdroj nebyl nalezen.');
        return $data;
    }

    /**
     * Neměnná historie zdroje (víceřádkový INSERT) a jeden záznam auditní stopy na akci.
     * @param list<array{0:string,1:?int,2:?int,3:?array,4:?array}> $events [akce, výnos, přiřazení, před, po]
     */
    private function history(int $supplier, int $source, array $events, ?string $reason, int $actor): void
    {
        if ($events === []) return;
        $values = [];
        $params = [];
        foreach ($events as [$action, $income, $allocation, $before, $after]) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($params, $supplier, $source, $income, $allocation, $action,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $reason, $actor);
        }
        $this->db->pdo()->prepare('INSERT INTO nonprofit_funding_work_history (supplier_id, source_id, income_id, allocation_id, action, before_json, after_json, reason, created_by) VALUES ' . implode(', ', $values))
            ->execute($params);
        $grouped = [];
        foreach ($events as [$action, $income, $allocation, $before, $after]) {
            $grouped[$action][] = array_filter(['income_id' => $income, 'allocation_id' => $allocation, 'before' => $before, 'after' => $after], static fn($v) => $v !== null);
        }
        foreach ($grouped as $action => $items) {
            $payload = count($items) === 1 ? $items[0] : ['items' => $items];
            if ($reason !== null) $payload['reason'] = $reason;
            $this->activity->log('nonprofit_funding_work.' . $action, $actor, 'nonprofit_funding_work_source', $source, $payload, null, null, $supplier);
        }
    }

    // ---------------------------------------------------------------- vstupy

    /** @return array<string,mixed> */
    private function normalize(array $input, bool $create): array
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

    private static function marks(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
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
            'linked_line_id' => (int) $r['linked_line_id'], 'entry_id' => (int) $r['entry_id'],
            'entry_source_type' => $r['entry_source_type'], 'entry_source_id' => $r['entry_source_id'] === null ? null : (int) $r['entry_source_id'],
            'account_id' => (int) $r['account_id'], 'account_code' => $r['account_code'] ?? null, 'entry_date' => $r['entry_date'], 'row_version' => (int) $r['row_version'],
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
