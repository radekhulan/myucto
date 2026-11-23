<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding\Work;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

/**
 * Čtení řádků deníku pro pracovní evidenci čerpání: aktuální stav řádku (zaúčtování, storno,
 * částka, druh činnosti) a kandidáti k přiřazení. Řádek je vždy omezený na firmu; cizí
 * řádek se chová jako neexistující.
 */
final class FundingWorkLedger
{
    private const LINE_SELECT = 'SELECT l.id, l.entry_id, l.account_id, l.side, l.amount, l.is_red_storno,
            a.account_code, a.name AS account_name, e.period_id, e.entry_date, e.document_no, e.description,
            e.source_type, e.source_id, e.posted_at, e.reversed_by,
            EXISTS(SELECT 1 FROM journal_entries r WHERE r.reversed_by = e.id AND r.supplier_id = e.supplier_id) AS is_reversal,
            d.dimension_value_id AS activity_kind_id,
            EXISTS(SELECT 1 FROM journal_entry_line_dimension_splits s WHERE s.line_id = l.id AND s.dimension_type_id = t.id) AS activity_split
        FROM journal_entry_lines l
        JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
        JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
        LEFT JOIN dimension_types t ON t.supplier_id = l.supplier_id AND t.system_key = \'activity_kind\'
        LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = t.id';

    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $ids @return array<int,array<string,mixed>> řádky podle id (chybějící a cizí nejsou) */
    public function lines(int $supplier, array $ids, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
        if ($ids === []) return [];
        // Zamykací čtení uvnitř zápisu: vidí potvrzené přeúčtování i při starším snímku transakce.
        $q = $this->db->pdo()->prepare(self::LINE_SELECT . ' WHERE l.supplier_id = ? AND l.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $q->execute([$supplier, ...$ids]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['id']] = self::shape($row);
        return $out;
    }

    /** @param list<int> $entryIds @return array<int,list<array<string,mixed>>> řádky zápisů podle entry_id */
    public function entryLines(int $supplier, array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id) => $id > 0)));
        if ($entryIds === []) return [];
        $q = $this->db->pdo()->prepare(self::LINE_SELECT . ' WHERE l.supplier_id = ? AND l.entry_id IN (' . implode(',', array_fill(0, count($entryIds), '?')) . ') ORDER BY l.entry_id, l.line_no, l.id');
        $q->execute([$supplier, ...$entryIds]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['entry_id']][] = self::shape($row);
        return $out;
    }

    /**
     * Zamkne zápisy v pořadí podle id. Zámek zápisu serializuje kapacitu jeho řádků mezi zdroji
     * a souběh s přeúčtováním (to řádky maže pod týmž zámkem).
     * @param list<int> $entryIds
     */
    public function lockEntries(int $supplier, array $entryIds): void
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id) => $id > 0)));
        if ($entryIds === []) return;
        sort($entryIds, SORT_NUMERIC);
        $q = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($entryIds), '?')) . ') ORDER BY id FOR UPDATE');
        $q->execute([$supplier, ...$entryIds]);
        $q->fetchAll();
    }

    /**
     * Výnosové řádky období, které ještě nepatří žádnému aktivnímu zdroji.
     * @return array{rows:list<array<string,mixed>>,more:bool}
     */
    public function incomeCandidates(int $supplier, int $period, string $search, int $page): array
    {
        $where = "e.supplier_id = ? AND e.period_id = ? AND l.side = 'credit' AND a.account_code LIKE '6%'";
        $params = [$supplier, $period];
        $where .= ' AND NOT EXISTS (SELECT 1 FROM nonprofit_funding_work_incomes i WHERE i.supplier_id = l.supplier_id AND i.active_line_id = l.id)';
        return $this->candidates($where, $params, $search, $page);
    }

    /**
     * Nákladové řádky období podle podmínek zdroje, které tento zdroj ještě nemá a mají
     * volnou kapacitu (částka řádku − aktivní přiřazení všech zdrojů).
     * @return array{rows:list<array<string,mixed>>,more:bool}
     */
    public function costCandidates(int $supplier, array $source, string $search, int $page): array
    {
        $where = "e.supplier_id = ? AND e.period_id = ? AND l.side = 'debit' AND a.account_code LIKE '5%' AND a.account_code NOT LIKE '59%'
            AND e.entry_date >= ? AND e.entry_date <= ?";
        $params = [$supplier, $source['period_id'], $source['valid_from'], $source['valid_to']];
        if ($source['account_prefixes'] !== []) {
            $where .= ' AND (' . implode(' OR ', array_fill(0, count($source['account_prefixes']), 'a.account_code LIKE ?')) . ')';
            foreach ($source['account_prefixes'] as $prefix) $params[] = addcslashes($prefix, '%_\\') . '%';
        }
        if ($source['activity_kind_ids'] !== []) {
            $where .= ' AND d.dimension_value_id IN (' . implode(',', array_fill(0, count($source['activity_kind_ids']), '?')) . ')';
            array_push($params, ...$source['activity_kind_ids']);
        }
        $where .= ' AND NOT EXISTS (SELECT 1 FROM nonprofit_funding_work_allocations x WHERE x.supplier_id = l.supplier_id AND x.source_id = ? AND x.active_line_id = l.id)
            AND ROUND(l.amount * 100) > COALESCE((SELECT SUM(y.amount_cents) FROM nonprofit_funding_work_allocations y
                JOIN nonprofit_funding_work_sources ys ON ys.supplier_id = y.supplier_id AND ys.id = y.source_id AND ys.status = \'active\'
                WHERE y.supplier_id = l.supplier_id AND y.linked_line_id = l.id AND y.status = \'active\'), 0)';
        $params[] = $source['id'];
        $result = $this->candidates($where, $params, $search, $page);
        $used = $this->allocatedOnLines($supplier, array_map(static fn(array $r) => $r['id'], $result['rows']));
        $rows = [];
        foreach ($result['rows'] as $row) {
            $row['allocated_cents'] = $used[$row['id']] ?? 0;
            $row['capacity_cents'] = $row['amount_cents'] - $row['allocated_cents'];
            $rows[] = $row;
        }
        return ['rows' => $rows, 'more' => $result['more']];
    }

    /**
     * Součet aktivních přiřazení na řádcích přes všechny zdroje (kapacita nákladu).
     * @param list<int> $lineIds @return array<int,int>
     */
    public function allocatedOnLines(int $supplier, array $lineIds, ?int $exceptAllocation = null, bool $lock = false): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) return [];
        $q = $this->db->pdo()->prepare('SELECT x.linked_line_id, SUM(x.amount_cents) FROM nonprofit_funding_work_allocations x
            JOIN nonprofit_funding_work_sources s ON s.supplier_id = x.supplier_id AND s.id = x.source_id AND s.status = \'active\'
            WHERE x.supplier_id = ? AND x.status = \'active\' AND x.id <> ? AND x.linked_line_id IN (' . implode(',', array_fill(0, count($lineIds), '?')) . ')
            GROUP BY x.linked_line_id' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $q->execute([$supplier, $exceptAllocation ?? 0, ...$lineIds]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$line, $sum]) $out[(int) $line] = (int) $sum;
        return $out;
    }

    /** @return array{rows:list<array<string,mixed>>,more:bool} */
    private function candidates(string $where, array $params, string $search, int $page): array
    {
        $where .= ' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.is_red_storno = 0
            AND NOT EXISTS(SELECT 1 FROM journal_entries r WHERE r.reversed_by = e.id AND r.supplier_id = e.supplier_id)';
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where .= ' AND (e.document_no LIKE ? OR e.description LIKE ? OR a.account_code LIKE ? OR a.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $limit = 50;
        $offset = max(0, $page - 1) * $limit;
        // Řazení jen podle sloupců zápisu: idx_je_supplier_entry_date (supplier_id, entry_date, id) pak čte
        // v pořadí indexu a skončí po naplnění stránky. Přidání sloupce řádku (l.id) vynutí filesort přes
        // celé období (100 tis. zápisů: 2,4 s proti setinám). Řádky jednoho zápisu přicházejí v pořadí
        // idx_jel_entry (podle id řádku).
        $q = $this->db->pdo()->prepare(self::LINE_SELECT . ' WHERE ' . $where . ' ORDER BY e.entry_date, e.id LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset);
        $q->execute($params);
        $rows = array_map(self::shape(...), $q->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'entry_id' => (int) $row['entry_id'],
            'period_id' => (int) $row['period_id'],
            'account_id' => (int) $row['account_id'],
            'account_code' => (string) $row['account_code'],
            'account_name' => (string) $row['account_name'],
            'side' => (string) $row['side'],
            'amount_cents' => JournalLineAmount::signedCents($row),
            'date' => (string) $row['entry_date'],
            'document_no' => $row['document_no'],
            'description' => $row['description'],
            'source_type' => (string) $row['source_type'],
            'source_id' => $row['source_id'] === null ? null : (int) $row['source_id'],
            'posted' => $row['posted_at'] !== null,
            'reversed' => $row['reversed_by'] !== null,
            'is_reversal' => (bool) $row['is_reversal'],
            'activity_kind_id' => $row['activity_kind_id'] === null ? null : (int) $row['activity_kind_id'],
            'activity_split' => (bool) $row['activity_split'],
        ];
    }
}
