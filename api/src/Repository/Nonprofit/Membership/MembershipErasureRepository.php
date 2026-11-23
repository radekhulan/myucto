<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Návrhy výmazu osobních údajů členů a jejich položky (#321, krok 10).
 * Položka je zároveň dokladem o výmazu: nese jen ID člena, pravidlo, data a počty.
 */
final class MembershipErasureRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Členové (i smazaní) s položkou v rozpracovaném nebo provedeném návrhu; do nového návrhu nepatří znovu. */
    /** @param list<int> $memberIds */
    public function claimedAmong(int $supplier, array $memberIds, string $subject, ?int $ownProposal = null): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) return [];
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $q = $this->db->pdo()->prepare("SELECT DISTINCT i.member_id FROM nonprofit_membership_erasure_items i FORCE INDEX(ix_nmei_member)
            JOIN nonprofit_membership_erasure_proposals p ON p.supplier_id=i.supplier_id AND p.id=i.proposal_id
            WHERE i.supplier_id=? AND i.member_id IN ({$marks}) AND i.subject=?
              AND (i.outcome='done' OR (i.outcome='pending' AND p.status IN ('pending','approved') AND i.proposal_id<>?))");
        $q->execute([$supplier, ...$memberIds, $subject, $ownProposal ?? 0]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function insertProposal(int $supplier, string $asOf, ?string $note, int $actor): int
    {
        $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_erasure_proposals(supplier_id,as_of,note,created_by) VALUES(?,?,?,?)')
            ->execute([$supplier, $asOf, $note, $actor]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param list<array<string,mixed>> $items posouzení členů, kteří jdou vymazat */
    public function insertItems(int $supplier, int $proposal, array $items): void
    {
        foreach (array_chunk($items, 100) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $item) {
                $values[] = '(?,?,?,?,?,?,?,?)';
                array_push($params, $supplier, $proposal, $item['member_id'], $item['subject'], $item['governing_rule'], $item['ended_on'],
                    $item['last_record_period_end'], $item['retained_until']);
            }
            $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_erasure_items(supplier_id,proposal_id,member_id,subject,governing_rule,ended_on,last_record_period_end,retained_until) VALUES '
                . implode(',', $values))->execute($params);
        }
    }

    public function proposal(int $supplier, int $proposal, bool $lock = false): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_erasure_proposals WHERE supplier_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $proposal]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::proposalRow($row);
    }

    /** Návrhy s počty položek po výsledcích; nejnovější první. */
    public function proposals(int $supplier, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $q = $this->db->pdo()->prepare("SELECT p.*,COUNT(i.id) item_count,COALESCE(SUM(i.outcome='pending'),0) pending_count,COALESCE(SUM(i.outcome='done'),0) done_count,
                COALESCE(SUM(i.outcome IN ('skipped_changed','skipped_hold')),0) skipped_count
            FROM nonprofit_membership_erasure_proposals p FORCE INDEX(uq_nmep_tenant)
            LEFT JOIN nonprofit_membership_erasure_items i ON i.supplier_id=p.supplier_id AND i.proposal_id=p.id
            WHERE p.supplier_id=? GROUP BY p.id ORDER BY p.id DESC LIMIT {$limit}");
        $q->execute([$supplier]);
        return array_map(static function (array $row): array {
            $out = self::proposalRow($row);
            foreach (['item_count', 'pending_count', 'done_count', 'skipped_count'] as $field) $out[$field] = (int) $row[$field];
            return $out;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Položky návrhu. Jméno a číslo člena se připojí jen k položce, která ještě neproběhla (ke kontrole
     * před schválením); provedená a přeskočená položka ani doklad o výmazu osobní údaj nevrací.
     */
    public function items(int $supplier, int $proposal, int $after = 0, int $limit = 100): array
    {
        $limit = max(1, min(501, $limit));
        // Jméno jen v otevřeném návrhu (ke schválení, schválený) a jen u nevymazaného člena: ke kontrole před
        // provedením a u přeskočené položky, jejíž údaje zůstaly (uživatel musí vědět, koho drží zadržení).
        // Uzavřený (zamítnutý, provedený) návrh osobní údaj nevrací ani u přeskočeného člena (minimalizace údajů
        // v dokladu o výmazu); člena pak dohledá podle ID v seznamu, ani když ho mezitím vymazal jiný návrh.
        $show = "i.outcome<>'done' AND i.subject='member' AND p.status IN ('pending','approved') AND NOT " . MembershipListRepository::erasedSql('m');
        $q = $this->db->pdo()->prepare("SELECT i.*,
                CASE WHEN {$show} THEN m.member_number END member_number,
                CASE WHEN {$show} THEN c.company_name END company_name,
                CASE WHEN {$show} THEN c.first_name END first_name,
                CASE WHEN {$show} THEN c.last_name END last_name
            FROM nonprofit_membership_erasure_items i FORCE INDEX(uq_nmei_member)
            JOIN nonprofit_membership_erasure_proposals p ON p.supplier_id=i.supplier_id AND p.id=i.proposal_id
            LEFT JOIN nonprofit_members m ON m.supplier_id=i.supplier_id AND m.id=i.member_id AND i.subject='member'
            LEFT JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
            WHERE i.supplier_id=? AND i.proposal_id=? AND i.member_id>? ORDER BY i.member_id LIMIT {$limit}");
        $q->execute([$supplier, $proposal, $after]);
        return array_map(self::itemRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Další položka čekající na provedení; zamyká se pod zámkem hlavičky návrhu. */
    public function nextPending(int $supplier, int $proposal): ?array
    {
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_membership_erasure_items FORCE INDEX(uq_nmei_member)
            WHERE supplier_id=? AND proposal_id=? AND outcome='pending' ORDER BY member_id LIMIT 1 FOR UPDATE");
        $q->execute([$supplier, $proposal]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::itemRow($row);
    }

    public function finishItem(int $supplier, int $item, string $outcome, ?string $skipReason, array $counts): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_erasure_items SET outcome=?,skip_reason=?,counts_json=?,executed_at=UTC_TIMESTAMP()
            WHERE supplier_id=? AND id=? AND outcome='pending'");
        $q->execute([$outcome, $skipReason, json_encode($counts, JSON_THROW_ON_ERROR), $supplier, $item]);
        if ($q->rowCount() !== 1) throw new \LogicException('Erasure item changed while it was being executed.');
    }

    public static function proposalRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'row_version'] as $field) $row[$field] = (int) $row[$field];
        foreach (['created_by', 'approved_by', 'revoked_by', 'rejected_by', 'executed_by'] as $field) $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        return $row;
    }

    private static function itemRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'proposal_id', 'member_id'] as $field) $row[$field] = (int) $row[$field];
        $row['counts'] = $row['counts_json'] === null ? null : json_decode((string) $row['counts_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['counts_json']);
        return $row;
    }
}
