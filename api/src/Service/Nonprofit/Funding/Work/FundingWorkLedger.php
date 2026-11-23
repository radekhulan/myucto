<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding\Work;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

/**
 * Čtení řádků deníku pro pracovní evidenci čerpání. Pravidla způsobilosti řádku jsou jediný
 * SQL výraz ({@see self::problemSql()}), který sdílí nabídka kandidátů, stav uložené vazby
 * i kontrola při zápisu. Řádek je vždy omezený na firmu; cizí řádek se chová jako neexistující.
 */
final class FundingWorkLedger
{
    /** Sloupce řádku; aliasy l řádek, e zápis, a účet, d druh činnosti (LEFT JOIN, NULL i při rozpadu). */
    public const LINE_COLUMNS = 'l.id AS line_id_now, l.entry_id AS line_entry_id, l.account_id AS line_account_id, l.side AS line_side, l.amount AS line_amount, l.is_red_storno AS line_red,
        a.account_code AS line_account_code, a.name AS line_account_name, e.period_id AS line_period_id, e.entry_date AS line_date, e.document_no AS line_document_no,
        e.description AS line_description, e.source_type AS line_source_type, e.source_id AS line_source_id, e.posted_at AS line_posted_at, e.reversed_by AS line_reversed_by,
        EXISTS(SELECT 1 FROM journal_entries r WHERE r.supplier_id = e.supplier_id AND r.reversed_by = e.id) AS line_is_reversal,
        d.dimension_value_id AS line_activity_kind_id';
    public const LINE_JOINS = 'JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
        JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
        LEFT JOIN dimension_types t ON t.supplier_id = l.supplier_id AND t.system_key = \'activity_kind\'
        LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = t.id';

    public function __construct(private readonly Connection $db) {}

    /**
     * Důvod, proč řádek (aliasy l, e, a, d) nesplňuje zdroj s aliasem s; NULL = splňuje.
     * Náklad: MD na nákladovém účtu třídy 5 mimo daň z příjmů 59x (shodně s CapitalLedger::cost),
     * mimo uzávěrkové, otevírací a modulové zápisy financování (NativeCostCoverage::EXCLUDED_INCOME_SOURCES),
     * v období zdroje, v rozsahu způsobilosti, na způsobilém účtu a druhu činnosti (rozpad druhu = nesplňuje).
     * Výnos: Dal na výnosovém účtu třídy 6 v období zdroje.
     */
    public static function problemSql(string $kind): string
    {
        $excluded = "'" . implode("','", NativeCostCoverage::EXCLUDED_INCOME_SOURCES) . "'";
        $common = "WHEN e.posted_at IS NULL THEN 'not_posted'
            WHEN e.reversed_by IS NOT NULL OR EXISTS(SELECT 1 FROM journal_entries r2 WHERE r2.supplier_id = e.supplier_id AND r2.reversed_by = e.id) THEN 'reversed'
            WHEN e.period_id <> s.period_id THEN 'other_period'
            WHEN l.is_red_storno = 1 OR l.amount <= 0 THEN 'amount'
            WHEN e.source_type IN ($excluded) THEN 'not_" . $kind . "'";
        if ($kind === 'income') {
            return "CASE $common
                WHEN l.side <> 'credit' OR a.account_type <> 'revenue' OR a.account_code NOT LIKE '6%' THEN 'not_income'
                ELSE NULL END";
        }
        return "CASE $common
            WHEN l.side <> 'debit' OR a.account_type <> 'expense' OR a.account_code NOT LIKE '5%' OR a.account_code LIKE '59%' THEN 'not_cost'
            WHEN e.entry_date < s.valid_from OR e.entry_date > s.valid_to THEN 'date'
            WHEN JSON_LENGTH(s.account_prefixes) > 0 AND NOT EXISTS(SELECT 1 FROM JSON_TABLE(s.account_prefixes, '$[*]' COLUMNS(p VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$')) jp WHERE a.account_code LIKE CONCAT(jp.p, '%')) THEN 'account'
            WHEN JSON_LENGTH(s.activity_kind_ids) > 0 AND (d.dimension_value_id IS NULL OR NOT JSON_CONTAINS(s.activity_kind_ids, CAST(d.dimension_value_id AS CHAR))) THEN 'activity'
            ELSE NULL END";
    }

    /**
     * Počet zaúčtovaných dobropisů k přijaté faktuře, ze které řádek pochází (alias e), stejně jako
     * NativeCostCoverage::creditNotes(). Vazba se při novém dobropisu ukáže ke kontrole.
     */
    public static function creditNotesSql(): string
    {
        // Od dobropisů faktury (idx_pi_parent) k jejich zápisům (idx_je_supplier_source).
        return "IF(e.source_type = 'purchase_invoice', (SELECT COUNT(*) FROM purchase_invoices cn
            JOIN journal_entries ce ON ce.supplier_id = e.supplier_id AND ce.source_type = 'purchase_invoice' AND ce.source_id = cn.id
            WHERE cn.parent_purchase_invoice_id = e.source_id AND cn.supplier_id = e.supplier_id AND cn.document_kind = 'credit_note'
              AND ce.posted_at IS NOT NULL AND ce.reversed_by IS NULL), 0)";
    }

    /**
     * Řádky pro zápis vůči zdroji: aktuální stav, důvod nezpůsobilosti a počet dobropisů. Zamykací čtení
     * (vidí potvrzené přeúčtování i při starším snímku transakce). Drží krátké S zámky i na řádcích účtů
     * a typu dimenze; účetní cesty zamykají nejdřív období, které evidence drží také, takže s nimi se pořadí
     * nekříží. Souběžná úprava účtu nebo dimenze mimo zámek období může skončit uváznutím, akce ho vrátí
     * jako 409 funding_work_busy (opakovatelné).
     * @param list<int> $ids @return array<int,array<string,mixed>>
     */
    public function linesFor(int $supplier, int $sourceId, string $kind, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) return [];
        $q = $this->db->pdo()->prepare('SELECT ' . self::LINE_COLUMNS . ', ' . self::problemSql($kind) . ' AS problem, ' . self::creditNotesSql() . ' AS credit_notes
            FROM journal_entry_lines l ' . self::LINE_JOINS . '
            JOIN nonprofit_funding_work_sources s ON s.supplier_id = l.supplier_id AND s.id = ?
            WHERE l.supplier_id = ? AND l.id IN (' . self::marks($ids) . ') LOCK IN SHARE MODE');
        $q->execute([$sourceId, $supplier, ...$ids]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $line = self::line($row);
            $line['problem'] = $row['problem'];
            $line['credit_notes'] = (int) $row['credit_notes'];
            $out[$line['id']] = $line;
        }
        return $out;
    }

    /** Zamkne zápisy v pořadí podle id (kapacita řádku mezi zdroji, souběh s přeúčtováním). @param list<int> $entryIds */
    public function lockEntries(int $supplier, array $entryIds): void
    {
        $entryIds = self::ids($entryIds);
        if ($entryIds === []) return;
        sort($entryIds, SORT_NUMERIC);
        $q = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND id IN (' . self::marks($entryIds) . ') ORDER BY id FOR UPDATE');
        $q->execute([$supplier, ...$entryIds]);
        $q->fetchAll();
    }

    /** @param list<int> $lineIds @return list<int> */
    public function entryIdsOf(int $supplier, array $lineIds): array
    {
        $lineIds = self::ids($lineIds);
        if ($lineIds === []) return [];
        $q = $this->db->pdo()->prepare('SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id = ? AND id IN (' . self::marks($lineIds) . ')');
        $q->execute([$supplier, ...$lineIds]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Kandidáti k přiřazení: způsobilé řádky období, které tento zdroj (u výnosu žádný aktivní zdroj)
     * ještě nemá a u nákladu mají volnou kapacitu.
     * @return array{rows:list<array<string,mixed>>,more:bool}
     */
    public function candidates(int $supplier, array $source, array $period, string $kind, string $search, int $page): array
    {
        // Období a rozsah dat jako konstanty: jen tak optimalizátor čte idx_je_supplier_entry_date v pořadí
        // (podmínka přes sloupce zdroje vede na idx_je_supplier_period a filesort celého období).
        $from = $kind === 'cost' ? max($period['starts_on'], $source['valid_from']) : $period['starts_on'];
        $to = $kind === 'cost' ? min($period['ends_on'], $source['valid_to']) : $period['ends_on'];
        $where = 'e.supplier_id = ? AND e.period_id = ? AND e.entry_date >= ? AND e.entry_date <= ? AND (' . self::problemSql($kind) . ') IS NULL';
        $params = [$source['id'], $supplier, $source['period_id'], $from, $to];
        if ($kind === 'income') {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM nonprofit_funding_work_incomes i WHERE i.supplier_id = l.supplier_id AND i.active_line_id = l.id)';
        } else {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM nonprofit_funding_work_allocations x WHERE x.supplier_id = ? AND x.source_id = ? AND x.active_line_id = l.id)
                AND ROUND(l.amount * 100) > ' . self::allocatedSql('l.id');
            array_push($params, $supplier, $source['id']);
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where .= ' AND (e.document_no LIKE ? OR e.description LIKE ? OR a.account_code LIKE ? OR a.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $limit = 50;
        $offset = max(0, $page - 1) * $limit;
        // Řazení jen podle sloupců zápisu: idx_je_supplier_entry_date (supplier_id, entry_date, id) pak čte
        // v pořadí indexu a skončí po naplnění stránky. Sloupec řádku (l.id) v ORDER BY vynutí filesort přes
        // celé období (100 tis. zápisů: 2,4 s proti setinám). Řádky jednoho zápisu jdou v pořadí idx_jel_entry.
        $q = $this->db->pdo()->prepare('SELECT ' . self::LINE_COLUMNS . ', ' . self::allocatedSql('l.id') . ' AS allocated_cents
            FROM nonprofit_funding_work_sources s
            JOIN journal_entries e FORCE INDEX (idx_je_supplier_entry_date) ON e.supplier_id = s.supplier_id
            JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
            JOIN chart_of_accounts a FORCE INDEX (PRIMARY) ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            LEFT JOIN dimension_types t ON t.supplier_id = l.supplier_id AND t.system_key = \'activity_kind\'
            LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = t.id
            WHERE s.id = ? AND s.supplier_id = e.supplier_id AND ' . $where . ' ORDER BY e.entry_date, e.id LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset);
        $q->execute($params);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $line = self::line($row);
            if ($kind === 'cost') {
                $line['allocated_cents'] = (int) $row['allocated_cents'];
                $line['capacity_cents'] = $line['amount_cents'] - $line['allocated_cents'];
            }
            $rows[] = $line;
        }
        return ['rows' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
    }

    /**
     * Nabídka „Navázat znovu“: řádky se stejným účtem v témže zápisu, nebo v zápisu téhož dokladu
     * (odúčtování a nové zaúčtování dokladu, storno a nové zaúčtování převzatého dokladu nebo dokladu
     * v měsíci s podaným DPH). Jen platné zápisy daného období. Protizápis storna se nenabízí: nese doklad
     * bez source_id (JournalEntryRepository::insertReversal), takže se s dokladem vazby nespáruje.
     * Filtr účtu v SQL jen zmenšuje výsledek pod limit; pravidlo „stejný účet“ uplatní volající.
     * Jeden dotaz pro všechny vazby.
     * @param list<array{entry_id:int,account_id:int,entry_source_type:?string,entry_source_id:?int}> $links
     * @return list<array<string,mixed>>
     */
    public function relinkCandidates(int $supplier, int $period, array $links): array
    {
        $entries = [];
        $documents = [];
        $accounts = [];
        foreach ($links as $link) {
            $entries[] = $link['entry_id'];
            $accounts[] = $link['account_id'];
            if ($link['entry_source_type'] !== null && $link['entry_source_id'] !== null) $documents[$link['entry_source_type'] . ':' . $link['entry_source_id']] = [$link['entry_source_type'], $link['entry_source_id']];
        }
        $entries = self::ids($entries);
        $accounts = self::ids($accounts);
        if ($entries === []) return [];
        $where = 'e.id IN (' . self::marks($entries) . ')';
        $params = [$supplier, $period, ...$accounts, ...$entries];
        foreach ($documents as [$type, $id]) {
            $where .= ' OR (e.source_type = ? AND e.source_id = ?)';
            array_push($params, $type, $id);
        }
        $q = $this->db->pdo()->prepare('SELECT ' . self::LINE_COLUMNS . ' FROM journal_entries e
            JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            LEFT JOIN dimension_types t ON t.supplier_id = l.supplier_id AND t.system_key = \'activity_kind\'
            LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = t.id
            WHERE e.supplier_id = ? AND e.period_id = ? AND l.account_id IN (' . self::marks($accounts) . ') AND (' . $where . ')
              AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.is_red_storno = 0 AND l.amount > 0
            ORDER BY e.id, l.line_no, l.id LIMIT 5000');
        $q->execute($params);
        return array_map(self::line(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Do nabídky doplní počet dobropisů k přijaté faktuře řádku (upozornění před přiřazením). @param list<array<string,mixed>> $rows */
    public function attachCreditNotes(int $supplier, array &$rows): void
    {
        $ids = self::ids(array_map(static fn(array $r) => $r['entry_id'], $rows));
        $counts = [];
        if ($ids !== []) {
            $q = $this->db->pdo()->prepare('SELECT e.id, ' . self::creditNotesSql() . ' FROM journal_entries e WHERE e.supplier_id = ? AND e.id IN (' . self::marks($ids) . ')');
            $q->execute([$supplier, ...$ids]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$entry, $count]) $counts[(int) $entry] = (int) $count;
        }
        foreach ($rows as &$row) $row['credit_notes'] = $counts[$row['entry_id']] ?? 0;
        unset($row);
    }

    /** SQL součtu aktivních přiřazení na řádku přes všechny aktivní zdroje (kapacita nákladu). */
    public static function allocatedSql(string $lineExpr): string
    {
        return "COALESCE((SELECT SUM(y.amount_cents) FROM nonprofit_funding_work_allocations y
            JOIN nonprofit_funding_work_sources ys ON ys.supplier_id = y.supplier_id AND ys.id = y.source_id AND ys.status = 'active'
            WHERE y.supplier_id = l.supplier_id AND y.linked_line_id = $lineExpr AND y.status = 'active'), 0)";
    }

    /**
     * Součet aktivních přiřazení na řádcích (zamykací čtení uvnitř zápisu).
     * @param list<int> $lineIds @return array<int,int>
     */
    public function allocatedOnLines(int $supplier, array $lineIds, ?int $exceptAllocation = null): array
    {
        $lineIds = self::ids($lineIds);
        if ($lineIds === []) return [];
        $q = $this->db->pdo()->prepare('SELECT x.linked_line_id, SUM(x.amount_cents) FROM nonprofit_funding_work_allocations x
            JOIN nonprofit_funding_work_sources s ON s.supplier_id = x.supplier_id AND s.id = x.source_id AND s.status = \'active\'
            WHERE x.supplier_id = ? AND x.status = \'active\' AND x.id <> ? AND x.linked_line_id IN (' . self::marks($lineIds) . ')
            GROUP BY x.linked_line_id LOCK IN SHARE MODE');
        $q->execute([$supplier, $exceptAllocation ?? 0, ...$lineIds]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$line, $sum]) $out[(int) $line] = (int) $sum;
        return $out;
    }

    /** Řádek z výsledku s LINE_COLUMNS; null, když řádek už neexistuje. @param array<string,mixed> $row */
    public static function line(array $row): ?array
    {
        if (($row['line_id_now'] ?? null) === null) return null;
        return [
            'id' => (int) $row['line_id_now'],
            'entry_id' => (int) $row['line_entry_id'],
            'period_id' => (int) $row['line_period_id'],
            'account_id' => (int) $row['line_account_id'],
            'account_code' => (string) $row['line_account_code'],
            'account_name' => (string) $row['line_account_name'],
            'side' => (string) $row['line_side'],
            'amount_cents' => JournalLineAmount::signedCents(['amount' => $row['line_amount'], 'is_red_storno' => $row['line_red']]),
            'date' => (string) $row['line_date'],
            'document_no' => $row['line_document_no'],
            'description' => $row['line_description'],
            'source_type' => (string) $row['line_source_type'],
            'source_id' => $row['line_source_id'] === null ? null : (int) $row['line_source_id'],
            'posted' => $row['line_posted_at'] !== null,
            'reversed' => $row['line_reversed_by'] !== null,
            'is_reversal' => (bool) $row['line_is_reversal'],
            'activity_kind_id' => $row['line_activity_kind_id'] === null ? null : (int) $row['line_activity_kind_id'],
        ];
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
    }

    private static function marks(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
