<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Úložiště agendy Časové rozlišení: položky (`accrual_items`), plán (`accrual_schedule`)
 * a vazby na zápisy deníku (`accrual_entry_links`). Každý dotaz začíná firmou.
 *
 * Zůstatek položky se neukládá. {@see balanceSql()} ho skládá z vazeb na zaúčtované zápisy
 * k datu: zápis se počítá od svého data, stornovaný zápis jen do data svého storna.
 */
final class AccrualItemRepository
{
    public const SORTABLE = [
        'item_no' => 'ai.item_no',
        'title' => 'ai.title',
        'partner_name' => 'ai.partner_name',
        'accrual_account_code' => 'ai.accrual_account_code',
        'period_from' => 'ai.period_from',
        'period_to' => 'ai.period_to',
        'basis_amount' => 'ai.basis_amount',
        'balance' => 'balance_signed',
        'next_due_on' => 'next_due_on',
        'status' => 'ai.status',
        'created_at' => 'ai.created_at',
    ];

    private const ITEM_COLUMNS = [
        'item_no', 'title', 'contract_ref', 'note', 'kind', 'accrual_account_code', 'counter_account_code',
        'termination_account_code', 'partner_id', 'partner_name', 'source_type', 'source_id', 'source_item_id',
        'source_document_no', 'managed_by', 'basis_amount', 'opening_balance', 'opening_as_of', 'period_from',
        'period_to', 'method', 'status', 'terminated_on', 'termination_entry_id', 'termination_reason',
        'cost_center', 'project_id', 'dimensions_json', 'import_key', 'created_by', 'updated_by',
    ];

    public function __construct(private readonly Connection $db) {}

    public function pdo(): PDO
    {
        return $this->db->pdo();
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accrual_items WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castItem($row);
    }

    /** @return array<string,mixed>|null */
    public function findByImportKey(int $supplierId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accrual_items WHERE supplier_id = ? AND import_key = ?');
        $stmt->execute([$supplierId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castItem($row);
    }

    /**
     * Seznam položek se zůstatkem k datu, stránkovaný.
     *
     * @param array<string,mixed> $filters
     * @return array{items: list<array<string,mixed>>, total:int, totals: array<string,float>}
     */
    public function list(int $supplierId, array $filters, string $asOf, int $page, int $perPage, string $sortBy, string $sortDir): array
    {
        [$where, $params] = self::where($supplierId, $filters);
        $balance = self::balanceSql();
        $base = "FROM accrual_items ai
                 LEFT JOIN ({$balance}) b ON b.accrual_item_id = ai.id
                 LEFT JOIN (SELECT s.accrual_item_id, MIN(s.due_on) AS next_due_on
                              FROM accrual_schedule s
                             WHERE s.supplier_id = ? AND s.kind = 'release' AND s.status = 'planned'
                             GROUP BY s.accrual_item_id) nx ON nx.accrual_item_id = ai.id
                 WHERE {$where}";
        $baseParams = [$supplierId, $asOf, $asOf, $supplierId, ...$params];
        if (!empty($filters['with_balance'])) {
            $base .= ' AND COALESCE(b.bal, 0) <> 0';
        }
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) AS n,
                       COALESCE(SUM(COALESCE(b.bal, 0) * (CASE WHEN ai.kind IN ('deferred_revenue','accrued_expense') THEN -1 ELSE 1 END)), 0) AS balance,
                       COALESCE(SUM(ai.basis_amount), 0) AS basis
                  {$base}");
        $count->execute($baseParams);
        $agg = $count->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'balance' => 0, 'basis' => 0];
        $order = (self::SORTABLE[$sortBy] ?? 'ai.item_no') . ' ' . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ', ai.id DESC';
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare("SELECT ai.*, COALESCE(b.bal, 0) AS balance_raw, COALESCE(b.released, 0) AS released_raw,
                       COALESCE(b.bal, 0) * (CASE WHEN ai.kind IN ('deferred_revenue','accrued_expense') THEN -1 ELSE 1 END) AS balance_signed,
                       nx.next_due_on
                  {$base}
                 ORDER BY {$order}
                 LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset);
        $stmt->execute($baseParams);
        $items = array_map(static fn (array $r): array => self::castItem($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [
            'items' => $items,
            'total' => (int) $agg['n'],
            'totals' => ['basis_amount' => round((float) $agg['basis'], 2), 'balance' => round((float) $agg['balance'], 2)],
        ];
    }

    /**
     * Zůstatky (MD − D na účtu položky) a rozpuštěné části k datu pro vybrané položky.
     *
     * @param list<int> $ids
     * @return array<int, array{balance:float, released:float}>
     */
    public function balances(int $supplierId, array $ids, string $asOf): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $stmt = $this->db->pdo()->prepare('SELECT b.* FROM (' . self::balanceSql() . ") b WHERE b.accrual_item_id IN ({$in})");
        $stmt->execute([$supplierId, $asOf, $asOf]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['accrual_item_id']] = ['balance' => round((float) $r['bal'], 2), 'released' => round((float) $r['released'], 2)];
        }
        return $out;
    }

    /**
     * Agregace vazeb na živé zápisy k datu po položkách. Parametry: firma, datum, datum.
     * Rozpouštění (`release`, `termination`, `closing_release`) se sčítá zvlášť.
     */
    public static function balanceSql(): string
    {
        return "SELECT l.accrual_item_id,
                       SUM(l.amount) AS bal,
                       SUM(CASE WHEN l.role IN ('release', 'termination', 'closing_release') THEN l.amount ELSE 0 END) AS released
                  FROM accrual_entry_links l
                  JOIN journal_entries je ON je.id = l.journal_entry_id AND je.supplier_id = l.supplier_id
             LEFT JOIN journal_entries rv ON rv.id = je.reversed_by AND rv.supplier_id = je.supplier_id
                 WHERE l.supplier_id = ?
                   AND je.posted_at IS NOT NULL
                   AND je.entry_date <= ?
                   AND (je.reversed_by IS NULL OR rv.entry_date > ?)
                 GROUP BY l.accrual_item_id";
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private static function where(int $supplierId, array $filters): array
    {
        $where = ['ai.supplier_id = ?'];
        $params = [$supplierId];
        foreach (['status', 'kind', 'method', 'source_type', 'managed_by'] as $key) {
            $values = $filters[$key] ?? [];
            if ($values !== []) {
                $where[] = "ai.{$key} IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            }
        }
        if (($filters['account'] ?? '') !== '') {
            $where[] = 'ai.accrual_account_code LIKE ?';
            $params[] = addcslashes((string) $filters['account'], '%_\\') . '%';
        }
        if (($filters['partner_id'] ?? null) !== null) {
            $where[] = 'ai.partner_id = ?';
            $params[] = (int) $filters['partner_id'];
        }
        if (($filters['ends_by'] ?? null) !== null) {
            $where[] = 'ai.period_to <= ?';
            $params[] = $filters['ends_by'];
        }
        if (($filters['active_on'] ?? null) !== null) {
            $where[] = '(ai.period_from IS NULL OR ai.period_from <= ?) AND (ai.period_to IS NULL OR ai.period_to >= ?)';
            array_push($params, $filters['active_on'], $filters['active_on']);
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $where[] = '(ai.item_no LIKE ? OR ai.title LIKE ? OR ai.contract_ref LIKE ? OR ai.partner_name LIKE ? OR ai.source_document_no LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string,mixed> $data */
    public function insert(int $supplierId, array $data): int
    {
        $cols = array_values(array_filter(self::ITEM_COLUMNS, static fn (string $c): bool => array_key_exists($c, $data)));
        $sql = 'INSERT INTO accrual_items (supplier_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...array_map(static fn (string $c): mixed => $data[$c], $cols)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $supplierId, int $id, array $data): void
    {
        $cols = array_values(array_filter(self::ITEM_COLUMNS, static fn (string $c): bool => array_key_exists($c, $data)));
        if ($cols === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $cols));
        $stmt = $this->db->pdo()->prepare("UPDATE accrual_items SET {$set}, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?");
        $stmt->execute([...array_map(static fn (string $c): mixed => $data[$c], $cols), $supplierId, $id]);
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM accrual_items WHERE supplier_id = ? AND id = ?')->execute([$supplierId, $id]);
    }

    /**
     * Další číslo položky `CR-RRRR-NNNN`. Převzaté položky nesou čísla POHODY (`24CR0001`),
     * vlastní řada se s nimi nepotká. Volá se v transakci, souběh zachytí unikátní klíč.
     */
    public function nextItemNo(int $supplierId, int $year): string
    {
        $prefix = 'CR-' . $year . '-';
        $stmt = $this->db->pdo()->prepare('SELECT item_no FROM accrual_items WHERE supplier_id = ? AND item_no LIKE ? ORDER BY item_no DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$supplierId, $prefix . '%']);
        $last = $stmt->fetchColumn();
        $n = $last === false ? 0 : (int) substr((string) $last, strlen($prefix));
        return $prefix . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    // ── plán ──────────────────────────────────────────────────────────────────

    /**
     * Řádky plánu s efektivním stavem: zaúčtovaný či navázaný řádek, jehož zápis zmizel nebo
     * je stornovaný, je zase nezaúčtovaný (`planned`).
     *
     * @return list<array<string,mixed>>
     */
    public function schedule(int $supplierId, int $itemId, bool $lock = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.*, je.entry_date, je.document_no AS entry_document_no, je.source_type AS entry_source_type,
                    je.reversed_by AS entry_reversed_by,
                    CASE WHEN s.status IN ('posted', 'linked') AND (je.id IS NULL OR je.reversed_by IS NOT NULL OR je.posted_at IS NULL)
                         THEN 'planned' ELSE s.status END AS effective_status
               FROM accrual_schedule s
          LEFT JOIN journal_entries je ON je.id = s.journal_entry_id AND je.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND s.accrual_item_id = ?
              ORDER BY s.due_on, FIELD(s.kind, 'release', 'adjustment', 'termination'), s.id" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['accrual_item_id'] = (int) $r['accrual_item_id'];
            $r['amount_planned'] = round((float) $r['amount_planned'], 2);
            $r['days'] = $r['days'] === null ? null : (int) $r['days'];
            $r['journal_entry_id'] = $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'];
            $r['entry_reversed_by'] = $r['entry_reversed_by'] === null ? null : (int) $r['entry_reversed_by'];
            $r['stored_status'] = $r['status'];
            $r['status'] = $r['effective_status'];
            unset($r['effective_status'], $r['supplier_id']);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function scheduleRow(int $supplierId, int $rowId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT accrual_item_id FROM accrual_schedule WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $rowId]);
        $itemId = $stmt->fetchColumn();
        if ($itemId === false) {
            return null;
        }
        foreach ($this->schedule($supplierId, (int) $itemId, $lock) as $row) {
            if ($row['id'] === $rowId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Nahradí nezaúčtované řádky rozpouštění novým plánem; zaúčtované a navázané zůstávají.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function replacePlanned(int $supplierId, int $itemId, array $rows): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE s FROM accrual_schedule s
          LEFT JOIN journal_entries je ON je.id = s.journal_entry_id AND je.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND s.accrual_item_id = ? AND s.kind = 'release'
                AND (s.status IN ('planned', 'cancelled') OR je.id IS NULL OR je.reversed_by IS NOT NULL)"
        )->execute([$supplierId, $itemId]);
        $insert = $pdo->prepare(
            'INSERT INTO accrual_schedule (supplier_id, accrual_item_id, kind, due_on, from_on, to_on, days, amount_planned, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $insert->execute([$supplierId, $itemId, $r['kind'] ?? 'release', $r['due_on'], $r['from_on'] ?? null, $r['to_on'] ?? null,
                $r['days'] ?? null, $r['amount_planned'], $r['status'] ?? 'planned']);
        }
    }

    /** Vloží nebo obnoví řádek plánu se stejným klíčem (položka, druh, datum). */
    public function upsertScheduleRow(int $supplierId, int $itemId, array $r): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accrual_schedule (supplier_id, accrual_item_id, kind, due_on, from_on, to_on, days, amount_planned, status, journal_entry_id, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), from_on = VALUES(from_on), to_on = VALUES(to_on), days = VALUES(days),
                amount_planned = VALUES(amount_planned), status = VALUES(status), journal_entry_id = VALUES(journal_entry_id),
                posted_at = VALUES(posted_at)'
        )->execute([$supplierId, $itemId, $r['kind'], $r['due_on'], $r['from_on'] ?? null, $r['to_on'] ?? null, $r['days'] ?? null,
            $r['amount_planned'], $r['status'] ?? 'planned', $r['journal_entry_id'] ?? null, $r['posted_at'] ?? null]);
        return (int) $pdo->lastInsertId();
    }

    public function setScheduleStatus(int $supplierId, int $rowId, string $status, ?int $entryId, ?string $postedAt = null): void
    {
        $this->db->pdo()->prepare('UPDATE accrual_schedule SET status = ?, journal_entry_id = ?, posted_at = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$status, $entryId, $postedAt, $supplierId, $rowId]);
    }

    public function cancelPlannedAfter(int $supplierId, int $itemId, string $date): void
    {
        $this->db->pdo()->prepare("UPDATE accrual_schedule SET status = 'cancelled' WHERE supplier_id = ? AND accrual_item_id = ? AND kind = 'release' AND status = 'planned' AND due_on > ?")
            ->execute([$supplierId, $itemId, $date]);
    }

    // ── vazby na zápisy ───────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function links(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.journal_entry_id, l.account_id, ca.account_code, l.schedule_id, l.amount, l.role, l.matched_by, l.created_at,
                    je.entry_date, je.document_no, je.description, je.source_type, je.source_id, je.posted_at, je.reversed_by,
                    rv.entry_date AS reversed_on
               FROM accrual_entry_links l
               JOIN journal_entries je ON je.id = l.journal_entry_id AND je.supplier_id = l.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
          LEFT JOIN journal_entries rv ON rv.id = je.reversed_by AND rv.supplier_id = je.supplier_id
              WHERE l.supplier_id = ? AND l.accrual_item_id = ?
              ORDER BY je.entry_date, l.id'
        );
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $r): array {
            foreach (['id', 'journal_entry_id', 'account_id'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['schedule_id'] = $r['schedule_id'] === null ? null : (int) $r['schedule_id'];
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
            $r['amount'] = round((float) $r['amount'], 2);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addLink(int $supplierId, int $itemId, int $entryId, int $accountId, float $amount, string $role, string $matchedBy, ?int $scheduleId = null, ?int $userId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accrual_entry_links (supplier_id, accrual_item_id, journal_entry_id, account_id, schedule_id, amount, role, matched_by, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), amount = VALUES(amount), schedule_id = VALUES(schedule_id), matched_by = VALUES(matched_by)'
        )->execute([$supplierId, $itemId, $entryId, $accountId, $scheduleId, round($amount, 2), $role, $matchedBy, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function deleteLink(int $supplierId, int $itemId, int $linkId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM accrual_entry_links WHERE supplier_id = ? AND accrual_item_id = ? AND id = ?');
        $stmt->execute([$supplierId, $itemId, $linkId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Obrat řádků zápisu na účtu (MD − D) a součet vazeb na ně ze všech položek.
     *
     * @return array{turnover:float, allocated:float}
     */
    public function entryAccountAllocation(int $supplierId, int $entryId, int $accountId, bool $lock = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
              WHERE l.supplier_id = ? AND l.entry_id = ? AND l.account_id = ?"
        );
        $stmt->execute([$supplierId, $entryId, $accountId]);
        $turnover = round((float) $stmt->fetchColumn(), 2);
        $alloc = $this->db->pdo()->prepare('SELECT COALESCE(SUM(amount), 0) FROM accrual_entry_links WHERE supplier_id = ? AND journal_entry_id = ? AND account_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $alloc->execute([$supplierId, $entryId, $accountId]);
        return ['turnover' => $turnover, 'allocated' => round((float) $alloc->fetchColumn(), 2)];
    }

    public function accountId(int $supplierId, string $code): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $r */
    public static function castItem(array $r): array
    {
        foreach (['id', 'supplier_id', 'row_version'] as $k) {
            if (isset($r[$k])) {
                $r[$k] = (int) $r[$k];
            }
        }
        foreach (['partner_id', 'source_id', 'source_item_id', 'termination_entry_id', 'project_id', 'created_by', 'updated_by'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
        }
        $r['basis_amount'] = round((float) ($r['basis_amount'] ?? 0), 2);
        if (array_key_exists('opening_balance', $r)) {
            $r['opening_balance'] = $r['opening_balance'] === null ? null : round((float) $r['opening_balance'], 2);
        }
        if (array_key_exists('dimensions_json', $r)) {
            $r['dimensions'] = $r['dimensions_json'] === null ? null : json_decode((string) $r['dimensions_json'], true);
            unset($r['dimensions_json']);
        }
        if (array_key_exists('balance_raw', $r)) {
            $sign = AccrualKinds::normalSign((string) $r['kind']);
            $r['balance'] = round((float) $r['balance_raw'] * $sign, 2);
            $r['released'] = round(-(float) $r['released_raw'] * $sign, 2);
            unset($r['balance_raw'], $r['released_raw'], $r['balance_signed']);
        }
        return $r;
    }
}
