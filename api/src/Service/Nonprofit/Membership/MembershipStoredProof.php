<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoLegalRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipStoredProof
{
    public function __construct(private readonly Connection $db, private readonly MembershipRepository $members) {}

    public function assertMany(int $supplier, array $operations, bool $current = false): void
    {
        if ($supplier <= 0 || $operations === [] || count($operations) > 500) throw new \InvalidArgumentException('Membership stored proof batch must contain one tenant and 1-500 operations.');
        $memberIds = [];
        $periodIds = [];
        $legalTargets = [];
        $classifications = [];
        foreach ($operations as $operation) {
            if ((int) ($operation['supplier_id'] ?? 0) !== $supplier || !is_array($operation['proof'] ?? null)) self::memberChanged();
            $proof = $operation['proof'];
            $memberIds[(int) ($proof['member']['id'] ?? 0)] = (int) ($proof['member']['id'] ?? 0);
            $periodIds[(int) ($proof['accounting_period']['id'] ?? 0)] = (int) ($proof['accounting_period']['id'] ?? 0);
            $legalTargets[(int) ($proof['accounting_period']['id'] ?? 0)][(int) ($operation['legal_profile_id'] ?? 0)] = true;
            $classifications[(int) $operation['id']] = $proof['classification'] ?? [];
        }
        if (isset($memberIds[0]) || isset($periodIds[0])) self::memberChanged();
        (new MembershipClassification($this->db))->assertStoredMany($supplier, $classifications, $current);
        $revisionTargets = [];
        foreach ($operations as $operation) $revisionTargets[(int) $operation['proof']['member']['id']][] = (int) $operation['member_revision_id'];
        $revisions = $this->members->revisionsMany($supplier, $revisionTargets);
        $legal = $this->legalProfiles($supplier, array_values($periodIds), $legalTargets);
        foreach ($operations as $operation) {
            $proof = $operation['proof'];
            $revision = $revisions[(int) $operation['member_revision_id']] ?? null;
            if ($revision === null || !hash_equals($revision['record_hash'], (string) ($proof['member_revision']['record_hash'] ?? ''))
                || ($revision['legal_basis']['organisation_form'] ?? null) !== 'association'
                || ($revision['legal_basis']['relationship'] ?? null) !== 'nonreciprocal_membership') self::memberChanged();
            $profile = $legal[(int) $operation['legal_profile_id']] ?? null;
            if ($profile === null || !hash_equals($profile['record_hash'], (string) ($proof['legal_profile']['record_hash'] ?? ''))
                || ($profile['profile']['entity_type'] ?? null) !== 'association'
                || ($proof['legal_title']['relationship'] ?? null) !== 'nonreciprocal_membership') self::legalChanged();
        }
        (new MembershipJournalProof($this->db))->assertOperations($supplier, $operations, $current);
    }

    private function legalProfiles(int $supplier, array $periodIds, array $targets): array
    {
        if ($periodIds === [] || count($periodIds) > 500) self::legalChanged();
        $marks = implode(',', array_fill(0, count($periodIds), '?'));
        $previous = [];
        $out = [];
        $cursorPeriod = null;
        $cursorId = 0;
        do {
            $after = $cursorPeriod === null ? '' : ' AND (period_id>? OR (period_id=? AND id>?))';
            $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_legal_profiles FORCE INDEX(uq_nlp_period) WHERE supplier_id=? AND period_id IN ({$marks}){$after} ORDER BY supplier_id,period_id,id LIMIT 500");
            $params = [$supplier, ...$periodIds];
            if ($cursorPeriod !== null) array_push($params, $cursorPeriod, $cursorPeriod, $cursorId);
            $q->execute($params);
            $batch = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($batch as $row) {
                foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
                $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
                foreach (['profile', 'module_proof'] as $field) {
                    $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
                    unset($row[$field . '_json']);
                }
                $period = $row['period_id'];
                $prior = $previous[$period] ?? null;
                $record = array_intersect_key($row, array_flip(['supplier_id', 'period_id', 'revision_no', 'previous_id', 'profile', 'module_proof', 'reason', 'idempotency_key', 'request_hash', 'created_by']));
                $record['parent_hash'] = $prior['record_hash'] ?? null;
                $request = ['period_id' => $period, 'expected_revision' => $row['revision_no'] - 1, 'profile' => $row['profile'], 'reason' => $row['reason'], 'actor' => $row['created_by']];
                try { $canonical = NonprofitDppoLegalRules::profile($row['profile'], $row['module_proof'] !== []); }
                catch (\Throwable) { self::legalChanged(); }
                if ($row['revision_no'] !== ($prior['revision_no'] ?? 0) + 1 || $row['previous_id'] !== ($prior['id'] ?? null)
                    || NonprofitTaxRules::hash($canonical) !== NonprofitTaxRules::hash($row['profile'])
                    || !hash_equals($row['request_hash'], NonprofitTaxRules::hash($request))
                    || !hash_equals($row['record_hash'], NonprofitTaxRules::hash($record))) self::legalChanged();
                if (isset($targets[$period][$row['id']])) $out[$row['id']] = $row;
                $previous[$period] = $row;
                $cursorPeriod = $period;
                $cursorId = $row['id'];
            }
        } while (count($batch) === 500);
        return $out;
    }

    private static function memberChanged(): never
    {
        throw new MembershipException('nonprofit_membership_member_proof_changed', 'Zachycený členský podklad není průkazný.', 409);
    }

    private static function legalChanged(): never
    {
        throw new MembershipException('nonprofit_membership_legal_proof_changed', 'Zachycený právní podklad není průkazný.', 409);
    }
}
