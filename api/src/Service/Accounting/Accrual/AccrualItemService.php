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
        private readonly \MyInvoice\Service\Accounting\Closing\AccrualDeferralService $deferrals,
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
            'id' => isset($query['item_id']) && ctype_digit((string) $query['item_id']) ? (int) $query['item_id'] : null,
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
        $item['warnings'] = $this->warnings($supplierId, $item, $links);
        $item['can'] = [
            'edit' => in_array($item['status'], ['draft', 'active'], true),
            'activate' => $item['status'] === 'draft',
            'terminate' => $item['status'] === 'active' && $item['managed_by'] === 'agenda' && in_array($item['kind'], AccrualKinds::SUPPORTED, true),
            'cancel_termination' => $item['status'] === 'terminated' && $this->terminatedByAgenda($supplierId, $item),
            'cancel' => in_array($item['status'], ['draft', 'active'], true) && !self::hasAgendaPostings($schedule),
            'delete' => $item['status'] === 'draft' || ($item['status'] === 'cancelled' && !self::hasAgendaPostings($schedule)),
        ];
        return $item;
    }

    /**
     * Upozornění na možné dvojí odložení stejného dokladu agendou a uzávěrkou:
     *  - položka z interního dokladu, který není vazbou „Souvisí" spojený se zdrojovou fakturou
     *    (uzávěrka by doklad s obdobím od–do odložila znovu, agenda ho nevyřadí),
     *  - zápis vzniku patří faktuře, jejíž řádky mají období od–do a položka je nespravuje.
     *
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $links
     * @return list<array{code:string, message:string}>
     */
    private function warnings(int $supplierId, array $item, array $links): array
    {
        if ($item['managed_by'] !== 'agenda' || in_array($item['status'], ['cancelled', 'completed'], true)) {
            return [];
        }
        $out = [];
        if ($item['source_type'] === 'internal_document' && $item['source_id'] !== null) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM internal_documents d
                   JOIN journal_entry_document_links l ON l.supplier_id = d.supplier_id AND l.entry_id = d.journal_entry_id
                  WHERE d.supplier_id = ? AND d.id = ? AND l.doc_type IN ('invoice', 'purchase_invoice', 'other_item')"
            );
            $stmt->execute([$supplierId, (int) $item['source_id']]);
            if ((int) $stmt->fetchColumn() === 0) {
                $out[] = ['code' => 'internal_document_unlinked',
                    'message' => 'Interní doklad není vazbou „Souvisí“ spojený se zdrojovým dokladem. Má-li zdrojová faktura období od–do, uzávěrka ji odloží znovu.'];
            }
        }
        foreach ($links as $l) {
            if ($l['role'] !== 'origin' || !in_array($l['source_type'], ['invoice', 'purchase_invoice'], true) || $l['source_id'] === null) {
                continue;
            }
            if ($item['source_type'] === $l['source_type'] && (int) $item['source_id'] === (int) $l['source_id'] && $item['source_item_id'] !== null) {
                continue;
            }
            [$table, $fk] = $l['source_type'] === 'invoice' ? ['invoice_items', 'invoice_id'] : ['purchase_invoice_items', 'purchase_invoice_id'];
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$fk} = ? AND accrual_from IS NOT NULL AND accrual_to IS NOT NULL");
            $stmt->execute([(int) $l['source_id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $out[] = ['code' => 'origin_document_has_period_lines',
                    'message' => 'Zápis vzniku patří dokladu, jehož řádky mají období od–do. Zkontrolujte, že je neodkládá i uzávěrka.'];
                break;
            }
        }
        return $out;
    }

    /**
     * Ukončení zaúčtovala agenda (zrušit ho jde jen stornem jejího zápisu); ukončení
     * převzaté z POHODY je interní doklad a ruší se v deníku.
     *
     * @param array<string,mixed> $item
     */
    private function terminatedByAgenda(int $supplierId, array $item): bool
    {
        if ($item['termination_entry_id'] === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT source_type FROM journal_entries WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, (int) $item['termination_entry_id']]);
        return $stmt->fetchColumn() === 'accrual_termination';
    }

    /**
     * Náhled plánu bez uložení (editor, živý výpočet ze serveru).
     *
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function previewPlan(int $supplierId, array $input): array
    {
        $method = (string) ($input['method'] ?? 'monthly_days');
        if (!in_array($method, AccrualPlanBuilder::METHODS, true)) {
            throw new AccrualException('invalid_method', 'Neznámá metoda rozpouštění.', 422, 'method');
        }
        $basis = self::positiveBasis($input['basis_amount'] ?? null);
        $from = self::requiredDate($input['period_from'] ?? null, 'period_from');
        $to = self::requiredDate($input['period_to'] ?? null, 'period_to');
        if ($to < $from) {
            throw new AccrualException('invalid_period', 'Konec období je před jeho začátkem.', 422, 'period_to');
        }
        $covered = isset($input['opening_as_of']) && IsoDate::isValid((string) $input['opening_as_of'])
            ? AccrualPlanBuilder::dayBefore((string) $input['opening_as_of']) : null;
        $target = isset($input['opening_balance']) && $input['opening_balance'] !== null && $input['opening_balance'] !== ''
            ? self::amount($input['opening_balance'], 'opening_balance') : null;
        return AccrualPlanBuilder::build($method, $basis, $from, $to, $covered, $target, $this->fiscalYearEnd($supplierId));
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
                $handover = null;
                if (!empty($input['closing_handover'])) {
                    $handover = $this->closingHandover($supplierId, (string) ($input['source_type'] ?? ''), (int) ($input['source_id'] ?? 0),
                        isset($input['source_item_id']) && ctype_digit((string) $input['source_item_id']) ? (int) $input['source_item_id'] : null)
                        ?? throw new AccrualException('no_closing_deferral', 'Doklad nemá odklad zaúčtovaný uzávěrkou.', 422, 'closing_handover');
                    $input = array_merge($input, [
                        'accrual_account_code' => $handover['account_code'],
                        'opening_balance' => $handover['remaining'],
                        'opening_as_of' => $handover['opening_as_of'],
                        'origin_entry_id' => null,
                    ]);
                }
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
                if ($handover !== null) {
                    // Předání z uzávěrky: vznikem je odklad uzávěrky, rozpuštění z otevření roku se
                    // navážou. Odsouhlasení pak tyto zápisy nepočítá do odkladu uzávěrky.
                    $sign = AccrualKinds::normalSign((string) $data['kind']);
                    $accountId = (int) $this->items->accountId($supplierId, (string) $data['accrual_account_code']);
                    $this->items->addLink($supplierId, $id, $handover['deferral_entry_id'], $accountId, round($handover['deferred'] * $sign, 2), 'origin', 'manual', null, $userId);
                    foreach ($handover['releases'] as $r) {
                        $this->items->addLink($supplierId, $id, $r['entry_id'], $accountId, round(-$r['amount'] * $sign, 2), 'closing_release', 'manual', null, $userId);
                    }
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
            // Optimistický zámek je povinný: úprava bez verze by tiše přepsala změnu jiného uživatele.
            if (!isset($input['row_version']) || (int) $input['row_version'] !== (int) $item['row_version']) {
                throw new AccrualException('changed', 'Položku mezitím změnil jiný uživatel. Načtěte ji znovu.', 409, 'row_version');
            }
            if (!in_array($item['status'], ['draft', 'active'], true) || $item['managed_by'] !== 'agenda') {
                throw new AccrualException('not_editable', 'Ukončenou, dokončenou nebo zrušenou položku nelze upravit.', 409);
            }
            $merged = array_merge($item, array_intersect_key($input, array_flip([...self::EDITABLE_ALWAYS, ...self::EDITABLE_PLAN, 'kind', 'accrual_account_code', 'opening_balance', 'opening_as_of'])));
            $data = $this->validated($supplierId, $merged, $item);
            // Vazby (vznik, rozpuštění, převzaté zápisy) leží na účtu položky. Změna druhu nebo
            // účtu by je nechala na starém účtu a knihy by se rozešly s položkou, aniž by to
            // odsouhlasení poznalo; položku je pak třeba zrušit a založit znovu.
            if ($this->items->links($supplierId, $id) !== [] || self::hasAgendaPostings($this->items->schedule($supplierId, $id))) {
                foreach (['kind', 'accrual_account_code'] as $locked) {
                    if ((string) $data[$locked] !== (string) $item[$locked]) {
                        throw new AccrualException('has_links', 'Druh a účet položky s navázanými zápisy nelze změnit.', 409, $locked);
                    }
                }
            }
            $data['updated_by'] = $userId;
            $this->items->update($supplierId, $id, $data);
            $planChanged = false;
            foreach ([...self::EDITABLE_PLAN, 'opening_balance', 'opening_as_of'] as $field) {
                if ((string) ($data[$field] ?? '') !== (string) ($item[$field] ?? '')) {
                    $planChanged = true;
                }
            }
            if ($item['status'] === 'active' && $planChanged) {
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
        // Položka s plánem musí mít v deníku zůstatek, který rozpouští (zápis vzniku, převzatý
        // nebo předaný zůstatek). Bez něj by nešlo zaúčtovat žádný řádek, a přitom by plán
        // blokoval uzavření roku a vstupoval do projekce DPPO i průběžného výkazu.
        if ($item['method'] !== 'manual' && !$this->hasOriginLink($supplierId, $id)) {
            throw new AccrualException('origin_required', 'Před aktivací navažte zápis vzniku (zápis na účtu položky), ze kterého se rozpouští.', 422, 'origin_entry_id');
        }
        $this->items->update($supplierId, $id, ['status' => 'active', 'updated_by' => $userId]);
        $this->rebuildPlan($supplierId, $id);
    }

    /** Má položka živou vazbu vzniku (zaúčtovaný, nestornovaný zápis)? */
    public function hasOriginLink(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM accrual_entry_links al
               JOIN journal_entries je ON je.id = al.journal_entry_id AND je.supplier_id = al.supplier_id
              WHERE al.supplier_id = ? AND al.accrual_item_id = ? AND al.role = 'origin'
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL LIMIT 1"
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    /** Zruší položku bez zaúčtovaného rozpouštění agendy; vazby na zápisy se uvolní. */
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
            // Zrušená položka nenese nic: vazby na zápisy (vznik, převzaté rozpuštění) se uvolní
            // a zápisy se v odsouhlasení objeví mezi řádky bez položky.
            $pdo->prepare('DELETE FROM accrual_entry_links WHERE supplier_id = ? AND accrual_item_id = ?')->execute([$supplierId, $id]);
            $pdo->prepare('DELETE FROM accrual_schedule WHERE supplier_id = ? AND accrual_item_id = ?')->execute([$supplierId, $id]);
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
     * Přepočte nezaúčtované řádky plánu. Plán se sestaví od začátku období (u převzaté položky
     * ode dne převzatého zůstatku); řádky, které už pokrývá zaúčtovaný nebo navázaný zápis, se
     * vynechají, nezaúčtované řádky před nimi zůstanou na svém místě. Cílem je zbývající
     * zůstatek položky podle vazeb (vznik − všechna rozpuštění včetně ručně přiřazených), bez
     * vazby vzniku převzatý zůstatek nebo základ. Rozdíl se rozloží do zbývajících řádků
     * poměrem jejich částek ({@see AccrualPlanBuilder::spreadTo()}).
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
        $start = $item['opening_as_of'] !== null ? AccrualPlanBuilder::dayBefore((string) $item['opening_as_of']) : null;
        $rows = AccrualPlanBuilder::build((string) $item['method'], (float) $item['basis_amount'],
            (string) $item['period_from'], (string) $item['period_to'], $start, null, $this->fiscalYearEnd($supplierId));
        $done = [];
        foreach ($schedule as $row) {
            if ($row['kind'] === 'release' && in_array($row['status'], ['posted', 'linked'], true)) {
                $done[] = $row;
            }
        }
        $rows = array_values(array_filter($rows, static function (array $r) use ($done): bool {
            foreach ($done as $d) {
                if ($d['due_on'] === $r['due_on']
                    || ((string) ($d['from_on'] ?? $d['due_on']) <= $r['from_on'] && (string) ($d['to_on'] ?? $d['due_on']) >= $r['to_on'])) {
                    return false;
                }
            }
            return true;
        }));
        $target = $this->remainingTarget($supplierId, $item);
        $rows = (int) round($target * 100) > 0 ? AccrualPlanBuilder::spreadTo($rows, $target) : [];
        $rows = $this->mergeClosedPeriodRows($supplierId, $rows);
        $this->items->replacePlanned($supplierId, $id, array_values(array_filter($rows,
            static fn (array $r): bool => (int) round($r['amount_planned'] * 100) !== 0)));
    }

    /**
     * Řádky splatné v uzavřeném účetním období už nikdo nezaúčtuje (uzavřené období zápis
     * nepřijme). Jejich částka se proto přičte k prvnímu řádku v neuzavřeném období a ten
     * pokryje i jejich dny: položka založená po uzavření roku, jehož část už uplynula, rozpustí
     * dlužnou část prvním otevřeným rozpuštěním a neblokuje plán ani uzavření dalšího roku.
     * Řádky v období, které se uzavírá, zůstávají: zaúčtuje je krok uzávěrky.
     * Řádky v uzamčeném datu otevřeného období řeší až zaúčtování
     * ({@see AccrualPostingService::prepareRelease()}): zámek se může posunout a uzavíraný rok
     * je musí mít ve svém období.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function mergeClosedPeriodRows(int $supplierId, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $stmt = $this->db->pdo()->prepare('SELECT starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on');
        $stmt->execute([$supplierId]);
        $closed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (\MyInvoice\Service\Accounting\AccountingPeriodStatus::isClosed((string) $p['status'])) {
                $closed[] = [(string) $p['starts_on'], (string) $p['ends_on']];
            }
        }
        if ($closed === []) {
            return $rows;
        }
        $inClosed = static function (string $date) use ($closed): bool {
            foreach ($closed as [$from, $to]) {
                if ($date >= $from && $date <= $to) {
                    return true;
                }
            }
            return false;
        };
        $out = [];
        $carry = 0.0;
        $carryFrom = null;
        $carryDays = 0;
        foreach ($rows as $row) {
            if ($inClosed((string) $row['due_on'])) {
                $carry = round($carry + (float) $row['amount_planned'], 2);
                $carryFrom ??= (string) $row['from_on'];
                $carryDays += (int) ($row['days'] ?? 0);
                continue;
            }
            if ($carryFrom !== null) {
                $row['amount_planned'] = round((float) $row['amount_planned'] + $carry, 2);
                $row['from_on'] = $carryFrom;
                $row['days'] = (int) ($row['days'] ?? 0) + $carryDays;
                $carry = 0.0;
                $carryFrom = null;
                $carryDays = 0;
            }
            $out[] = $row;
        }
        if ($carryFrom !== null) {
            // Celý zbytek plánu leží v uzavřených obdobích: jeden řádek prvním dnem po nich.
            $due = AccrualPlanBuilder::dayAfter(max(array_map(static fn (array $c): string => $c[1], $closed)));
            $out[] = ['kind' => 'release', 'due_on' => $due, 'from_on' => $carryFrom, 'to_on' => $due, 'days' => $carryDays, 'amount_planned' => $carry];
        }
        return $out;
    }

    /**
     * Částka, kterou má zbývající plán rozpustit (normální znaménko): součet živých vazeb
     * položky; bez vazby vzniku převzatý zůstatek nebo základ snížený o navázaná rozpuštění.
     *
     * @param array<string,mixed> $item
     */
    public function remainingTarget(int $supplierId, array $item): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN al.role = 'origin' THEN al.amount ELSE 0 END), 0) AS origin,
                    COALESCE(SUM(CASE WHEN al.role = 'origin' THEN 0 ELSE al.amount END), 0) AS other,
                    COUNT(CASE WHEN al.role = 'origin' THEN 1 END) AS origins
               FROM accrual_entry_links al
               JOIN journal_entries je ON je.id = al.journal_entry_id AND je.supplier_id = al.supplier_id
              WHERE al.supplier_id = ? AND al.accrual_item_id = ? AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL"
        );
        $stmt->execute([$supplierId, (int) $item['id']]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['origin' => 0, 'other' => 0, 'origins' => 0];
        $sign = AccrualKinds::normalSign((string) $item['kind']);
        $base = (int) $r['origins'] > 0 ? (float) $r['origin'] * $sign : (float) ($item['opening_balance'] ?? $item['basis_amount']);
        return round($base + (float) $r['other'] * $sign, 2);
    }

    /**
     * Konec účetního roku firmy (MM-DD) podle posledního účetního období; bez období 12-31.
     * Roční rozpouštění se účtuje ke konci účetního roku, u hospodářského roku tedy ne k 31. 12.
     */
    public function fiscalYearEnd(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ends_on FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on DESC LIMIT 1');
        $stmt->execute([$supplierId]);
        $end = $stmt->fetchColumn();
        return $end === false ? '12-31' : substr((string) $end, 5, 5);
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
            'closing_deferrals' => $this->closingHandoverOptions($supplierId, $sourceType, $sourceId, $periods),
        ];
    }

    /**
     * Řádky dokladu, které odložila uzávěrka: nabídka předání do agendy se zůstatkem k datu.
     *
     * @param list<array{item_id:?int, from:string, to:string}> $periods
     * @return list<array<string,mixed>>
     */
    private function closingHandoverOptions(int $supplierId, string $sourceType, int $sourceId, array $periods): array
    {
        $out = [];
        foreach ($periods === [] ? [['item_id' => null]] : $periods as $p) {
            $h = $this->closingHandover($supplierId, $sourceType, $sourceId, $p['item_id'] ?? null);
            if ($h !== null) {
                $out[] = $h + ['source_item_id' => $p['item_id'] ?? null];
            }
        }
        return $out;
    }

    /**
     * Odklad řádku dokladu zaúčtovaný uzávěrkou a jeho dosavadní rozpouštění v otevření roku
     * (ze záznamu kroku, stejné částky jako uzávěrka): zápis odkladu, účet, odložená částka,
     * rozpuštění po letech, zbývající zůstatek a den, od kterého ho rozpouští agenda.
     *
     * @return array<string,mixed>|null
     */
    public function closingHandover(int $supplierId, string $sourceType, int $sourceId, ?int $sourceItemId): ?array
    {
        $type = match ($sourceType) {
            'purchase_invoice' => 'prepaid_expense_accrual',
            'invoice' => 'deferred_revenue_accrual',
            default => null,
        };
        if ($type === null || $sourceId <= 0) {
            return null;
        }
        $snapshots = $this->deferrals->closingDeferralSnapshots($supplierId, $type, '9999-12-31');
        $periods = $this->db->pdo()->prepare('SELECT id, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on');
        $periods->execute([$supplierId]);
        $list = $periods->fetchAll(PDO::FETCH_ASSOC);
        $index = [];
        foreach ($list as $i => $p) {
            $index[(int) $p['id']] = $i;
        }
        $matched = array_values(array_filter($snapshots['items'], static fn (array $it): bool =>
            !isset($it['source']) && (int) $it['doc_id'] === $sourceId && ($sourceItemId === null || (int) $it['item_id'] === $sourceItemId)));
        if ($matched === [] || !isset($index[(int) $matched[0]['period_id']])) {
            return null;
        }
        $periodId = (int) $matched[0]['period_id'];
        $endsOn = (string) $list[$index[$periodId]]['ends_on'];
        $entry = $this->db->pdo()->prepare("SELECT je.id, ca.account_code FROM journal_entries je
                                              JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
                                              JOIN chart_of_accounts ca ON ca.id = l.account_id
                                             WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.posted_at IS NOT NULL
                                               AND je.reversed_by IS NULL AND ca.account_code >= '381' AND ca.account_code < '386' LIMIT 1");
        $entry->execute([$supplierId, $type, $periodId]);
        $e = $entry->fetch(PDO::FETCH_ASSOC);
        if ($e === false) {
            return null;
        }
        $deferred = 0.0;
        $basis = 0.0;
        $from = null;
        $to = null;
        foreach ($matched as $it) {
            if ((int) $it['period_id'] !== $periodId) {
                continue;
            }
            $deferred = round($deferred + AccrualScheduleCalculator::deferredAmount((float) $it['basis'], (string) $it['from'], (string) $it['to'], $endsOn), 2);
            $basis = round($basis + (float) $it['basis'], 2);
            $from = $from === null || $it['from'] < $from ? (string) $it['from'] : $from;
            $to = $to === null || $it['to'] > $to ? (string) $it['to'] : $to;
        }
        // Rozpuštění v otevření dalších let: zápis uvolnění z období k, tranše ke konci období k+1.
        $rel = $this->db->pdo()->prepare('SELECT source_id, id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND posted_at IS NOT NULL AND reversed_by IS NULL');
        $rel->execute([$supplierId, $type]);
        $postedBySource = $rel->fetchAll(PDO::FETCH_KEY_PAIR);
        $releases = [];
        $covered = $endsOn;
        for ($k = $index[$periodId]; $k + 1 < count($list); $k++) {
            $releaseId = $type === 'prepaid_expense_accrual'
                ? \MyInvoice\Service\Accounting\Closing\ClosingSourceId::prepaidExpenseAccrualRelease((int) $list[$k]['id'])
                : \MyInvoice\Service\Accounting\Closing\ClosingSourceId::deferredRevenueAccrualRelease((int) $list[$k]['id']);
            if (!isset($postedBySource[$releaseId])) {
                break;
            }
            $upper = (string) $list[$k + 1]['ends_on'];
            $amount = 0.0;
            foreach ($matched as $it) {
                if ((int) $it['period_id'] === $periodId) {
                    $amount = round($amount + AccrualScheduleCalculator::recognizedBetween((float) $it['basis'], (string) $it['from'], (string) $it['to'], (string) $list[$k]['ends_on'], $upper), 2);
                }
            }
            if ((int) round($amount * 100) !== 0) {
                $releases[] = ['entry_id' => (int) $postedBySource[$releaseId], 'amount' => $amount];
            }
            $covered = $upper;
        }
        $released = round(array_sum(array_column($releases, 'amount')), 2);
        return [
            'deferral_entry_id' => (int) $e['id'],
            'account_code' => (string) $e['account_code'],
            'period_ends_on' => $endsOn,
            'basis' => $basis,
            'period_from' => $from,
            'period_to' => $to,
            'deferred' => $deferred,
            'releases' => $releases,
            'released' => $released,
            'remaining' => round($deferred - $released, 2),
            'opening_as_of' => AccrualPlanBuilder::dayAfter($covered),
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
            $p = $pdo->prepare("SELECT it.id, it.accrual_from, it.accrual_to, it.total_without_vat, it.description FROM {$itemTable} it JOIN " . ($sourceType === 'invoice' ? 'invoices' : 'purchase_invoices')
                . " d ON d.id = it.{$fk} WHERE d.supplier_id = ? AND it.{$fk} = ? AND it.accrual_from IS NOT NULL AND it.accrual_to IS NOT NULL");
            $p->execute([$supplierId, $sourceId]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $periods[] = ['item_id' => (int) $r['id'], 'from' => (string) $r['accrual_from'], 'to' => (string) $r['accrual_to'],
                    'amount' => round((float) $r['total_without_vat'], 2), 'description' => mb_substr((string) ($r['description'] ?? ''), 0, 120)];
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
        $basis = self::positiveBasis($input['basis_amount'] ?? null);
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
        $sourceItemId = $current !== null ? $current['source_item_id']
            : (isset($input['source_item_id']) && ctype_digit((string) $input['source_item_id']) ? (int) $input['source_item_id'] : null);
        if ($current === null && $sourceItemId === 0) {
            // Výslovně žádný řádek: položka nespravuje řádek s obdobím, uzávěrka je odkládá dál.
            $sourceItemId = null;
        } elseif ($current === null && $sourceId !== null && in_array($sourceType, ['invoice', 'purchase_invoice'], true)) {
            $sourceItemId = $this->resolveSourceItem($supplierId, $sourceType, $sourceId, $sourceItemId, $basis, $from, $to);
        }
        $projectId = isset($input['project_id']) && ctype_digit((string) $input['project_id']) ? (int) $input['project_id'] : null;
        if ($projectId !== null) {
            $p = $this->db->pdo()->prepare('SELECT p.id FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ? AND p.id = ?');
            $p->execute([$supplierId, $projectId]);
            if ($p->fetchColumn() === false) {
                throw new AccrualException('project_not_found', 'Projekt neexistuje.', 422, 'project_id');
            }
        }
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
            'source_item_id' => $sourceItemId,
            'source_document_no' => $current === null ? self::text($input['source_document_no'] ?? null, 50) : $current['source_document_no'],
            'basis_amount' => $basis,
            'opening_balance' => $opening,
            'opening_as_of' => $openingAsOf,
            'period_from' => $from,
            'period_to' => $to,
            'method' => $method,
            'cost_center' => self::text($input['cost_center'] ?? null, 50),
            'project_id' => $projectId,
        ];
    }

    /**
     * Řádek zdrojové faktury, který položka spravuje. Uzávěrka vyřadí z odkladu jen tento
     * řádek ({@see AccrualAgendaGuard}), ostatní řádky s obdobím dál odkládá sama. Bez
     * zadaného řádku se dohledá řádek s obdobím, který se s položkou shoduje částkou
     * a obdobím, částkou nebo obdobím. Žádná shoda = položka nespravuje žádný řádek
     * s obdobím; víc shod vyžaduje výběr řádku.
     */
    private function resolveSourceItem(int $supplierId, string $sourceType, int $sourceId, ?int $itemId, float $basis, ?string $from, ?string $to): ?int
    {
        [$table, $fk, $doc] = $sourceType === 'invoice'
            ? ['invoice_items', 'invoice_id', 'invoices']
            : ['purchase_invoice_items', 'purchase_invoice_id', 'purchase_invoices'];
        $stmt = $this->db->pdo()->prepare("SELECT it.id, it.accrual_from, it.accrual_to, it.total_without_vat
                                             FROM {$table} it JOIN {$doc} d ON d.id = it.{$fk}
                                            WHERE d.supplier_id = ? AND it.{$fk} = ?");
        $stmt->execute([$supplierId, $sourceId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($itemId !== null) {
            foreach ($lines as $l) {
                if ((int) $l['id'] === $itemId) {
                    return $itemId;
                }
            }
            throw new AccrualException('source_item_not_found', 'Řádek nepatří ke zdrojovému dokladu.', 422, 'source_item_id');
        }
        $period = array_values(array_filter($lines, static fn (array $l): bool => $l['accrual_from'] !== null && $l['accrual_to'] !== null));
        if ($period === []) {
            return null;
        }
        // I jediný řádek s obdobím musí položce odpovídat částkou nebo obdobím. Jinak položka
        // spravuje něco jiného (řádek bez období, část dokladu) a řádek s obdobím dál odkládá
        // uzávěrka.
        $samePeriod = static fn (array $l): bool => (string) $l['accrual_from'] === (string) $from && (string) $l['accrual_to'] === (string) $to;
        $sameAmount = static fn (array $l): bool => abs((float) $l['total_without_vat'] - $basis) < 0.005;
        foreach ([
            static fn (array $l): bool => $samePeriod($l) && $sameAmount($l),
            $sameAmount,
            $samePeriod,
        ] as $match) {
            $found = array_values(array_filter($period, $match));
            if (count($found) === 1) {
                return (int) $found[0]['id'];
            }
            if (count($found) > 1) {
                throw new AccrualException('source_item_required', 'Doklad má víc odpovídajících řádků s obdobím. Vyberte řádek, který položka spravuje.', 422, 'source_item_id');
            }
        }
        return null;
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

    /**
     * Základ položky je kladný. Dobropis k rozlišenému dokladu snižuje zůstatek: zadejte ho
     * jako přiřazený zápis k původní položce (plán se přepočte na zbývající zůstatek), nebo
     * jako samostatnou položku opačného druhu.
     */
    private static function positiveBasis(mixed $value): float
    {
        $basis = self::amount($value, 'basis_amount');
        if ($basis < 0) {
            throw new AccrualException('negative_basis',
                'Základ musí být kladný. Dobropis přiřaďte k původní položce jako zápis, nebo ho zadejte jako položku opačného druhu.', 422, 'basis_amount');
        }
        return $basis;
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
