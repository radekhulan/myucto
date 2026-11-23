<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use PDO;

/**
 * Položky agendy Časové rozlišení: založení (ručně, z dokladu, převzetím), úprava, plán
 * rozpouštění, zrušení a detail se zůstatkem z deníku.
 *
 * Položka spravovaná agendou (`managed_by = agenda`) se rozpouští jen zápisy agendy nebo
 * navázanými převzatými zápisy. Uzávěrka a otevření roku ji vynechají
 * ({@see AccrualAgendaGuard}), takže se nikdy nerozpustí dvakrát.
 */
final class AccrualItemService
{
    private const EDITABLE_ALWAYS = ['title', 'contract_ref', 'note', 'counter_account_code', 'termination_account_code',
        'partner_id', 'partner_name', 'cost_center', 'project_id'];
    private const EDITABLE_PLAN = ['basis_amount', 'period_from', 'period_to', 'method'];

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualItemRepository $items,
        private readonly DocumentPostingGroup $postingGroup,
    ) {}

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function list(int $supplierId, array $query): array
    {
        $asOf = self::dateOr($query['as_of'] ?? null, date('Y-m-d'), 'as_of');
        $filters = [
            'status' => self::csv($query['status'] ?? null, AccrualKinds::STATUSES, 'status'),
            'kind' => self::csv($query['kind'] ?? null, array_keys(AccrualKinds::PREFIXES), 'kind'),
            'method' => self::csv($query['method'] ?? null, AccrualPlanBuilder::METHODS, 'method'),
            'source_type' => self::csv($query['source_type'] ?? null, AccrualKinds::SOURCE_TYPES, 'source_type'),
            'managed_by' => self::csv($query['managed_by'] ?? null, ['agenda', 'closing'], 'managed_by'),
            'account' => preg_match('/^[0-9]{0,10}$/', (string) ($query['account'] ?? '')) === 1 ? (string) ($query['account'] ?? '') : '',
            'partner_id' => isset($query['partner_id']) && ctype_digit((string) $query['partner_id']) ? (int) $query['partner_id'] : null,
            'ends_by' => self::dateOr($query['ends_by'] ?? null, null, 'ends_by'),
            'active_on' => self::dateOr($query['active_on'] ?? null, null, 'active_on'),
            'with_balance' => in_array((string) ($query['with_balance'] ?? ''), ['1', 'true'], true),
            'q' => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
        ];
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 50)));
        $sortBy = array_key_exists((string) ($query['sort_by'] ?? ''), AccrualItemRepository::SORTABLE) ? (string) $query['sort_by'] : 'item_no';
        $sortDir = ($query['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $result = $this->items->list($supplierId, $filters, $asOf, $page, $perPage, $sortBy, $sortDir);
        return $result + ['page' => $page, 'per_page' => $perPage, 'as_of' => $asOf];
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $id, ?string $asOf = null): array
    {
        $item = $this->items->find($supplierId, $id) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
        $asOf = self::dateOr($asOf, date('Y-m-d'), 'as_of');
        return $this->decorate($supplierId, $item, $asOf);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function decorate(int $supplierId, array $item, string $asOf): array
    {
        $id = (int) $item['id'];
        $sign = AccrualKinds::normalSign((string) $item['kind']);
        $bal = $this->items->balances($supplierId, [$id], $asOf)[$id] ?? ['balance' => 0.0, 'released' => 0.0];
        $total = $this->items->balances($supplierId, [$id], '9999-12-31')[$id] ?? ['balance' => 0.0, 'released' => 0.0];
        $schedule = $this->items->schedule($supplierId, $id);
        $links = $this->items->links($supplierId, $id);
        $next = null;
        foreach ($schedule as $row) {
            if ($row['kind'] === 'release' && $row['status'] === 'planned') {
                $next = $row;
                break;
            }
        }
        $item['as_of'] = $asOf;
        $item['balance'] = round($bal['balance'] * $sign, 2);
        $item['released'] = round(-$bal['released'] * $sign, 2);
        $item['balance_after_all'] = round($total['balance'] * $sign, 2);
        $item['next_release'] = $next;
        $item['schedule'] = $schedule;
        $item['links'] = array_map(static fn (array $l): array => $l + ['amount_normal' => round($l['amount'] * $sign, 2)], $links);
        $item['covered_through'] = self::coveredThrough($item, $schedule);
        $item['source'] = $this->sourceInfo($supplierId, $item);
        $item['can'] = [
            'edit' => in_array($item['status'], ['draft', 'active'], true),
            'activate' => $item['status'] === 'draft',
            'terminate' => $item['status'] === 'active' && $item['managed_by'] === 'agenda' && in_array($item['kind'], AccrualKinds::SUPPORTED, true),
            'cancel_termination' => $item['status'] === 'terminated' && $item['termination_entry_id'] !== null,
            'cancel' => in_array($item['status'], ['draft', 'active'], true) && !self::hasAgendaPostings($schedule),
            'delete' => $item['status'] === 'draft' || ($item['status'] === 'cancelled' && !self::hasAgendaPostings($schedule)),
        ];
        return $item;
    }

    /**
     * Náhled plánu bez uložení (editor, živý výpočet ze serveru).
     *
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function previewPlan(array $input): array
    {
        $method = (string) ($input['method'] ?? 'monthly_days');
        if (!in_array($method, AccrualPlanBuilder::METHODS, true)) {
            throw new AccrualException('invalid_method', 'Neznámá metoda rozpouštění.', 422, 'method');
        }
        $basis = self::amount($input['basis_amount'] ?? null, 'basis_amount');
        $from = self::requiredDate($input['period_from'] ?? null, 'period_from');
        $to = self::requiredDate($input['period_to'] ?? null, 'period_to');
        if ($to < $from) {
            throw new AccrualException('invalid_period', 'Konec období je před jeho začátkem.', 422, 'period_to');
        }
        $covered = isset($input['opening_as_of']) && IsoDate::isValid((string) $input['opening_as_of'])
            ? AccrualPlanBuilder::dayBefore((string) $input['opening_as_of']) : null;
        $target = isset($input['opening_balance']) && $input['opening_balance'] !== null && $input['opening_balance'] !== ''
            ? self::amount($input['opening_balance'], 'opening_balance') : null;
        return AccrualPlanBuilder::build($method, $basis, $from, $to, $covered, $target);
    }

    /**
     * Založí položku. Volitelně ji rovnou aktivuje (vznikne plán) a naváže zápis vzniku
     * (`origin_entry_id`, řádky na účtu položky).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $input, ?int $userId): array
    {
        return TransactionFailure::retrying($this->db->pdo(), function () use ($supplierId, $input, $userId): array {
            $pdo = $this->db->pdo();
            $own = !$pdo->inTransaction();
            if ($own) $pdo->beginTransaction();
            try {
                $data = $this->validated($supplierId, $input, null);
                $data['managed_by'] = 'agenda';
                $data['status'] = 'draft';
                $data['created_by'] = $userId;
                $data['updated_by'] = $userId;
                $itemNo = trim((string) ($input['item_no'] ?? ''));
                $data['item_no'] = $itemNo !== '' ? mb_substr($itemNo, 0, 50)
                    : $this->items->nextItemNo($supplierId, (int) substr((string) ($data['period_from'] ?? date('Y-m-d')), 0, 4));
                try {
                    $id = $this->items->insert($supplierId, $data);
                } catch (\PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        throw new AccrualException('duplicate_item_no', 'Položka s tímto číslem už existuje.', 409, 'item_no');
                    }
                    throw $e;
                }
                $originEntry = isset($input['origin_entry_id']) && ctype_digit((string) $input['origin_entry_id']) ? (int) $input['origin_entry_id'] : null;
                if ($originEntry !== null) {
                    $this->linkOrigin($supplierId, $id, $originEntry, $data['accrual_account_code'], $input['origin_amount'] ?? null, $userId);
                }
                if (!empty($input['activate'])) {
                    $this->activateLocked($supplierId, $id, $userId);
                }
                if ($own) $pdo->commit();
            } catch (\Throwable $e) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            return $this->get($supplierId, $id);
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
            if (isset($input['row_version']) && (int) $input['row_version'] !== (int) $item['row_version']) {
                throw new AccrualException('changed', 'Položku mezitím změnil jiný uživatel. Načtěte ji znovu.', 409);
            }
            if (!in_array($item['status'], ['draft', 'active'], true) || $item['managed_by'] !== 'agenda') {
                throw new AccrualException('not_editable', 'Ukončenou, dokončenou nebo zrušenou položku nelze upravit.', 409);
            }
            $merged = array_merge($item, array_intersect_key($input, array_flip([...self::EDITABLE_ALWAYS, ...self::EDITABLE_PLAN, 'kind', 'accrual_account_code', 'opening_balance', 'opening_as_of'])));
            $data = $this->validated($supplierId, $merged, $item);
            $schedule = $this->items->schedule($supplierId, $id);
            if (self::hasAgendaPostings($schedule) || self::hasLinked($schedule)) {
                // Zaúčtovaná historie drží druh a účet položky; plán se přepočte jen za ní.
                foreach (['kind', 'accrual_account_code'] as $locked) {
                    if ((string) $data[$locked] !== (string) $item[$locked]) {
                        throw new AccrualException('has_postings', 'Druh a účet položky se zaúčtovaným rozpouštěním nelze změnit.', 409, $locked);
                    }
                }
            }
            $data['updated_by'] = $userId;
            $this->items->update($supplierId, $id, $data);
            if ($item['status'] === 'active') {
                $this->rebuildPlan($supplierId, $id);
            }
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /** @return array<string,mixed> */
    public function activate(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $this->activateLocked($supplierId, $id, $userId);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    private function activateLocked(int $supplierId, int $id, ?int $userId): void
    {
        $item = $this->items->find($supplierId, $id, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
        if ($item['status'] !== 'draft') {
            throw new AccrualException('not_draft', 'Aktivovat lze jen koncept položky.', 409);
        }
        if ($item['method'] !== 'manual' && $item['counter_account_code'] === null) {
            throw new AccrualException('counter_account_required', 'Pro plán rozpouštění vyplňte protiúčet.', 422, 'counter_account_code');
        }
        $this->items->update($supplierId, $id, ['status' => 'active', 'updated_by' => $userId]);
        $this->rebuildPlan($supplierId, $id);
    }

    /** Zruší položku bez zaúčtovaného rozpouštění agendy (převzaté vazby se jen uvolní). */
    public function cancel(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
            if (!in_array($item['status'], ['draft', 'active'], true)) {
                throw new AccrualException('not_cancellable', 'Zrušit lze jen koncept nebo aktivní položku.', 409);
            }
            if (self::hasAgendaPostings($this->items->schedule($supplierId, $id, true))) {
                throw new AccrualException('has_postings', 'Položka má zaúčtované rozpuštění. Nejdřív ho stornujte.', 409);
            }
            $pdo->prepare("DELETE FROM accrual_schedule WHERE supplier_id = ? AND accrual_item_id = ? AND status IN ('planned', 'cancelled')")->execute([$supplierId, $id]);
            $this->items->update($supplierId, $id, ['status' => 'cancelled', 'updated_by' => $userId]);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /** @return array<string,mixed> smazaná položka */
    public function delete(int $supplierId, int $id): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
            $schedule = $this->items->schedule($supplierId, $id, true);
            if (!($item['status'] === 'draft' || ($item['status'] === 'cancelled' && !self::hasAgendaPostings($schedule)))) {
                throw new AccrualException('not_deletable', 'Smazat lze jen koncept nebo zrušenou položku bez zaúčtování.', 409);
            }
            if (self::hasAgendaPostings($schedule)) {
                throw new AccrualException('has_postings', 'Položka má zaúčtované rozpuštění.', 409);
            }
            $this->items->delete($supplierId, $id);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $item;
    }

    /**
     * Přepočte nezaúčtovanou část plánu: za posledním zaúčtovaným či navázaným řádkem až do
     * konce období, cílem je zbývající částka (základ nebo převzatý zůstatek − to, co už
     * rozpouštějí zaúčtované řádky). Haléřový zbytek nese poslední řádek.
     */
    public function rebuildPlan(int $supplierId, int $id): void
    {
        $item = $this->items->find($supplierId, $id) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
        if ($item['status'] !== 'active' || $item['managed_by'] !== 'agenda' || $item['method'] === 'manual'
            || $item['period_from'] === null || $item['period_to'] === null) {
            $this->items->replacePlanned($supplierId, $id, []);
            return;
        }
        $schedule = $this->items->schedule($supplierId, $id);
        $done = 0.0;
        foreach ($schedule as $row) {
            if ($row['kind'] === 'release' && in_array($row['status'], ['posted', 'linked'], true)) {
                $done = round($done + $this->rowActualAmount($supplierId, $item, $row), 2);
            }
        }
        $covered = self::coveredThrough($item, $schedule);
        $target = round(($item['opening_balance'] ?? $item['basis_amount']) - $done, 2);
        $rows = AccrualPlanBuilder::build((string) $item['method'], (float) $item['basis_amount'],
            (string) $item['period_from'], (string) $item['period_to'], $covered, $target);
        $kept = [];
        foreach ($schedule as $row) {
            if ($row['kind'] === 'release' && in_array($row['status'], ['posted', 'linked'], true)) {
                $kept[$row['due_on']] = true;
            }
        }
        $this->items->replacePlanned($supplierId, $id, array_values(array_filter($rows, static fn (array $r): bool => !isset($kept[$r['due_on']]))));
    }

    /**
     * Skutečně rozpuštěná částka řádku plánu podle vazby na zápis (v normálním znaménku).
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $row
     */
    public function rowActualAmount(int $supplierId, array $item, array $row): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(SUM(amount), 0) FROM accrual_entry_links WHERE supplier_id = ? AND accrual_item_id = ? AND journal_entry_id = ?');
        $stmt->execute([$supplierId, (int) $item['id'], (int) $row['journal_entry_id']]);
        $linked = round(-(float) $stmt->fetchColumn() * AccrualKinds::normalSign((string) $item['kind']), 2);
        return (int) round($linked * 100) !== 0 ? $linked : (float) $row['amount_planned'];
    }

    /**
     * Poslední den, který pokrývají zaúčtované či navázané řádky rozpouštění. Bez nich den před
     * začátkem období, u převzaté položky den před datem převzatého zůstatku.
     *
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $schedule
     */
    public static function coveredThrough(array $item, array $schedule): ?string
    {
        $covered = null;
        if ($item['opening_as_of'] !== null) {
            $covered = AccrualPlanBuilder::dayBefore((string) $item['opening_as_of']);
        } elseif ($item['period_from'] !== null) {
            $covered = AccrualPlanBuilder::dayBefore((string) $item['period_from']);
        }
        foreach ($schedule as $row) {
            if ($row['kind'] === 'release' && in_array($row['status'], ['posted', 'linked'], true)) {
                $end = (string) ($row['to_on'] ?? $row['due_on']);
                if ($covered === null || $end > $covered) {
                    $covered = $end;
                }
            }
        }
        return $covered;
    }

    /**
     * Návrh položky z dokladu: řádky jeho živých zápisů na účtech 381 až 385, partner, číslo
     * a období od-do z řádků dokladu, pokud je mají.
     *
     * @return array<string,mixed>
     */
    public function sourceCandidates(int $supplierId, string $sourceType, int $sourceId): array
    {
        [$doc, $entryIds, $periods] = $this->sourceDocument($supplierId, $sourceType, $sourceId);
        $lines = [];
        if ($entryIds !== []) {
            $in = implode(',', array_map('intval', $entryIds));
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.entry_id, je.entry_date, ca.account_code, ca.name AS account_name,
                        SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
                   FROM journal_entry_lines l
                   JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$in})
                    AND (ca.account_code LIKE '381%' OR ca.account_code LIKE '382%' OR ca.account_code LIKE '383%'
                         OR ca.account_code LIKE '384%' OR ca.account_code LIKE '385%')
                  GROUP BY l.entry_id, je.entry_date, ca.account_code, ca.name
                 HAVING SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) <> 0"
            );
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $kind = AccrualKinds::kindForAccount((string) $r['account_code']);
                $amount = round((float) $r['amount'], 2);
                $lines[] = [
                    'entry_id' => (int) $r['entry_id'],
                    'entry_date' => (string) $r['entry_date'],
                    'account_code' => (string) $r['account_code'],
                    'account_name' => (string) $r['account_name'],
                    'kind' => $kind,
                    'amount' => $kind === null ? $amount : round($amount * AccrualKinds::normalSign($kind), 2),
                ];
            }
        }
        $existing = $this->db->pdo()->prepare("SELECT id, item_no, title, status FROM accrual_items WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND status <> 'cancelled'");
        $existing->execute([$supplierId, $sourceType, $sourceId]);
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'document_no' => $doc['document_no'],
            'document_date' => $doc['document_date'],
            'partner_id' => $doc['partner_id'],
            'partner_name' => $doc['partner_name'],
            'title' => $doc['title'],
            'accrual_lines' => $lines,
            'periods' => $periods,
            'items' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'item_no' => $r['item_no'], 'title' => $r['title'], 'status' => $r['status']],
                $existing->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * @return array{0: array{document_no:?string, document_date:?string, partner_id:?int, partner_name:?string, title:?string}, 1: list<int>, 2: list<array{from:string,to:string}>}
     */
    private function sourceDocument(int $supplierId, string $sourceType, int $sourceId): array
    {
        $pdo = $this->db->pdo();
        $partner = "COALESCE(NULLIF(c.company_name, ''), NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''))";
        $periods = [];
        if ($sourceType === 'invoice' || $sourceType === 'purchase_invoice') {
            $sql = $sourceType === 'invoice'
                ? "SELECT d.varsymbol AS document_no, d.tax_date AS document_date, d.client_id AS partner_id, {$partner} AS partner_name
                     FROM invoices d LEFT JOIN clients c ON c.id = d.client_id WHERE d.supplier_id = ? AND d.id = ?"
                : "SELECT COALESCE(NULLIF(d.vendor_invoice_number, ''), d.varsymbol) AS document_no, d.tax_date AS document_date,
                          d.vendor_id AS partner_id, {$partner} AS partner_name
                     FROM purchase_invoices d LEFT JOIN clients c ON c.id = d.vendor_id WHERE d.supplier_id = ? AND d.id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId, $sourceId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new AccrualException('source_not_found', 'Doklad neexistuje.', 404);
            $entryIds = array_map(static fn (array $e): int => (int) $e['id'], $this->postingGroup->active($supplierId, $sourceType, $sourceId));
            $itemTable = $sourceType === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
            $fk = $sourceType === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
            $p = $pdo->prepare("SELECT it.id, it.accrual_from, it.accrual_to FROM {$itemTable} it JOIN " . ($sourceType === 'invoice' ? 'invoices' : 'purchase_invoices')
                . " d ON d.id = it.{$fk} WHERE d.supplier_id = ? AND it.{$fk} = ? AND it.accrual_from IS NOT NULL AND it.accrual_to IS NOT NULL");
            $p->execute([$supplierId, $sourceId]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $periods[] = ['item_id' => (int) $r['id'], 'from' => (string) $r['accrual_from'], 'to' => (string) $r['accrual_to']];
            }
            $doc['title'] = null;
        } elseif ($sourceType === 'other_item') {
            $stmt = $pdo->prepare("SELECT d.document_no, d.issued_on AS document_date, d.partner_id, COALESCE(d.partner_name, {$partner}) AS partner_name,
                                          d.title, d.journal_entry_id, d.accrual_from, d.accrual_to
                                     FROM other_items d LEFT JOIN clients c ON c.id = d.partner_id
                                    WHERE d.supplier_id = ? AND d.id = ? AND d.deleted_at IS NULL");
            $stmt->execute([$supplierId, $sourceId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new AccrualException('source_not_found', 'Doklad neexistuje.', 404);
            $entryIds = $doc['journal_entry_id'] === null ? [] : [(int) $doc['journal_entry_id']];
            if ($doc['accrual_from'] !== null && $doc['accrual_to'] !== null) {
                $periods[] = ['item_id' => null, 'from' => (string) $doc['accrual_from'], 'to' => (string) $doc['accrual_to']];
            }
        } elseif ($sourceType === 'internal_document') {
            $stmt = $pdo->prepare('SELECT d.document_no, d.document_date, d.partner_id, d.partner_name, d.title, d.journal_entry_id
                                     FROM internal_documents d WHERE d.supplier_id = ? AND d.id = ?');
            $stmt->execute([$supplierId, $sourceId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new AccrualException('source_not_found', 'Doklad neexistuje.', 404);
            $entryIds = $doc['journal_entry_id'] === null ? [] : [(int) $doc['journal_entry_id']];
        } else {
            throw new AccrualException('invalid_source', 'Neznámý druh zdrojového dokladu.', 422, 'source_type');
        }
        return [[
            'document_no' => $doc['document_no'] === null ? null : (string) $doc['document_no'],
            'document_date' => $doc['document_date'] === null ? null : (string) $doc['document_date'],
            'partner_id' => $doc['partner_id'] === null ? null : (int) $doc['partner_id'],
            'partner_name' => $doc['partner_name'] === null ? null : (string) $doc['partner_name'],
            'title' => $doc['title'] ?? null,
        ], $entryIds, $periods];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function sourceInfo(int $supplierId, array $item): ?array
    {
        if ($item['source_id'] === null || !in_array($item['source_type'], ['invoice', 'purchase_invoice', 'other_item', 'internal_document'], true)) {
            return $item['source_document_no'] === null ? null : ['type' => $item['source_type'], 'id' => null, 'document_no' => $item['source_document_no']];
        }
        try {
            [$doc] = $this->sourceDocument($supplierId, (string) $item['source_type'], (int) $item['source_id']);
        } catch (AccrualException) {
            return ['type' => $item['source_type'], 'id' => null, 'document_no' => $item['source_document_no']];
        }
        return ['type' => $item['source_type'], 'id' => (int) $item['source_id'], 'document_no' => $doc['document_no'] ?? $item['source_document_no']];
    }

    /**
     * Naváže zápis vzniku: částka na účtu položky v zápisu, nejvýš nenavázaná část jeho obratu.
     */
    public function linkOrigin(int $supplierId, int $itemId, int $entryId, string $accountCode, mixed $amount, ?int $userId): void
    {
        $accountId = $this->items->accountId($supplierId, $accountCode)
            ?? throw new AccrualException('account_not_found', 'Účet položky není v účtové osnově.', 422, 'accrual_account_code');
        $entry = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND id = ? AND posted_at IS NOT NULL AND reversed_by IS NULL');
        $entry->execute([$supplierId, $entryId]);
        if ($entry->fetchColumn() === false) {
            throw new AccrualException('entry_not_found', 'Zápis vzniku neexistuje nebo není zaúčtovaný.', 422, 'origin_entry_id');
        }
        $alloc = $this->items->entryAccountAllocation($supplierId, $entryId, $accountId, true);
        $free = round($alloc['turnover'] - $alloc['allocated'], 2);
        $want = $amount === null || $amount === '' ? $free : self::signedLike($free, self::amount($amount, 'origin_amount'));
        self::assertWithin($free, $want);
        $this->items->addLink($supplierId, $itemId, $entryId, $accountId, $want, 'origin', 'manual', null, $userId);
    }

    /** Ověří, že alokace nepřekročí nenavázanou část obratu ani neotočí znaménko. */
    public static function assertWithin(float $free, float $want): void
    {
        if ((int) round($want * 100) === 0 || (int) round($free * 100) === 0
            || ($free > 0) !== ($want > 0) || abs($want) - abs($free) > 0.004) {
            throw new AccrualException('allocation_exceeds', 'Částka překračuje nenavázaný obrat zápisu na účtu položky.', 422, 'amount');
        }
    }

    private static function signedLike(float $reference, float $amount): float
    {
        return $reference < 0 ? -abs($amount) : abs($amount);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function validated(int $supplierId, array $input, ?array $current): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new AccrualException('title_required', 'Vyplňte název položky.', 422, 'title');
        }
        $kind = (string) ($input['kind'] ?? '');
        if (!in_array($kind, AccrualKinds::SUPPORTED, true)) {
            throw new AccrualException('unsupported_kind', 'Agenda zatím rozpouští náklady (381) a výnosy (384) příštích období.', 422, 'kind');
        }
        $account = trim((string) ($input['accrual_account_code'] ?? ''));
        if (!str_starts_with($account, AccrualKinds::PREFIXES[$kind])) {
            throw new AccrualException('invalid_account', 'Účet položky musí být analytika účtu ' . AccrualKinds::PREFIXES[$kind] . '.', 422, 'accrual_account_code');
        }
        $this->assertAccount($supplierId, $account, 'accrual_account_code');
        $counter = self::nullableCode($input['counter_account_code'] ?? null);
        $termination = self::nullableCode($input['termination_account_code'] ?? null);
        foreach (['counter_account_code' => $counter, 'termination_account_code' => $termination] as $field => $code) {
            if ($code !== null) {
                if (str_starts_with($code, '38')) {
                    throw new AccrualException('invalid_counter_account', 'Protiúčet nesmí být účet časového rozlišení.', 422, $field);
                }
                $this->assertAccount($supplierId, $code, $field);
            }
        }
        $method = (string) ($input['method'] ?? 'monthly_days');
        if (!in_array($method, AccrualPlanBuilder::METHODS, true)) {
            throw new AccrualException('invalid_method', 'Neznámá metoda rozpouštění.', 422, 'method');
        }
        $from = self::dateOr($input['period_from'] ?? null, null, 'period_from');
        $to = self::dateOr($input['period_to'] ?? null, null, 'period_to');
        if ($method !== 'manual' && ($from === null || $to === null)) {
            throw new AccrualException('period_required', 'Vyplňte období rozpouštění od a do.', 422, $from === null ? 'period_from' : 'period_to');
        }
        if ($from !== null && $to !== null && $to < $from) {
            throw new AccrualException('invalid_period', 'Konec období je před jeho začátkem.', 422, 'period_to');
        }
        $basis = self::amount($input['basis_amount'] ?? null, 'basis_amount');
        $partnerId = isset($input['partner_id']) && $input['partner_id'] !== null && $input['partner_id'] !== '' ? (int) $input['partner_id'] : null;
        if ($partnerId !== null) {
            $p = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id = ? AND id = ?');
            $p->execute([$supplierId, $partnerId]);
            if ($p->fetchColumn() === false) {
                throw new AccrualException('partner_not_found', 'Partner neexistuje.', 422, 'partner_id');
            }
        }
        $sourceType = (string) ($input['source_type'] ?? ($current['source_type'] ?? 'manual'));
        if (!in_array($sourceType, AccrualKinds::SOURCE_TYPES, true)) {
            throw new AccrualException('invalid_source', 'Neznámý druh zdrojového dokladu.', 422, 'source_type');
        }
        $sourceId = isset($input['source_id']) && $input['source_id'] !== null && $input['source_id'] !== '' ? (int) $input['source_id'] : null;
        if ($current === null && $sourceId !== null && in_array($sourceType, ['invoice', 'purchase_invoice', 'other_item', 'internal_document'], true)) {
            $this->sourceDocument($supplierId, $sourceType, $sourceId);
        }
        $opening = isset($input['opening_balance']) && $input['opening_balance'] !== null && $input['opening_balance'] !== ''
            ? self::amount($input['opening_balance'], 'opening_balance') : null;
        $openingAsOf = self::dateOr($input['opening_as_of'] ?? null, null, 'opening_as_of');
        return [
            'title' => mb_substr($title, 0, 255),
            'contract_ref' => self::text($input['contract_ref'] ?? null, 100),
            'note' => self::text($input['note'] ?? null, 10000),
            'kind' => $kind,
            'accrual_account_code' => $account,
            'counter_account_code' => $counter,
            'termination_account_code' => $termination,
            'partner_id' => $partnerId,
            'partner_name' => self::text($input['partner_name'] ?? null, 190),
            'source_type' => $sourceType,
            'source_id' => $current === null ? $sourceId : $current['source_id'],
            'source_item_id' => $current === null
                ? (isset($input['source_item_id']) && ctype_digit((string) $input['source_item_id']) ? (int) $input['source_item_id'] : null)
                : $current['source_item_id'],
            'source_document_no' => $current === null ? self::text($input['source_document_no'] ?? null, 50) : $current['source_document_no'],
            'basis_amount' => $basis,
            'opening_balance' => $opening,
            'opening_as_of' => $openingAsOf,
            'period_from' => $from,
            'period_to' => $to,
            'method' => $method,
            'cost_center' => self::text($input['cost_center'] ?? null, 50),
            'project_id' => isset($input['project_id']) && ctype_digit((string) $input['project_id']) ? (int) $input['project_id'] : null,
        ];
    }

    private function assertAccount(int $supplierId, string $code, string $field): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_synthetic, is_active FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new AccrualException('account_not_found', 'Účet ' . $code . ' není v účtové osnově.', 422, $field);
        }
    }

    /** @param list<array<string,mixed>> $schedule */
    public static function hasAgendaPostings(array $schedule): bool
    {
        foreach ($schedule as $row) {
            if ($row['status'] === 'posted' && in_array($row['entry_source_type'] ?? null, ['accrual_release', 'accrual_termination'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $schedule */
    private static function hasLinked(array $schedule): bool
    {
        foreach ($schedule as $row) {
            if ($row['status'] === 'linked') {
                return true;
            }
        }
        return false;
    }

    private static function amount(mixed $value, string $field): float
    {
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], $value);
        }
        if (!is_numeric($value)) {
            throw new AccrualException('invalid_amount', 'Zadejte částku.', 422, $field);
        }
        $amount = round((float) $value, 2);
        if (abs($amount) >= 1e13 || (int) round($amount * 100) === 0) {
            throw new AccrualException('invalid_amount', 'Částka musí být nenulová.', 422, $field);
        }
        return $amount;
    }

    private static function requiredDate(mixed $value, string $field): string
    {
        return self::dateOr($value, null, $field) ?? throw new AccrualException('invalid_date', 'Zadejte platné datum.', 422, $field);
    }

    private static function dateOr(mixed $value, ?string $default, string $field): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $value = substr((string) $value, 0, 10);
        if (!IsoDate::isValid($value)) {
            throw new AccrualException('invalid_date', 'Neplatné datum.', 422, $field);
        }
        return $value;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function csv(mixed $value, array $allowed, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = array_values(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value)), 'strlen'));
        foreach ($values as $v) {
            if (!in_array($v, $allowed, true)) {
                throw new AccrualException('invalid_filter', 'Neplatná hodnota filtru.', 422, $field);
            }
        }
        return $values;
    }

    private static function nullableCode(mixed $value): ?string
    {
        $code = trim((string) ($value ?? ''));
        return $code === '' ? null : mb_substr($code, 0, 20);
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
