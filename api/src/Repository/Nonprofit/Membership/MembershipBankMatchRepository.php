<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use PDO;

/** Členské cíle sdíleného bankovního párování; finanční evidence zůstává v deníku. */
final class MembershipBankMatchRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Kolik nejnovějších předpisů dané částky projde jeden požadavek fáze částky. V ustáleném stavu
     * (historie uhrazená) by hledání otevřeného předpisu jinak četlo celou historii; takto je práce
     * na požadavek omezená a další předpisy přinese kurzor.
     */
    public const AMOUNT_SCAN_WINDOW = 500;

    /** Nejvíc odpovídajících členů (označení), jejichž předpisy se čtou po indexu; nad to průchod od nejnovějších. */
    private const SEARCH_MEMBER_LIMIT = 200;
    private const SEARCH_TITLE_LIMIT = 50;

    /** Platný kurzor: '0' (začátek) nebo fáze a ID, pod kterým se pokračuje. */
    public const CURSOR_PATTERN = '/^(?:0|(?:vs|amount|search):[1-9][0-9]{0,18})$/D';

    /**
     * Otevřené předpisy od nejnovějších. Ruční hledání prochází členy a označení příspěvku najednou;
     * bez hledání se nejdřív vyčerpají shody podle VS a teprve pak předpisy shodné jen částkou,
     * aby se platba člena neztratila mezi předpisy stejné výše po hromadném předpisu.
     * Kurzor nese fázi ('vs:ID', 'amount:ID', 'search:ID'). Stránka může být kratší nebo prázdná,
     * když okno obsadí uhrazené předpisy; žádný otevřený se přitom nepřeskočí.
     *
     * @return array{rows: list<array<string,mixed>>, next_cursor: ?string}
     */
    public function candidates(int $supplier, string $symbol, int $paymentCents, string $cursor, int $limit, string $search = ''): array
    {
        $limit = max(1, min(50, $limit));
        [$phase, $after] = $cursor === '0' ? [null, 0] : [strstr($cursor, ':', true), (int) substr($cursor, strpos($cursor, ':') + 1)];
        if ($search !== '') {
            [$rows, $next] = $this->page($supplier, $symbol, $paymentCents, $after, $limit, $search, null);
            return ['rows' => $rows, 'next_cursor' => $next === null ? null : 'search:' . $next];
        }
        if ($symbol !== '' && ($phase ?? 'vs') === 'vs') {
            [$rows, $next] = $this->page($supplier, $symbol, $paymentCents, $after, $limit, '', 'vs');
            if ($rows !== [] || $next !== null) return ['rows' => $rows, 'next_cursor' => $next === null ? 'amount:' . PHP_INT_MAX : 'vs:' . $next];
            $after = 0;
        }
        [$rows, $next] = $this->page($supplier, $symbol, $paymentCents, $after, $limit, '', 'amount');
        return ['rows' => $rows, 'next_cursor' => $next === null ? null : 'amount:' . $next];
    }

    /**
     * Aktivní účty kontaktů členů stránky kandidátů, jeden dotaz na stránku (pomocné kritérium pořadí).
     *
     * @param list<int> $clientIds
     * @return array<int,list<array{account_number:string,bank_code:?string}>>
     */
    public function clientAccounts(int $supplier, array $clientIds): array
    {
        $clientIds = array_values(array_unique($clientIds));
        if ($clientIds === []) return [];
        $in = implode(',', array_fill(0, count($clientIds), '?'));
        $q = $this->db->pdo()->prepare("SELECT client_id,account_number,bank_code FROM client_bank_accounts
            WHERE supplier_id=? AND is_active=1 AND client_id IN ($in) ORDER BY client_id,id");
        $q->execute([$supplier, ...$clientIds]);
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['client_id']][] = ['account_number' => (string) $r['account_number'], 'bank_code' => $r['bank_code']];
        }
        return $out;
    }

    /** @return array{0: list<array<string,mixed>>, 1: ?int} řádky a ID, pod kterým pokračovat (null = vyčerpáno) */
    private function page(int $supplier, string $symbol, int $paymentCents, int $after, int $limit, string $search, ?string $phase): array
    {
        [$sql, $params] = $this->candidateQuery($supplier, $symbol, $paymentCents, $after, $limit, $search, $phase);
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($params);
        $branches = [];
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['claim_id'];
            $branches[$row['branch']][] = $id;
            if ((int) $row['eligible'] === 1) $rows[$id] = $this->claimRow($row);
        }
        $boundary = null;
        foreach ($branches as $branch => $ids) {
            if (count($ids) >= self::window($branch, $limit)) $boundary = max($boundary ?? 0, min($ids));
        }
        if ($boundary !== null) $rows = array_filter($rows, static fn (array $r): bool => $r['claim_id'] >= $boundary);
        krsort($rows);
        $rows = array_values($rows);
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            return [$rows, $rows[$limit - 1]['claim_id']];
        }
        return [$rows, $boundary];
    }

    private static function window(string $branch, int $limit): int
    {
        return $branch === 'amount' ? self::AMOUNT_SCAN_WINDOW : max(1, min(50, $limit)) + 1;
    }

    /**
     * Stejný dotaz pro běh aplikace i kontrolu plánu EXPLAIN; $after je ID, pod kterým se pokračuje
     * (0 = od začátku), $phase omezí výchozí hledání na větev 'vs' nebo 'amount' (null = obě).
     */
    public function candidateQuery(int $supplier, string $symbol, int $paymentCents, int $after, int $limit,
        string $search = '', ?string $phase = null): array
    {
        $older = $after > 0 ? ' AND c.id<?' : '';
        $branch = function (string $name, string $filter, array $filterParams, string $from = 'case') use ($supplier, $after, $older, $limit): array {
            $window = self::window($name, $limit);
            $params = [$supplier, ...$filterParams];
            if ($after > 0) $params[] = $after;
            return ["(SELECT '{$name}' branch, x.* FROM (" . $this->claimsSql($filter . $older, $from)
                . " ORDER BY c.id DESC LIMIT {$window}) x)", $params];
        };
        $parts = [];
        if ($search !== '') {
            // Stejně jako seznam členů (MembershipListFilter::searchWords): každé slovo musí sedět na číslo
            // člena, název, jméno nebo příjmení, takže najde i celé zobrazené jméno v libovolném pořadí.
            $words = MembershipListFilter::searchWords($search);
            $memberSql = '';
            $memberParams = [$supplier];
            foreach ($words as $word) {
                $like = MembershipListFilter::containsPattern($word);
                // Vymazaného člena (krok 10) jméno sdíleného kontaktu nenajde.
                $memberSql .= " AND (sm.member_number LIKE ? ESCAPE '=' OR (NOT " . MembershipListRepository::erasedSql('sm') . " AND (scl.company_name LIKE ? ESCAPE '='"
                    . " OR scl.first_name LIKE ? ESCAPE '=' OR scl.last_name LIKE ? ESCAPE '=')))";
                array_push($memberParams, $like, $like, $like, $like);
            }
            $members = "SELECT sm.id FROM nonprofit_members sm
                    JOIN clients scl ON scl.supplier_id=sm.supplier_id AND scl.id=sm.client_id
                    WHERE sm.supplier_id=?{$memberSql}";
            // Nejdřív odpovídající členové (jako seznam členů), teprve pak jejich předpisy po indexu člena.
            // Hledání bez shody tak předpisy nečte vůbec. Při mnoha shodách najde okno rychleji průchod
            // od nejnovějších předpisů, protože odpovídající předpis potká brzy.
            $q = $this->db->pdo()->prepare($members . ' ORDER BY sm.id LIMIT ' . (self::SEARCH_MEMBER_LIMIT + 1));
            $q->execute($memberParams);
            $memberIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if (count($memberIds) > self::SEARCH_MEMBER_LIMIT) {
                $parts[] = $branch('member', "c.member_id IN ($members)", $memberParams, 'recent');
            } elseif ($memberIds !== []) {
                $parts[] = $branch('member', 'c.member_id IN (' . implode(',', array_fill(0, count($memberIds), '?')) . ')', $memberIds, 'member');
            }
            // Označení příspěvku je ASCII klíč (ascii_bin): text s diakritikou na něj nesedí a srovnání
            // s ním by skončilo chybou kolace, proto se větev titulu pro takové hledání vynechá.
            $title = implode(' ', $words);
            if (mb_check_encoding($title, 'ASCII')) {
                // Odlišných označení je málo: index je přečte po jednom záznamu na označení (group-by).
                // Bez shody se předpisy nečtou. Předpisy shodného označení se procházejí od nejnovějších:
                // označení má předpis u většiny členů, takže okno se naplní hned (řazení všech by bylo dražší).
                $pattern = MembershipListFilter::containsPattern($title);
                $q = $this->db->pdo()->prepare("SELECT title_key FROM nonprofit_membership_charge_cases FORCE INDEX(ix_nmcc_list_title)
                    WHERE supplier_id=? GROUP BY title_key HAVING title_key LIKE ? ESCAPE '=' LIMIT " . (self::SEARCH_TITLE_LIMIT + 1));
                $q->execute([$supplier, $pattern]);
                $titles = $q->fetchAll(PDO::FETCH_COLUMN);
                if (count($titles) > self::SEARCH_TITLE_LIMIT) {
                    $parts[] = $branch('title', "cc.title_key LIKE ? ESCAPE '='", [$pattern], 'recent');
                } elseif ($titles !== []) {
                    $parts[] = $branch('title', 'cc.title_key IN (' . implode(',', array_fill(0, count($titles), '?')) . ')', $titles, 'recent');
                }
            }
            if ($parts === []) return ['SELECT NULL branch, NULL claim_id, 0 eligible FROM DUAL WHERE 1=0', []];
        } else {
            // VS najde i dílčí úhrady. Částka nabízí jen předpisy bez párování: spárovaný předpis má
            // otevřenou částku menší než předepsanou, takže by na platbu stejné výše nikdy nesedl.
            // Podmínka párování je až ve výsledku (eligible), aby okno omezovalo prošlé, ne nalezené řádky.
            // Shody VS už nabídla fáze VS, částka je proto vynechá.
            if ($symbol !== '' && $phase !== 'amount') $parts[] = $branch('vs', 'cc.supplier_id=? AND cc.variable_symbol_match_key=?', [$supplier, $symbol], 'vs');
            if ($phase !== 'vs') {
                $parts[] = $symbol === ''
                    ? $branch('amount', 'c.amount_cents=?', [$paymentCents], 'amount')
                    : $branch('amount', 'c.amount_cents=? AND NOT (cc.variable_symbol_match_key<=>?)', [$paymentCents, $symbol], 'amount');
            }
        }
        $sql = implode(' UNION ALL ', array_column($parts, 0));
        $params = array_merge(...array_column($parts, 1));
        $open = 'CASE WHEN p.claim_id IS NULL THEN c.amount_cents ELSE p.net_cents END';
        $query = "WITH claims AS ($sql),
            requested AS (SELECT DISTINCT claim_id,pairing_id,account_id FROM claims WHERE pairing_id IS NOT NULL AND branch<>'amount'),
            evidence AS (" . MembershipBalanceRepository::nativePairingTotalsSql() . ")
            SELECT c.*, $open open_cents,
                   IF(c.branch='amount', c.pairing_id IS NULL, (p.claim_id IS NULL OR (p.invalid_count=0 AND p.membership_claim_count=1
                    AND p.net_cents>0 AND p.net_cents<=c.amount_cents)) AND $open>0) eligible
              FROM claims c LEFT JOIN evidence p ON p.claim_id=c.claim_id";
        $params[] = $supplier;
        return [$query, $params];
    }

    private function claimRow(array $row): array
    {
        $ints = ['claim_id', 'case_id', 'member_id', 'client_id', 'amount_cents', 'journal_entry_id', 'receivable_line_id', 'line_no', 'account_id'];
        foreach ($ints as $key) $row[$key] = (int) $row[$key];
        return $row;
    }

    /** Případy se zamykají ve stejném pořadí předpisu/storna; žádný dotaz po jednotlivých členech. */
    public function selected(int $supplier, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->pdo()->prepare("SELECT cc.id FROM nonprofit_membership_charge_cases cc
            JOIN nonprofit_membership_charge_claims c ON c.supplier_id=cc.supplier_id AND c.case_id=cc.id
            WHERE cc.supplier_id=? AND c.id IN ($marks) ORDER BY cc.id FOR UPDATE");
        $q->execute([$supplier, ...$ids]);
        $q->fetchAll(PDO::FETCH_COLUMN);
        // Výhradní zámek záměrně i na řádku účtu pohledávky: přerazítkování dimenzí po párování zamyká osnovu
        // výhradně (SystemDimensionWritePolicy), takže sdílený zámek by se u dvou souběžných párování na stejný
        // účet měnil na výhradní a skončil zablokováním. Takto druhé párování počká už zde, ve stejném pořadí.
        // Zámek drží do konce párování i řádky člena a kontaktu; jejich souběžná úprava na párování krátce počká.
        $q = $this->db->pdo()->prepare($this->claimsSql("c.id IN ($marks)") . ' ORDER BY c.id FOR UPDATE');
        $q->execute([$supplier, ...$ids]);
        return array_map($this->claimRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param 'case'|'amount'|'recent'|'member'|'vs' $driver tabulka a index, kterými dotaz začíná; 'recent' čte
     *        předpisy firmy od nejnovějších v pevném pořadí spojení, aby ruční hledání skončilo po naplnění okna
     *        a nespojovalo celou historii (optimalizátor jinak začínal účty a všemi členy).
     */
    private function claimsSql(string $filter, string $driver = 'case'): string
    {
        $cases = 'JOIN nonprofit_membership_charge_cases cc ON cc.supplier_id=c.supplier_id AND cc.id=c.case_id';
        $from = match ($driver) {
            'amount' => "nonprofit_membership_charge_claims c FORCE INDEX(ix_nmclaim_bank_amount) $cases",
            'recent' => "nonprofit_membership_charge_claims c FORCE INDEX(uq_nmclaim_tenant) $cases",
            'member' => "nonprofit_membership_charge_claims c FORCE INDEX(ix_nmclaim_member) $cases",
            // Případy se shodným VS po indexu VS a teprve pak jejich předpisy: bez pevného pořadí začínal
            // optimalizátor u osnovy účtů a násobil ji počtem shodných předpisů (měření 15. 11., 5 000 shod 32 s).
            // Firmu nese filtr větve přímo na cc (rovnost přes c.supplier_id optimalizátor do indexu nepropaguje).
            'vs' => 'nonprofit_membership_charge_cases cc FORCE INDEX(ix_nmcc_variable_symbol) JOIN nonprofit_membership_charge_claims c ON c.supplier_id=cc.supplier_id AND c.case_id=cc.id',
            default => 'nonprofit_membership_charge_cases cc JOIN nonprofit_membership_charge_claims c ON c.supplier_id=cc.supplier_id AND c.case_id=cc.id',
        };
        return "SELECT " . (in_array($driver, ['recent', 'member', 'vs'], true) ? 'STRAIGHT_JOIN ' : '') . "c.supplier_id,c.id claim_id,c.case_id,c.member_id,c.client_id,c.amount_cents,
                o.journal_entry_id,o.receivable_line_id,l.line_no,l.account_id,a.account_code,
                e.posted_at,e.reversed_by,r.id reversal_claim_id," . MembershipBalanceRepository::receiptValiditySql() . " receipt_valid,
                cc.title_key,cc.period_from,cc.period_to,cc.variable_symbol,cc.variable_symbol_match_key,
                IF(EXISTS(SELECT 1 FROM nonprofit_members em WHERE em.supplier_id=c.supplier_id AND em.id=c.member_id AND " . MembershipListRepository::erasedSql('em') . "),NULL,
                  COALESCE(NULLIF(TRIM(cl.company_name),''),TRIM(CONCAT_WS(' ',cl.first_name,cl.last_name)))) member_name,pi.pairing_id
            FROM $from
            JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id
                AND o.operation='issue' AND cc.head_operation_id=o.id
            JOIN journal_entries e ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
            JOIN journal_entry_lines l ON l.supplier_id=o.supplier_id AND l.entry_id=e.id AND l.id=o.receivable_line_id
                AND l.side='debit' AND l.is_red_storno=0 AND l.currency_code IS NULL
                AND l.amount_foreign IS NULL AND l.fx_rate IS NULL AND ROUND(l.signed_amount*100)=c.amount_cents
            JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            JOIN clients cl ON cl.supplier_id=c.supplier_id AND cl.id=c.client_id
            JOIN nonprofit_members m ON m.supplier_id=c.supplier_id AND m.id=c.member_id
            LEFT JOIN nonprofit_membership_charge_claims r ON r.supplier_id=c.supplier_id AND r.original_claim_id=c.id AND r.kind='reversal'
            LEFT JOIN journal_line_pairing_items pi ON pi.supplier_id=l.supplier_id AND pi.entry_id=l.entry_id
                AND pi.line_no=l.line_no AND pi.account_id=l.account_id
                AND EXISTS(SELECT 1 FROM journal_line_pairing_items other
                    WHERE other.supplier_id=pi.supplier_id AND other.pairing_id=pi.pairing_id
                    AND (other.entry_id<>pi.entry_id OR other.line_no<>pi.line_no))
            WHERE c.supplier_id=? AND c.kind='issue' AND r.id IS NULL
                AND " . MembershipBalanceRepository::receiptValiditySql() . "=1 AND $filter";
    }

    /** Jen okruhy konkrétních řádků, včetně osamocených (readery je jinak skrývají). */
    public function pairingIdsForLines(int $supplier, array $lineIds): array
    {
        if ($lineIds === []) return [];
        $in = implode(',', array_fill(0, count($lineIds), '?'));
        $q = $this->db->pdo()->prepare("SELECT DISTINCT i.pairing_id FROM journal_line_pairing_items i
            JOIN journal_entry_lines l ON l.supplier_id=i.supplier_id AND l.entry_id=i.entry_id
                AND l.line_no=i.line_no AND l.account_id=i.account_id
            WHERE i.supplier_id=? AND l.id IN ($in)");
        $q->execute([$supplier, ...$lineIds]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function paymentLines(int $supplier, int $transaction): array
    {
        $q = $this->db->pdo()->prepare("SELECT e.id entry_id,e.posted_at,e.reversed_by,l.id line_id,l.line_no,l.account_id,
            l.side,l.is_red_storno,l.currency_code,l.amount_foreign,l.fx_rate,ROUND(l.signed_amount*100) cents
            FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND e.reversed_by IS NULL
            ORDER BY e.id,l.line_no FOR UPDATE");
        $q->execute([$supplier, $transaction]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
