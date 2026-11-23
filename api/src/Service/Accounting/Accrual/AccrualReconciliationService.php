<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Odsouhlasení agendy Časové rozlišení s hlavní knihou po analytikách 381 až 385 k datu.
 *
 * Zůstatek účtu v hlavní knize se počítá jako v obratové předvaze: počáteční stav a pohyby
 * účetního období, do kterého datum patří, bez závěrkového zápisu. Rozdělí se na:
 *  - položky agendy (vazby položek na zaúčtované zápisy, {@see AccrualItemRepository::balanceSql()}),
 *  - odklad uzávěrky (zápisy kroku Časové rozlišení a jejich rozpuštění v otevření roku,
 *    které spravuje uzávěrka podle řádků dokladů s obdobím),
 *  - řádky bez položky (rozdíl). Ten se dá rozepsat na jednotlivé zápisy
 *    ({@see unallocated()}) a každý přiřadit k položce nebo z něj položku založit.
 *
 * Dotazy jdou po indexu `idx_jel_supplier_account (supplier_id, account_id)` jen nad účty 38x
 * firmy a po primárním klíči zápisu; vazby agendy po `idx_accrual_link_account`.
 */
final class AccrualReconciliationService
{
    /** Zápisy, kterými časové rozlišení spravuje uzávěrka (odklad i rozpuštění v openNext). */
    public const CLOSING_TYPES = ['prepaid_expense_accrual', 'deferred_revenue_accrual', 'small_asset_accrual'];

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualItemRepository $items,
    ) {}

    /** @return array<string,mixed> */
    public function reconcile(int $supplierId, ?string $asOf = null): array
    {
        $asOf = self::date($asOf);
        $start = $this->periodStart($supplierId, $asOf);
        $accounts = $this->accounts($supplierId);
        if ($accounts === []) {
            return ['as_of' => $asOf, 'period_starts_on' => $start, 'accounts' => [], 'totals' => self::zero()];
        }
        $ids = implode(',', array_keys($accounts));
        $gl = $this->sumByAccount($supplierId, $ids, $start, $asOf);
        $closing = $this->closingByAccount($supplierId, $ids, $asOf);
        $items = $this->itemsByAccount($supplierId, $asOf);
        $rows = [];
        $totals = self::zero();
        foreach ($accounts as $id => $acc) {
            $kind = AccrualKinds::kindForAccount($acc['account_code']);
            $sign = $kind === null ? 1 : AccrualKinds::normalSign($kind);
            $glBal = round(($gl[$id] ?? 0.0) * $sign, 2);
            $closingBal = round(($closing[$id] ?? 0.0) * $sign, 2);
            $itemsBal = round(($items[$id]['balance'] ?? 0.0) * $sign, 2);
            $diff = round($glBal - $closingBal - $itemsBal, 2);
            if ((int) round($glBal * 100) === 0 && (int) round($itemsBal * 100) === 0 && (int) round($closingBal * 100) === 0) {
                continue;
            }
            $rows[] = [
                'account_id' => $id,
                'account_code' => $acc['account_code'],
                'account_name' => $acc['name'],
                'kind' => $kind,
                'normal_side' => $sign === 1 ? 'debit' : 'credit',
                'ledger_balance' => $glBal,
                'items_balance' => $itemsBal,
                'items_count' => (int) ($items[$id]['count'] ?? 0),
                'closing_balance' => $closingBal,
                'difference' => $diff,
            ];
            foreach (['ledger_balance' => $glBal, 'items_balance' => $itemsBal, 'closing_balance' => $closingBal, 'difference' => $diff] as $k => $v) {
                $totals[$k] = round($totals[$k] + $v, 2);
            }
        }
        return ['as_of' => $asOf, 'period_starts_on' => $start, 'accounts' => $rows, 'totals' => $totals];
    }

    /**
     * Řádky 38x bez položky: zápisy období do data a jejich nenavázaná část. U počátečního
     * stavu období se za navázanou bere část, kterou z minulých let nesou položky agendy
     * a odklad uzávěrky. Stornovaný zápis i jeho storno se vzájemně ruší a nevypisují se.
     *
     * @return array<string,mixed>
     */
    public function unallocated(int $supplierId, ?string $asOf = null, ?string $accountCode = null, int $limit = 500): array
    {
        $asOf = self::date($asOf);
        $start = $this->periodStart($supplierId, $asOf);
        $accounts = $this->accounts($supplierId);
        if ($accountCode !== null && $accountCode !== '') {
            $accounts = array_filter($accounts, static fn (array $a): bool => str_starts_with($a['account_code'], $accountCode));
        }
        if ($accounts === []) {
            return ['as_of' => $asOf, 'rows' => [], 'total' => 0.0];
        }
        $ids = implode(',', array_keys($accounts));
        $closingTypes = "'" . implode("','", self::CLOSING_TYPES) . "'";
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, je.id AS entry_id, je.entry_date, je.document_no, je.description, je.source_type, je.source_id,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS turnover,
                    (SELECT COALESCE(SUM(al.amount), 0) FROM accrual_entry_links al
                      WHERE al.supplier_id = je.supplier_id AND al.journal_entry_id = je.id AND al.account_id = l.account_id) AS allocated
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
          LEFT JOIN journal_entries rv ON rv.id = je.reversed_by AND rv.supplier_id = je.supplier_id
          LEFT JOIN journal_entries orig ON orig.supplier_id = je.supplier_id AND orig.reversed_by = je.id
              WHERE l.supplier_id = ? AND l.account_id IN ({$ids})
                AND je.posted_at IS NOT NULL AND je.entry_date >= ? AND je.entry_date <= ?
                AND je.source_type <> 'closing' AND je.source_type NOT IN ({$closingTypes})
                AND NOT (je.reversed_by IS NOT NULL AND rv.entry_date <= ? AND rv.entry_date >= ?)
                AND NOT (orig.id IS NOT NULL AND orig.entry_date >= ? AND orig.entry_date <= ?)
              GROUP BY l.account_id, je.id, je.entry_date, je.document_no, je.description, je.source_type, je.source_id
              ORDER BY je.entry_date, je.id"
        );
        $stmt->execute([$supplierId, $start, $asOf, $asOf, $start, $start, $asOf]);
        $carried = $this->carriedIntoPeriod($supplierId, $ids, $start);
        $candidates = [];
        $netByAccount = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $accountId = (int) $r['account_id'];
            $free = round((float) $r['turnover'] - (float) $r['allocated'], 2);
            if ($r['source_type'] === 'opening' && (string) $r['entry_date'] === $start) {
                $free = round($free - ($carried[$accountId] ?? 0.0), 2);
            }
            if ((int) round($free * 100) === 0) {
                continue;
            }
            $r['free'] = $free;
            $candidates[] = $r;
            $netByAccount[$accountId] = round(($netByAccount[$accountId] ?? 0.0) + $free, 2);
        }
        $rows = [];
        $total = 0.0;
        foreach ($candidates as $r) {
            $accountId = (int) $r['account_id'];
            $free = (float) $r['free'];
            // Účet, jehož nenavázané řádky se k datu vzájemně vyrovnají (dohad a jeho
            // vypořádání), nemá rozdíl a jeho řádky by seznam jen zahlcovaly.
            if ((int) round($netByAccount[$accountId] * 100) === 0) {
                continue;
            }
            $acc = $accounts[$accountId];
            $kind = AccrualKinds::kindForAccount($acc['account_code']);
            $sign = $kind === null ? 1 : AccrualKinds::normalSign($kind);
            $total = round($total + $free * $sign, 2);
            if (count($rows) < $limit) {
                $rows[] = [
                    'entry_id' => (int) $r['entry_id'],
                    'entry_date' => (string) $r['entry_date'],
                    'document_no' => $r['document_no'],
                    'description' => $r['description'],
                    'source_type' => (string) $r['source_type'],
                    'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'],
                    'account_id' => $accountId,
                    'account_code' => $acc['account_code'],
                    'kind' => $kind,
                    'turnover' => round((float) $r['turnover'] * $sign, 2),
                    'allocated' => round((float) $r['allocated'] * $sign, 2),
                    'unallocated' => round($free * $sign, 2),
                    'unallocated_signed' => $free,
                ];
            }
        }
        return ['as_of' => $asOf, 'period_starts_on' => $start, 'rows' => $rows, 'total' => $total, 'truncated' => count($rows) >= $limit];
    }

    /**
     * Přiřadí nenavázanou část zápisu na účtu 38x k položce (role podle směru: zvýšení
     * zůstatku je vznik, snížení rozpuštění).
     *
     * @return array<string,mixed>
     */
    public function assign(int $supplierId, int $itemId, int $entryId, string $accountCode, mixed $amount, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $itemId, true) ?? throw new AccrualException('not_found', 'Položka časového rozlišení neexistuje.', 404);
            if ($item['status'] === 'cancelled') {
                throw new AccrualException('not_active', 'Ke zrušené položce nelze přiřadit zápis.', 409);
            }
            if ($accountCode !== $item['accrual_account_code']) {
                throw new AccrualException('account_mismatch', 'Zápis účtuje na jiný účet, než má položka.', 422, 'account_code');
            }
            $accountId = $this->items->accountId($supplierId, $accountCode) ?? throw new AccrualException('account_not_found', 'Účet neexistuje.', 422);
            $entry = $pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND id = ? AND posted_at IS NOT NULL AND reversed_by IS NULL');
            $entry->execute([$supplierId, $entryId]);
            if ($entry->fetchColumn() === false) {
                throw new AccrualException('entry_not_found', 'Zápis neexistuje nebo není zaúčtovaný.', 422, 'entry_id');
            }
            $alloc = $this->items->entryAccountAllocation($supplierId, $entryId, $accountId, true);
            $free = round($alloc['turnover'] - $alloc['allocated'], 2);
            $sign = AccrualKinds::normalSign((string) $item['kind']);
            $want = $amount === null || $amount === '' ? $free : round((float) str_replace(',', '.', (string) $amount) * $sign, 2);
            AccrualItemService::assertWithin($free, $want);
            $role = $want * $sign > 0 ? 'origin' : 'release';
            $this->items->addLink($supplierId, $itemId, $entryId, $accountId, $want, $role, 'manual', null, $userId);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['item_id' => $itemId, 'entry_id' => $entryId, 'amount' => round($want * $sign, 2), 'role' => $role];
    }

    /** @return array<int, array{account_code:string, name:string}> */
    private function accounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code >= '381' AND account_code < '386'
              ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['account_code' => (string) $r['account_code'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /** Zůstatek účtů (MD − D) jako v obratové předvaze: období data, bez závěrkového zápisu. @return array<int,float> */
    private function sumByAccount(int $supplierId, string $ids, string $start, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.account_id IN ({$ids})
                AND je.posted_at IS NOT NULL AND je.entry_date >= ? AND je.entry_date <= ? AND je.source_type <> 'closing'
              GROUP BY l.account_id"
        );
        $stmt->execute([$supplierId, $start, $asOf]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Kumulativní zůstatek zápisů odkladu uzávěrky (odklad − rozpuštění) k datu. @return array<int,float> */
    private function closingByAccount(int $supplierId, string $ids, string $asOf): array
    {
        $types = "'" . implode("','", self::CLOSING_TYPES) . "'";
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.account_id IN ({$ids})
                AND je.posted_at IS NOT NULL AND je.entry_date <= ? AND je.source_type IN ({$types})
              GROUP BY l.account_id"
        );
        $stmt->execute([$supplierId, $asOf]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<int, array{balance:float, count:int}> */
    private function itemsByAccount(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, SUM(l.amount) AS bal, COUNT(DISTINCT l.accrual_item_id) AS cnt
               FROM accrual_entry_links l
               JOIN accrual_items ai ON ai.id = l.accrual_item_id AND ai.supplier_id = l.supplier_id
               JOIN journal_entries je ON je.id = l.journal_entry_id AND je.supplier_id = l.supplier_id
          LEFT JOIN journal_entries rv ON rv.id = je.reversed_by AND rv.supplier_id = je.supplier_id
              WHERE l.supplier_id = ? AND ai.status <> 'cancelled'
                AND je.posted_at IS NOT NULL AND je.entry_date <= ?
                AND (je.reversed_by IS NULL OR rv.entry_date > ?)
              GROUP BY l.account_id"
        );
        $stmt->execute([$supplierId, $asOf, $asOf]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['account_id']] = ['balance' => (float) $r['bal'], 'count' => (int) $r['cnt']];
        }
        return $out;
    }

    /**
     * Část počátečního stavu období, kterou nesou položky agendy a odklad uzávěrky z dřívějška
     * (stav ke dni před začátkem období). @return array<int,float>
     */
    private function carriedIntoPeriod(int $supplierId, string $ids, string $start): array
    {
        $before = AccrualPlanBuilder::dayBefore($start);
        $out = [];
        foreach ($this->itemsByAccount($supplierId, $before) as $id => $r) {
            $out[$id] = $r['balance'];
        }
        foreach ($this->closingByAccount($supplierId, $ids, $before) as $id => $bal) {
            $out[$id] = round(($out[$id] ?? 0.0) + $bal, 2);
        }
        return $out;
    }

    private function periodStart(int $supplierId, string $asOf): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT starts_on FROM accounting_periods WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ? ORDER BY starts_on DESC LIMIT 1');
        $stmt->execute([$supplierId, $asOf, $asOf]);
        $start = $stmt->fetchColumn();
        return $start === false ? '1000-01-01' : (string) $start;
    }

    private static function date(?string $asOf): string
    {
        if ($asOf === null || $asOf === '') {
            return date('Y-m-d');
        }
        $asOf = substr($asOf, 0, 10);
        if (!IsoDate::isValid($asOf)) {
            throw new AccrualException('invalid_date', 'Neplatné datum.', 422, 'as_of');
        }
        return $asOf;
    }

    /** @return array<string,float> */
    private static function zero(): array
    {
        return ['ledger_balance' => 0.0, 'items_balance' => 0.0, 'closing_balance' => 0.0, 'difference' => 0.0];
    }
}
