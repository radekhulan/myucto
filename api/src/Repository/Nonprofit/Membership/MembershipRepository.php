<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipRecordedTime;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipRepository
{
    public function __construct(private readonly Connection $db) {}

    public function member(int $supplier, int $member, bool $currentRead = false): ?array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_members WHERE supplier_id=? AND id=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $member]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->memberRow($row);
    }

    public function page(int $supplier, int $afterId = 0, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $q = $this->db->pdo()->prepare(
            "SELECT m.*,c.company_name,r.revision_no,r.effective_from,r.status
               FROM nonprofit_members m
               JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
          LEFT JOIN nonprofit_member_revisions r
                 ON r.supplier_id=m.supplier_id AND r.member_id=m.id
                AND NOT EXISTS(SELECT 1 FROM nonprofit_member_revisions newer
                                WHERE newer.supplier_id=r.supplier_id AND newer.member_id=r.member_id
                                  AND newer.revision_no>r.revision_no)
              WHERE m.supplier_id=? AND m.id>?
              ORDER BY m.id
              LIMIT {$limit}"
        );
        $q->execute([$supplier, max(0, $afterId)]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['revision_no'] = $row['revision_no'] === null ? null : (int) $row['revision_no'];
            $rows[] = $row;
        }
        return $rows;
    }

    public function byIdentity(int $supplier, int $client, string $number, bool $currentRead = false): ?array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_members WHERE supplier_id=? AND (client_id=? OR member_number=?) ORDER BY id' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $client, $number]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        if (count($rows) !== 1 || (int) $rows[0]['client_id'] !== $client || $rows[0]['member_number'] !== $number) {
            throw new MembershipException('nonprofit_membership_identity_conflict', 'Klient nebo členské číslo už patří jinému členovi.', 409);
        }
        return $this->memberRow($rows[0]);
    }

    public function insertMember(int $supplier, int $client, string $number, int $actor): array
    {
        $recordedAt = MembershipRecordedTime::nowSql();
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_members(supplier_id,client_id,member_number,created_by,created_at,recorded_at_utc) VALUES(?,?,?,?,?,?)');
        $q->execute([$supplier, $client, $number, $actor, $recordedAt, $recordedAt]);
        return $this->member($supplier, (int) $this->db->pdo()->lastInsertId(), true)
            ?? throw new \LogicException('Inserted member disappeared.');
    }

    public function history(int $supplier, int $member, bool $currentRead = false): array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=? ORDER BY revision_no,id' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $member]);
        $rows = [];
        $previous = null;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = $this->revisionRow($raw);
            if ($row['revision_no'] !== ($previous['revision_no'] ?? 0) + 1 || $row['previous_id'] !== ($previous['id'] ?? null)
                || !hash_equals($row['parent_hash'], $previous['record_hash'] ?? str_repeat('0', 64))
                || !hash_equals($row['request_hash'], NonprofitTaxRules::hash($row['request']))
                || !hash_equals($row['record_hash'], NonprofitTaxRules::hash(self::revisionRecord($row)))) {
                throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
            }
            $rows[] = $row;
            $previous = $row;
        }
        MembershipRevisionTimeline::intervals($rows);
        return $rows;
    }

    public function historiesMany(int $supplier, array $memberIds, ?string $knownAtSql = null): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($supplier <= 0 || $memberIds === [] || count($memberIds) > 500 || min($memberIds) <= 0) throw new \InvalidArgumentException('Membership history batch must contain one tenant and 1-500 members.');
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        if ($knownAtSql !== null) $this->assertRecordedTimeProven($supplier, $memberIds);
        $known = $knownAtSql === null ? '' : ' AND (recorded_at_utc<=? OR recorded_at_utc IS NULL)';
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id IN ({$marks}){$known} ORDER BY member_id,revision_no,id");
        $params = [$supplier, ...$memberIds];
        if ($knownAtSql !== null) $params[] = $knownAtSql;
        $q->execute($params);
        $groups = array_fill_keys($memberIds, []);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            if ($knownAtSql !== null && $raw['recorded_at_utc'] === null) $this->recordedTimeUnproven();
            $groups[(int) $raw['member_id']][] = $this->revisionRow($raw);
        }
        foreach ($groups as $member => $rows) $groups[$member] = $this->validateHistory($rows);
        return $groups;
    }

    public function effectiveMany(int $supplier, array $memberIds, string $asOf, string $knownAtSql): array
    {
        MembershipRevisionTimeline::date($asOf);
        $this->assertRecordedTimeProven($supplier, $memberIds);
        $state = $this->scanVerified($supplier, $memberIds, $knownAtSql, null, $asOf);
        $out = [];
        foreach ($state as $member => $item) {
            if ($item['effective'] === null) continue;
            $row = $item['effective'];
            $row['effective_to'] = $item['next_effective'];
            $out[$member] = $row;
        }
        return $out;
    }

    public function revisionsMany(int $supplier, array $memberRevisionIds): array
    {
        $memberIds = array_keys($memberRevisionIds);
        $targets = [];
        foreach ($memberRevisionIds as $member => $revisions) {
            if (!is_int($member) || $member <= 0 || !is_array($revisions) || $revisions === []) throw new \InvalidArgumentException('Membership revision targets are invalid.');
            foreach ($revisions as $revision) {
                if (!is_int($revision) || $revision <= 0) throw new \InvalidArgumentException('Membership revision targets are invalid.');
                $targets[$member][$revision] = true;
            }
        }
        $state = $this->scanVerified($supplier, $memberIds, null, $targets, null);
        $out = [];
        foreach ($targets as $member => $revisions) {
            foreach ($revisions as $revision => $_) {
                $row = $state[$member]['target'][$revision] ?? null;
                if ($row === null) throw new MembershipException('nonprofit_membership_member_proof_changed', 'Zachycený členský podklad není průkazný.', 409);
                $out[$revision] = $row;
            }
        }
        return $out;
    }

    public function publicMembersPage(int $supplier, string $asOf, string $knownAtSql, int $afterId, int $limit): array
    {
        $limit = max(1, min(101, $limit));
        $q = $this->db->pdo()->prepare("SELECT m.id,m.client_id,m.recorded_at_utc
            FROM nonprofit_members m FORCE INDEX(uq_nm_tenant)
            WHERE m.supplier_id=? AND m.id>?
              AND (m.recorded_at_utc<=? OR m.recorded_at_utc IS NULL)
              AND EXISTS(SELECT 1 FROM nonprofit_member_revisions r FORCE INDEX(ix_nmr_effective_lookup)
                          WHERE r.supplier_id=m.supplier_id AND r.member_id=m.id AND r.effective_from<=?
                            AND (r.recorded_at_utc<=? OR r.recorded_at_utc IS NULL))
            ORDER BY m.id LIMIT {$limit}");
        $q->execute([$supplier, max(0, $afterId), $knownAtSql, $asOf, $knownAtSql]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows !== []) $this->assertRecordedTimeProven($supplier, array_map('intval', array_column($rows, 'id')));
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'client_id' => (int) $row['client_id'],
            'recorded_at_utc' => $row['recorded_at_utc']], $rows);
    }

    public function publicMember(int $supplier, int $member, string $knownAtSql): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT id,client_id,recorded_at_utc FROM nonprofit_members WHERE supplier_id=? AND id=?');
        $q->execute([$supplier, $member]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ($row['recorded_at_utc'] === null) $this->recordedTimeUnproven();
        if ($row['recorded_at_utc'] > $knownAtSql) return null;
        return ['id' => (int) $row['id'], 'client_id' => (int) $row['client_id'], 'recorded_at_utc' => $row['recorded_at_utc']];
    }

    public function revisionByKey(int $supplier, string $key, bool $currentRead = false): ?array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT member_id FROM nonprofit_member_revisions WHERE supplier_id=? AND idempotency_key=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $key]);
        $member = $q->fetchColumn();
        if ($member === false) return null;
        foreach ($this->history($supplier, (int) $member, $currentRead) as $row) if ($row['idempotency_key'] === $key) return $row;
        throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
    }

    public function revision(int $supplier, int $member, int $revision, bool $currentRead = false): ?array
    {
        foreach ($this->history($supplier, $member, $currentRead) as $row) if ($row['id'] === $revision) return $row;
        return null;
    }

    public function appendRevision(array $row): array
    {
        $row['recorded_at_utc'] = MembershipRecordedTime::nowSql();
        $record = self::revisionRecord($row);
        $row['record_hash'] = NonprofitTaxRules::hash($record);
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_member_revisions(supplier_id,member_id,revision_no,previous_id,effective_from,status,legal_basis_json,request_json,idempotency_key,request_hash,parent_hash,record_hash,created_by,created_at,recorded_at_utc) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['supplier_id'], $row['member_id'], $row['revision_no'], $row['previous_id'], $row['effective_from'], $row['status'],
            NonprofitTaxRules::canonicalJson($row['legal_basis']), NonprofitTaxRules::canonicalJson($row['request']), $row['idempotency_key'], $row['request_hash'], $row['parent_hash'], $row['record_hash'], $row['created_by'], $row['recorded_at_utc'], $row['recorded_at_utc']]);
        return $this->revision((int) $row['supplier_id'], (int) $row['member_id'], (int) $this->db->pdo()->lastInsertId(), true)
            ?? throw new \LogicException('Inserted membership revision disappeared.');
    }

    public static function revisionRecord(array $row): array
    {
        $record = array_intersect_key($row, array_flip(['supplier_id', 'member_id', 'revision_no', 'previous_id', 'effective_from', 'status', 'legal_basis',
            'request', 'idempotency_key', 'request_hash', 'parent_hash', 'created_by']));
        if (($row['recorded_at_utc'] ?? null) !== null) $record['recorded_at_utc'] = $row['recorded_at_utc'];
        return $record;
    }

    private function revisionRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'member_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
        foreach (['legal_basis', 'request'] as $field) {
            $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row[$field . '_json']);
        }
        return $row;
    }

    private function validateHistory(array $rows): array
    {
        $previous = null;
        foreach ($rows as $row) {
            if ($row['revision_no'] !== ($previous['revision_no'] ?? 0) + 1 || $row['previous_id'] !== ($previous['id'] ?? null)
                || !hash_equals($row['parent_hash'], $previous['record_hash'] ?? str_repeat('0', 64))
                || !hash_equals($row['request_hash'], NonprofitTaxRules::hash($row['request']))
                || !hash_equals($row['record_hash'], NonprofitTaxRules::hash(self::revisionRecord($row)))) {
                throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
            }
            $previous = $row;
        }
        MembershipRevisionTimeline::intervals($rows);
        return $rows;
    }

    private function scanVerified(int $supplier, array $memberIds, ?string $knownAtSql, ?array $targets, ?string $asOf): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($supplier <= 0 || $memberIds === [] || count($memberIds) > 500 || min($memberIds) <= 0) throw new \InvalidArgumentException('Membership revision scan must contain one tenant and 1-500 members.');
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $known = $knownAtSql === null ? '' : ' AND (recorded_at_utc<=? OR recorded_at_utc IS NULL)';
        $cursorMember = null;
        $cursorId = 0;
        $state = [];
        foreach ($memberIds as $member) $state[$member] = ['previous' => null, 'effective' => null, 'next_effective' => null, 'target' => []];
        do {
            $after = $cursorMember === null ? '' : ' AND (member_id>? OR (member_id=? AND id>?))';
            $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_member_revisions FORCE INDEX(uq_nmr_chain) WHERE supplier_id=? AND member_id IN ({$marks}){$after}{$known} ORDER BY supplier_id,member_id,id LIMIT 500");
            $params = [$supplier, ...$memberIds];
            if ($cursorMember !== null) array_push($params, $cursorMember, $cursorMember, $cursorId);
            if ($knownAtSql !== null) $params[] = $knownAtSql;
            $q->execute($params);
            $batch = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($batch as $raw) {
                if ($knownAtSql !== null && $raw['recorded_at_utc'] === null) $this->recordedTimeUnproven();
                $row = $this->revisionRow($raw);
                $member = $row['member_id'];
                $previous = $state[$member]['previous'];
                if ($row['revision_no'] !== ($previous['revision_no'] ?? 0) + 1 || $row['previous_id'] !== ($previous['id'] ?? null)
                    || !hash_equals($row['parent_hash'], $previous['record_hash'] ?? str_repeat('0', 64))
                    || !hash_equals($row['request_hash'], NonprofitTaxRules::hash($row['request']))
                || !hash_equals($row['record_hash'], NonprofitTaxRules::hash(self::revisionRecord($row)))) {
                    throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
                }
                MembershipRevisionTimeline::date($row['effective_from']);
                if ($targets !== null && isset($targets[$member][$row['id']])) $state[$member]['target'][$row['id']] = $row;
                if ($asOf !== null) {
                    if ($row['effective_from'] <= $asOf && ($state[$member]['effective'] === null || $row['effective_from'] > $state[$member]['effective']['effective_from'])) {
                        $state[$member]['effective'] = $row;
                    } elseif ($row['effective_from'] > $asOf
                        && ($state[$member]['next_effective'] === null || $row['effective_from'] < $state[$member]['next_effective'])) {
                        $state[$member]['next_effective'] = $row['effective_from'];
                    }
                }
                $state[$member]['previous'] = $row;
                $cursorMember = $member;
                $cursorId = $row['id'];
            }
        } while (count($batch) === 500);
        return $state;
    }

    private function assertRecordedTimeProven(int $supplier, array $memberIds): void
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === [] || count($memberIds) > 500 || min($memberIds) <= 0) throw new \InvalidArgumentException('Membership recorded-time batch must contain 1-500 members.');
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $q = $this->db->pdo()->prepare("SELECT id FROM nonprofit_members FORCE INDEX(uq_nm_tenant) WHERE supplier_id=? AND id IN ({$marks}) AND recorded_at_utc IS NULL LIMIT 1");
        $q->execute([$supplier, ...$memberIds]);
        if ($q->fetchColumn() !== false) $this->recordedTimeUnproven();
    }

    private function recordedTimeUnproven(): never
    {
        throw new MembershipException('nonprofit_membership_recorded_time_unproven', 'Historický čas záznamu členství nemá průkazný UTC základ.', 409);
    }

    private function memberRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }

    private function current(bool $currentRead): void
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Membership current reads require a transaction.');
    }
}
