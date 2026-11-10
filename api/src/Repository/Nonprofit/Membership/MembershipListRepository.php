<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use PDO;

final class MembershipListRepository
{
    /** Nejvyšší počet členů, které jeden dotaz s filtrem úhrady prověří. */
    private const MAX_CHUNK = 4000;

    /** @param int $firstChunk počet členů v první prověřované dávce filtru úhrady (testy ho zmenšují) */
    public function __construct(private readonly Connection $db, private readonly int $firstChunk = 200) {}

    /**
     * Souhrn i filtr úhrady před stránkováním; částky souhrnů zůstávají přesné řetězce.
     * Bez filtru úhrady stačí jeden dotaz nad stránkou členů. S filtrem se kandidáti prověřují po dávkách
     * podle ID od kurzoru, dokud se stránka nezaplní nebo kandidáti nedojdou. Každé „Načíst další“ tak
     * pokračuje od posledního vráceného člena a nepočítá znovu stav celé firmy. Kandidáty stavů s předpisem
     * vybírá index předpisů, takže řídký titul neagreguje členy bez předpisu ve zvoleném roce nebo titulu.
     */
    public function page(int $supplier, int $after, int $limit, array $filter): array
    {
        if ($filter['payment_status'] === 'all') return $this->fetch($supplier, $after, $limit, $filter, null);
        $rows = []; $cursor = $after; $chunk = max($this->firstChunk, $limit);
        while (true) {
            $upper = $this->chunkUpperBound($supplier, $cursor, $chunk, $filter);
            array_push($rows, ...$this->fetch($supplier, $cursor, $limit - count($rows), $filter, $upper));
            if (count($rows) >= $limit || $upper === null) return $rows;
            // Řídký výskyt stavu: dávka se zdvojuje až do MAX_CHUNK, nad ním počet dotazů roste lineárně s kandidáty.
            $cursor = $upper; $chunk = min(self::MAX_CHUNK, $chunk * 2);
        }
    }

    /**
     * ID posledního kandidáta dávky (stejné hledání jako seznam), nebo null, když za kurzorem už víc kandidátů není.
     * Stav „bez předpisu“ hledá členy bez předpisu, proto prochází členy; ostatní stavy jen členy s předpisem v rozsahu.
     */
    private function chunkUpperBound(int $supplier, int $after, int $chunk, array $filter): ?int
    {
        [$search, $params] = $this->searchCondition($filter);
        if ($filter['payment_status'] === 'none') {
            $sql = 'SELECT m.id FROM nonprofit_members m FORCE INDEX(uq_nm_tenant)
                JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
                WHERE m.supplier_id=? AND m.id>?' . $search . ' ORDER BY m.id';
            $params = [$supplier, $after, ...$params];
        } else {
            [$cases, $caseParams] = $this->caseCondition($filter, 'x');
            $join = $search === '' ? '' : ' JOIN nonprofit_members m ON m.supplier_id=x.supplier_id AND m.id=x.member_id
                JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id';
            $sql = 'SELECT x.member_id FROM nonprofit_membership_charge_cases x FORCE INDEX(' . $this->candidateIndex($filter) . ')' . $join . '
                WHERE x.supplier_id=? AND x.member_id>?' . $cases . $search . ' GROUP BY x.member_id ORDER BY x.member_id';
            $params = [$supplier, $after, ...$caseParams, ...$params];
        }
        $q = $this->db->pdo()->prepare($sql . ' LIMIT 1 OFFSET ' . ($chunk - 1));
        $q->execute($params);
        $id = $q->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Index pro výběr kandidátů: u titulu krycí ix_nmcc_list_title (řídký titul nečte předpisy ostatních titulů),
     * bez titulu uq_nmcc_identity v pořadí členů.
     */
    private function candidateIndex(array $filter): string
    {
        return $filter['title_key'] === '' ? 'uq_nmcc_identity' : 'ix_nmcc_list_title';
    }

    /** Rozsah předpisů podle roku a titulu; stejné podmínky pro výběr kandidátů i finanční agregaci. */
    private function caseCondition(array $filter, string $alias): array
    {
        $sql = ''; $params = [];
        if ($filter['year'] !== null) {
            $sql .= " AND {$alias}.period_to>=? AND {$alias}.period_from<?";
            array_push($params, $filter['year'] . '-01-01', ($filter['year'] + 1) . '-01-01');
        }
        if ($filter['title_key'] !== '') { $sql .= " AND {$alias}.title_key=?"; $params[] = $filter['title_key']; }
        return [$sql, $params];
    }

    private function fetch(int $supplier, int $after, int $limit, array $filter, ?int $upper): array
    {
        [$sql, $params] = $this->query($supplier, $after, $limit, $filter, null, $upper);
        $q = $this->db->pdo()->prepare($sql); $q->execute($params);
        return array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['revision_no'] = $row['revision_no'] === null ? null : (int) $row['revision_no'];
            $row['contribution'] = ['status' => $row['dues_status'], 'claim_count' => (int) $row['dues_count'],
                'prescribed_cents' => (string) $row['dues_prescribed'], 'paid_cents' => $row['dues_paid'], 'open_cents' => $row['dues_open'], 'unpaired_count' => (int) $row['dues_unpaired']];
            foreach (['dues_status', 'dues_count', 'dues_prescribed', 'dues_paid', 'dues_open', 'dues_unpaired'] as $field) unset($row[$field]);
            return $row;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{string, list<string>} */
    private function searchCondition(array $filter): array
    {
        if ($filter['search'] === '') return ['', []];
        $like = '%' . strtr($filter['search'], ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
        return [" AND (m.member_number LIKE ? ESCAPE '=' OR c.company_name LIKE ? ESCAPE '=' OR c.first_name LIKE ? ESCAPE '=' OR c.last_name LIKE ? ESCAPE '=')",
            [$like, $like, $like, $like]];
    }

    /**
     * Veřejné sestavení pro EXPLAIN stejného dotazu jako v aplikaci, bez odlišné měřicí kopie.
     * $upper omezuje členy na dávku (after, upper]; bez filtru úhrady se místo toho omezí přímo stránka.
     */
    public function query(int $supplier, int $after, int $limit, array $filter, ?array $memberIds = null, ?int $upper = null): array
    {
        $limit = max(1, min(501, $limit));
        $params = [$supplier, $after];
        [$search, $searchParams] = $this->searchCondition($filter);
        array_push($params, ...$searchParams);
        if ($upper !== null) { $search .= ' AND m.id<=?'; $params[] = $upper; }
        if ($memberIds !== null) {
            if ($memberIds === [] || count($memberIds)>500) throw new \InvalidArgumentException('Výběr vyžaduje 1–500 členů.');
            $search .= ' AND m.id IN (' . implode(',', array_fill(0, count($memberIds), '?')) . ')';
            array_push($params, ...$memberIds);
        }
        if (!in_array($filter['payment_status'], ['all', 'none'], true)) {
            // Stav s předpisem: do agregace vstupují jen členové s předpisem v rozsahu, ne celé ID pásmo dávky.
            [$cases, $caseParams] = $this->caseCondition($filter, 'x');
            if ($filter['title_key'] === '') {
                $search .= ' AND EXISTS(SELECT 1 FROM nonprofit_membership_charge_cases x FORCE INDEX(uq_nmcc_identity)
                    WHERE x.supplier_id=m.supplier_id AND x.member_id=m.id' . $cases . ')';
            } else {
                // Titul: členy bereme z krycího indexu titulu, ne prověřováním každého člena dávky.
                $search .= ' AND m.id IN(SELECT x.member_id FROM nonprofit_membership_charge_cases x FORCE INDEX(ix_nmcc_list_title)
                    WHERE x.supplier_id=?' . $cases . ')';
                $params[] = $supplier;
            }
            array_push($params, ...$caseParams);
        }
        [$cases, $caseParams] = $this->caseCondition($filter, 'cc');
        $condition = 'cc.supplier_id=?' . $cases; $params[] = $supplier; array_push($params, ...$caseParams);
        // Finanční agregace vždy začíná omezenými členy: bez filtru úhrady stránkou, s filtrem dávkou
        // (after, upper] nebo výběrem ID. Stav celé firmy se nepočítá ani pro další stránky.
        $scopeLimit = $filter['payment_status'] === 'all' ? " ORDER BY m.id LIMIT {$limit}" : '';
        $claimsSource = "scope m STRAIGHT_JOIN nonprofit_membership_charge_cases cc FORCE INDEX(uq_nmcc_identity) ON cc.supplier_id=m.supplier_id AND cc.member_id=m.id";
        $pairingSql = MembershipBalanceRepository::nativePairingTotalsSql();
        // Požadavky materializujeme podle pohledávky, aby optimizer nezačal účetními řádky celé firmy.
        // Unikátní claim/receivable-line/PK párovací položky zaručují shodu pro validní data.
        $statusSql = MembershipReconciliationService::settlementStatusSql();
        $receiptSql=MembershipBalanceRepository::receiptValiditySql();
        // DISTINCT materializuje unikátní pohledávky; bez něj sloučení CTE dovolilo číst deník před omezením stránky.
        $params[] = $supplier;
        $statusFilter = '';
        if ($filter['payment_status'] !== 'all') { $statusFilter = ' WHERE dues_status=?'; $params[] = $filter['payment_status']; }
        $sql = "WITH scope AS (
            SELECT m.*,c.company_name,c.first_name,c.last_name FROM nonprofit_members m FORCE INDEX(uq_nm_tenant)
              JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
              WHERE m.supplier_id=? AND m.id>?{$search}{$scopeLimit}
          ), claims AS (
            SELECT DISTINCT STRAIGHT_JOIN c.id claim_id,c.supplier_id,c.member_id,c.amount_cents,o.journal_entry_id,o.receivable_line_id,
              l.line_no,l.account_id,e.posted_at,e.reversed_by,{$receiptSql} receipt_valid
            FROM {$claimsSource}
              JOIN nonprofit_membership_charge_operations o ON o.supplier_id=cc.supplier_id AND o.case_id=cc.id AND o.id=cc.head_operation_id AND o.operation='issue'
              JOIN nonprofit_membership_charge_claims c ON c.supplier_id=o.supplier_id AND c.operation_id=o.id AND c.kind='issue'
              LEFT JOIN journal_entries e ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id
              LEFT JOIN journal_entry_lines l ON l.supplier_id=o.supplier_id AND l.id=o.receivable_line_id AND l.entry_id=o.journal_entry_id
            WHERE {$condition}
          ), requested AS (
            SELECT STRAIGHT_JOIN c.claim_id,i.pairing_id,c.account_id FROM claims c
              JOIN journal_line_pairing_items i ON i.supplier_id=c.supplier_id AND i.entry_id=c.journal_entry_id AND i.line_no=c.line_no AND i.account_id=c.account_id
              WHERE EXISTS(SELECT 1 FROM journal_line_pairing_items other WHERE other.supplier_id=i.supplier_id AND other.pairing_id=i.pairing_id
                AND (other.entry_id<>i.entry_id OR other.line_no<>i.line_no))
            GROUP BY c.claim_id,i.pairing_id,c.account_id
          ), pairing_totals AS ({$pairingSql}), facts AS (
            SELECT c.*,p.net_cents,{$statusSql} settlement_status FROM claims c LEFT JOIN pairing_totals p ON p.claim_id=c.claim_id
          ), totals AS (
            SELECT member_id,COUNT(*) claim_count,CAST(SUM(amount_cents) AS CHAR) prescribed,
              CASE WHEN SUM(settlement_status='ambiguous')>0 THEN NULL
                ELSE CAST(SUM(CASE WHEN settlement_status='unreconciled' THEN 0 ELSE amount_cents-net_cents END) AS CHAR) END paid,
              CASE WHEN SUM(settlement_status='ambiguous')>0 THEN NULL
                ELSE CAST(SUM(CASE WHEN settlement_status='unreconciled' THEN amount_cents ELSE net_cents END) AS CHAR) END open_cents,
              SUM(settlement_status='ambiguous') unknown_count,SUM(settlement_status='unreconciled') unpaired_count
            FROM facts GROUP BY member_id
          ), filtered AS (
            SELECT m.*,COALESCE(t.claim_count,0) dues_count,COALESCE(t.prescribed,'0') dues_prescribed,COALESCE(t.unpaired_count,0) dues_unpaired,
              CASE WHEN t.claim_count IS NULL THEN '0' ELSE t.paid END dues_paid,
              CASE WHEN t.claim_count IS NULL THEN '0' ELSE t.open_cents END dues_open,
              CASE WHEN t.claim_count IS NULL THEN 'none' WHEN t.unknown_count>0 THEN 'unproven'
                WHEN t.open_cents=0 THEN 'paid' ELSE 'unpaid' END dues_status
            FROM scope m LEFT JOIN totals t ON t.member_id=m.id
          ), paged AS (SELECT * FROM filtered{$statusFilter} ORDER BY id LIMIT {$limit})
          SELECT p.*,r.revision_no,r.effective_from,r.status FROM paged p
            LEFT JOIN nonprofit_member_revisions r ON r.supplier_id=p.supplier_id AND r.id=(
              SELECT latest.id FROM nonprofit_member_revisions latest
              WHERE latest.supplier_id=p.supplier_id AND latest.member_id=p.id ORDER BY latest.revision_no DESC LIMIT 1)
          ORDER BY p.id";
        return [$sql, $params];
    }

    /** Obnova ověřuje celý výběr, nikoli jen právě zobrazenou stránku. */
    public function matchingSelection(int $supplier, array $memberIds, array $filter): array
    {
        if ($memberIds === []) return [];
        [$sql,$params] = $this->query($supplier, 0, 501, $filter, $memberIds);
        $q=$this->db->pdo()->prepare($sql); $q->execute($params);
        return array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    public function titles(int $supplier, ?int $year, string $after, int $limit): array
    {
        $limit=max(1,min(101,$limit));
        $range=$year===null?'':' AND period_to>=? AND period_from<?';
        $q=$this->db->pdo()->prepare("SELECT title_key FROM nonprofit_membership_charge_cases FORCE INDEX(ix_nmcc_list_title)
            WHERE supplier_id=? AND title_key>?{$range} GROUP BY title_key ORDER BY title_key LIMIT {$limit}");
        $params=[$supplier,$after];
        if($year!==null) array_push($params,$year.'-01-01',($year+1).'-01-01');
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }
}
