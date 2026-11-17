<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipErasureRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Service\Accounting\RetentionPolicy;
use PDO;

/**
 * Posouzení, kterým bývalým členům uplynula lhůta pro uchování osobních údajů (#321, krok 10).
 *
 * Lhůta běží od ukončení členství a od posledního účetního záznamu, který na členovi stojí;
 * platí ta, která skončí později:
 *   - účetní záznam (předpis, storno, úhrada spárovaná s pohledávkou člena): § 31 odst. 2
 *     písm. b) ZoÚ, 5 let od konce účetního období posledního záznamu (RetentionPolicy),
 *   - samotné členství: 1 rok od ukončení (rozhodnutí zadavatele, ochrana pro spor o členství).
 * Smazaný člen (krok 7) má jen auditní stopu; ta se redaktuje rok po smazání.
 *
 * Uplynulá lhůta není příkaz ke smazání. Posouzení nic nemění; výmaz vždy prochází
 * návrhem a schválením (MembershipErasureService).
 */
final class MembershipErasureAssessment
{
    public const RULE_ACCOUNTING = 'accounting_records';
    public const RULE_MEMBERSHIP = 'membership_only';
    public const SUBJECT_MEMBER = 'member';
    public const SUBJECT_DELETED = 'deleted_member';
    /** Ochranná lhůta po ukončení členství bez účetního záznamu (a po smazání člena). */
    public const MEMBERSHIP_RETENTION_YEARS = 1;
    public const PAGE = 500;

    /** Všechny předpisy bez ohledu na rok a titul; filtr seznamu bez hledání. */
    private const ALL_DUES = ['search' => '', 'title_key' => '', 'year' => null, 'payment_status' => 'all'];
    private const OPEN_BATCH_PHASES = "'seeding','preparing','ready','posting'";

    public function __construct(
        private readonly Connection $db,
        private readonly MembershipListRepository $list,
        private readonly RetentionHoldRepository $holds,
        private readonly MembershipErasureRepository $erasures,
    ) {}

    /**
     * Stránka posouzení ukončených členů, kteří ještě nejsou vymazaní (podle ID člena).
     *
     * @param list<int>|null $only omezení na konkrétní členy (provedení návrhu znovu posuzuje svého člena)
     * @param int|null $ownProposal návrh, jehož vlastní položky se za „už navržené“ nepočítají
     * @return array{items:list<array<string,mixed>>, next_after:?int}
     */
    public function members(int $supplier, string $asOf, int $after = 0, int $limit = self::PAGE, ?array $only = null, ?int $ownProposal = null): array
    {
        $limit = max(1, min(self::PAGE, $limit));
        $params = [$supplier, $after];
        $onlySql = '';
        if ($only !== null) {
            if ($only === [] || count($only) > self::PAGE) throw new \InvalidArgumentException('Erasure assessment accepts 1-500 members.');
            $onlySql = ' AND m.id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
            array_push($params, ...array_map('intval', $only));
        }
        // Kandidát = poslední platná revize je „ukončeno“ (stejné pravidlo jako seznam členů).
        $q = $this->db->pdo()->prepare('SELECT m.id,r.effective_from ended_on
            FROM nonprofit_members m FORCE INDEX(uq_nm_tenant)
            JOIN nonprofit_member_revisions r ON r.supplier_id=m.supplier_id AND r.id=' . MembershipListRepository::latestValidRevisionIdSql('m') . "
            LEFT JOIN nonprofit_membership_erasure_items i FORCE INDEX(ix_nmei_member)
              ON i.supplier_id=m.supplier_id AND i.member_id=m.id AND i.outcome='done' AND i.subject='member'
            WHERE m.supplier_id=? AND m.id>?{$onlySql} AND r.status='ended' AND i.id IS NULL
            ORDER BY m.id LIMIT " . ($limit + 1));
        // Vymazaný člen se vylučuje spojením po indexu člena; NOT EXISTS optimizer materializoval přes položky všech firem.
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int) $rows[array_key_last($rows)]['id'];
        }
        if ($rows === []) return ['items' => [], 'next_after' => null];
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $records = $this->lastRecords($supplier, $ids);
        $dues = $this->dues($supplier, $ids);
        $batches = $this->openBatchMembers($supplier, $ids);
        $holdYears = $this->holdYears($supplier);
        $claimed = array_flip($this->erasures->claimedAmong($supplier, $ids, self::SUBJECT_MEMBER, $ownProposal));
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $record = $records[$id] ?? null;
            $item = self::retention(self::SUBJECT_MEMBER, $id, (string) $row['ended_on'], $record['last_end'] ?? null);
            $blocked = null;
            if (($dues[$id] ?? 'none') === 'unpaid') $blocked = 'open_receivable';
            elseif (($dues[$id] ?? 'none') === 'unproven') $blocked = 'unproven_receivable';
            elseif (self::held($holdYears, $record)) $blocked = 'retention_hold';
            elseif (isset($batches[$id])) $blocked = 'open_batch';
            elseif (isset($claimed[$id])) $blocked = 'already_proposed';
            elseif ($item['retained_until'] >= $asOf) $blocked = 'within_retention';
            $items[] = $item + ['blocked_by' => $blocked, 'erasable' => $blocked === null];
        }
        return ['items' => $items, 'next_after' => $next];
    }

    /**
     * Smazaní členové (krok 7), jejichž auditní stopa ještě nese číslo a kontakt.
     *
     * @return list<array<string,mixed>>
     */
    public function deletedMembers(int $supplier, string $asOf, ?array $only = null, ?int $ownProposal = null): array
    {
        $onlySql = '';
        $params = [$supplier];
        if ($only !== null) {
            if ($only === [] || count($only) > self::PAGE) throw new \InvalidArgumentException('Erasure assessment accepts 1-500 members.');
            $onlySql = ' AND a.entity_id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
            array_push($params, ...array_map('intval', $only));
        }
        $q = $this->db->pdo()->prepare("SELECT a.entity_id member_id,MAX(DATE(a.created_at)) deleted_on
            FROM activity_log a FORCE INDEX(idx_al_action)
            WHERE a.action='nonprofit_membership_member_delete' AND a.supplier_id=? AND a.entity_type='nonprofit_member'{$onlySql}
              AND NOT EXISTS(SELECT 1 FROM nonprofit_members m WHERE m.id=a.entity_id)
              AND NOT EXISTS(SELECT 1 FROM nonprofit_membership_erasure_items i FORCE INDEX(ix_nmei_member)
                WHERE i.supplier_id=a.supplier_id AND i.member_id=a.entity_id AND i.outcome='done' AND i.subject='deleted_member')
            GROUP BY a.entity_id ORDER BY deleted_on,a.entity_id LIMIT " . self::PAGE);
        // Nejdřív nejstarší smazání (jim lhůta uplyne první); už vymazané stopy dotaz vynechá, takže
        // další návrh dostane dalších nejvýše 500.
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return [];
        $ids = array_map(static fn (array $row): int => (int) $row['member_id'], $rows);
        $claimed = array_flip($this->erasures->claimedAmong($supplier, $ids, self::SUBJECT_DELETED, $ownProposal));
        // Smazaný člen nemá účetní záznam; drží ho jen zadržení celého účetnictví (bez roku).
        $wholeHold = self::held($this->holdYears($supplier), null);
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['member_id'];
            $item = self::retention(self::SUBJECT_DELETED, $id, (string) $row['deleted_on'], null);
            $blocked = $wholeHold ? 'retention_hold'
                : (isset($claimed[$id]) ? 'already_proposed' : ($item['retained_until'] >= $asOf ? 'within_retention' : null));
            $items[] = $item + ['blocked_by' => $blocked, 'erasable' => $blocked === null];
        }
        return $items;
    }

    /** Rozhodná data a pravidlo; delší z obou lhůt rozhoduje. */
    public static function retention(string $subject, int $member, string $endedOn, ?string $lastRecordPeriodEnd): array
    {
        $membership = (new \DateTimeImmutable($endedOn))->modify('+' . self::MEMBERSHIP_RETENTION_YEARS . ' years')->format('Y-m-d');
        $accounting = $lastRecordPeriodEnd === null ? null : RetentionPolicy::retainUntil(RetentionPolicy::ACCOUNTING_RECORDS, $lastRecordPeriodEnd);
        $useAccounting = $accounting !== null && $accounting >= $membership;
        return [
            'member_id' => $member,
            'subject' => $subject,
            'ended_on' => $endedOn,
            'last_record_period_end' => $lastRecordPeriodEnd,
            'retained_until' => $useAccounting ? $accounting : $membership,
            'governing_rule' => $useAccounting ? self::RULE_ACCOUNTING : self::RULE_MEMBERSHIP,
        ];
    }

    /**
     * Konec účetního období a rozsah let posledních účetních záznamů člena: operace jeho případů
     * a zápisy spárované s jeho pohledávkami (úhrada z banky nebo pokladny).
     *
     * @param list<int> $ids
     * @return array<int,array{last_end:string,first_year:int,last_year:int}>
     */
    private function lastRecords(int $supplier, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->pdo()->prepare("SELECT member_id,MAX(ends_on) last_end,MIN(fiscal_year) first_year,MAX(fiscal_year) last_year FROM (
              SELECT cc.member_id,ap.ends_on,ap.fiscal_year
                FROM nonprofit_membership_charge_cases cc FORCE INDEX(uq_nmcc_identity)
                JOIN nonprofit_membership_charge_operations o ON o.supplier_id=cc.supplier_id AND o.case_id=cc.id
                JOIN accounting_periods ap ON ap.supplier_id=o.supplier_id AND ap.id=o.accounting_period_id
               WHERE cc.supplier_id=? AND cc.member_id IN ({$marks})
              UNION ALL
              SELECT cc.member_id,ap.ends_on,ap.fiscal_year
                FROM nonprofit_membership_charge_cases cc FORCE INDEX(uq_nmcc_identity)
                JOIN nonprofit_membership_charge_operations o ON o.supplier_id=cc.supplier_id AND o.case_id=cc.id AND o.operation='issue'
                JOIN journal_entry_lines l ON l.supplier_id=o.supplier_id AND l.id=o.receivable_line_id
                JOIN journal_line_pairing_items i ON i.supplier_id=o.supplier_id AND i.entry_id=o.journal_entry_id AND i.line_no=l.line_no
                JOIN journal_line_pairing_items p ON p.supplier_id=i.supplier_id AND p.pairing_id=i.pairing_id
                JOIN journal_entries e ON e.supplier_id=p.supplier_id AND e.id=p.entry_id
                JOIN accounting_periods ap ON ap.supplier_id=e.supplier_id AND ap.id=e.period_id
               WHERE cc.supplier_id=? AND cc.member_id IN ({$marks})
            ) records GROUP BY member_id");
        $q->execute([$supplier, ...$ids, $supplier, ...$ids]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['member_id']] = ['last_end' => (string) $row['last_end'], 'first_year' => (int) $row['first_year'], 'last_year' => (int) $row['last_year']];
        }
        return $out;
    }

    /**
     * Stav úhrady všech předpisů člena; stejná SQL agregace jako seznam členů (nezaplacený nebo
     * neprokázaný předpis výmaz blokuje, pohledávka se ještě může vymáhat).
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function dues(int $supplier, array $ids): array
    {
        [$sql, $params] = $this->list->query($supplier, 0, 501, self::ALL_DUES, $ids);
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($params);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['id']] = (string) $row['dues_status'];
        return $out;
    }

    /**
     * Členové v rozpracované dávce: zapsaná položka v otevřené fázi, nebo plněná dávka, do které člen
     * ještě může spadnout (stejné podmínky jako MembershipCorrectionService::seedingBatchIds()).
     *
     * @param list<int> $ids
     * @return array<int,true>
     */
    private function openBatchMembers(int $supplier, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->pdo()->prepare('SELECT DISTINCT bm.member_id FROM nonprofit_membership_batch_members bm
            JOIN nonprofit_membership_batches b ON b.supplier_id=bm.supplier_id AND b.id=bm.batch_id
            WHERE bm.supplier_id=? AND bm.member_id IN (' . $marks . ') AND b.phase IN (' . self::OPEN_BATCH_PHASES . ')');
        $q->execute([$supplier, ...$ids]);
        $out = array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
        $q = $this->db->pdo()->prepare("SELECT selection_mode,selection_json,seed_cursor,seed_until FROM nonprofit_membership_batches FORCE INDEX(ix_nmb_phase)
            WHERE supplier_id=? AND phase='seeding'");
        $q->execute([$supplier]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $batch) {
            $selected = $batch['selection_mode'] === 'active' ? null : array_map('intval', (array) json_decode((string) $batch['selection_json'], true));
            foreach ($ids as $id) {
                if ((int) $batch['seed_cursor'] < $id && (int) $batch['seed_until'] >= $id && ($selected === null || in_array($id, $selected, true))) $out[$id] = true;
            }
        }
        return $out;
    }

    /** @return list<?int> roky aktivních firemních zadržení (null = celé účetnictví) */
    private function holdYears(int $supplier): array
    {
        return array_map(static fn (array $hold): ?int => $hold['period_year'] === null ? null : (int) $hold['period_year'], $this->holds->activeHolds($supplier));
    }

    /** Zadržení podle § 32 ZoÚ drží záznamy svého roku; zadržení celého účetnictví drží každého člena. */
    private static function held(array $holdYears, ?array $record): bool
    {
        foreach ($holdYears as $year) {
            if ($year === null) return true;
            if ($record !== null && $year >= $record['first_year'] && $year <= $record['last_year']) return true;
        }
        return false;
    }
}
